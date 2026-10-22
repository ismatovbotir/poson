<?php

use App\Actions\Leads\SubmitSiteLead;
use App\Data\Leads\SiteLeadData;
use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Livewire\Site\LeadForm;
use App\Support\Site\Navigation;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * A rendered lead form with valid data, opened long enough ago to pass the fill-time check.
 *
 * @param  array<string, string>  $overrides
 */
function filledLeadForm(array $overrides = [], ?Testable $component = null): Testable
{
    $component ??= Livewire::test(LeadForm::class);

    foreach (array_merge(['name' => 'Aziz', 'phone' => '90 123 45 67', 'company' => 'OOO Market'], $overrides) as $field => $value) {
        $component->set($field, $value);
    }

    test()->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();

    return $component;
}

/**
 * Distinct valid Uzbek numbers, so the per-phone limit doesn't interfere.
 */
function leadPhone(int $index): string
{
    return '+99890'.str_pad((string) (1000000 + $index), 7, '0', STR_PAD_LEFT);
}

it('requires a name and a phone', function () {
    $this->mock(SubmitSiteLead::class)->shouldNotReceive('handle');

    Livewire::test(LeadForm::class)
        ->call('submit')
        ->assertHasErrors(['name' => 'required', 'phone' => 'required'])
        ->assertHasNoErrors(['company', 'message'])
        ->assertSet('sent', false);
});

it('rejects a phone that is not an Uzbek number', function (string $phone) {
    $this->mock(SubmitSiteLead::class)->shouldNotReceive('handle');

    filledLeadForm(['phone' => $phone])
        ->call('submit')
        ->assertHasErrors(['phone'])
        ->assertSee(__('validation.uz_phone', ['attribute' => __('site.lead_form.attributes.phone')]));
})->with(['+998 00 000 00 00', '12345', '+7 912 345 67 89']);

it('limits the message to 1000 characters', function () {
    filledLeadForm(['message' => str_repeat('a', 1001)])
        ->call('submit')
        ->assertHasErrors(['message' => 'max']);
});

it('shows validation messages in the page locale', function (string $locale, string $text) {
    app()->setLocale($locale);

    Livewire::test(LeadForm::class)
        ->call('submit')
        ->assertSee($text);
})->with([
    'uz' => ['uz', 'Ism maydoni toʻldirilishi shart.'],
    'ru' => ['ru', 'Поле имя обязательно.'],
    'en' => ['en', 'The name field is required.'],
]);

it('submits a valid lead through the action with a normalized phone', function () {
    app()->setLocale('ru');

    $this->mock(SubmitSiteLead::class)
        ->shouldReceive('handle')
        ->once()
        ->withArgs(fn (SiteLeadData $data): bool => $data->name === 'Aziz'
            && $data->phone === '+998901234567'
            && $data->company === 'OOO Market'
            && $data->message === 'Need 3 scanners'
            && $data->locale === 'ru'
            && $data->source === LeadSource::Site
            && $data->type === LeadType::Callback
            && $data->utm === ['utm_source' => 'telegram', 'utm_campaign' => 'autumn'])
        ->andReturn('L-TEST');

    // A non-UTM query parameter ("phone") is neither captured nor used to prefill the form.
    $component = Livewire::withQueryParams(['utm_source' => 'telegram', 'utm_campaign' => 'autumn', 'phone' => '+998935554433'])
        ->test(LeadForm::class)
        ->assertSet('utm', ['utm_source' => 'telegram', 'utm_campaign' => 'autumn'])
        ->assertSet('phone', '');

    filledLeadForm(['name' => ' Aziz ', 'message' => 'Need 3 scanners'], $component)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true)
        ->assertSet('phone', '')
        ->assertSee(__('site.lead_form.success_title', [], 'ru'))
        ->assertSee(__('site.lead_form.success_text', [], 'ru'))
        ->assertSee('data-lead-success', false)
        ->assertDontSee('wire:submit="submit"', false);
});

it('sends empty optional fields as null', function () {
    $this->mock(SubmitSiteLead::class)
        ->shouldReceive('handle')
        ->once()
        ->withArgs(fn (SiteLeadData $data): bool => $data->company === null && $data->message === null)
        ->andReturn('L-TEST');

    filledLeadForm(['company' => '   '])->call('submit')->assertHasNoErrors();
});

it('silently accepts honeypot submissions without calling the action', function () {
    $this->mock(SubmitSiteLead::class)->shouldNotReceive('handle');

    filledLeadForm(['fax_ext' => '123'])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true)
        ->assertSee(__('site.lead_form.success_title'));
});

it('silently accepts forms submitted faster than the minimum fill time', function () {
    $this->mock(SubmitSiteLead::class)->shouldNotReceive('handle');

    Livewire::test(LeadForm::class)
        ->set('name', 'Aziz')
        ->set('phone', '90 123 45 67')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true);
});

it('rate limits submissions to five per ten minutes per IP', function () {
    $this->mock(SubmitSiteLead::class)
        ->shouldReceive('handle')
        ->times(LeadForm::RATE_LIMIT)
        ->andReturn('L-TEST');

    foreach (range(1, LeadForm::RATE_LIMIT) as $attempt) {
        filledLeadForm(['phone' => leadPhone($attempt)])->call('submit')->assertHasNoErrors()->assertSet('sent', true);
    }

    filledLeadForm(['phone' => leadPhone(99)])
        ->call('submit')
        ->assertHasErrors(['form'])
        ->assertSet('sent', false)
        ->assertSee('data-form-error', false);

    $this->travel(LeadForm::RATE_LIMIT_SECONDS + 1)->seconds();

    $this->mock(SubmitSiteLead::class)->shouldReceive('handle')->once()->andReturn('L-TEST');

    filledLeadForm(['phone' => leadPhone(100)])->call('submit')->assertHasNoErrors()->assertSet('sent', true);
});

it('does not count invalid submissions toward the limit', function () {
    $this->mock(SubmitSiteLead::class)->shouldReceive('handle')->once()->andReturn('L-TEST');

    foreach (range(1, LeadForm::RATE_LIMIT + 2) as $attempt) {
        filledLeadForm(['phone' => '12345'])->call('submit')->assertHasErrors(['phone']);
    }

    filledLeadForm()->call('submit')->assertHasNoErrors()->assertSet('sent', true);
});

it('counts honeypot hits toward the IP limit', function () {
    $this->mock(SubmitSiteLead::class)->shouldNotReceive('handle');

    foreach (range(1, LeadForm::RATE_LIMIT) as $attempt) {
        filledLeadForm(['phone' => leadPhone($attempt), 'fax_ext' => 'x'])->call('submit')->assertSet('sent', true);
    }

    filledLeadForm(['phone' => leadPhone(99)])
        ->call('submit')
        ->assertHasErrors(['form'])
        ->assertSet('sent', false);
});

it('rate limits submissions to three per hour per phone number', function () {
    $this->mock(SubmitSiteLead::class)
        ->shouldReceive('handle')
        ->times(LeadForm::PHONE_LIMIT)
        ->andReturn('L-TEST');

    // One number in different formats normalizes to one limiter key; the IP limit (5) isn't reached.
    $formats = ['90 123 45 67', '+998 90 123 45 67', '998901234567', '(90) 123-45-67'];

    foreach ($formats as $index => $phone) {
        $component = filledLeadForm(['phone' => $phone])->call('submit');

        if ($index < LeadForm::PHONE_LIMIT) {
            $component->assertHasNoErrors()->assertSet('sent', true);

            continue;
        }

        $component->assertHasErrors(['form'])->assertSet('sent', false);

        preg_match('/\d+/', $component->errors()->first('form'), $seconds);

        // The phone window (an hour) is longer than the IP window (ten minutes).
        expect((int) $seconds[0])->toBeGreaterThan(LeadForm::RATE_LIMIT_SECONDS);
    }

    // Another number from the same IP still goes through.
    $this->mock(SubmitSiteLead::class)->shouldReceive('handle')->once()->andReturn('L-TEST');

    filledLeadForm(['phone' => leadPhone(1)])->call('submit')->assertHasNoErrors()->assertSet('sent', true);
});

it('logs the lead without the phone or the name', function () {
    Log::spy();

    filledLeadForm(['name' => 'Aziz Karimov', 'phone' => '+998 90 123 45 67', 'message' => 'Call me on 901234567'])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            $logged = $message.' '.json_encode($context, JSON_UNESCAPED_UNICODE);

            return ! str_contains($logged, '901234567')
                && ! str_contains($logged, '+998')
                && ! str_contains($logged, 'Aziz')
                && ! str_contains($logged, 'OOO Market')
                && str_starts_with($context['reference'], 'L-');
        });
});

it('rejects tampering with locked properties', function (string $property, mixed $value) {
    Livewire::test(LeadForm::class)->set($property, $value);
})->with([
    'utm' => ['utm', ['utm_source' => 'evil']],
    'attribution' => ['attribution', ['channel' => 'ai']],
    'locale' => ['locale', 'de'],
    'pageUrl' => ['pageUrl', 'https://evil.example'],
    'renderedAt' => ['renderedAt', 0],
])->throws(CannotUpdateLockedPropertyException::class);

it('falls back to UTM tags of the first-touch attribution in the session', function () {
    session()->put('attribution', ['channel' => 'campaign', 'source' => 'google', 'utm_source' => 'google', 'utm_medium' => 'cpc']);

    Livewire::test(LeadForm::class)
        ->assertSet('utm', ['utm_source' => 'google', 'utm_medium' => 'cpc']);
});

it('remembers UTM tags from the query as the first-touch attribution', function () {
    Livewire::withQueryParams(['utm_source' => 'telegram'])->test(LeadForm::class);

    expect(session('attribution'))->toMatchArray(['channel' => 'campaign', 'source' => 'telegram', 'utm_source' => 'telegram']);
});

it('keeps the first touch when a later page carries other UTM tags', function () {
    session()->put('attribution', ['channel' => 'ai', 'source' => 'chatgpt.com', 'utm_source' => 'chatgpt.com']);

    Livewire::withQueryParams(['utm_source' => 'telegram'])
        ->test(LeadForm::class)
        ->assertSet('utm', ['utm_source' => 'chatgpt.com'])
        ->assertSet('attribution.channel', 'ai');
});

it('sanitizes UTM tags from the query and the session', function (string $from) {
    $input = [
        'utm_source' => str_repeat('a', 300),
        'utm_medium' => ['nested' => 'array'],
        'utm_campaign' => "autumn\x00\x1F\x7F",
        'utm_term' => '   ',
        'gclid' => 'unknown-key',
        'phone' => '+998901234567',
    ];

    $component = $from === 'query'
        ? Livewire::withQueryParams($input)->test(LeadForm::class)
        : (function () use ($input) {
            session()->put('attribution', $input);

            return Livewire::test(LeadForm::class);
        })();

    $component->assertSet('utm', [
        'utm_source' => str_repeat('a', LeadForm::UTM_MAX_LENGTH),
        'utm_campaign' => 'autumn',
    ]);
})->with(['query', 'session']);

it('ignores a session attribution that is not an array', function () {
    session()->put('attribution', 'utm_source=evil');

    Livewire::test(LeadForm::class)->assertSet('utm', []);
});

it('renders accessible fields, a honeypot and the loading state', function () {
    $html = Livewire::test(LeadForm::class)->html();

    expect($html)
        ->toContain('for="lead-name"')->toContain('id="lead-name"')
        ->toContain('for="lead-phone"')->toContain('id="lead-phone"')
        ->toContain('type="tel"')
        ->toContain('for="lead-company"')->toContain('for="lead-message"')
        ->toContain('maxlength="120"')->toContain('maxlength="32"')
        ->toContain('maxlength="160"')->toContain('maxlength="1000"')
        ->toContain('aria-live="polite"')
        ->toContain('role="alert"')
        ->not->toContain('aria-live="assertive"')
        ->toContain('wire:model="fax_ext"')
        ->toContain('tabindex="-1"')
        ->not->toContain('website')
        ->toContain(__('site.lead_form.sending'))
        ->toContain(__('site.lead_form.consent_privacy'))
        ->toContain('href="'.e(Navigation::page('privacy')).'"');
});

it('moves focus to the success message', function () {
    filledLeadForm()
        ->call('submit')
        ->assertSee('data-lead-success', false)
        ->assertSeeHtml('tabindex="-1" x-init="$nextTick(() => $el.focus())"');
});
