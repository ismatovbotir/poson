<?php

use App\Actions\Telegram\HandleTelegramUpdate;
use App\Enums\GroupStatus;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use App\Models\User;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResult;
use App\Services\Ai\ChatModel;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeChatModel;

/*
| Plan 44 (Telegram groups) test helpers. Synthetic ids only
| (tests/Fixtures/telegram/groups/README.md).
*/

const GROUP_BASIC_ID = -9990020001;

const GROUP_SUPER_ID = -1009990020001;

const GROUP_CLIENT_ID = 9990010001;

const GROUP_STAFF_ID = 9990000002;

const GROUP_OTHER_BOT_ID = 9990000500;

if (! function_exists('groupFixture')) {
    /**
     * A group fixture update with its update_id and optional overrides (merged recursively).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function groupFixture(string $name, int $updateId, array $overrides = []): array
    {
        $payload = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/telegram/groups/'.$name.'.json'), true, 32, JSON_THROW_ON_ERROR);
        $payload['update_id'] = $updateId;

        return array_replace_recursive($payload, $overrides);
    }

    /**
     * Handle one update like the webhook does.
     *
     * @param  array<string, mixed>  $payload
     */
    function groupHandle(array $payload): string
    {
        return app(HandleTelegramUpdate::class)->handle($payload);
    }

    /**
     * Groups and office ingest switched on (the owner flow), flood counters cleared.
     */
    function groupsReady(): void
    {
        officeSettings(['ingest_enabled' => true, 'groups_enabled' => true]);
        Cache::forget('office:new-groups:'.now('Asia/Tashkent')->format('Ymd'));
    }

    /**
     * A tracked group row for a fixture chat id.
     *
     * @param  array<string, mixed>  $attributes
     */
    function groupTracked(int $chatId = GROUP_SUPER_ID, array $attributes = []): TelegramGroup
    {
        return TelegramGroup::factory()->tracked()->create(array_merge(['telegram_chat_id' => $chatId, 'type' => $chatId < -1000000000000 ? 'supergroup' : 'group', 'bot_status' => 'administrator', 'bot_is_admin' => true], $attributes));
    }

    /**
     * The customer bot's kill switch on (BotSwitch), so a leak into the customer bot would show.
     */
    function groupBotOn(): void
    {
        Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => true]);
    }

    function groupStatusOf(int $chatId): ?GroupStatus
    {
        return TelegramGroup::query()->where('telegram_chat_id', $chatId)->first()?->status;
    }
}

if (! function_exists('groupAnalysisReady')) {
    /**
     * Plan 44 T6: groups, ingest and the office AI on (fake key), the four agents published.
     */
    function groupAnalysisReady(): void
    {
        groupsReady();
        officeSettings(['ai_enabled' => true]);
        config(['services.anthropic.key' => 'sk-ant-test-fake-key']);
        officeAgents();
    }

    /**
     * A due tracked supergroup assigned to a sales manager, with a client (role set by staff, own shared
     * phone), an employee and an unknown member, each with one message.
     *
     * @param  array<string, mixed>  $group
     * @return array{group: TelegramGroup, client: TelegramGroupMember, staff: TelegramGroupMember, unknown: TelegramGroupMember, sales: User, admin: User}
     */
    function groupForAnalysis(array $group = [], ?string $clientText = null): array
    {
        groupAnalysisReady();
        $admin = staffUser(Role::Admin);
        $sales = staffUser(Role::SalesManager);

        $model = groupTracked(GROUP_SUPER_ID, array_merge(['assignee_id' => $sales->id, 'title' => 'Fakemarket Team Chat', 'username' => 'fakemarket_team', 'analysis_due_at' => now()->subMinute()], $group));

        $client = TelegramGroupMember::factory()->client()->create(['telegram_group_id' => $model->id, 'telegram_id' => GROUP_CLIENT_ID, 'first_name' => 'Zarnigor', 'username' => 'fake_zarnigor', 'phone' => '+998900000077', 'role_set_by_id' => $admin->id]);
        $staff = TelegramGroupMember::factory()->employee()->create(['telegram_group_id' => $model->id, 'telegram_id' => GROUP_STAFF_ID, 'first_name' => 'Bekzodjon', 'username' => 'fake_bekzod', 'role_set_by_id' => $admin->id]);
        $unknown = TelegramGroupMember::factory()->unknown()->create(['telegram_group_id' => $model->id, 'telegram_id' => 9990010099, 'first_name' => 'Shoxruxbek', 'username' => 'fake_shoxrux']);

        groupMessage($client, $clientText ?? 'Salom, doʻkon uchun yorliq printer kerak.', 1);
        groupMessage($staff, 'Kuniga nechta yorliq chop etasiz?', 2);
        groupMessage($unknown, 'Men ham qiziqaman.', 3);

        return ['group' => $model, 'client' => $client, 'staff' => $staff, 'unknown' => $unknown, 'sales' => $sales, 'admin' => $admin];
    }

    function groupMessage(TelegramGroupMember $member, ?string $text, int $telegramMessageId, string $kind = 'text', ?array $media = null): TelegramGroupMessage
    {
        $message = (new TelegramGroupMessage)->forceFill([
            'telegram_group_id' => $member->telegram_group_id,
            'member_id' => $member->id,
            'telegram_message_id' => $telegramMessageId,
            'kind' => $kind,
            'text' => $text,
            'media' => $media,
            'sent_at' => now()->subMinutes(20 - min(19, $telegramMessageId)),
        ]);
        $message->save();

        return $message;
    }

    /**
     * A chat model whose answer is built from each request ($answer(request, call number) → JSON),
     * every request recorded; bound as the ChatModel. Never a network call.
     */
    function groupModel(Closure $answer): ChatModel
    {
        $model = new class($answer) implements ChatModel
        {
            /** @var list<AiRequest> */
            public array $requests = [];

            public function __construct(private Closure $answer) {}

            public function complete(AiRequest $request): AiResult
            {
                $this->requests[] = $request;

                return FakeChatModel::answer((string) ($this->answer)($request, count($this->requests)));
            }
        };

        app()->instance(ChatModel::class, $model);

        return $model;
    }

    /**
     * The transcript entries ({label, time, text}) the model was sent.
     *
     * @return list<array{label: string, time: string, text: string}>
     */
    function groupEntries(AiRequest $request): array
    {
        $content = (string) $request->messages[0]['content'];
        preg_match('~<conversation>\n(.*)\n</conversation>~s', $content, $match);

        return json_decode($match[1] ?? '[]', true) ?? [];
    }

    /**
     * The label of the first entry whose text contains $needle.
     */
    function groupLabel(AiRequest $request, string $needle): ?string
    {
        foreach (groupEntries($request) as $entry) {
            if (str_contains($entry['text'], $needle)) {
                return $entry['label'];
            }
        }

        return null;
    }

    /**
     * A router answer (JSON) with the given threads.
     *
     * @param  list<array<string, mixed>>  $threads
     */
    function groupRouterAnswer(array $threads, string $language = 'uz'): string
    {
        return (string) json_encode(['language' => $language, 'threads' => array_map(fn (array $thread): array => array_merge([
            'client_labels' => [],
            'role' => 'sales',
            'topic' => 'Label printer for a shop',
            'confidence' => 85,
            'summary' => 'The client needs a label printer.',
        ], $thread), $threads)]);
    }

    /**
     * Router: one sales thread for the client who wrote $needle; every later call: the agent answer.
     *
     * @param  array<string, mixed>  $agent  Agent answer overrides
     */
    function groupStandardModel(string $needle = 'yorliq printer', array $agent = [], array $thread = []): ChatModel
    {
        return groupModel(fn (AiRequest $request, int $call): string => $call === 1
            ? groupRouterAnswer([['client_labels' => [groupLabel($request, $needle)], ...$thread]])
            : officeAgentAnswer($agent));
    }
}
