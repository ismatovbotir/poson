<?php

namespace App\Livewire\Admin\Concerns;

use Livewire\WithPagination;

/**
 * Livewire pagination rendered with the admin kit paginator view.
 */
trait WithAdminPagination
{
    use WithPagination;

    public function paginationView(): string
    {
        return 'vendor.pagination.admin';
    }
}
