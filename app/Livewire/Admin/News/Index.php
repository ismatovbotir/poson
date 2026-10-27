<?php

namespace App\Livewire\Admin\News;

use App\Actions\News\DeleteNewsPost;
use App\Enums\NewsKind;
use App\Enums\NewsState;
use App\Enums\NewsStatus;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\NewsPost;
use App\Support\Search;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * News list (plan 07 7b): title, URL, state (draft / scheduled / published),
 * translated locales and publication date (Tashkent time); search by title
 * or slug, state filter, whitelisted sort, delete with confirmation.
 * Lists only posts of kind(); App\Livewire\Admin\Articles\Index is the
 * articles screen (same view, same news.* permissions).
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use HandlesActionErrors, WithAdminPagination;

    /**
     * Sort key => [column, direction] (whitelist).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const SORTS = [
        'newest' => ['published_at', 'desc'],
        'oldest' => ['published_at', 'asc'],
        'updated' => ['updated_at', 'desc'],
        'created' => ['created_at', 'desc'],
    ];

    public const PER_PAGE = 25;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: 'newest')]
    public string $sort = 'newest';

    /**
     * The kind this screen lists (fixed by the screen).
     */
    protected function kind(): NewsKind
    {
        return NewsKind::News;
    }

    public function mount(): void
    {
        $this->authorize('viewAny', NewsPost::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'sort'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }

    public function delete(int $id, DeleteNewsPost $deletePost): void
    {
        $post = NewsPost::query()->ofKind($this->kind())->findOrFail($id);
        $this->authorize('delete', $post);

        if ($this->runToastAction(fn () => $deletePost->handle($post))) {
            $this->dispatch('toast', title: __('admin.toast.deleted'), body: (string) $post->title);
        }
    }

    public function render(): View
    {
        $search = trim($this->search);
        [$column, $direction] = self::SORTS[$this->sort] ?? self::SORTS['newest'];

        $posts = NewsPost::query()
            ->ofKind($this->kind())
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw("lower(slug) like ? escape '!'", [Search::likePattern($search)])
                ->orWhere(fn ($titles) => Search::orWhereTranslatable($titles, 'title', $search))))
            ->when(NewsState::tryFrom($this->status), fn (Builder $query, NewsState $state) => match ($state) {
                NewsState::Draft => $query->where('status', NewsStatus::Draft->value),
                NewsState::Published => $query->visible(),
                NewsState::Scheduled => $query->where('status', NewsStatus::Published->value)->where('published_at', '>', now()),
            })
            // Drafts without a date last when newest first.
            ->when($column === 'published_at', fn (Builder $query) => $query->orderByRaw('published_at is null '.($direction === 'desc' ? 'asc' : 'desc')))
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate(self::PER_PAGE);

        return view('livewire.admin.news.index', [
            'kind' => $this->kind(),
            'items' => $posts,
            'filtering' => $search !== '' || $this->status !== '',
        ])->title($this->kind()->adminText('title'));
    }
}
