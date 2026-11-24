<?php

use App\Actions\Orders\PlaceOrder;
use App\Enums\LeadFlow;
use App\Enums\TrafficChannel;
use App\Events\LeadCreated;
use App\Livewire\Site\Checkout\Page as CheckoutPage;
use App\Livewire\Site\LeadForm;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Setting;
use App\Support\Analytics\AdClickIds;
use App\Support\Analytics\Attribution;
use App\Support\Analytics\TrafficClassifier;
use App\Support\Chat\WebChatDialog;
use App\Support\CompanySettings;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

require_once __DIR__.'/../../Site/Chat/WidgetHelpers.php';

/**
 * Plan 40 T3: Yandex click ids (YD-6, YD-7, YD-8 switch, YD-9).
 */
const YD_YCLID = '1234567890123456789';
const YD_CLIENT = '1700000000123456789';

function ydClickIds(bool $on = true): void
{
    Setting::query()->updateOrCreate(['key' => CompanySettings::AD_CLICK_IDS_KEY], ['group' => 'seo', 'value' => $on]);
    CompanySettings::flush();
}

function ydMetrika(bool $metrika = true): void
{
    Setting::query()->updateOrCreate(['key' => 'tracking'], ['group' => 'seo', 'value' => ['enabled' => true, 'ga4' => 'G-AB12CD34EF', 'metrika' => $metrika ? '98765432' : '']]);
    config(['site.analytics_force' => true]);
    CompanySettings::flush();
}

function ydSubmitLead(array $cookies = []): void
{
    $component = Livewire::withCookies($cookies)->test(LeadForm::class)->set('name', 'Aziz')->set('phone', '90 123 45 67');
    test()->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $component->call('submit')->assertHasNoErrors();
}

beforeEach(function () {
    app()->setLocale('en');
    URL::defaults(['locale' => 'en']);
});

describe('YD-7 yclid in the session', function () {
    it('keeps the last valid Direct click, UTM and channel stay first touch', function () {
        ydClickIds();

        $this->get('/uz?utm_source=yandex&utm_medium=cpc&utm_campaign=first&yclid=111')->assertOk();
        $this->get('/uz/catalog?yclid=222&utm_campaign=second')->assertOk();

        $stored = session(Attribution::SESSION_KEY);

        expect($stored['yclid'])->toBe('222')
            ->and($stored['utm_campaign'])->toBe('first')
            ->and($stored['channel'])->toBe('campaign');
    });

    it('drops invalid yclid values (never cut)', function (string $value) {
        ydClickIds();

        $this->get('/uz?yclid='.urlencode($value))->assertOk();

        expect(session(Attribution::SESSION_KEY))->not->toHaveKey('yclid');
    })->with(['abc', '12a', str_repeat('1', 33), '-1', '1 2', '=1+1', '']);

    it('stores nothing in the session while the switch is off', function () {
        ydClickIds(false);

        $this->get('/uz?yclid=111')->assertOk();

        expect(session(Attribution::SESSION_KEY))->not->toHaveKey('yclid');
    });

    it('re-validates a session value on read', function () {
        expect(Attribution::sanitize(['channel' => 'campaign', 'yclid' => '12<script>']))->not->toHaveKey('yclid')
            ->and(Attribution::sanitize(['yclid' => ['1']]))->not->toHaveKey('yclid')
            ->and(Attribution::sanitize(['yclid' => '42']))->toBe(['yclid' => '42']);
    });

    it('classifies a Direct click without UTM tags as a yandex campaign', function () {
        $classifier = app(TrafficClassifier::class);

        expect($classifier->classify(null, 'yandex.ru', [], '123')->channel)->toBe(TrafficChannel::Campaign)
            ->and($classifier->classify(null, 'yandex.ru', [], '123')->source)->toBe('yandex')
            ->and($classifier->classify(null, 'yandex.ru', [], 'abc')->channel)->toBe(TrafficChannel::Search)
            ->and($classifier->classify('google', null, [], '123')->source)->toBe('google');

        $this->get('/uz?yclid=555')->assertOk();
        expect(session(Attribution::SESSION_KEY)['channel'])->toBe('campaign')
            ->and(session(Attribution::SESSION_KEY)['source'])->toBe('yandex');
    });
});

describe('YD-6 / YD-8 storing with leads and orders', function () {
    it('stores the session yclid and the Metrika ClientID with a site lead, never in its Livewire state', function () {
        ydClickIds();
        ydMetrika();
        session()->put(Attribution::SESSION_KEY, ['channel' => 'campaign', 'source' => 'yandex', 'yclid' => YD_YCLID]);
        Log::spy();

        $component = Livewire::withCookies(['_ym_uid' => YD_CLIENT])->test(LeadForm::class);

        expect(json_encode($component->snapshot))->not->toContain(YD_YCLID)->not->toContain(YD_CLIENT);

        $component->set('name', 'Aziz')->set('phone', '90 123 45 67');
        $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
        $component->call('submit')->assertHasNoErrors();

        $lead = Lead::query()->sole();

        expect($lead->yclid)->toBe(YD_YCLID)
            ->and($lead->ym_client_id)->toBe(YD_CLIENT)
            ->and($lead->toArray())->not->toHaveKey('yclid')->not->toHaveKey('ym_client_id')
            ->and($lead->utm)->not->toHaveKey('yclid');

        Log::shouldNotHaveReceived('info', fn (string $message, array $context = []): bool => str_contains(json_encode($context), YD_YCLID) || str_contains(json_encode($context), YD_CLIENT));
    });

    it('stores nothing while the switch is off', function () {
        ydClickIds(false);
        ydMetrika();
        session()->put(Attribution::SESSION_KEY, ['channel' => 'campaign', 'yclid' => YD_YCLID]);

        ydSubmitLead(['_ym_uid' => YD_CLIENT]);

        expect(Lead::query()->sole()->only(['yclid', 'ym_client_id']))->toBe(['yclid' => null, 'ym_client_id' => null]);
    });

    it('drops an invalid or foreign ClientID cookie', function (mixed $cookie) {
        ydClickIds();
        ydMetrika();

        ydSubmitLead(['_ym_uid' => $cookie]);

        expect(Lead::query()->sole()->ym_client_id)->toBeNull();
    })->with([
        'letters' => ['abc'],
        'separator' => ['1;2'],
        'formula' => ['=1+1'],
        '25 digits' => [str_repeat('1', 25)],
        '9 digits' => ['123456789'],
        'encrypted-looking' => ['eyJpdiI6IjEyMyIsInZhbHVlIjoiYWJjIn0='],
    ]);

    it('reads no ClientID without Metrika on the page (GA4 only) or for an array cookie', function () {
        ydClickIds();
        ydMetrika(false);

        $request = Request::create('/uz', 'GET', [], ['_ym_uid' => YD_CLIENT]);
        expect(AdClickIds::capture($request)['ym_client_id'])->toBeNull();

        ydMetrika();
        $array = Request::create('/uz', 'GET', [], ['_ym_uid' => ['1700000000123456789']]);
        expect(AdClickIds::capture($array)['ym_client_id'])->toBeNull()
            ->and(AdClickIds::capture(Request::create('/uz', 'GET', [], ['_ym_uid' => YD_CLIENT]))['ym_client_id'])->toBe(YD_CLIENT);
    });

    it('stores the click ids with a checkout order, never in orders.attribution', function () {
        ydClickIds();
        ydMetrika();
        config(['site.cart_enabled' => true]);
        $cart = orderCart();

        $order = app(PlaceOrder::class)->handle($cart->token, checkoutData($cart, [
            'attribution' => ['channel' => 'campaign', 'yclid' => YD_YCLID],
            'yclid' => YD_YCLID,
            'ymClientId' => 'not-digits',
        ]));

        expect($order->yclid)->toBe(YD_YCLID)
            ->and($order->ym_client_id)->toBeNull()
            ->and($order->attribution)->toBe(['channel' => 'campaign'])
            ->and($order->toArray())->not->toHaveKey('yclid');
    });

    it('passes the click ids from the checkout page', function () {
        ydClickIds();
        ydMetrika();
        orderCart();
        session()->put(Attribution::SESSION_KEY, ['channel' => 'campaign', 'yclid' => YD_YCLID]);

        $component = Livewire::withCookies(['_ym_uid' => YD_CLIENT])->test(CheckoutPage::class)->set('name', 'Aziz Karimov')->set('phone', '90 123 45 67');
        $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
        $component->call('placeOrder')->assertHasNoErrors();

        expect(Order::query()->sole()->only(['yclid', 'ym_client_id']))->toBe(['yclid' => YD_YCLID, 'ym_client_id' => YD_CLIENT]);
    });

    it('stores the click ids with a website chat lead', function () {
        wcSetup();
        ydClickIds();
        ydMetrika();
        Event::fake([LeadCreated::class]);
        [, $token] = wcChat(['dialog' => WebChatDialog::withForm(LeadFlow::Callback)]);
        session()->put(Attribution::SESSION_KEY, ['channel' => 'campaign', 'yclid' => YD_YCLID]);
        $this->travel(5)->seconds();

        wgWidget($token)->set('name', 'Ali')->set('phone', '90 123 45 67')->call('submitLead')->assertHasNoErrors();

        expect(Lead::query()->sole()->yclid)->toBe(YD_YCLID);
    });
});

describe('YD-9 _ym_uid is the only unencrypted cookie', function () {
    it('lets the plain _ym_uid cookie reach AdClickIds and keeps the session cookie encrypted', function () {
        ydClickIds();
        ydMetrika();
        Route::middleware('web')->get('/__yd-client-id', fn (Request $request): string => (string) (AdClickIds::capture($request)['ym_client_id'] ?? 'none'));

        $this->withUnencryptedCookie('_ym_uid', YD_CLIENT)->get('/__yd-client-id')->assertOk()->assertSeeText(YD_CLIENT);

        $encrypter = app(EncryptCookies::class);

        expect($encrypter->isDisabled('_ym_uid'))->toBeTrue()
            ->and($encrypter->isDisabled((string) config('session.cookie')))->toBeFalse()
            ->and($encrypter->isDisabled('XSRF-TOKEN'))->toBeFalse();
    });
});
