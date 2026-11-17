<?php

namespace App\Actions\Chat\Web;

use App\Actions\Chat\RecordChatStat;
use App\Enums\ChatAuthor;
use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Enums\ChatMode;
use App\Events\Chat\WebChatHandedOff;
use App\Models\WebChat;
use App\Support\Chat\ChatReply;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\WebChatVersion;
use App\Support\Chat\WorkingHours;
use Illuminate\Support\Facades\DB;

/**
 * The website chat goes to staff (plan 28 C5 §19.3, §19.7 Q1, CB-4, CB-22,
 * W-9, WC-13). Always the sales inbox and the sales chat: service engineers
 * have no inbox permission; a service request comes in as a lead.
 *
 * handle() runs inside the caller's transaction with the web_chats row
 * locked (PostVisitorMessage); request() is the stand-alone entry for the
 * widget's hand-off button and does the lock itself. A chat that already
 * waits for staff changes nothing (no second system line or alert).
 * Otherwise: mode = manager, handoff_at, the dialog cleared (an open lead
 * form ends), a new AI context, a system line in Uzbek (the staff language,
 * never shown to the visitor), the Handoff stat, and WebChatHandedOff once
 * per chatbot.web.alert_minutes (last_alert_at; the global cap is in the
 * event, outside the transaction). No limiter hit here (WC-3).
 *
 * decorate(): outside working hours the after-hours text and a "request a
 * callback" button are added to the reply; the hand-off still happens.
 */
class HandOffWebChat
{
    public function __construct(private readonly RecordChatStat $stats) {}

    /**
     * @return bool whether the chat was handed off now (false: it already waited)
     */
    public function handle(WebChat $locked, ?int $ruleId = null): bool
    {
        if ($locked->isWaitingForStaff()) {
            return false;
        }

        $now = now();
        $locked->forceFill(['mode' => ChatMode::Manager, 'handoff_at' => $now, 'dialog' => null]);
        $locked->resetAiContext();

        $locked->messages()->create([
            'author' => ChatAuthor::System,
            'text' => __('telegram_inbox.web.handoff_line', [], 'uz'),
        ]);

        $this->alert($locked, WebChatHandedOff::REASON_HANDOFF);
        $locked->save();

        DB::afterCommit(fn () => $this->stats->handle(ChatChannel::Web, ChatMetric::Handoff, $ruleId ?? 0));
        WebChatVersion::bump($locked->id);

        return true;
    }

    /**
     * The widget's hand-off button: lock, hand off, done.
     */
    public function request(WebChat $chat): bool
    {
        return DB::transaction(function () use ($chat): bool {
            $locked = WebChat::query()->whereKey($chat->id)->lockForUpdate()->first();

            if ($locked === null || $locked->isBanned() || $locked->isClosed()) {
                return false;
            }

            return $this->handle($locked);
        });
    }

    /**
     * Alert staff at most once per chatbot.web.alert_minutes per chat (row locked by the caller).
     */
    public function alert(WebChat $locked, string $reason): void
    {
        $minutes = max(1, (int) config('chatbot.web.alert_minutes', 10));

        if ($locked->last_alert_at !== null && $locked->last_alert_at->gt(now()->subMinutes($minutes))) {
            return;
        }

        $locked->forceFill(['last_alert_at' => now()]);
        WebChatHandedOff::dispatch($locked->id, $reason);
    }

    /**
     * The hand-off reply as the visitor sees it: after hours with the after-hours text and a callback button.
     */
    public function decorate(ChatReply $reply, string $locale): ChatReply
    {
        if (WorkingHours::isOpen()) {
            return $reply;
        }

        $afterHours = ChatSettings::text('after_hours', $locale) ?? __('chatbot.after_hours', [], $locale);

        return new ChatReply(
            trim($reply->text."\n\n".$afterHours),
            [...$reply->buttons, ['type' => 'callback', 'label' => __('chatbot.buttons.callback', [], $locale)]],
            $reply->ruleId,
            $reply->outcome,
            $reply->param,
        );
    }
}
