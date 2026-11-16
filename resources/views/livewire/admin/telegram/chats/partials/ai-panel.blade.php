@php($t = 'telegram_inbox.ai')

{{-- "Bot conversation" (plan 30 AI-22, AI-B10): redacted turns, escaped, queried only while open. --}}
<x-admin.card :title="__($t.'.title')">
    <div class="space-y-3 text-sm" data-ai-panel>
        <button type="button" wire:click="$toggle('aiOpen')" class="font-semibold text-brand-700 hover:underline" aria-expanded="{{ $aiOpen ? 'true' : 'false' }}" data-ai-toggle>{{ $aiOpen ? __($t.'.hide') : __($t.'.show') }}</button>

        @if ($aiOpen)
            <p class="text-xs text-slate-500">{{ trans_choice($t.'.note', (int) config('chatbot.ai.retention_days', 30), ['days' => (int) config('chatbot.ai.retention_days', 30)]) }}</p>

            @if ($this->aiTurns->isEmpty())
                <p class="text-xs text-slate-500" data-ai-empty>{{ __($t.'.empty') }}</p>
            @else
                <ul class="space-y-2" data-ai-turns>
                    @foreach ($this->aiTurns as $turn)
                        <li wire:key="ai-turn-{{ $turn->id }}" class="rounded-lg px-3 py-2 {{ $turn->role === \App\Enums\ChatAiRole::Assistant ? 'bg-brand-50' : 'bg-slate-50' }}" data-ai-turn="{{ $turn->id }}">
                            <span class="flex items-center gap-2 text-xs text-slate-500">
                                @if ($turn->role === \App\Enums\ChatAiRole::Assistant)
                                    <x-admin.badge color="brand" pill data-ai-badge>{{ __($t.'.badge') }}</x-admin.badge>
                                @else
                                    <span class="font-semibold text-slate-600">{{ __($t.'.customer') }}</span>
                                @endif
                                <span>{{ \App\Support\DateFormat::iso($turn->created_at->copy()->setTimezone(config('app.timezone')), 'D MMM, HH:mm') }}</span>
                            </span>
                            @if ($turn->role === \App\Enums\ChatAiRole::Assistant && ! $turn->status?->isDelivered())
                                <span class="mt-1 block text-xs italic text-slate-500" data-ai-status="{{ $turn->status?->value }}">{{ __($t.'.not_delivered', ['status' => $turn->status?->label() ?? '']) }}</span>
                            @else
                                <span class="mt-1 block whitespace-pre-line break-words text-slate-800">{{ $turn->text }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </div>
</x-admin.card>
