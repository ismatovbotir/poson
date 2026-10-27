<?php

namespace App\Livewire\Admin\Banners;

use App\Actions\Banners\DeleteBanner;
use App\Actions\Banners\MoveBanner;
use App\Enums\BannerPlacement;
use App\Enums\BannerState;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\Banner;
use App\Support\Catalog\CategoryTree;
use App\Support\Search;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Banners list (plan 07 7c): desktop thumbnail, title, placement (and
 * category), state (active / scheduled / expired / inactive) and date
 * window; search by title, placement, category and state filters, arrows
 * that move a banner within its list (MoveBanner), delete with
 * confirmation. Ordered as the site shows them: placement, category, sort.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use HandlesActionErrors, WithAdminPagination;

    public const PER_PAGE = 25;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $placement = '';

    #[Url(as: 'category', except: '')]
    public string $categoryId = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Banner::class);
    }

    public function updated(string $property): void
    {
        if ($property === 'placement' && $this->placement !== BannerPlacement::Category->value) {
            $this->categoryId = '';
        }

        if (in_array($property, ['search', 'placement', 'categoryId', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'placement', 'categoryId', 'status');
        $this->resetPage();
    }

    public function move(int $id, int $direction, MoveBanner $moveBanner): void
    {
        $banner = Banner::query()->findOrFail($id);
        $this->authorize('update', $banner);

        $moveBanner->handle($banner, $direction);
    }

    public function delete(int $id, DeleteBanner $deleteBanner): void
    {
        $banner = Banner::query()->findOrFail($id);
        $this->authorize('delete', $banner);

        if ($this->runToastAction(fn () => $deleteBanner->handle($banner))) {
            $this->dispatch('toast', title: __('admin.toast.deleted'), body: (string) $banner->title);
        }
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        $options = [];

        foreach (CategoryTree::flattened() as $node) {
            $options[$node['id']] = str_repeat('— ', $node['depth'] - 1).CategoryTree::name($node['id']);
        }

        return $options;
    }

    public function render(): View
    {
        $search = trim($this->search);
        $placement = BannerPlacement::tryFrom($this->placement);
        $categoryId = ctype_digit($this->categoryId) ? (int) $this->categoryId : null;

        $banners = Banner::query()
            ->with('media')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $titles) => Search::orWhereTranslatable($titles, 'title', $search)))
            ->when($placement, fn (Builder $query, BannerPlacement $placement) => $query->placedAt($placement))
            ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
            ->when(BannerState::tryFrom($this->status), fn (Builder $query, BannerState $state) => match ($state) {
                BannerState::Inactive => $query->where('is_active', false),
                BannerState::Expired => $query->where('is_active', true)->whereNotNull('ends_at')->where('ends_at', '<=', now()),
                BannerState::Scheduled => $query->where('is_active', true)->where('starts_at', '>', now())
                    ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now())),
                BannerState::Active => $query->live(),
            })
            ->orderByRaw('placement = ? desc', [BannerPlacement::HomeHero->value])
            ->orderBy('category_id')
            ->ordered()
            ->paginate(self::PER_PAGE);

        return view('livewire.admin.banners.index', [
            'items' => $banners,
            'filtering' => $search !== '' || $this->placement !== '' || $this->categoryId !== '' || $this->status !== '',
        ])->title(__('admin.banners.title'));
    }
}
