<?php

use App\Actions\Office\InstallAgentProfiles;
use App\Enums\AgentRole;
use App\Models\AgentProfile;
use App\Models\AgentProfileVersion;
use App\Models\BusinessConnection;
use App\Models\Setting;
use App\Support\Office\OfficeSettings;

/*
| Plan 41 (Virtual office) test helpers. Synthetic ids only (99900xxxxx,
| tests/Fixtures/telegram/business/README.md); the fake ChatModel only.
*/

const OFFICE_OWNER_ID = 9990000001;

const OFFICE_CLIENT_ID = 9990010001;

const OFFICE_CONNECTION_ID = 'fake-conn-0001';

if (! function_exists('officeFixture')) {
    /**
     * A fixture update with its update_id and optional overrides (merged recursively).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function officeFixture(string $name, int $updateId, array $overrides = []): array
    {
        $payload = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/telegram/business/'.$name.'.json'), true, 32, JSON_THROW_ON_ERROR);
        $payload['update_id'] = $updateId;

        return array_replace_recursive($payload, $overrides);
    }

    /**
     * Store office settings rows (group "office") and drop the memo.
     *
     * @param  array<string, mixed>  $values  key => value (keys without the "office." prefix)
     */
    function officeSettings(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => 'office.'.$key], ['group' => 'office', 'value' => $value]);
        }

        OfficeSettings::forget();
    }

    /**
     * Ingest on for the fake owner, with an allowed enabled connection "fake-conn-0001".
     */
    function officeIngestReady(bool $ai = false): BusinessConnection
    {
        officeSettings(['ingest_enabled' => true, 'owner_telegram_ids' => [OFFICE_OWNER_ID], 'ai_enabled' => $ai]);

        if ($ai) {
            config(['services.anthropic.key' => 'sk-ant-test-fake-key']);
        }

        return BusinessConnection::factory()->create([
            'connection_id' => OFFICE_CONNECTION_ID,
            'owner_telegram_id' => OFFICE_OWNER_ID,
            'user_chat_id' => OFFICE_OWNER_ID,
        ]);
    }

    /**
     * The four agents with a published v1 (the seed texts).
     */
    function officeAgents(): void
    {
        app(InstallAgentProfiles::class)->handle();
    }

    /**
     * A published version of a role with custom fields.
     *
     * @param  array<string, mixed>  $attributes
     */
    function officeAgentVersion(AgentRole $role, array $attributes = []): AgentProfileVersion
    {
        $profile = AgentProfile::query()->firstOrCreate(['role' => $role->value], ['use_site_knowledge' => false]);
        $version = $profile->versions()->create(array_merge([
            'version' => (int) $profile->versions()->max('version') + 1,
            'instructions' => 'Test instructions.',
            'model' => $role === AgentRole::Router ? 'claude-haiku-5-5' : 'claude-sonnet-5-5',
            'max_tokens' => $role === AgentRole::Router ? 300 : 900,
        ], $attributes));
        $profile->forceFill(['current_version_id' => $version->id])->save();

        return $version;
    }

    /**
     * A router answer (JSON).
     *
     * @param  array<string, mixed>  $overrides
     */
    function officeRouterAnswer(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'role' => 'sales',
            'topic' => 'Label printer for a shop',
            'confidence' => 85,
            'language' => 'uz',
            'needs_reply' => true,
            'summary' => 'The client needs a label printer.',
        ], $overrides));
    }

    /**
     * An agent answer (JSON).
     *
     * @param  array<string, mixed>  $overrides
     */
    function officeAgentAnswer(array $overrides = []): string
    {
        return (string) json_encode(array_replace_recursive([
            'summary' => 'The client needs a label printer for 58 mm labels in a shop.',
            'next_step' => 'Ask about the daily label volume.',
            'draft_reply' => 'Assalomu alaykum! Kuniga nechta yorliq chop etasiz? Shunda mos printerni tanlab, sozlab beramiz.',
            'lead' => ['intent' => 'buy', 'products' => ['ZD421'], 'device_brand' => '', 'device_model' => '', 'urgency' => 'normal'],
        ], $overrides));
    }
}
