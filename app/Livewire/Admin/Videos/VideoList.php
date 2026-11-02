<?php

namespace App\Livewire\Admin\Videos;

use App\Actions\Videos\AttachVideo;
use App\Actions\Videos\DeleteVideo;
use App\Actions\Videos\MoveVideo;
use App\Actions\Videos\RefreshVideoDetails;
use App\Actions\Videos\UpdateVideo;
use App\Enums\VideoFetchStatus;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Videos\VideoForm;
use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use App\Models\Video;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * YouTube video editor (plan 19), a child component of the product, category,
 * brand and news / article forms (after the first save). Paste a link, edit the
 * optional title and date, switch on / off, reorder, refresh, delete.
 *
 * Access follows the owner (VideoPolicy): viewing on mount, managing before
 * every write; every row is re-loaded through the owner (a foreign id is a
 * 404). Writes go through App\Actions\Videos\*. No outbound HTTP here: the
 * title and preview come from a queued job; the list polls while it runs.
 */
class VideoList extends Component
{
    use HandlesActionErrors;

    /**
     * How long the list keeps polling for a pending fetch (minutes).
     */
    public const POLL_MINUTES = 10;

    #[Locked]
    public ?Product $product = null;

    #[Locked]
    public ?Category $category = null;

    #[Locked]
    public ?Brand $brand = null;

    #[Locked]
    public ?NewsPost $newsPost = null;

    /**
     * The video being edited (null = none; "adding" = the add bar is used).
     */
    #[Locked]
    public ?int $editingId = null;

    public VideoForm $form;

    public function mount(?Product $product = null, ?Category $category = null, ?Brand $brand = null, ?NewsPost $newsPost = null): void
    {
        $owners = array_values(array_filter([$product, $category, $brand, $newsPost], fn (?Model $owner): bool => $owner?->exists === true));

        abort_unless(count($owners) === 1, 404);

        $this->product = $owners[0] instanceof Product ? $owners[0] : null;
        $this->category = $owners[0] instanceof Category ? $owners[0] : null;
        $this->brand = $owners[0] instanceof Brand ? $owners[0] : null;
        $this->newsPost = $owners[0] instanceof NewsPost ? $owners[0] : null;

        $this->authorize('viewAny', [Video::class, $this->owner()]);
    }

    /**
     * @return Collection<int, Video>
     */
    #[Computed]
    public function videos(): Collection
    {
        return Video::query()->ownedBy($this->owner())->ordered()->get();
    }

    /**
     * Poll only while a fetch is pending and younger than POLL_MINUTES.
     */
    #[Computed]
    public function polling(): bool
    {
        return $this->videos->contains(
            fn (Video $video): bool => $video->fetch_status === VideoFetchStatus::Pending
                && $video->updated_at !== null
                && $video->updated_at->gt(now()->subMinutes(self::POLL_MINUTES)),
        );
    }

    public function add(AttachVideo $attach): void
    {
        $this->authorize('create', [Video::class, $this->owner()]);

        $this->form->validated(adding: true);

        $this->runFormAction(fn (): Video => $attach->handle($this->owner(), $this->form->url, $this->form->data()));

        $this->form->setVideo(null);
        $this->editingId = null;
        unset($this->videos, $this->polling);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.videos.added'), type: 'success');
    }

    public function edit(mixed $id): void
    {
        $video = $this->find($id);
        $this->authorize('update', $video);

        $this->form->setVideo($video);
        $this->editingId = $video->id;
    }

    public function cancel(): void
    {
        $this->form->setVideo(null);
        $this->editingId = null;
    }

    public function save(UpdateVideo $update): void
    {
        abort_if($this->editingId === null, 404);

        $video = $this->find($this->editingId);
        $this->authorize('update', $video);

        $this->form->validated(adding: false);

        $this->runFormAction(fn (): Video => $update->handle($video, $this->form->data()));

        $this->cancel();
        unset($this->videos, $this->polling);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.videos.saved'), type: 'success');
    }

    public function toggle(mixed $id, UpdateVideo $update): void
    {
        $video = $this->find($id);
        $this->authorize('update', $video);

        $update->handle($video, ['is_active' => ! $video->is_active]);
        unset($this->videos);
    }

    public function move(mixed $id, mixed $direction, MoveVideo $move): void
    {
        $video = $this->find($id);
        $this->authorize('update', $video);

        $move->handle($video, (int) $direction);
        unset($this->videos);
    }

    public function refresh(mixed $id, RefreshVideoDetails $refresh): void
    {
        $video = $this->find($id);
        $this->authorize('update', $video);

        if ($this->runToastAction(fn () => $refresh->handle($video, auth()->user()))) {
            $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.videos.refreshed'), type: 'success');
        }

        unset($this->videos, $this->polling);
    }

    public function delete(mixed $id, DeleteVideo $delete): void
    {
        $video = $this->find($id);
        $this->authorize('delete', $video);

        $delete->handle($video);

        if ($this->editingId === $video->id) {
            $this->cancel();
        }

        unset($this->videos, $this->polling);

        $this->dispatch('toast', title: __('admin.toast.deleted'), body: __('admin.videos.deleted'));
    }

    public function render(): View
    {
        return view('livewire.admin.videos.video-list', [
            'canEdit' => (bool) auth()->user()?->can('manage', [Video::class, $this->owner()]),
        ]);
    }

    protected function owner(): Model
    {
        return $this->product ?? $this->category ?? $this->brand ?? $this->newsPost ?? abort(404);
    }

    private function find(mixed $id): Video
    {
        abort_unless(is_int($id) || (is_string($id) && ctype_digit($id)), 404);

        return Video::query()->ownedBy($this->owner())->findOrFail((int) $id);
    }
}
