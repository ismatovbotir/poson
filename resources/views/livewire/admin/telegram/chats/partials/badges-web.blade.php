@php($t = 'telegram_inbox')
<x-admin.badge color="outline" data-channel-badge="web">{{ __($t.'.channels.web') }}</x-admin.badge>
@if ($chat->unread_count > 0)
    <x-admin.badge color="brand" data-unread>{{ __($t.'.unread_count', ['count' => $chat->unread_count]) }}</x-admin.badge>
@endif
<x-admin.badge :color="$chat->isClosed() ? 'muted' : 'sky'">{{ $chat->isClosed() ? \App\Enums\ConversationStatus::Closed->label() : \App\Enums\ConversationStatus::Open->label() }}</x-admin.badge>
@if ($chat->isWaitingForStaff() && ! $chat->isClosed())
    <x-admin.badge color="amber" data-waiting>{{ __($t.'.waiting') }}</x-admin.badge>
@endif
@if ($chat->isBanned())
    <x-admin.badge color="red">{{ __($t.'.badges.banned') }}</x-admin.badge>
@endif
@if ($marksInactiveManagers && $chat->manager && ! $chat->manager->is_active)
    <x-admin.badge color="amber" data-inactive-manager>{{ __($t.'.badges.inactive_manager') }}</x-admin.badge>
@endif
