@php($t = 'telegram_inbox')
@if ($chat->unread_count > 0)
    <x-admin.badge color="brand" data-unread>{{ __($t.'.unread_count', ['count' => $chat->unread_count]) }}</x-admin.badge>
@endif
<x-admin.badge :color="$chat->conversation_status === \App\Enums\ConversationStatus::Open ? 'sky' : 'muted'">{{ $chat->conversation_status->label() }}</x-admin.badge>
@if (! $chat->isRegistered())
    <x-admin.badge color="outline">{{ __($t.'.badges.unregistered') }}</x-admin.badge>
@endif
@if ($chat->isRegistered() && $chat->customer_id === null)
    <x-admin.badge color="outline" data-unlinked>{{ __($t.'.badges.unlinked') }}</x-admin.badge>
@endif
@if ($chat->isBanned())
    <x-admin.badge color="red">{{ __($t.'.badges.banned') }}</x-admin.badge>
@endif
@if ($chat->blocked_at)
    <x-admin.badge color="amber">{{ __($t.'.badges.blocked') }}</x-admin.badge>
@endif
@if ($marksInactiveManagers && $chat->manager && ! $chat->manager->is_active)
    <x-admin.badge color="amber" data-inactive-manager>{{ __($t.'.badges.inactive_manager') }}</x-admin.badge>
@endif
@if (in_array($chat->id, $stuck, true))
    <x-admin.badge color="amber" data-stuck>{{ __($t.'.badges.stuck') }}</x-admin.badge>
@endif
