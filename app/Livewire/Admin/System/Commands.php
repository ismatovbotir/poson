<?php

namespace App\Livewire\Admin\System;

use App\Support\Admin\CommandCatalog;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * System → Commands (/admin/commands, decision 2026-11-02): read-only list of
 * the artisan commands with their English description, help, usage, arguments,
 * options and cron schedule. The catalogue is a cached plain-array list
 * (CommandCatalog); search and the framework toggle filter it in PHP.
 *
 * system.commands is checked in mount(), boot() (every request) and in the
 * updated hooks. Nothing here runs a command or writes anything.
 */
#[Layout('layouts::admin')]
class Commands extends Component
{
    public const SEARCH_MAX = 100;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'framework', except: false)]
    public bool $showFramework = false;

    public function boot(): void
    {
        $this->authorize('system.commands');
    }

    public function mount(): void
    {
        $this->authorize('system.commands');

        $this->search = mb_substr($this->search, 0, self::SEARCH_MAX);
    }

    public function updatedSearch(): void
    {
        $this->authorize('system.commands');

        $this->search = mb_substr($this->search, 0, self::SEARCH_MAX);
    }

    public function updatedShowFramework(): void
    {
        $this->authorize('system.commands');
    }

    public function render(): View
    {
        $this->authorize('system.commands');

        return view('livewire.admin.system.commands', [
            'commands' => CommandCatalog::filter(CommandCatalog::all(), $this->search, $this->showFramework),
        ])->title(__('commands.title'));
    }
}
