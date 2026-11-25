<?php

namespace App\Actions\Office;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\BusinessConnection;
use App\Models\SettingsAudit;
use App\Support\Office\OfficeSettings;

/**
 * Store a Telegram Business connection (plan 41 §3.1, VO-1, VO-13), from a
 * business_connection update or a getBusinessConnection answer. The row is
 * keyed by connection id; is_allowed = the owner is on the allow-list right
 * now; rights are the whitelisted booleans only. A connect / disconnect is
 * audited (area office, key office.connection, no ids, no names).
 *
 * Every connection is stored, also a stranger's (a public bot can be
 * connected by anyone): as one disallowed row, whose messages are dropped
 * and which the prune deletes after 30 inactive days (VO-18).
 */
class UpsertBusinessConnection
{
    public const AUDIT_KEY = 'office.connection';

    /**
     * @param  array{connectionId: ?string, ownerId: ?int, userChatId: ?int, isEnabled: bool, rights: array<string, bool>, connectionDate: ?int}  $fields
     */
    public function handle(array $fields): ?BusinessConnection
    {
        if ($fields['connectionId'] === null || $fields['ownerId'] === null) {
            return null;
        }

        $connection = BusinessConnection::query()->where('connection_id', $fields['connectionId'])->lockForUpdate()->first()
            ?? new BusinessConnection(['connection_id' => $fields['connectionId']]);

        $wasEnabled = $connection->exists && $connection->is_enabled;

        $connection->fill([
            'owner_telegram_id' => $fields['ownerId'],
            'user_chat_id' => $fields['userChatId'],
            'is_enabled' => $fields['isEnabled'],
            'is_allowed' => OfficeSettings::isOwner($fields['ownerId']),
            'can_reply' => ($fields['rights']['can_reply'] ?? false) === true,
            'rights' => $fields['rights'],
        ]);

        if ($fields['isEnabled'] && ! $wasEnabled) {
            $connection->connected_at = $fields['connectionDate'] !== null ? now()->setTimestamp($fields['connectionDate']) : now();
            $connection->disabled_at = null;
        }

        if (! $fields['isEnabled'] && ($wasEnabled || ! $connection->exists)) {
            $connection->disabled_at = now();
        }

        $connection->save();

        if ($fields['isEnabled'] !== $wasEnabled) {
            SettingsAudit::record(SettingsArea::Office, self::AUDIT_KEY, $fields['isEnabled'] ? AuditAction::Enabled : AuditAction::Disabled, null, null);
        }

        return $connection;
    }

    /**
     * After an allow-list change: is_allowed again for every stored connection (VO-1).
     */
    public static function recomputeAllowed(): void
    {
        $owners = OfficeSettings::ownerIds();

        BusinessConnection::query()->whereNotIn('owner_telegram_id', $owners === [] ? [0] : $owners)->update(['is_allowed' => false]);

        if ($owners !== []) {
            BusinessConnection::query()->whereIn('owner_telegram_id', $owners)->update(['is_allowed' => true]);
        }
    }
}
