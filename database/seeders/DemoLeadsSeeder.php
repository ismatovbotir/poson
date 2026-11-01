<?php

namespace Database\Seeders;

use App\Actions\Quotes\ChangeQuoteStatus;
use App\Actions\Quotes\CreateQuote;
use App\Data\Quotes\QuoteData;
use App\Data\Quotes\QuoteItemData;
use App\Enums\ActivityType;
use App\Enums\LeadLostReason;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\LeadType;
use App\Enums\ProductPricingUnit;
use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Enums\TrafficChannel;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Demo Murojaatlar for local / staging only (plan 05 5a), so the CRM screens
 * have data: ~15 leads across types, statuses, sources and managers, a few
 * customers with contacts, items from the demo catalog, a duplicate, a
 * short timeline per lead and three quotes (draft, sent, accepted).
 *
 * Creates three demo staff users (…@poson.test) that can't sign in (random
 * password). Skipped outside local / staging and when leads already exist.
 * Rows are inserted directly (no CreateLead, so no LeadCreated events).
 */
class DemoLeadsSeeder extends Seeder
{
    public function run(): void
    {
        // Hard check, never DemoSeeding: demo leads are never seeded in production (plan 15 A2).
        if (! app()->environment(['local', 'staging']) || Lead::query()->exists()) {
            return;
        }

        $aziz = $this->staff('aziz.demo@poson.test', 'Aziz Karimov', Role::SalesManager);
        $sevara = $this->staff('sevara.demo@poson.test', 'Sevara Rahimova', Role::SalesManager);
        $jasur = $this->staff('jasur.demo@poson.test', 'Jasur Toshev', Role::ServiceEngineer);

        $mega = Customer::factory()->managedBy($aziz)->create(['name' => '“Mega Savdo” MChJ', 'phone' => '+998901234567', 'inn' => '305123456']);
        $baraka = Customer::factory()->managedBy($sevara)->create(['name' => '“Baraka Market” MChJ', 'phone' => '+998998765432', 'inn' => '306654321']);
        $pharm = Customer::factory()->create(['name' => '“Qoʻqon Farm” MChJ', 'phone' => '+998732001122', 'inn' => '307111222']);
        Contact::factory()->for($mega)->primary()->create(['name' => 'Bekzod Nazarov', 'position' => 'Ombor mudiri', 'phone' => '+998901112233']);
        Contact::factory()->for($baraka)->create(['name' => 'Ilhom Qosimov', 'position' => 'Direktor', 'phone' => '+998935551122']);
        Customer::factory()->individual()->create(['name' => 'Otabek Yuldashev', 'phone' => '+998977000001']);

        $products = Product::query()->where('is_active', true)->where('pricing_unit', ProductPricingUnit::Unit)->orderBy('id')->limit(12)->get(['id', 'sku', 'name']);

        $rows = [
            ['Bekzod Nazarov', '+998901234567', '“Mega Savdo” MChJ', LeadType::Quote, LeadSource::Site, LeadStatus::New, null, 0, 'Ombor uchun 20 ta TSD va 1C integratsiya kerak.', $mega, TrafficChannel::Search],
            ['Gulnora Ergasheva', '+998935551100', '“Textile Group” AJ', LeadType::Callback, LeadSource::Site, LeadStatus::New, null, 0, 'RFID inventarizatsiya boʻyicha maslahat.', null, TrafficChannel::Ai],
            ['Otabek Yuldashev', '+998977000001', null, LeadType::Quote, LeadSource::Telegram, LeadStatus::New, $aziz, 1, 'Doʻkon uchun skaner va chek printeri.', null, null],
            ['Sardor Mirzayev', '+998912345678', '“Sardor Trade”', LeadType::Quote, LeadSource::Phone, LeadStatus::InProgress, $aziz, 2, 'Printer, 3 dona.', null, null],
            ['Ilhom Qosimov', '+998935551122', '“Baraka Market” MChJ', LeadType::Quote, LeadSource::Phone, LeadStatus::InProgress, $sevara, 3, 'Tarozi-printer va etiketkalar, 6 filial.', $baraka, null],
            ['Nodira Saidova', '+998901112200', '“Fresh Food” filiali', LeadType::Callback, LeadSource::Site, LeadStatus::Qualified, $sevara, 4, 'Termoetiketkalar 58×40, oyiga 400 rulon.', null, TrafficChannel::Direct],
            ['Rustam Aliyev', '+998954443322', '“Logistics Pro”', LeadType::Quote, LeadSource::Site, LeadStatus::Qualified, $aziz, 5, 'TSD × 12, 1C integratsiya.', null, TrafficChannel::Campaign],
            ['Dilshod Karimov', '+998732001122', '“Qoʻqon Farm” MChJ', LeadType::Quote, LeadSource::Manual, LeadStatus::Converted, $aziz, 8, 'DataMatrix markirovka liniyasi.', $pharm, null],
            ['Malika Usmonova', '+998909998877', null, LeadType::Callback, LeadSource::Site, LeadStatus::Lost, $sevara, 12, null, null, TrafficChannel::Social],
            ['Anvar Rasulov', '+998931231231', '“Anvar Savdo”', LeadType::Checkout, LeadSource::Site, LeadStatus::Lost, $aziz, 15, 'Buyurtma: chek lentasi.', null, TrafficChannel::Search],
            ['Shahzod Ismoilov', '+998945556677', '“Shahzod Market”', LeadType::ServiceRequest, LeadSource::Phone, LeadStatus::New, null, 0, 'Etiketka printeri bosmayapti, kallak tekshirish kerak.', null, null],
            ['Zarina Hamidova', '+998998887766', '“Apteka Plus”', LeadType::ServiceRequest, LeadSource::Telegram, LeadStatus::InProgress, $jasur, 1, 'Skanerni kassa dasturiga ulash.', null, null],
            ['Ilhom Qosimov', '+998935551122', '“Baraka Market” MChJ', LeadType::ServiceRequest, LeadSource::Phone, LeadStatus::Converted, $jasur, 6, 'Tarozilarni sozlash, 3 filial.', $baraka, null],
            ['Bekzod Nazarov', '+998901234567', '“Mega Savdo” MChJ', LeadType::Callback, LeadSource::Site, LeadStatus::New, $aziz, 0, 'Takroriy murojaat: narxlar boʻyicha qoʻngʻiroq.', $mega, TrafficChannel::Direct],
            ['Kamola Tursunova', '+998977776655', null, LeadType::Callback, LeadSource::Manual, LeadStatus::InProgress, null, 2, 'Etiketka dizayni boʻyicha savol.', null, null],
        ];

        $created = [];

        foreach ($rows as $index => [$name, $phone, $company, $type, $source, $status, $manager, $daysAgo, $message, $customer, $channel]) {
            $at = now()->subDays($daysAgo)->subMinutes(37 * ($index + 1));
            $original = $created[$phone] ?? null;

            $lead = Lead::factory()
                ->status($status)
                ->create([
                    'name' => $name,
                    'phone' => $phone,
                    'company' => $company,
                    'type' => $type,
                    'source' => $source,
                    'message' => $message,
                    'manager_id' => $manager?->id,
                    'customer_id' => $customer?->id,
                    'created_by_id' => in_array($source, [LeadSource::Phone, LeadSource::Manual], true) ? ($manager ?? $aziz)->id : null,
                    'duplicate_of_id' => $original !== null && $original->created_at->diffInDays($at, true) <= Lead::DUPLICATE_WINDOW_DAYS ? $original->id : null,
                    'traffic_channel' => $channel,
                    'traffic_source' => $channel === TrafficChannel::Search ? 'google' : ($channel === TrafficChannel::Ai ? 'chatgpt.com' : null),
                    'landing_path' => $source === LeadSource::Site ? '/uz/catalog' : null,
                    'page_url' => $source === LeadSource::Site ? url('/uz') : null,
                    'lost_reason' => $status === LeadStatus::Lost ? ($index % 2 ? LeadLostReason::NoResponse : LeadLostReason::BoughtElsewhere) : null,
                    'estimated_amount' => $type === LeadType::Quote ? (($index + 2) * 4_500_000) * 100 : null,
                    'created_at' => $at,
                    'updated_at' => $at,
                    'status_changed_at' => $at,
                ]);

            $created[$phone] ??= $lead;

            if ($type !== LeadType::Callback && $products->isNotEmpty()) {
                $product = $products[$index % $products->count()];
                $lead->items()->create(['product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->getTranslation('name', 'uz'), 'qty' => ($index % 4) + 1, 'sort' => 0]);
            }

            $this->timeline($lead, $manager, $at);
        }

        $this->quotes($aziz, $sevara, $mega, $baraka, $pharm, $products);
    }

    /**
     * A few demo quotes (plan 06): a draft, a sent one and an accepted one,
     * through CreateQuote so numbers come from the sequence.
     *
     * @param  Collection<int, Product>  $products
     */
    private function quotes(User $aziz, User $sevara, Customer $mega, Customer $baraka, Customer $pharm, $products): void
    {
        if ($products->isEmpty()) {
            return;
        }

        $line = fn (int $index, int $qty): QuoteItemData => new QuoteItemData(productId: $products[$index % $products->count()]->id, qty: $qty);
        $service = new QuoteItemData(name: 'Oʻrnatish, sozlash va xodimlarni oʻqitish', qty: 1, price: 1_500_000_00);

        app(CreateQuote::class)->handle(new QuoteData(customerId: $mega->id, items: [$line(0, 20), $line(1, 2), $service], notes: 'Yetkazib berish Qoʻqon boʻylab bepul. Kafolat 12 oy.'), $aziz);

        $sent = app(CreateQuote::class)->handle(new QuoteData(customerId: $baraka->id, items: [$line(2, 6), $line(3, 40)]), $sevara);
        app(ChangeQuoteStatus::class)->markSent($sent, $sevara, 'link');

        $accepted = app(CreateQuote::class)->handle(new QuoteData(customerId: $pharm->id, items: [$line(4, 1), $service]), $aziz);
        app(ChangeQuoteStatus::class)->markSent($accepted, $aziz, 'pdf');
        app(ChangeQuoteStatus::class)->handle($accepted, QuoteStatus::Accepted, $aziz);
    }

    private function staff(string $email, string $name, Role $role): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], [
            'name' => $name,
            'password' => Hash::make(Str::random(40)),
            'locale' => 'uz',
            'is_active' => true,
        ]);

        SpatieRole::findOrCreate($role->value, 'web');
        $user->syncRoles([$role->value]);

        return $user;
    }

    private function timeline(Lead $lead, ?User $manager, CarbonInterface $at): void
    {
        $lead->activities()->create(['type' => ActivityType::Created, 'user_id' => $lead->created_by_id, 'meta' => ['source' => $lead->source->value, 'type' => $lead->type->value], 'created_at' => $at]);

        if ($manager !== null) {
            $lead->activities()->create(['type' => ActivityType::Assignment, 'user_id' => $manager->id, 'meta' => ['action' => 'take', 'to_id' => $manager->id, 'to_name' => $manager->name], 'created_at' => $at->copy()->addMinutes(10)]);
            $lead->activities()->create(['type' => ActivityType::Call, 'user_id' => $manager->id, 'body' => 'Mijoz bilan gaplashildi, tafsilotlar aniqlandi.', 'created_at' => $at->copy()->addMinutes(25)]);
        }

        if ($lead->status !== LeadStatus::New) {
            $lead->activities()->create(['type' => ActivityType::StatusChange, 'user_id' => $manager?->id, 'meta' => ['from' => 'new', 'to' => $lead->status->value] + ($lead->lost_reason ? ['reason' => $lead->lost_reason->value] : []), 'created_at' => $at->copy()->addMinutes(40)]);
        }
    }
}
