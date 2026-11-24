<?php

namespace App\Livewire\Site\Chat;

use App\Actions\Chat\Web\EndWebChat;
use App\Actions\Chat\Web\PostedMessage;
use App\Actions\Chat\Web\PostVisitorMessage;
use App\Actions\Chat\Web\PressWebChatButton;
use App\Actions\Chat\Web\ResolvedWebChat;
use App\Actions\Chat\Web\ResolveWebChat;
use App\Actions\Chat\Web\SaveWebChatContact;
use App\Actions\Chat\Web\SubmitWebChatLead;
use App\Actions\Leads\CreateLead;
use App\Data\Site\ProductCard;
use App\Enums\AnalyticsGoal;
use App\Enums\ChatAuthor;
use App\Enums\ChatChannel;
use App\Enums\ChatMode;
use App\Enums\LeadFlow;
use App\Livewire\Site\Concerns\DispatchesAnalyticsGoals;
use App\Livewire\Site\Concerns\GuardsLeadSubmissions;
use App\Models\Product;
use App\Models\WebChat;
use App\Models\WebChatMessage;
use App\Rules\PhoneNumber;
use App\Support\Catalog\ProductCards;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\Engine;
use App\Support\Chat\LinkTargets;
use App\Support\Chat\WebChatDialog;
use App\Support\Chat\WebChatLimits;
use App\Support\Chat\WebChatPage;
use App\Support\Chat\WebChatSwitch;
use App\Support\Chat\WebChatToken;
use App\Support\Chat\WebChatVersion;
use App\Support\Chat\WorkingHours;
use App\Support\Phone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The public website chat widget (plan 28 C5b §6, §19.3, §20). Rules only:
 * the actions call the engine with AI as a literal false (WC-4).
 *
 * Loaded lazily by the layout's launcher (<x-site.chat.launcher />) only
 * after the visitor's first click (or right after the page when a chat
 * cookie with recent staff activity exists); mount() and the lazy load
 * write nothing (WC-6).
 *
 * Identity (CB-18, WC-5, WC-6): there is no chat id or token property and
 * no method takes one; every call resolves the chat from the cookie
 * (ResolveWebChat inside each action). The public state Alpine reads
 * (mode, seenVersion, pending, notice) is #[Locked] and never drives a
 * server decision. The page the chat starts on is the #[Locked] page
 * prop rendered by the launcher on the page request (WC-10).
 *
 * Buttons (WC-7): press()/topic() take mixed; bad values are no-ops. Only
 * the latest bot message's buttons are pressable; links are built at
 * render (product cards with route(), url buttons re-validated through
 * LinkTargets), never output as stored. Product cards are built live from
 * the stored ids: visible() products only, Retail price only (WC-16).
 *
 * Lead form (WC-8): opened by the server (dialog.form); submitLead() runs
 * GuardsLeadSubmissions in Site\LeadForm's order, then SubmitWebChatLead.
 *
 * Polling (CB-20, WC-11): an Alpine back-off timer (resources/js/web-chat.js)
 * calls refresh() only in manager mode (and while an AI turn is pending,
 * Plan 33 B); refresh() takes no arguments, hits its own limiter, reads the
 * chat and its version and skips the render when nothing changed.
 *
 * Output (CB-5): every text is escaped by Blade; staff are shown as
 * "Manager", never by name (WC-12).
 *
 * Plan 40 goals (only while the visitor is tracked): chat_started when a
 * stored message created the chat, chat_handoff when a stored message or
 * button switched it to manager mode, lead_chat when a real lead was stored.
 */
class Widget extends Component
{
    use DispatchesAnalyticsGoals, GuardsLeadSubmissions;

    /**
     * Messages rendered (the latest ones).
     */
    public const MESSAGES_SHOWN = 50;

    #[Locked]
    public string $locale = '';

    /**
     * Path of the page the widget was opened on (from the launcher, checksummed by Livewire).
     */
    #[Locked]
    public ?string $page = null;

    /**
     * bot | manager: the poll runs in manager mode only.
     */
    #[Locked]
    public string $mode = 'bot';

    /**
     * The version (WebChatVersion) of the last render.
     */
    #[Locked]
    public string $seenVersion = '';

    /**
     * An AI answer is on its way (Plan 33 B, dialog.ai): the "typing" bubble. Always false in C5.
     */
    #[Locked]
    public bool $pending = false;

    /**
     * Last notice (lang chatbot.widget.*), set by the server only.
     */
    #[Locked]
    public ?string $notice = null;

    /**
     * The lead form was sent (also shown to bots, who get the same screen).
     */
    #[Locked]
    public bool $leadSent = false;

    #[Locked]
    public bool $contactSaved = false;

    public string $name = '';

    public string $phone = '';

    public string $details = '';

    /**
     * Honeypot of the lead form (GuardsLeadSubmissions).
     */
    public string $fax_ext = '';

    public string $contactName = '';

    public string $contactPhone = '';

    /**
     * When the lead form card opened (dialog.form.at): set from the server state before the bot check, never from the client.
     */
    protected int $renderedAt = 0;

    private ?ResolvedWebChat $resolved = null;

    public function mount(?string $page = null): void
    {
        $locale = app()->getLocale();
        $this->locale = in_array($locale, (array) config('app.supported_locales'), true) ? $locale : (string) config('app.locale');
        $this->page = WebChatPage::clean($page);
    }

    public function boot(): void
    {
        if (in_array($this->locale, (array) config('app.supported_locales'), true)) {
            app()->setLocale($this->locale);
        }
    }

    public function placeholder(): View
    {
        return view('livewire.site.chat.placeholder');
    }

    public function send(mixed $text = null): bool
    {
        $before = $this->chat();

        return $this->settle(app(PostVisitorMessage::class)->handle($text, $this->locale, $this->page), $before);
    }

    public function press(mixed $index = null): void
    {
        $before = $this->chat();

        $this->settle(app(PressWebChatButton::class)->press($index, $this->locale), $before);
    }

    public function topic(mixed $ruleId = null): void
    {
        $before = $this->chat();

        $this->settle(app(PressWebChatButton::class)->topic($ruleId, $this->locale, $this->page), $before);
    }

    /**
     * "Menu" (also "Cancel" on the form card): the form closes, the bot answers again.
     */
    public function menu(): void
    {
        $this->resetForm();
        $this->settle(app(PressWebChatButton::class)->menu($this->locale));
    }

    public function handoff(): void
    {
        $before = $this->chat();

        $this->settle(app(PressWebChatButton::class)->handoff($this->locale, $this->page), $before);
    }

    public function submitLead(): void
    {
        $this->notice = null;
        $chat = WebChatSwitch::isOpen() ? $this->chat() : null;
        $form = $chat === null ? null : WebChatDialog::form($chat->dialog);

        if ($chat === null || $form === null || $chat->lead_id !== null) {
            $this->notice = 'unavailable';

            return;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', new PhoneNumber(public: true)],
            'details' => ['nullable', 'string', 'max:'.CreateLead::MESSAGE_LIMIT],
        ], [], $this->attributeNames());
        $phone = Phone::normalizeAny($validated['phone']);

        if ($phone === null) {
            $this->addError('phone', __('validation.phone_any', ['attribute' => __('site.lead_form.attributes.phone')]));

            return;
        }

        if ($form['product_id'] !== null && ! Product::query()->visible()->whereKey($form['product_id'])->exists()) {
            $this->addError('form', __('chatbot.widget.product_unavailable'));

            return;
        }

        if ($this->leadLimitsExceeded($phone)) {
            return;
        }

        $this->renderedAt = $form['at'];

        if ($this->foreignPhoneCapExceeded($phone)) {
            return;
        }

        // Bots get the same success screen, but nothing is submitted.
        if (! $this->looksLikeBot()) {
            $details = trim($validated['details'] ?? '');
            $lead = app(SubmitWebChatLead::class)->handle($chat, trim($validated['name']), $phone, $details === '' ? null : $details, $this->captureAdClickIds());

            if ($lead === null) {
                $this->notice = 'unavailable';

                return;
            }

            $this->reachGoal(AnalyticsGoal::LeadChat);
        }

        $this->resetForm();
        $this->leadSent = true;
        $this->resolved = null;
        $this->dispatch('chat-activity');
    }

    public function saveContact(): void
    {
        $this->notice = null;
        $chat = WebChatSwitch::isOpen() ? $this->chat() : null;

        if ($chat === null || ! $this->offersContact($chat)) {
            $this->notice = 'unavailable';

            return;
        }

        $validated = $this->validate([
            'contactName' => ['nullable', 'string', 'max:'.SaveWebChatContact::NAME_MAX],
            'contactPhone' => ['required', 'string', 'max:32', new PhoneNumber(public: true)],
        ], [], [
            'contactName' => __('site.lead_form.attributes.name'),
            'contactPhone' => __('site.lead_form.attributes.phone'),
        ]);
        $phone = Phone::normalizeAny($validated['contactPhone']);

        if ($phone === null) {
            $this->addError('contactPhone', __('validation.phone_any', ['attribute' => __('site.lead_form.attributes.phone')]));

            return;
        }

        $result = app(SaveWebChatContact::class)->handle($chat, $validated['contactName'] ?? null, $phone);

        match ($result) {
            SaveWebChatContact::SAVED => $this->contactSaved = true,
            SaveWebChatContact::LIMITED => $this->addError('contact', __('chatbot.widget.too_many')),
            SaveWebChatContact::FOREIGN_LIMIT => $this->addError('contact', __('validation.foreign_phone_limit')),
            default => $this->notice = 'unavailable',
        };

        if ($result === SaveWebChatContact::SAVED) {
            $this->reset('contactName', 'contactPhone');
            $this->resolved = null;
        }
    }

    public function end(): void
    {
        app(EndWebChat::class)->handle();

        $this->resetForm();
        $this->reset('contactName', 'contactPhone', 'leadSent', 'contactSaved', 'pending');
        $this->mode = ChatMode::Bot->value;
        $this->seenVersion = '';
        $this->notice = 'ended';
        $this->resolved = null;
        $this->dispatch('chat-ended');
    }

    /**
     * The poll (WC-11): no arguments; idle = its own limiter hit, one
     * web_chats select (the cookie's chat), one version read, no render.
     *
     * @return bool whether something changed (the widget renders)
     */
    public function refresh(): bool
    {
        // Switched off: render once (the widget shows "unavailable" and goes back to bot mode, so the poll stops).
        if (! WebChatSwitch::isOpenCached()) {
            return true;
        }

        $token = app(WebChatToken::class)->current();

        if ($token === null) {
            return $this->changed($this->seenVersion !== '');
        }

        if (! WebChatLimits::refresh($token)) {
            return $this->changed(false);
        }

        $chat = $this->chat();

        if ($chat === null) {
            return $this->changed(true);
        }

        $version = WebChatVersion::get($chat->id);

        // A failed or missing cache read renders instead of failing (WC-11).
        if ($version !== null && $version === $this->seenVersion) {
            return $this->changed(false);
        }

        $this->dispatch('chat-updated');

        return true;
    }

    public function render(): View
    {
        $open = WebChatSwitch::isOpen();
        $resolved = $open ? $this->resolvedChat() : new ResolvedWebChat;
        $chat = $resolved->chat;
        $context = new ChatContext(ChatChannel::Web, $this->locale, '');

        $this->mode = $chat?->mode->value ?? ChatMode::Bot->value;
        $this->seenVersion = $chat !== null ? (WebChatVersion::get($chat->id) ?? '') : '';
        $this->pending = $chat !== null && isset(WebChatDialog::clean($chat->dialog)['ai']);

        $messages = $chat !== null ? $this->visibleMessages($chat) : collect();
        $latestBot = $messages->last(fn (WebChatMessage $message): bool => $message->author === ChatAuthor::Bot);
        $form = $chat !== null && $chat->lead_id === null && ! $this->leadSent ? WebChatDialog::form($chat->dialog) : null;
        $menu = $open && ! $resolved->banned && $chat === null ? app(Engine::class)->menu($context) : null;

        return view('livewire.site.chat.widget', [
            'available' => $open && ! $resolved->banned,
            'chat' => $chat,
            'messages' => $messages,
            'latestBotId' => $latestBot?->id,
            'cards' => $this->cards($messages),
            'actions' => $latestBot !== null ? $this->actions($latestBot) : [],
            'menuText' => $menu?->text,
            'menuTopics' => $menu !== null ? array_values(array_filter($menu->buttons, fn (array $button): bool => $button['type'] === 'rule' && is_int($button['id'] ?? null))) : [],
            'form' => $form,
            'formProduct' => $form !== null && $form['flow'] === LeadFlow::Quote && $form['product_id'] !== null
                ? $this->productName($form['product_id'])
                : null,
            'leadDone' => $chat !== null && $chat->lead_id !== null && WebChatDialog::form($chat->dialog) !== null,
            'showContact' => $chat !== null && ! $this->contactSaved && $this->offersContact($chat),
            'afterHours' => $chat !== null && $chat->mode === ChatMode::Manager && ! WorkingHours::isOpen(),
            'consent' => ChatSettings::text('consent', $this->locale) ?? __('chatbot.widget.consent'),
            'privacyUrl' => route('site.page', ['locale' => $this->locale, 'page' => 'privacy']),
        ]);
    }

    /**
     * Skip the render when nothing changed.
     */
    private function changed(bool $changed): bool
    {
        if (! $changed) {
            $this->skipRender();
        }

        return $changed;
    }

    /**
     * @param  WebChat|null  $before  The chat before the call (null: none yet), for the plan 40 goals
     */
    private function settle(PostedMessage $posted, ?WebChat $before = null): bool
    {
        $this->notice = match ($posted->status) {
            PostedMessage::LIMITED => 'too_many',
            PostedMessage::WAIT => 'wait_staff',
            PostedMessage::UNAVAILABLE => 'unavailable',
            default => null,
        };
        $this->resolved = null;

        if ($posted->stored()) {
            $this->leadSent = false;
            $this->dispatch('chat-activity');
            $this->reachChatGoals($before);
        }

        return $posted->stored();
    }

    /**
     * chat_started: the stored message created the chat; chat_handoff: the chat is now in manager mode and was not before.
     */
    private function reachChatGoals(?WebChat $before): void
    {
        $after = $this->chat();

        if ($after === null) {
            return;
        }

        if ($before === null) {
            $this->reachGoal(AnalyticsGoal::ChatStarted);
        }

        if ($after->mode === ChatMode::Manager && $before?->mode !== ChatMode::Manager) {
            $this->reachGoal(AnalyticsGoal::ChatHandoff);
        }
    }

    private function resetForm(): void
    {
        $this->reset('name', 'phone', 'details', 'fax_ext');
        $this->resetValidation();
    }

    private function chat(): ?WebChat
    {
        return $this->resolvedChat()->chat;
    }

    private function resolvedChat(): ResolvedWebChat
    {
        return $this->resolved ??= app(ResolveWebChat::class)->handle();
    }

    /**
     * The optional "leave your phone" card: once, after a hand-off, while no phone and no lead are known.
     */
    private function offersContact(WebChat $chat): bool
    {
        return $chat->mode === ChatMode::Manager && $chat->handoff_at !== null && $chat->phone === null && $chat->lead_id === null;
    }

    /**
     * The latest messages the visitor may see (system lines are for staff only), oldest first.
     *
     * @return Collection<int, WebChatMessage>
     */
    private function visibleMessages(WebChat $chat): Collection
    {
        return $chat->messages()
            ->where('author', '!=', ChatAuthor::System)
            ->latest('id')
            ->limit(self::MESSAGES_SHOWN)
            ->get(['id', 'web_chat_id', 'author', 'text', 'buttons', 'created_at'])
            ->reverse()
            ->values();
    }

    /**
     * Live product cards for the product buttons of the rendered bot
     * messages: one visible() query, Retail price only (WC-16). A product
     * that left the site simply has no card.
     *
     * @param  Collection<int, WebChatMessage>  $messages
     * @return array<int, ProductCard> by product id
     */
    private function cards(Collection $messages): array
    {
        $ids = $messages
            ->filter(fn (WebChatMessage $message): bool => $message->author === ChatAuthor::Bot && is_array($message->buttons))
            ->flatMap(fn (WebChatMessage $message): array => array_column(array_filter($message->buttons, fn (mixed $button): bool => is_array($button) && ($button['type'] ?? null) === 'product' && is_int($button['id'] ?? null)), 'id'))
            ->unique()
            ->take(self::MESSAGES_SHOWN)
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $products = Product::query()->visible()->with(ProductCards::EAGER_LOADS)->whereIn('products.id', $ids)->get();
        ProductCards::loadLabelSizes($products);

        $cards = [];

        foreach ($products as $product) {
            $cards[$product->id] = ProductCards::fromProduct($product);
        }

        return $cards;
    }

    /**
     * The pressable buttons of the latest bot message, by stored index.
     * Links are re-validated here; product buttons are drawn as cards.
     *
     * @return list<array{index: int, type: string, label: string, url: string|null}>
     */
    private function actions(WebChatMessage $message): array
    {
        $actions = [];

        foreach (is_array($message->buttons) ? $message->buttons : [] as $index => $button) {
            if (! is_int($index) || ! is_array($button) || ! is_string($button['type'] ?? null) || $button['type'] === 'product') {
                continue;
            }

            $label = is_string($button['label'] ?? null) ? trim($button['label']) : '';

            if ($label === '') {
                continue;
            }

            $url = null;

            if ($button['type'] === 'url') {
                $url = self::safeUrl($button['url'] ?? null);

                if ($url === null) {
                    continue;
                }
            }

            $actions[] = ['index' => $index, 'type' => $button['type'], 'label' => $label, 'url' => $url];
        }

        return $actions;
    }

    /**
     * A stored link only while LinkTargets still allows it (https, an allowed
     * host, no odd characters). On a plain-http site URL (local development)
     * the site's own links are allowed over http too.
     */
    private static function safeUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        if (LinkTargets::isSafeExternalUrl($url)) {
            return $url;
        }

        $own = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (str_starts_with((string) config('app.url'), 'http://') && str_starts_with($url, 'http://')
            && LinkTargets::isSafeExternalUrl('https://'.substr($url, 7))
            && is_string($own) && strtolower((string) parse_url($url, PHP_URL_HOST)) === strtolower($own)) {
            return $url;
        }

        return null;
    }

    private function productName(int $productId): ?string
    {
        $product = Product::query()->visible()->whereKey($productId)->first(['products.id', 'products.name']);

        return $product instanceof Product ? trim((string) $product->getTranslation('name', $this->locale)) : null;
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        return [
            'name' => __('site.lead_form.attributes.name'),
            'phone' => __('site.lead_form.attributes.phone'),
            'details' => __('site.lead_form.attributes.message'),
        ];
    }
}
