<?php

namespace App\Actions\Pages;

use App\Models\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a custom page with its cover image and FAQ items. System pages are
 * never deleted, whoever asks (Gate::before lets admins past the policy, so
 * the rule is enforced here too): deactivate them instead.
 */
class DeletePage
{
    /**
     * @throws ValidationException
     */
    public function handle(Page $page): void
    {
        if ($page->is_system || Page::isSystemSlug($page->slug)) {
            throw ValidationException::withMessages(['page' => __('admin.pages.errors.system_delete')]);
        }

        DB::transaction(fn () => $page->delete());
    }
}
