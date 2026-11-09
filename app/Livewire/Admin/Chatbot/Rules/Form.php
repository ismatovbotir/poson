<?php

namespace App\Livewire\Admin\Chatbot\Rules;

use App\Actions\Chat\DeleteChatRule;
use App\Actions\Chat\ResolveUnmatched;
use App\Actions\Chat\SaveChatRule;
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
        $rule = $this->runFormAction(fn (): ChatRule => $saveChatRule->handle($this->form->ruleData(), auth()->user(), $this->rule));

        if ($creating && $this->unmatchedId > 0) {
            $unmatched = ChatUnmatched::query()->find($this->unmatchedId);

            if ($unmatched !== null) {
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
            'actions' => collect(ChatAction::cases())->mapWithKeys(fn (ChatAction $action): array => [$action->value => $action->label()])->all(),
            'matchTypes' => collect(KeywordMatch::cases())->mapWithKeys(fn (KeywordMatch $type): array => [$type->value => $type->label()])->all(),
            'targets' => collect(LinkTargets::targets())->mapWithKeys(fn (string $target): array => [$target => __('chatbot_admin.targets.'.$target)])->all(),
            'topics' => collect(CompanyInfoSkill::TOPICS)->mapWithKeys(fn (string $topic): array => [$topic => __('chatbot_admin.topics.'.$topic)])->all(),
            'flows' => collect(['callback', 'quote', 'service'])->mapWithKeys(fn (string $flow): array => [$flow => __('chatbot_admin.flows.'.$flow)])->all(),
        ])->title($title);
    }
}
