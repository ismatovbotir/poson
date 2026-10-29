<?php

use App\Actions\Settings\UpdateUzqrImage;
use App\Enums\Region;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Jobs\SendTelegramMessage;
use App\Livewire\Admin\Products\Form;
use App\Livewire\Admin\Settings\Checkout;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Support\Orders\CheckoutSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

it('saves fees, caps and fiscal codes, audited without values', function () {
    $admin = staffUser();

    Livewire::actingAs($admin)->test(Checkout::class)
        ->set('form.region_fees.samarkand', '150 000')
        ->set('form.max_online_total', '30 000 000')
        ->set('form.max_manual_discount_percent', '5')
        ->set('form.delivery_ikpu', '10112006002000000')
        ->set('form.uzqr_recipient', 'Poson MChJ')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(CheckoutSettings::class);

    expect($settings->regionFee(Region::Samarkand))->toBe(150_000_00)
        ->and($settings->regionFee(Region::Khorezm))->toBeNull()
        ->and($settings->maxOnlineTotal())->toBe(30_000_000_00)
        ->and($settings->maxManualDiscountPercent())->toBe(5)
        ->and($settings->deliveryIkpu())->toBe('10112006002000000')
        ->and(SettingsAudit::query()->where('area', SettingsArea::Checkout->value)->where('key', 'checkout')->exists())->toBeTrue();
});

it('validates the IKPU and the percentage', function () {
    Livewire::actingAs(staffUser())->test(Checkout::class)
        ->set('form.delivery_ikpu', '123')
        ->set('form.max_manual_discount_percent', '150')
        ->call('save')
        ->assertHasErrors(['form.delivery_ikpu', 'form.max_manual_discount_percent']);
});

it('is Admin only', function (Role $role) {
    Livewire::actingAs(staffUser($role))->test(Checkout::class)->assertForbidden();
})->with([[Role::SalesManager], [Role::ContentManager], [Role::ServiceEngineer]]);

it('re-encodes the UzQR code to a fresh PNG on the private disk', function () {
    $jpeg = UploadedFile::fake()->image('qr.jpg', 400, 400);

    app(UpdateUzqrImage::class)->handle($jpeg, false, staffUser(), '127.0.0.1');

    $media = Setting::query()->where('key', Setting::UZQR)->sole()->getMedia(Setting::UZQR)->sole();
    $stored = Storage::disk('local')->get($media->getPathRelativeToRoot());

    expect($media->mime_type)->toBe('image/png')
        ->and($media->disk)->toBe('local')
        ->and($media->file_name)->toEndWith('.png')->not->toContain('qr')
        ->and(substr($stored, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(app(CheckoutSettings::class)->uzqrDataUri())->toStartWith('data:image/png;base64,');
});

it('rejects SVG, scripts renamed to .png and animated images', function (UploadedFile $file) {
    expect(fn () => app(UpdateUzqrImage::class)->handle($file, false, null, null))->toThrow(ValidationException::class);
    expect(Setting::query()->where('key', Setting::UZQR)->exists())->toBeFalse();
})->with([
    'svg' => fn () => UploadedFile::fake()->createWithContent('qr.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'php as png' => fn () => UploadedFile::fake()->createWithContent('qr.png', '<?php echo "pwned"; ?>'),
    'png with php extension' => fn () => UploadedFile::fake()->image('qr.php', 300, 300),
    'animated png' => function () {
        $source = UploadedFile::fake()->image('a.png', 300, 300);
        $png = (string) file_get_contents($source->getRealPath());

        // An acTL chunk before IDAT makes it an APNG.
        return UploadedFile::fake()->createWithContent('qr.png', substr($png, 0, 33)."\x00\x00\x00\x08acTL\x00\x00\x00\x02\x00\x00\x00\x00\x00\x00\x00\x00".substr($png, 33));
    },
    'too small' => fn () => UploadedFile::fake()->image('qr.png', 50, 50),
]);

it('keeps the previous code, audits the replacement and alerts finance and sales', function () {
    freshSecrets(['services.telegram.bot_token' => '123456789:'.str_repeat('A', 35)]);
    Queue::fake([SendTelegramMessage::class]);
    $admin = staffUser();

    app(UpdateUzqrImage::class)->handle(UploadedFile::fake()->image('a.png', 300, 300), false, $admin, null);
    app(UpdateUzqrImage::class)->handle(UploadedFile::fake()->image('b.png', 300, 300), false, $admin, null);

    $setting = Setting::query()->where('key', Setting::UZQR)->sole();

    expect($setting->getMedia(Setting::UZQR))->toHaveCount(1)
        ->and($setting->getMedia(Setting::UZQR_HISTORY))->toHaveCount(1)
        ->and(SettingsAudit::query()->where('key', Setting::UZQR)->count())->toBe(2);

    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->chat === 'finance' && str_contains($job->html, __('telegram.order.uzqr.replaced', [], 'uz')));
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->chat === 'sales' && str_contains($job->html, __('telegram.order.uzqr.replaced', [], 'uz')));

    app(UpdateUzqrImage::class)->handle(null, true, $admin, null);
    expect($setting->fresh()->getMedia(Setting::UZQR))->toHaveCount(0)
        ->and($setting->fresh()->getMedia(Setting::UZQR_HISTORY))->toHaveCount(2)
        ->and(app(CheckoutSettings::class)->hasUzqr())->toBeFalse();
});

it('saves product IKPU and package codes and filters products without IKPU', function () {
    $product = Product::factory()->create();

    Livewire::actingAs(staffUser())->test(Form::class, ['product' => $product])
        ->set('form.ikpu_code', '123')->call('save')->assertHasErrors('form.ikpu_code')
        ->set('form.ikpu_code', '08471001001000000')->set('form.package_code', '1501171')->call('save')->assertHasNoErrors();

    expect($product->fresh()->ikpu_code)->toBe('08471001001000000')->and($product->fresh()->package_code)->toBe('1501171');
});
