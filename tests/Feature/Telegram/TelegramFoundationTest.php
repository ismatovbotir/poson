<?php

use App\Enums\ConversationStatus;
use App\Enums\LeadSource;
use App\Enums\Role;
use App\Livewire\Admin\Customers\Form;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Integrations\IntegrationRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Plan 14 v2 B1a: migrations 1-6, permissions, customers.source, models.
 */
function telegramMigration(string $name): Migration
{
    return require database_path("migrations/{$name}.php");
}

const TELEGRAM_PERMISSIONS = ['telegram.inbox', 'telegram.inbox-all', 'telegram.reassign', 'telegram.ban', 'telegram.bot'];

it('creates the Telegram tables and customers.source and drops customers.telegram_id', function () {
    expect(Schema::hasTable('telegram_users'))->toBeTrue()
        ->and(Schema::hasTable('telegram_messages'))->toBeTrue()
        ->and(Schema::hasTable('telegram_updates'))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'source'))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'telegram_id'))->toBeFalse();
});

it('rolls the batch back and migrates it again', function () {
    $names = [
        '2026_11_03_100005_drop_telegram_id_from_customers_table',
        '2026_11_03_100004_add_telegram_permissions',
        '2026_11_03_100003_add_source_to_customers_table',
        '2026_11_03_100002_create_telegram_updates_table',
        '2026_11_03_100001_create_telegram_messages_table',
        '2026_11_03_100000_create_telegram_users_table',
    ];

    foreach ($names as $name) {
        telegramMigration($name)->down();
    }

    expect(Schema::hasTable('telegram_users'))->toBeFalse()
        ->and(Schema::hasTable('telegram_messages'))->toBeFalse()
        ->and(Schema::hasTable('telegram_updates'))->toBeFalse()
        ->and(Schema::hasColumn('customers', 'source'))->toBeFalse()
        ->and(Schema::hasColumn('customers', 'telegram_id'))->toBeTrue();

    foreach (array_reverse($names) as $name) {
        telegramMigration($name)->up();
    }

    expect(Schema::hasTable('telegram_users'))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'source'))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'telegram_id'))->toBeFalse();
});

it('refuses to drop customers.telegram_id while any row has data', function () {
    $drop = telegramMigration('2026_11_03_100005_drop_telegram_id_from_customers_table');
    $drop->down();

    $customer = Customer::factory()->create();
    DB::table('customers')->where('id', $customer->id)->update(['telegram_id' => 123456789]);

    expect(fn () => $drop->up())->toThrow(RuntimeException::class, 'customers.telegram_id has data');
    expect(Schema::hasColumn('customers', 'telegram_id'))->toBeTrue();

    DB::table('customers')->where('id', $customer->id)->update(['telegram_id' => null]);
    $drop->up();

    expect(Schema::hasColumn('customers', 'telegram_id'))->toBeFalse();
});

it('adds the telegram permissions add-only and idempotently', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $migration = telegramMigration('2026_11_03_100004_add_telegram_permissions');

    // Simulate production: a manual edit on sales_manager, a revoked permission, an untouched content manager.
    $sales = SpatieRole::findByName(Role::SalesManager->value, 'web');
    $sales->givePermissionTo(Permission::findOrCreate('custom.manual', 'web'));
    $sales->revokePermissionTo(['telegram.ban', 'telegram.reassign']);
    SpatieRole::findByName(Role::Admin->value, 'web')->revokePermissionTo(TELEGRAM_PERMISSIONS);

    $snapshot = fn (Role $role): array => SpatieRole::findByName($role->value, 'web')->permissions()->pluck('name')->sort()->values()->all();
    $before = [
        Role::ContentManager->value => $snapshot(Role::ContentManager),
        Role::ServiceEngineer->value => $snapshot(Role::ServiceEngineer),
    ];

    $migration->up();
    $migration->up();

    $salesNames = $snapshot(Role::SalesManager);

    expect($salesNames)->toContain('custom.manual', 'telegram.inbox', 'telegram.reassign', 'telegram.ban')
        ->not->toContain('telegram.inbox-all', 'telegram.bot')
        ->and($snapshot(Role::Admin))->toContain(...TELEGRAM_PERMISSIONS)
        ->and($snapshot(Role::ContentManager))->toBe($before[Role::ContentManager->value])
        ->and($snapshot(Role::ServiceEngineer))->toBe($before[Role::ServiceEngineer->value])
        ->and(Permission::query()->whereIn('name', TELEGRAM_PERMISSIONS)->count())->toBe(5);
});

it('grants the same telegram permissions from the seeder', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $names = fn (Role $role): array => SpatieRole::findByName($role->value, 'web')->permissions()->pluck('name')->filter(fn ($n) => str_starts_with($n, 'telegram.'))->sort()->values()->all();

    expect($names(Role::Admin))->toBe(collect(TELEGRAM_PERMISSIONS)->sort()->values()->all())
        ->and($names(Role::SalesManager))->toBe(['telegram.ban', 'telegram.inbox', 'telegram.reassign'])
        ->and($names(Role::ContentManager))->toBe([])
        ->and($names(Role::ServiceEngineer))->toBe([]);
});

it('knows the optional inbox chat as a non-secret telegram setting', function () {
    $field = IntegrationRegistry::field('telegram.chats.inbox');

    expect($field->secret)->toBeFalse()
        ->and($field->env)->toBe('TELEGRAM_INBOX_CHAT_ID')
        ->and(config('services.telegram.chats'))->toHaveKey('inbox');

    foreach (['uz', 'ru', 'en'] as $locale) {
        expect(__('admin.settings.integrations.fields.telegram_chats_inbox', [], $locale))->not->toBe('admin.settings.integrations.fields.telegram_chats_inbox')
            ->and(__('admin.settings.integrations.hints.telegram_chats_inbox', [], $locale))->not->toBe('admin.settings.integrations.hints.telegram_chats_inbox');
    }
});

it('casts customers.source and never mass-assigns it', function () {
    $customer = Customer::factory()->create();
    $customer->forceFill(['source' => LeadSource::Telegram])->save();

    expect($customer->fresh()->source)->toBe(LeadSource::Telegram)
        ->and((new Customer)->isFillable('source'))->toBeFalse()
        ->and((new Customer)->isFillable('telegram_id'))->toBeFalse();

    expect(fn () => Customer::query()->create(['name' => 'X', 'type' => 'company', 'source' => 'telegram']))
        ->toThrow(MassAssignmentException::class);
});

it('ignores a tampered source in the admin customer form', function () {
    $sales = staffUser(Role::SalesManager);

    Livewire::actingAs($sales)->test(Form::class)
        ->set('form.name', 'Mega Savdo')
        ->set('form.contacts.0.name', 'Bekzod')
        ->set('form.source', 'telegram')
        ->call('save')
        ->assertHasNoErrors();

    $customer = Customer::query()->where('name', 'Mega Savdo')->first();

    expect($customer)->not->toBeNull()
        ->and($customer->source)->toBeNull();
});

it('no longer shows or fills telegram_id on the customer screens', function () {
    $admin = staffUser();
    $customer = Customer::factory()->create();

    $this->actingAs($admin)->get(route('admin.customers.edit', $customer))->assertOk()->assertDontSeeHtml('data-telegram-id');
    $this->actingAs($admin)->get(route('admin.customers.show', $customer))->assertOk();

    expect(array_key_exists('telegram_id', $customer->getAttributes()))->toBeFalse();
});

it('wires the Telegram models and relations', function () {
    $manager = User::factory()->withRole(Role::SalesManager)->create();
    $customer = Customer::factory()->create();
    $contact = Contact::factory()->create(['customer_id' => $customer->id]);
    $lead = Lead::factory()->create();

    $telegramUser = TelegramUser::factory()->registered()->assigned($manager)->create(['customer_id' => $customer->id, 'contact_id' => $contact->id]);
    $message = TelegramMessage::factory()->media()->forwarded()->create(['telegram_user_id' => $telegramUser->id, 'lead_id' => $lead->id]);

    expect($customer->telegramUsers->pluck('id')->all())->toBe([$telegramUser->id])
        ->and($contact->telegramUsers->pluck('id')->all())->toBe([$telegramUser->id])
        ->and($lead->telegramMessages->pluck('id')->all())->toBe([$message->id])
        ->and($telegramUser->messages->first()->is($message))->toBeTrue()
        ->and($telegramUser->manager->is($manager))->toBeTrue()
        ->and($telegramUser->conversation_status)->toBe(ConversationStatus::Open)
        ->and($message->media['file_name'])->toBe('requisites.pdf')
        ->and($message->telegramUser->is($telegramUser))->toBeTrue();

    $telegramUser->delete();

    expect(TelegramMessage::query()->count())->toBe(0)
        ->and($customer->fresh())->not->toBeNull();
});

it('scopes conversations with visibleTo', function () {
    $sales = staffUser(Role::SalesManager);
    $other = User::factory()->withRole(Role::SalesManager)->create();
    $own = TelegramUser::factory()->registered()->assigned($sales)->create();
    $free = TelegramUser::factory()->registered()->create();
    $foreign = TelegramUser::factory()->registered()->assigned($other)->create();
    // T21-11: staff without inbox-all never see unregistered conversations.
    TelegramUser::factory()->unregistered()->assigned($sales)->create();
    TelegramUser::factory()->unregistered()->create();

    expect(TelegramUser::query()->visibleTo($sales)->pluck('id')->sort()->values()->all())->toBe([$own->id, $free->id])
        ->and(TelegramUser::query()->visibleTo(User::factory()->withRole(Role::Admin)->create())->count())->toBe(5)
        ->and(TelegramUser::query()->visibleTo(User::factory()->withRole(Role::ContentManager)->create())->count())->toBe(0)
        ->and($foreign->id)->not->toBeIn(TelegramUser::query()->visibleTo($sales)->pluck('id')->all());
});

it('prunes stale rows only', function () {
    $oldVisitor = TelegramUser::factory()->unregistered()->create(['created_at' => now()->subDays(40)]);
    $newVisitor = TelegramUser::factory()->unregistered()->create();
    $oldThread = TelegramUser::factory()->registered()->create(['last_message_at' => now()->subMonths(13)]);
    $keptThread = TelegramUser::factory()->registered()->create(['last_message_at' => now()->subMonths(13)]);
    TelegramMessage::factory()->create(['telegram_user_id' => $keptThread->id, 'lead_id' => Lead::factory()->create()->id]);

    $ids = (new TelegramUser)->prunable()->pluck('id')->sort()->values()->all();

    expect($ids)->toBe([$oldVisitor->id, $oldThread->id])
        ->and($newVisitor->id)->not->toBeIn($ids);

    TelegramUpdate::factory()->create(['update_id' => 1, 'created_at' => now()->subDays(4)]);
    TelegramUpdate::factory()->create(['update_id' => 2]);

    expect((new TelegramUpdate)->prunable()->pluck('update_id')->all())->toBe([1]);
});
