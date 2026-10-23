<?php

use App\Actions\Quotes\GenerateQuotePdf;
use App\Actions\Settings\UpdateCompanyMedia;
use App\Enums\PriceLevel;
use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Livewire\Admin\Quotes\Show;
use App\Livewire\Admin\Settings\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Setting;
use App\Support\Site\RobotsTxt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * A small PNG upload (GD).
 */
function pngUpload(string $name, int $width, int $height): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

it('returns 404 for a draft and an unknown link', function () {
    $draft = Quote::factory()->create();

    $this->get(route('quotes.public', $draft->ulid))->assertNotFound();
    $this->get(route('quotes.public.pdf', $draft->ulid))->assertNotFound();
    $this->get(route('quotes.public', (string) Str::ulid()))->assertNotFound();
    $this->get('/q/not-a-ulid')->assertNotFound();
});

it('shows a sent quote with its lines, totals, print and PDF, never indexed', function () {
    $quote = Quote::factory()->sent()->totalling(3_000_000_00)->for(Customer::factory()->priceLevel(PriceLevel::Dealer)->state(['name' => 'Mega Savdo']))->create(['number' => 'Q-2026-0042']);
    QuoteItem::factory()->for($quote)->create(['name' => 'Zebra ZD421', 'qty' => 3, 'price' => 1_000_000_00, 'total' => 3_000_000_00]);

    $response = $this->get(route('quotes.public', $quote->ulid))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false)
        ->assertSee('Q-2026-0042')
        ->assertSee('Mega Savdo')
        ->assertSee('Zebra ZD421')
        ->assertSee('window.print()', false)
        ->assertSee(route('quotes.public.pdf', ['ulid' => $quote->ulid, 'lang' => 'uz']), false)
        ->assertDontSee('data-expired', false);

    // No price level or other price information.
    expect($response->getContent())
        ->not->toContain(PriceLevel::Dealer->label())
        ->not->toContain('price_dealer');
    expectNoBannedWording($response->getContent());
});

it('switches the language with ?lang', function (string $lang, string $title) {
    $quote = Quote::factory()->sent()->create(['locale' => 'uz']);

    $this->get(route('quotes.public', ['ulid' => $quote->ulid, 'lang' => $lang]))->assertOk()->assertSee($title);
})->with([
    'uz' => ['uz', 'Tijorat taklifi'],
    'ru' => ['ru', 'Коммерческое предложение'],
    'en' => ['en', 'Quote'],
]);

it('shows the expired banner after valid_until', function () {
    $quote = Quote::factory()->sent()->pastValidity()->create();

    $this->get(route('quotes.public', $quote->ulid))->assertOk()->assertSee('data-expired', false);

    $marked = Quote::factory()->status(QuoteStatus::Expired)->create();

    $this->get(route('quotes.public', $marked->ulid))->assertOk()->assertSee('data-expired', false);
});

it('downloads the PDF from the public link', function () {
    $quote = Quote::factory()->sent()->create(['number' => 'Q-2026-0077']);
    QuoteItem::factory()->for($quote)->create();

    $response = $this->get(route('quotes.public.pdf', $quote->ulid))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

    expect($response->headers->get('content-disposition'))->toContain('Q-2026-0077.pdf')
        ->and(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

it('is throttled, disallowed in robots.txt and kept out of analytics', function () {
    $quote = Quote::factory()->sent()->create();

    expect(RobotsTxt::DISALLOW)->toContain('/q/')
        ->and(config('analytics.exclude_paths'))->toContain('q/*');

    foreach (range(1, 60) as $i) {
        $this->get(route('quotes.public', $quote->ulid))->assertOk();
    }

    $this->get(route('quotes.public', $quote->ulid))->assertStatus(429);
});

describe('PDF', function () {
    beforeEach(function () {
        Storage::fake('local');
        Storage::fake('public');
    });

    it('renders the number, lines, VAT-inclusive total and the company requisites in the quote locale', function () {
        Setting::factory()->create(['key' => 'legal', 'group' => 'company', 'value' => ['legal_name' => ['uz' => 'Poson MChJ', 'ru' => 'ООО «Посон»'], 'inn' => '305000111', 'director' => 'Aziz Karimov']]);
        $quote = Quote::factory()->sent()->totalling(112_00)->create(['number' => 'Q-2026-0009', 'locale' => 'ru']);
        QuoteItem::factory()->for($quote)->forProduct(Product::factory()->create(['sku' => 'ZB-1']))->create();

        $html = app(GenerateQuotePdf::class)->html($quote);

        expect($html)
            ->toContain('Коммерческое предложение № Q-2026-0009')
            ->toContain('ООО «Посон»')
            ->toContain('305000111')
            ->toContain('ZB-1')
            ->toContain('Итого с НДС')
            ->toContain('в т.ч. НДС 12%')
            ->toContain('Aziz Karimov')
            ->toContain('DejaVu Sans')
            ->not->toContain('<img src="http');

        $pdf = app(GenerateQuotePdf::class)->pdf($quote)->output();

        expect(substr($pdf, 0, 5))->toBe('%PDF-')
            ->and(app()->getLocale())->toBe('uz');
    });

    it('embeds the stamp and signature from private storage only', function () {
        $admin = staffUser(Role::Admin);
        confirmAdminPassword();

        Livewire::actingAs($admin)->test(Company::class)
            ->set('tab', 'legal')
            ->set('legal.stamp', pngUpload('stamp.png', 300, 300))
            ->set('legal.signature', pngUpload('sign.png', 300, 120))
            ->call('save')
            ->assertHasNoErrors();

        foreach ([Setting::STAMP, Setting::SIGNATURE] as $key) {
            $media = Setting::query()->where('key', $key)->sole()->getFirstMedia($key);

            expect($media->disk)->toBe('local')
                ->and($media->generated_conversions)->toBe([]);

            Storage::disk('local')->assertExists($media->getPathRelativeToRoot());
            Storage::disk('public')->assertMissing($media->getPathRelativeToRoot());
        }

        $quote = Quote::factory()->sent()->create();
        $signed = app(GenerateQuotePdf::class)->html($quote, signed: true);

        expect($signed)
            ->toMatch('/<img src="data:image\/png;base64,[^"]+" class="stamp"/')
            ->toMatch('/<img src="data:image\/png;base64,[^"]+" class="signature"/')
            ->not->toContain('/storage/');

        // The customer's PDF and page: the stamp place only (user decision).
        $unsigned = app(GenerateQuotePdf::class)->html($quote);

        expect($unsigned)->not->toContain('data:image/png')->toContain('data-stamp-place')->toContain('M.O.');

        $this->get(route('quotes.public', $quote->ulid))
            ->assertOk()
            ->assertSee('data-stamp-place', false)
            ->assertDontSee('data:image/png', false);
    });

    it('accepts only PNG stamps', function () {
        expect(fn () => app(UpdateCompanyMedia::class)->validate([Setting::STAMP => ['file' => UploadedFile::fake()->image('stamp.jpg', 300, 300), 'remove' => false]]))
            ->toThrow(ValidationException::class);
    });

    it('lets only the admin upload the stamp', function () {
        Livewire::actingAs(staffUser(Role::ContentManager))->test(Company::class)->assertForbidden();
        Livewire::actingAs(staffUser(Role::SalesManager))->test(Company::class)->assertForbidden();
    });
});

it('signs the PDF downloaded in admin, never the public one', function () {
    Storage::fake('local');
    Storage::fake('public');

    $calls = [];
    $this->mock(GenerateQuotePdf::class, function ($mock) use (&$calls): void {
        $mock->shouldReceive('download')->andReturnUsing(function (Quote $quote, ?string $locale = null, bool $signed = false) use (&$calls) {
            $calls[] = $signed;

            return response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']);
        });
    });

    $admin = staffUser();
    $quote = Quote::factory()->sent()->managedBy($admin)->create();

    $this->actingAs($admin)->get(route('admin.quotes.pdf', $quote))->assertOk();
    auth()->logout();
    $this->get(route('quotes.public.pdf', $quote->ulid))->assertOk();

    expect($calls)->toBe([true, false]);
});

it('escapes hostile text on the public page and in the PDF', function () {
    $hostile = '<script>alert(1)</script><img src=file:///etc/passwd>';
    $quote = Quote::factory()->sent()->for(Customer::factory()->state(['name' => 'Evil '.$hostile]))->create(['notes' => 'Notes '.$hostile]);
    QuoteItem::factory()->for($quote)->create(['name' => 'Item '.$hostile]);

    $page = $this->get(route('quotes.public', $quote->ulid))->assertOk()->getContent();
    $pdf = app(GenerateQuotePdf::class)->html($quote);

    foreach ([$page, $pdf] as $html) {
        expect($html)
            ->not->toContain('<script>alert(1)</script>')
            ->not->toContain('<img src=file:///etc/passwd>')
            ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=file:///etc/passwd&gt;');
    }

    expect(app(GenerateQuotePdf::class)->pdf($quote)->output())->toStartWith('%PDF-');
});

it('falls back to the quote language for an unknown ?lang', function () {
    $quote = Quote::factory()->sent()->create(['locale' => 'ru']);

    $this->get(route('quotes.public', ['ulid' => $quote->ulid, 'lang' => 'xx']))->assertOk()->assertSee('Коммерческое предложение');
});

it('keeps working for rejected and expired quotes', function (QuoteStatus $status) {
    $quote = Quote::factory()->status($status)->create();

    $this->get(route('quotes.public', $quote->ulid))->assertOk();
})->with([QuoteStatus::Rejected, QuoteStatus::Expired, QuoteStatus::Accepted]);

it('sends private, no-store, no-referrer headers and throttles the PDF at 10 per minute', function () {
    $quote = Quote::factory()->sent()->create();

    $this->get(route('quotes.public', $quote->ulid))
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeaderMissing('Last-Modified');

    expect($this->get(route('quotes.public', $quote->ulid))->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    foreach (range(1, 10) as $i) {
        $this->get(route('quotes.public.pdf', $quote->ulid))->assertOk();
    }

    $this->get(route('quotes.public.pdf', $quote->ulid))->assertStatus(429);
});

it('records "customer opened the quote" once per day, without personal data', function () {
    $quote = Quote::factory()->sent()->create();

    $this->get(route('quotes.public', $quote->ulid))->assertOk();
    $this->get(route('quotes.public', $quote->ulid))->assertOk();

    $opened = $quote->activities()->where('meta->action', 'opened')->get();

    expect($opened)->toHaveCount(1)
        ->and($opened->first()->user_id)->toBeNull()
        ->and($opened->first()->meta)->toBe(['quote_id' => $quote->id, 'number' => $quote->number, 'action' => 'opened'])
        ->and($quote->customer->activities()->count())->toBe(0);

    $this->travel(1)->days();
    $this->get(route('quotes.public', $quote->ulid))->assertOk();

    expect($quote->activities()->where('meta->action', 'opened')->count())->toBe(2);

    $admin = staffUser();
    $quote->update(['manager_id' => $admin->id]);

    Livewire::actingAs($admin)->test(Show::class, ['quote' => $quote])->assertSee(__('crm.quotes.timeline.opened'));
});

it('accepts only PNG signatures and caps image sizes for the PDF', function () {
    $media = app(UpdateCompanyMedia::class);

    expect(fn () => $media->validate([Setting::SIGNATURE => ['file' => UploadedFile::fake()->image('sign.jpg', 300, 120), 'remove' => false]]))->toThrow(ValidationException::class)
        ->and(fn () => $media->validate([Setting::SIGNATURE => ['file' => pngUpload('sign.png', 1600, 400), 'remove' => false]]))->toThrow(ValidationException::class)
        ->and(fn () => $media->validate([Setting::STAMP => ['file' => pngUpload('stamp.png', 1501, 1501), 'remove' => false]]))->toThrow(ValidationException::class)
        ->and(fn () => $media->validate([Setting::LOGO => ['file' => pngUpload('logo.png', 2000, 2000), 'remove' => false]]))->toThrow(ValidationException::class);

    $media->validate([Setting::STAMP => ['file' => pngUpload('stamp.png', 1500, 1500), 'remove' => false]]);
    $media->validate([Setting::OG_IMAGE => ['file' => UploadedFile::fake()->image('og.jpg', 2400, 1260), 'remove' => false]]);

    expect(UpdateCompanyMedia::maxDimension(Setting::OG_IMAGE))->toBe(UpdateCompanyMedia::MAX_DIMENSION);
});

it('renders the PDF with dompdf hardened', function () {
    $quote = Quote::factory()->sent()->create();
    $options = app(GenerateQuotePdf::class)->pdf($quote)->getDomPDF()->getOptions();

    expect($options->getIsRemoteEnabled())->toBeFalse()
        ->and($options->getIsPhpEnabled())->toBeFalse()
        ->and($options->getIsJavascriptEnabled())->toBeFalse()
        ->and($options->getChroot())->toBe([realpath(GenerateQuotePdf::chroot())]);
});
