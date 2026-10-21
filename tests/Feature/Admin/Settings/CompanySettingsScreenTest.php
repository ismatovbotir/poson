<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Settings\Company;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\CompanySettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function companyScreen(?User $user = null): Testable
{
    return Livewire::actingAs($user ?? staffUser())->test(Company::class);
}

describe('access', function () {
    it('opens for the admin with every tab', function () {
        $this->actingAs(staffUser())
            ->get(route('admin.settings.company'))
            ->assertOk()
            ->assertSeeLivewire(Company::class)
            ->assertSee('data-settings-tab="legal"', false)
            ->assertSee('data-settings-tab="tracking"', false);
    });

    it('forbids every other staff role', function (Role $role) {
        $this->actingAs(staffUser($role))
            ->get(route('admin.settings.company'))
            ->assertForbidden();
    })->with([
        'content manager' => [Role::ContentManager],
        'sales manager' => [Role::SalesManager],
        'service engineer' => [Role::ServiceEngineer],
    ]);

    it('forbids a direct save by another role', function (Role $role) {
        $component = companyScreen()->set('contacts.email', 'hacked@example.com');

        $this->actingAs(staffUser($role));

        $component->call('save')->assertForbidden();

        expect(Setting::query()->where('key', 'email')->exists())->toBeFalse()
            ->and(SettingsAudit::query()->count())->toBe(0);
    })->with([
        'content manager' => [Role::ContentManager],
        'sales manager' => [Role::SalesManager],
        'service engineer' => [Role::ServiceEngineer],
    ]);

    it('keeps Legal and Tracking for users who also have settings.integrations', function () {
        $user = staffUser(Role::ContentManager);
        $user->givePermissionTo('settings.company');

        $this->actingAs($user)
            ->get(route('admin.settings.company'))
            ->assertOk()
            ->assertSee('data-settings-tab="contacts"', false)
            ->assertDontSee('data-settings-tab="legal"', false)
            ->assertDontSee('data-settings-tab="tracking"', false);

        Livewire::actingAs($user)->withQueryParams(['tab' => 'tracking'])->test(Company::class)
            ->assertSet('tab', 'contacts')
            ->set('tab', 'legal')
            ->assertSet('tab', 'contacts');
    });

    it('grants the settings permissions to the admin role only', function () {
        foreach ([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer] as $role) {
            $user = staffUser($role);

            foreach (['settings.company', 'settings.integrations', 'analytics.view'] as $permission) {
                expect($user->hasPermissionTo($permission))->toBeFalse("{$role->value} must not have {$permission}");
            }
        }

        $admin = staffUser();

        expect($admin->hasPermissionTo('settings.company'))->toBeTrue()
            ->and($admin->hasPermissionTo('settings.integrations'))->toBeTrue()
            ->and($admin->hasPermissionTo('analytics.view'))->toBeTrue();
    });

    it('shows the menu item only to users with settings.company', function () {
        $this->actingAs(staffUser())->get(route('admin.dashboard'))
            ->assertSee('href="'.route('admin.settings.company').'"', false);

        $this->actingAs(staffUser(Role::ContentManager))->get(route('admin.dashboard'))
            ->assertDontSee('href="'.route('admin.settings.company').'"', false);
    });
});

describe('saving', function () {
    it('saves only changed keys, audits them without values and flushes the cache', function () {
        $admin = staffUser();
        app(CompanySettings::class)->email();
        expect(Cache::has(CompanySettings::CACHE_KEY))->toBeTrue();

        companyScreen($admin)
            ->set('contacts.email', 'sales-desk@poson.uz')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        expect(Setting::query()->pluck('key')->all())->toBe(['email'])
            ->and(Setting::query()->sole()->group)->toBe('company')
            ->and(Cache::get(CompanySettings::CACHE_KEY))->toBe(['email' => 'sales-desk@poson.uz'])
            ->and(app(CompanySettings::class)->email())->toBe('sales-desk@poson.uz');

        $audit = SettingsAudit::query()->sole();

        expect($audit->user_id)->toBe($admin->id)
            ->and($audit->area)->toBe(SettingsArea::Contacts)
            ->and($audit->key)->toBe('email')
            ->and($audit->action)->toBe(AuditAction::Updated)
            ->and($audit->ip)->toBe('127.0.0.1')
            ->and(json_encode($audit->getAttributes()))->not->toContain('sales-desk')
            ->and(Schema::getColumnListing('settings_audits'))->toBe(['id', 'user_id', 'area', 'key', 'action', 'ip', 'created_at']);
    });

    it('writes nothing when nothing changed', function () {
        companyScreen()->call('save')->assertHasNoErrors();
        companyScreen()->set('tab', 'hero')->call('save')->assertHasNoErrors();

        expect(Setting::query()->count())->toBe(0)
            ->and(SettingsAudit::query()->count())->toBe(0);
    });

    it('saves phones with labels, a primary number and the site flag', function () {
        companyScreen()
            ->call('addPhone')
            ->set('contacts.phones.0.number', '90 123 45 67')
            ->set('contacts.phones.1.label', 'service')
            ->set('contacts.phones.1.number', '+998 (93) 555-44-33')
            ->set('contacts.phones.1.site', false)
            ->set('contacts.primary', 1)
            ->set('contacts.lat', '41,311081')
            ->set('contacts.lng', '69.240562')
            ->call('save')
            ->assertHasNoErrors();

        $settings = app(CompanySettings::class);

        expect(Setting::query()->where('key', 'phones')->sole()->value)->toBe([
            ['label' => 'orders', 'number' => '+998901234567', 'primary' => false, 'site' => true],
            ['label' => 'service', 'number' => '+998935554433', 'primary' => true, 'site' => true],
        ])
            ->and($settings->phone())->toBe('+998935554433')
            ->and($settings->geo())->toBe(['lat' => 41.311081, 'lng' => 69.240562])
            ->and($settings->mapUrl())->toBe('https://yandex.uz/maps/?pt=69.240562,41.311081&z=17&l=map');
    });

    it('limits phones to one to five', function () {
        $component = companyScreen();

        $component->call('removePhone', 0)->assertCount('contacts.phones', 1);

        foreach (range(1, 6) as $ignored) {
            $component->call('addPhone');
        }

        $component->assertCount('contacts.phones', 5);
    });

    it('saves legal details, social links, hero stats, SEO codes and tracking', function () {
        companyScreen()
            ->set('tab', 'legal')
            ->set('legal.inn', '301 234 567')
            ->set('legal.account', '2020 8000 1234 5678 9001')
            ->set('legal.legal_name.uz', 'POSON MChJ')
            ->call('save')->assertHasNoErrors()
            ->set('tab', 'social')
            ->call('addSocialLink')
            ->set('social.links.1.network', 'instagram')
            ->set('social.links.1.url', 'https://www.instagram.com/poson.uz')
            ->call('save')->assertHasNoErrors()
            ->set('tab', 'hero')
            ->set('hero.stats.0.value.en', '24 months')
            ->call('save')->assertHasNoErrors()
            ->set('tab', 'seo')
            ->set('seo.google', 'abcDEF123_-abcDEF123_-xyz')
            ->call('save')->assertHasNoErrors()
            ->set('tab', 'tracking')
            ->set('tracking.enabled', true)
            ->set('tracking.ga4', ' g-ab12cd34ef ')
            ->set('tracking.metrika', '98765432')
            ->call('save')->assertHasNoErrors();

        $settings = app(CompanySettings::class);

        expect($settings->legal()['inn'])->toBe('301234567')
            ->and($settings->legal()['account'])->toBe('20208000123456789001')
            ->and($settings->social())->toHaveCount(2)
            ->and($settings->heroStats())->toHaveCount(3)
            ->and($settings->verification()['google'])->toBe('abcDEF123_-abcDEF123_-xyz')
            ->and($settings->tracking())->toBe(['enabled' => true, 'ga4' => 'G-AB12CD34EF', 'metrika' => '98765432'])
            ->and(Setting::query()->where('key', 'tracking')->sole()->group)->toBe('seo')
            ->and(SettingsAudit::query()->orderBy('id')->pluck('area')->map->value->all())->toBe(['legal', 'social', 'hero', 'seo', 'tracking']);
    });
});

describe('validation', function () {
    it('rejects invalid values per field', function (string $tab, string $field, mixed $value) {
        $component = companyScreen()->set('tab', $tab);

        if ($tab === 'social') {
            $component->set('social.links.0.network', 'instagram');
        }

        $component->set($field, $value)->call('save')->assertHasErrors([$field]);

        expect(SettingsAudit::query()->count())->toBe(0);
    })->with([
        'phone' => ['contacts', 'contacts.phones.0.number', '12345'],
        'email' => ['contacts', 'contacts.email', 'not-an-email'],
        'name uz required' => ['contacts', 'contacts.name.uz', ''],
        'latitude range' => ['contacts', 'contacts.lat', '91'],
        'latitude format' => ['contacts', 'contacts.lat', '41.3abc'],
        'inn 8 digits' => ['legal', 'legal.inn', '12345678'],
        'inn letters' => ['legal', 'legal.inn', '12345678a'],
        'vat 11 digits' => ['legal', 'legal.vat_code', '12345678901'],
        'mfo 4 digits' => ['legal', 'legal.mfo', '0001'],
        'account 19 digits' => ['legal', 'legal.account', '2020800012345678900'],
        'oked 6 digits' => ['legal', 'legal.oked', '123456'],
        'iframe' => ['social', 'social.links.0.url', '<iframe src="https://www.instagram.com/poson"></iframe>'],
        'javascript' => ['social', 'social.links.0.url', 'javascript:alert(1)'],
        'http' => ['social', 'social.links.0.url', 'http://www.instagram.com/poson'],
        'foreign host' => ['social', 'social.links.0.url', 'https://instagram.com.evil.example/poson'],
        'other network host' => ['social', 'social.links.0.url', 'https://t.me/poson'],
        'hero discount' => ['hero', 'hero.stats.0.value.ru', '−20%'],
        'hero chegirma' => ['hero', 'hero.stats.1.label.uz', 'katta chegirma'],
        'whole meta tag' => ['seo', 'seo.google', '<meta name="google-site-verification" content="abcDEF123_-abcDEF123_-xyz">'],
        'yandex' => ['seo', 'seo.yandex', 'not-hex-zzzzzzzz'],
        'bing' => ['seo', 'seo.bing', '1234'],
        'ga4' => ['tracking', 'tracking.ga4', 'UA-12345-1'],
        'metrika' => ['tracking', 'tracking.metrika', 'ym(123)'],
    ]);

    it('requires an ID to enable tracking', function () {
        companyScreen()->set('tab', 'tracking')->set('tracking.enabled', true)->call('save')->assertHasErrors(['tracking.ga4' => 'required']);
    });

    it('requires opening hours to close after they open', function () {
        companyScreen()
            ->set('contacts.opening_hours.0.closes', '08:00')
            ->call('save')
            ->assertHasErrors(['contacts.opening_hours.0.closes']);
    });

    it('never echoes the input in error messages', function () {
        $component = companyScreen()->set('tab', 'tracking')->set('tracking.ga4', '<b>EVIL-XSS</b>')->call('save');

        expect(collect($component->errors()->all())->implode(' '))->not->toContain('EVIL');
    });

    it('cannot reach a tab outside the list', function () {
        companyScreen()->set('tab', 'integrations')->assertSet('tab', 'contacts');
    });
});

describe('media', function () {
    beforeEach(function () {
        Storage::fake('public');
        Storage::fake('local');
    });

    it('stores the logo and OG image privately with public conversions only', function () {
        companyScreen()
            ->set('tab', 'seo')
            ->set('seo.logo', UploadedFile::fake()->image('my logo.png', 300, 300))
            ->set('seo.og_image', UploadedFile::fake()->image('share.jpg', 1200, 630))
            ->call('save')
            ->assertHasNoErrors();

        foreach ([Setting::LOGO => 'logo', Setting::OG_IMAGE => 'og'] as $key => $conversion) {
            $media = Setting::query()->where('key', $key)->sole()->getFirstMedia($key);

            expect($media->disk)->toBe('local')
                ->and($media->conversions_disk)->toBe('public')
                ->and($media->file_name)->toMatch('/^[0-9a-f-]{36}\.(png|jpg)$/');

            Storage::disk('local')->assertExists($media->getPathRelativeToRoot());
            Storage::disk('public')->assertMissing($media->getPathRelativeToRoot());
            Storage::disk('public')->assertExists($media->getPathRelativeToRoot($conversion));
        }

        $settings = app(CompanySettings::class);

        expect($settings->logoUrl())->toContain('conversions/')->toEndWith('.webp')
            ->and($settings->ogImageUrl())->toContain('conversions/')->toEndWith('.jpg')
            ->and(SettingsAudit::query()->orderBy('id')->pluck('action')->map->value->all())->toBe(['uploaded', 'uploaded']);
    });

    it('rejects SVG files and too small OG images', function () {
        companyScreen()
            ->set('tab', 'seo')
            ->set('seo.logo', UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'))
            ->assertHasErrors(['seo.logo'])
            ->set('seo.og_image', UploadedFile::fake()->image('small.jpg', 800, 400))
            ->assertHasErrors(['seo.og_image']);

        expect(Setting::query()->count())->toBe(0);
    });

    it('removes an image and audits it', function () {
        companyScreen()->set('tab', 'seo')->set('seo.logo', UploadedFile::fake()->image('logo.png', 300, 300))->call('save');

        companyScreen()->set('tab', 'seo')->call('removeLogo')->call('save')->assertHasNoErrors();

        expect(Setting::query()->where('key', Setting::LOGO)->sole()->getFirstMedia(Setting::LOGO))->toBeNull()
            ->and(app(CompanySettings::class)->logoUrl())->toBe('')
            ->and(SettingsAudit::query()->latest('id')->first()->action)->toBe(AuditAction::Removed);
    });
});

describe('authorization on every request', function () {
    it('re-checks settings.company on each update, uploads included', function () {
        $admin = staffUser();
        $contentManager = staffUser(Role::ContentManager);

        $upload = companyScreen($admin);
        $this->actingAs($contentManager);
        $upload->call('_startUpload', 'seo.logo', [['name' => 'logo.png', 'size' => 1000, 'type' => 'image/png']], false)->assertForbidden();

        $repeater = companyScreen($admin);
        $this->actingAs($contentManager);
        $repeater->call('addPhone')->assertForbidden();
    });

    it('refuses Legal and Tracking saves without settings.integrations', function (string $tab, string $field, string $value, string $key) {
        $component = Livewire::actingAs(staffUser())->withQueryParams(['tab' => $tab])->test(Company::class)->assertSet('tab', $tab);

        $user = staffUser(Role::ContentManager);
        $user->givePermissionTo('settings.company');
        $this->actingAs($user);

        $component->set($field, $value)->call('save')->assertForbidden();

        expect(Setting::query()->where('key', $key)->exists())->toBeFalse()
            ->and(SettingsAudit::query()->count())->toBe(0);
    })->with([
        'legal' => ['legal', 'legal.inn', '301234567', 'legal'],
        'tracking' => ['tracking', 'tracking.metrika', '98765432', 'tracking'],
    ]);
});

it('rejects duplicate phone numbers after normalization', function () {
    companyScreen()
        ->call('addPhone')
        ->set('contacts.phones.0.number', '90 123 45 67')
        ->set('contacts.phones.1.number', '+998 (90) 123-45-67')
        ->call('save')
        ->assertHasErrors(['contacts.phones.1.number'])
        ->assertHasNoErrors(['contacts.phones.0.number']);
});

it('copes with malformed repeater rows', function () {
    companyScreen()->set('contacts.phones', ['oops'])->call('save')->assertHasErrors(['contacts.phones.0.number']);
    companyScreen()->set('tab', 'social')->set('social.links', ['oops'])->call('save')->assertHasErrors(['social.links.0.network']);
});

it('rejects images larger than 6000 px', function () {
    Storage::fake('public');
    Storage::fake('local');

    companyScreen()
        ->set('tab', 'seo')
        ->set('seo.logo', UploadedFile::fake()->image('huge.png', 6001, 200))
        ->assertHasErrors(['seo.logo']);
});

it('records the client IP from a trusted proxy', function () {
    config(['app.trusted_proxies' => ['10.0.0.0/8']]);
    $admin = staffUser();
    $snapshot = \Livewire\invade(companyScreen($admin))->lastState->getSnapshot();

    $this->actingAs($admin)
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.7', 'X-Livewire' => '1'])
        ->postJson(app('livewire')->getUpdateUri(), ['components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => ['contacts.email' => 'proxy@poson.uz'],
            'calls' => [['method' => 'save', 'params' => [], 'metadata' => []]],
        ]]])
        ->assertOk();

    expect(SettingsAudit::query()->sole()->ip)->toBe('203.0.113.7');
});
