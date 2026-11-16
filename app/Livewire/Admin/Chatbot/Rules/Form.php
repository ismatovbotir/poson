<?php

namespace App\Livewire\Admin\Chatbot\Rules;

use App\Actions\Chat\CheckTaughtRule;
use App\Actions\Chat\DeleteChatRule;
use App\Actions\Chat\PrepareTaughtRule;
use App\Actions\Chat\ResolveUnmatched;
use App\Actions\Chat\SaveChatRule;
use App\Actions\Chat\TranslateRuleAnswer;
use App\Enums\ChatAction;
use App\Enums\KeywordMatch;
use App\Enums\Role;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Chatbot\RuleForm;
use App\Models\ChatRule;
use App\Models\ChatUnmatched;
use App\Models\FaqItem;
use App\Models\Page;
use App\Support\Chat\ChatText;
use App\Support\Chat\LinkTargets;
use App\Support\Chat\Skills\CompanyInfoSkill;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Create / edit a chat rule (plan 28 C2, chatbot.rules): main data, the
 * per-action params, the answer per locale, up to 8 buttons and the keywords
 * with a live "normalized as" preview. Saved by SaveChatRule, which validates
 * everything again (CB-5, 6, 12). A system rule keeps its key and action
 * (shown read-only; the action also ignores the input) and cannot be deleted.
 *
 * "Teach" hands a phrase over with ?keyword=...&locale=...&unmatched=ID: the
 * form opens with that keyword added; the unmatched row is resolved by
 * ResolveUnmatched after the rule is saved.
 *
 * "Teach from AI answer" (plan 30 §16, AI-B11, AI-B17) opens the form with
 * ?teach=ID (only the unmatched id): the server picks the AI answer
 * (PrepareTaughtRule) and pre-fills it in its locale with keywords from the
 * phrase, as a draft. The picked turn id is #[Locked]; while it is set,
 * save() runs CheckTaughtRule before SaveChatRule and resolves the phrase.
 * Nothing is saved without Save.
 *
 * "Translate with Claude" (AI-B14) fills the two other locales of the
 * answer from one locale's text (TranslateRuleAnswer). The per-user and
 * global limits are hit here before any call; over them a fixed toast.
 * The result only fills the fields.
 */
#[Layout('layouts::admin')]
class Form extends Component
{
    use HandlesActionErrors;

    #[Locked]
    public ?ChatRule $rule = null;

    public RuleForm $form;

    #[Url(as: 'keyword', except: '')]
    public string $prefillKeyword = '';

    #[Url(as: 'locale', except: '')]
    public string $prefillLocale = '';

    #[Locked]
    #[Url(as: 'unmatched', except: 0)]
    public int $unmatchedId = 0;

    #[Locked]
    #[Url(as: 'teach', except: 0)]
    public int $teachId = 0;

    /**
     * The AI answer turn the form was pre-filled from (server-picked; 0 = not a taught rule).
     */
    #[Locked]
    public int $aiTurnId = 0;

    /**
     * The locale of that AI answer (the answer tab opened first).
     */
    #[Locked]
    public string $teachLocale = '';

    /**
     * The locale whose "Translate with Claude" waits for the overwrite
     * confirmation (the other locales already have text); '' = none. UI
     * state only: the overwrite itself is the second click (N1).
     */
    public string $translateConfirm = '';

    public function mount(?ChatRule $rule = null): void
    {
        if ($rule?->exists) {
            $this->authorize('view', $rule);
            $this->rule = $rule->load('keywords');
            $this->form->setRule($this->rule);

            return;
        }

        $this->authorize('create', ChatRule::class);
        $this->form->blank();

        if ($this->teachId > 0) {
            $this->prefillFromAi();

            return;
        }

        $phrase = trim(mb_substr($this->prefillKeyword, 0, (int) config('chatbot.keyword_max', 120)));

        if ($phrase !== '') {
            $this->form->keywords[] = [
                'phrase' => $phrase,
                'locale' => in_array($this->prefillLocale, config('app.supported_locales'), true) ? $this->prefillLocale : '',
                'match_type' => str_contains(ChatText::normalize($phrase), ' ') ? 'phrase' : 'word',
                'weight' => 10,
            ];
        }
    }

    /**
     * Pre-fill from the AI answer of a phrase (AI-B17): the client sent only the unmatched id.
     */
    private function prefillFromAi(): void
    {
        $unmatched = ChatUnmatched::query()->find($this->teachId);
        $this->teachId = 0;

        if ($unmatched === null) {
            return;
        }

        $this->authorize('update', $unmatched);

        $prefill = app(PrepareTaughtRule::class)->handle($unmatched);

        if ($prefill === null) {
            return;
        }

        $this->unmatchedId = $unmatched->id;
        $this->aiTurnId = $prefill['turn_id'];
        $this->teachLocale = $prefill['locale'];
        $this->form->name = $prefill['name'];
        $this->form->key = $prefill['key'];
        $this->form->answer[$prefill['locale']] = $prefill['answer'];
        $this->form->keywords = $prefill['keywords'];
        $this->form->is_active = false;
    }

    public function hydrate(): void
    {
        $this->rule !== null
            ? $this->authorize('view', $this->rule)
            : $this->authorize('create', ChatRule::class);
    }

    public function addKeyword(): void
    {
        $this->authorizeWrite();

        if (count($this->form->keywords) < (int) config('chatbot.max_keywords_per_rule', 50)) {
            $this->form->keywords[] = ['phrase' => '', 'locale' => '', 'match_type' => 'word', 'weight' => 10];
        }
    }

    public function removeKeyword(int $index): void
    {
        $this->authorizeWrite();

        unset($this->form->keywords[$index]);
        $this->form->keywords = array_values($this->form->keywords);
    }

    public function addButton(): void
    {
        $this->authorizeWrite();

        if (count($this->form->buttons) < (int) config('chatbot.max_buttons', 8)) {
            $this->form->buttons[] = [
                'label' => ['uz' => '', 'ru' => '', 'en' => ''],
                'kind' => 'site', 'rule_id' => '', 'target' => '', 'slug' => '', 'url' => '',
            ];
        }
    }

    public function removeButton(int $index): void
    {
        $this->authorizeWrite();

        unset($this->form->buttons[$index]);
        $this->form->buttons = array_values($this->form->buttons);
    }

    public function save(SaveChatRule $saveChatRule, ResolveUnmatched $resolveUnmatched): void
    {
        $this->authorizeWrite();

        $this->form->validate();

        $creating = $this->rule === null;
        $taught = $creating && $this->aiTurnId > 0;

        if ($taught) {
            $this->runFormAction(fn () => app(CheckTaughtRule::class)->handle($this->form->ruleData()));
        }

        $rule = $this->runFormAction(fn (): ChatRule => $saveChatRule->handle($this->form->ruleData(), auth()->user(), $this->rule));

        if ($creating && $this->unmatchedId > 0) {
            $unmatched = ChatUnmatched::query()->find($this->unmatchedId);

            // Only when the saved rule really contains the phrase (a URL id alone proves nothing),
            // or it was taught from that phrase's AI answer (the id was checked and locked at mount).
            if ($unmatched !== null && $unmatched->resolved_at === null && ($taught || $rule->keywords->contains('normalized', $unmatched->normalized))) {
                $resolveUnmatched->handle($unmatched, auth()->user(), $rule);
            }
        }

        if ($creating) {
            session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('chatbot_admin.rules.created', ['name' => $rule->name]), 'type' => 'success']);
            $this->redirectRoute('admin.chatbot.rules.edit', $rule, navigate: true);

            return;
        }

        $this->rule = $rule;
        $this->form->setRule($rule);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('chatbot_admin.rules.saved', ['name' => $rule->name]));
    }

    /**
     * "Translate with Claude": the answer of $from into the other two locales
     * (AI-B14). Availability and an empty source are checked before the
     * limiters are hit; when another locale already has text, the first
     * click only asks to overwrite and the second (overwrite: true) runs it.
     */
    public function translate(string $from, TranslateRuleAnswer $translateRuleAnswer, bool $overwrite = false): void
    {
        $this->authorizeWrite();

        $locales = config('app.supported_locales');

        if (! in_array($from, $locales, true)) {
            return;
        }

        $this->translateConfirm = '';

        if (! TranslateRuleAnswer::isAvailable() || trim((string) ($this->form->answer[$from] ?? '')) === '') {
            $reason = TranslateRuleAnswer::isAvailable() ? 'empty' : 'off';
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('chatbot_admin.rules.translate.'.$reason), type: 'error');

            return;
        }

        $filled = array_filter(
            array_diff($locales, [$from]),
            fn (string $locale): bool => trim((string) ($this->form->answer[$locale] ?? '')) !== '',
        );

        if ($filled !== [] && ! $overwrite) {
            $this->translateConfirm = $from;

            return;
        }

        $limits = (array) config('chatbot.ai.translate');

        if (RateLimiter::hit('chat-ai:trans:user:'.auth()->id(), 3600) > (int) ($limits['per_hour_user'] ?? 20)
            || RateLimiter::hit('chat-ai:trans:global:minute', 60) > (int) ($limits['per_minute_global'] ?? 30)) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('chatbot_admin.rules.translate.limited'), type: 'error');

            return;
        }

        try {
            $texts = $translateRuleAnswer->handle((string) ($this->form->answer[$from] ?? ''), $from, auth()->user());
        } catch (ValidationException $exception) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: collect($exception->errors())->flatten()->first(), type: 'error');

            return;
        }

        foreach ($texts as $locale => $text) {
            $this->form->answer[$locale] = $text;
        }

        $this->dispatch('toast', title: __('admin.toast.done'), body: __('chatbot_admin.rules.translate.done'));
    }

    public function cancelTranslate(): void
    {
        $this->translateConfirm = '';
    }

    public function delete(DeleteChatRule $deleteChatRule): void
    {
        if ($this->rule === null) {
            return;
        }

        $this->authorize('delete', $this->rule);

        if ($this->runToastAction(fn () => $deleteChatRule->handle($this->rule, auth()->user()))) {
            session()->flash('toast', ['title' => __('admin.toast.deleted'), 'body' => $this->rule->name, 'type' => 'success']);
            $this->redirectRoute('admin.chatbot.rules.index', navigate: true);
        }
    }

    /**
     * Active FAQ items for the FAQ picker (id => question in uz).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function faqOptions(): array
    {
        return FaqItem::query()->active()->ownedBy(null)->ordered()->limit(200)->get()
            ->mapWithKeys(fn (FaqItem $item): array => [$item->id => mb_strimwidth((string) $item->getTranslation('question', 'uz'), 0, 80, '…')])
            ->all();
    }

    /**
     * Other rules a button can point to (id => name).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function ruleOptions(): array
    {
        return ChatRule::query()
            ->when($this->rule !== null, fn ($query) => $query->whereKeyNot($this->rule->getKey()))
            ->orderBy('name')
            ->limit((int) config('chatbot.max_rules', 500))
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Active static pages for the "page" link target (slug => slug).
     *
     * @return array<string, string>
     */
    #[Computed]
    public function pageOptions(): array
    {
        return Page::query()->active()->orderBy('slug')->limit(200)->pluck('slug', 'slug')->all();
    }

    private function authorizeWrite(): void
    {
        $this->rule !== null
            ? $this->authorize('update', $this->rule)
            : $this->authorize('create', ChatRule::class);
    }

    public function render(): View
    {
        $rule = $this->rule;
        $title = $rule ? $rule->name : __('chatbot_admin.rules.new');
        $user = auth()->user();

        return view('livewire.admin.chatbot.rules.form', [
            'title' => $title,
            'isSystem' => (bool) $rule?->is_system,
            'canSave' => $rule ? $user->can('update', $rule) : $user->can('create', ChatRule::class),
            'canDelete' => $rule && ! $rule->is_system && $user->can('delete', $rule),
            'canUseUrl' => $user->hasRole(Role::Admin),
            'canTranslate' => TranslateRuleAnswer::isAvailable() && ($rule ? $user->can('update', $rule) : $user->can('create', ChatRule::class)),
            'actions' => collect(ChatAction::cases())->mapWithKeys(fn (ChatAction $action): array => [$action->value => $action->label()])->all(),
            'matchTypes' => collect(KeywordMatch::cases())->mapWithKeys(fn (KeywordMatch $type): array => [$type->value => $type->label()])->all(),
            'targets' => collect(LinkTargets::targets())->mapWithKeys(fn (string $target): array => [$target => __('chatbot_admin.targets.'.$target)])->all(),
            'topics' => collect(CompanyInfoSkill::TOPICS)->mapWithKeys(fn (string $topic): array => [$topic => __('chatbot_admin.topics.'.$topic)])->all(),
            'flows' => collect(['callback', 'quote', 'service'])->mapWithKeys(fn (string $flow): array => [$flow => __('chatbot_admin.flows.'.$flow)])->all(),
        ])->title($title);
    }
}
