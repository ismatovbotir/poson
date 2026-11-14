<?php

namespace App\Actions\Chat;

use App\Enums\ChatChannel;
use App\Enums\ChatDialogStep;
use App\Enums\ChatMetric;
use App\Enums\LeadFlow;
use App\Models\Lead;
use App\Models\Product;
use App\Models\TelegramUser;
use App\Support\Chat\ChatReply;
use App\Support\Chat\LeadDialog;
use App\Support\Phone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The three Telegram lead dialogs (plan 28 C4, CB-17): callback, quote and
 * service request. Hard-coded steps (ChatDialogStep): the customer writes the
 * details (a callback may skip them), checks the summary, then sends it.
 * The state lives server-side in bot_dialog (LeadDialog); the step comes from
 * there, never from the client, and every input is validated again.
 *
 * Text typed during a dialog is the dialog's answer: it never reaches the
 * rule engine or the unmatched list. Menu, /start, cancel, media and a
 * hand-off clear the dialog (HandleChatInput, AdvanceRegistration,
 * RouteRegisteredMessage); it also expires after 30 minutes.
 */
class HandleLeadDialog
{
    public function __construct(
        private readonly SubmitChatLead $submit,
        private readonly RecordChatStat $stats,
    ) {}

    /**
     * Start a flow (a lead dialog rule, or "Request a quote" on a found product).
     */
    public function start(TelegramUser $user, LeadFlow $flow, string $locale, ?int $ruleId = null, ?int $productId = null): ChatReply
    {
        if (SubmitChatLead::usablePhone($user) === null) {
            LeadDialog::clear($user);

            return $this->refused($locale);
        }

        $productName = null;

        if ($productId !== null) {
            $product = Product::query()->visible()->whereKey($productId)->first();

            if (! $product instanceof Product) {
                return new ChatReply(__('chatbot.search.unavailable', [], $locale), $this->closingButtons($locale), null, ChatReply::DONE);
            }

            $productName = trim((string) $product->getTranslation('name', $locale));
        }

        LeadDialog::start($user, $flow, $productId);
        $this->stats->handle(ChatChannel::Telegram, ChatMetric::Hit, $ruleId ?? 0);

        return $this->detailsPrompt($flow, $locale, $productName);
    }

    /**
     * Text typed during a dialog.
     *
     * @param  array{flow: LeadFlow, step: ChatDialogStep, started_at: int, answers: array{details: string, product_id: int|null}}  $state
     */
    public function text(TelegramUser $user, array $state, string $text, string $locale): ChatReply
    {
        $details = LeadDialog::answer($text);

        if ($details === '') {
            return $state['step'] === ChatDialogStep::Confirm
                ? $this->confirmPrompt($user, $state, $locale)
                : $this->detailsPrompt($state['flow'], $locale, null, repeat: true);
        }

        $state['answers']['details'] = $details;
        $state['step'] = ChatDialogStep::Confirm;
        LeadDialog::store($user, $state);

        $current = LeadDialog::current($user);

        return $current === null ? $this->expired($locale) : $this->confirmPrompt($user, $current, $locale);
    }

    /**
     * "Skip" on the details step (callback only).
     *
     * @param  array{flow: LeadFlow, step: ChatDialogStep, started_at: int, answers: array{details: string, product_id: int|null}}  $state
     */
    public function skip(TelegramUser $user, array $state, string $locale): ?ChatReply
    {
        if ($state['step'] !== ChatDialogStep::Details || ! $state['flow']->detailsOptional()) {
            return null;
        }

        $state['step'] = ChatDialogStep::Confirm;
        LeadDialog::store($user, $state);

        return $this->confirmPrompt($user, $state, $locale);
    }

    /**
     * "Send" on the summary.
     *
     * @param  array{flow: LeadFlow, step: ChatDialogStep, started_at: int, answers: array{details: string, product_id: int|null}}  $state
     */
    public function send(TelegramUser $user, array $state, string $locale): ?ChatReply
    {
        if ($state['step'] !== ChatDialogStep::Confirm) {
            return null;
        }

        $result = $this->submit->handle($user, $state);

        if ($result === SubmitChatLead::REFUSED_DETAILS) {
            $state['step'] = ChatDialogStep::Details;
            LeadDialog::store($user, $state);

            return $this->detailsPrompt($state['flow'], $locale, null, repeat: true);
        }

        if (! $result instanceof Lead) {
            LeadDialog::clear($user);

            return $result === SubmitChatLead::REFUSED_LIMIT
                ? new ChatReply(__('chatbot.dialog.limited', [], $locale), $this->closingButtons($locale), null, ChatReply::DONE)
                : $this->refused($locale);
        }

        $sentKey = self::sentKey($user);
        $leadId = $result->id;

        DB::afterCommit(fn () => Cache::put($sentKey, $leadId, 120));

        return $this->sentReply($result->id, $locale);
    }

    /**
     * A second tap on "Send" right after the lead was created: the same answer, no second lead.
     */
    public function alreadySent(TelegramUser $user, string $locale): ?ChatReply
    {
        $leadId = Cache::get(self::sentKey($user));

        return is_int($leadId) ? $this->sentReply($leadId, $locale) : null;
    }

    private static function sentKey(TelegramUser $user): string
    {
        return 'chat-lead-sent:'.$user->id;
    }

    private function sentReply(int $leadId, string $locale): ChatReply
    {
        return new ChatReply(
            __('chatbot.dialog.sent', ['number' => 'L-'.$leadId], $locale),
            [['type' => 'menu', 'label' => __('chatbot.buttons.menu', [], $locale)]],
            null,
            ChatReply::DONE,
        );
    }

    /**
     * A stale "Send" or "Skip" after the dialog ended or expired.
     */
    public function expired(string $locale): ChatReply
    {
        return new ChatReply(__('chatbot.dialog.expired', [], $locale), $this->closingButtons($locale), null, ChatReply::DONE);
    }

    private function detailsPrompt(LeadFlow $flow, string $locale, ?string $productName = null, bool $repeat = false): ChatReply
    {
        $text = $productName !== null && $productName !== ''
            ? __('chatbot.dialog.quote.details_product', ['product' => $productName], $locale)
            : __("chatbot.dialog.{$flow->value}.details", [], $locale);

        if ($repeat) {
            $text = __('chatbot.dialog.required', [], $locale)."\n".$text;
        }

        $buttons = $flow->detailsOptional() ? [['type' => 'skip', 'label' => __('chatbot.dialog.buttons.skip', [], $locale), 'id' => 0]] : [];
        $buttons[] = ['type' => 'cancel', 'label' => __('chatbot.dialog.buttons.cancel', [], $locale)];

        return new ChatReply($text, $buttons, null, ChatReply::DONE);
    }

    /**
     * @param  array{flow: LeadFlow, step: ChatDialogStep, started_at: int, answers: array{details: string, product_id: int|null}}  $state
     */
    private function confirmPrompt(TelegramUser $user, array $state, string $locale): ChatReply
    {
        $lines = [
            __('chatbot.dialog.confirm', [], $locale),
            __('chatbot.dialog.summary.type', ['type' => __('enums.lead_flow.'.$state['flow']->value, [], $locale)], $locale),
        ];

        if ($state['answers']['product_id'] !== null) {
            $product = Product::query()->visible()->whereKey($state['answers']['product_id'])->first();

            if ($product instanceof Product) {
                $lines[] = __('chatbot.dialog.summary.product', ['product' => trim((string) $product->getTranslation('name', $locale))], $locale);
            }
        }

        if ($state['answers']['details'] !== '') {
            $lines[] = __('chatbot.dialog.summary.details', ['details' => $state['answers']['details']], $locale);
        }

        $lines[] = __('chatbot.dialog.summary.phone', ['phone' => Phone::display($user->phone)], $locale);

        return new ChatReply(implode("\n", $lines), [
            ['type' => 'send', 'label' => __('chatbot.dialog.buttons.send', [], $locale), 'id' => 0],
            ['type' => 'cancel', 'label' => __('chatbot.dialog.buttons.cancel', [], $locale)],
        ], null, ChatReply::DONE);
    }

    private function refused(string $locale): ChatReply
    {
        return new ChatReply(__('chatbot.dialog.refused', [], $locale), $this->closingButtons($locale), null, ChatReply::DONE);
    }

    /**
     * @return list<array{type: string, label: string}>
     */
    private function closingButtons(string $locale): array
    {
        return [
            ['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $locale)],
            ['type' => 'menu', 'label' => __('chatbot.buttons.menu', [], $locale)],
        ];
    }
}
