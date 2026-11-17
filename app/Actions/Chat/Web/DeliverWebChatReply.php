<?php

namespace App\Actions\Chat\Web;

use App\Enums\ChatAction;
use App\Enums\ChatAuthor;
use App\Enums\LeadFlow;
use App\Models\ChatRule;
use App\Models\WebChat;
use App\Support\Chat\Adapters\WebAdapter;
use App\Support\Chat\ChatReply;
use App\Support\Chat\RuleIndex;
use App\Support\Chat\WebChatDialog;
use App\Support\Chat\WebChatVersion;

/**
 * Writes one bot answer of the website chat (plan 28 C5 §19.3), shared by
 * PostVisitorMessage (typed text) and PressWebChatButton (a button, a menu
 * topic, the menu, the hand-off). Runs INSIDE the caller's transaction
 * with the web_chats row locked; hits no limiter (WC-3).
 *
 *  - the visitor line (already redacted by the caller, or a server-built
 *    button label), when there is one;
 *  - a hand-off reply counts as unread for staff and gets the after-hours
 *    text and the callback button outside working hours (HandOffWebChat);
 *  - the dialog: the caller's explicit one (an inline form opened by a
 *    button), else a lead-dialog rule opens its form (dialog.form, the
 *    engine never runs a lead dialog on the web) and any other answer
 *    clears it (an open form or a waiting AI turn is superseded);
 *  - the bot message with its server-stored buttons (WebAdapter: plain
 *    text, product buttons carry ids only, WC-16);
 *  - the hand-off itself, then the version is bumped after commit.
 */
class DeliverWebChatReply
{
    public function __construct(
        private readonly RuleIndex $rules,
        private readonly WebAdapter $adapter,
        private readonly HandOffWebChat $handoff,
    ) {}

    /**
     * @param  array<string, mixed>|null  $dialog  An explicit dialog (an opened form); null = from the reply
     */
    public function handle(WebChat $locked, ?string $visitorText, string $locale, ChatReply $reply, ?array $dialog = null): void
    {
        $now = now();

        if ($visitorText !== null) {
            $locked->messages()->create(['author' => ChatAuthor::Visitor, 'text' => $visitorText]);
        }

        $locked->forceFill(['locale' => $locale, 'last_message_at' => $now]);

        if ($reply->isHandoff()) {
            // The visitor's request is what staff read first: it counts as unread.
            $locked->forceFill(['unread_count' => $locked->unread_count + 1, 'last_inbound_at' => $now]);
            $reply = $this->handoff->decorate($reply, $locale);
        }

        $flow = $dialog === null ? $this->leadFlow($reply) : null;
        $locked->forceFill(['dialog' => $dialog ?? ($flow !== null ? WebChatDialog::withForm($flow) : null)]);

        $rendered = $this->adapter->render($reply);
        $ruleId = $reply->ruleId !== null && ChatRule::query()->whereKey($reply->ruleId)->exists() ? $reply->ruleId : null;

        $locked->messages()->create([
            'author' => ChatAuthor::Bot,
            'text' => $rendered['text'],
            'buttons' => $rendered['buttons'] === [] ? null : $rendered['buttons'],
            'chat_rule_id' => $ruleId,
        ]);

        if ($reply->isHandoff()) {
            $this->handoff->handle($locked, $ruleId);
        }

        $locked->save();
        WebChatVersion::bump($locked->id);
    }

    /**
     * The flow of a lead-dialog rule hit (callback / quote / service), from the compiled rules.
     */
    public function leadFlow(ChatReply $reply): ?LeadFlow
    {
        if ($reply->ruleId === null || $reply->outcome !== ChatReply::HIT) {
            return null;
        }

        $rule = $this->rules->load()['rules'][$reply->ruleId] ?? null;

        if (! is_array($rule) || ($rule['action'] ?? null) !== ChatAction::LeadDialog->value) {
            return null;
        }

        $flow = $rule['params']['flow'] ?? null;

        return is_string($flow) ? LeadFlow::tryFrom($flow) : null;
    }
}
