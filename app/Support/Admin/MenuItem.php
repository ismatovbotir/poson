<?php

namespace App\Support\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * One admin menu entry (see Menu). An item is "available" once its named
 * route exists, otherwise it renders as "soon" (no link).
 */
final readonly class MenuItem
{
    /**
     * @param  string  $label  Lang key (menu.items.*)
     * @param  string  $icon  Symbol id from <x-admin.icon-sprite /> without the "i-" prefix
     * @param  string|null  $permission  Spatie permission checked through the Gate (admins pass via Gate::before)
     * @param  list<Role>|null  $roles  Role allow-list for modules that have no permissions yet
     * @param  list<MenuItem>  $children
     * @param  string|null  $active  Route-name pattern for the active state; defaults to "<route prefix>.*" for index routes
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public ?string $route = null,
        public ?string $permission = null,
        public ?array $roles = null,
        public array $children = [],
        public ?string $active = null,
    ) {}

    /**
     * The module behind this item exists (its route is registered).
     */
    public function isAvailable(): bool
    {
        return $this->route !== null && Route::has($this->route);
    }

    public function isSoon(): bool
    {
        return ! $this->isAvailable();
    }

    public function url(): ?string
    {
        return $this->isAvailable() ? route($this->route) : null;
    }

    public function isVisibleTo(User $user): bool
    {
        if ($this->permission !== null && ! $user->can($this->permission)) {
            return false;
        }

        if ($this->roles !== null && ! $user->hasAnyRole(array_map(fn (Role $role): string => $role->value, $this->roles))) {
            return false;
        }

        return true;
    }

    /**
     * Whether the current request is on this item's screen (or one of its children's).
     */
    public function isActive(): bool
    {
        if ($this->isAvailable() && request()->routeIs($this->activePattern())) {
            return true;
        }

        foreach ($this->children as $child) {
            if ($child->isActive()) {
                return true;
            }
        }

        return false;
    }

    public function activePattern(): string
    {
        if ($this->active !== null) {
            return $this->active;
        }

        return Str::endsWith($this->route, '.index')
            ? Str::beforeLast($this->route, '.index').'.*'
            : (string) $this->route;
    }

    /**
     * Copy of this item with only the children the user may see.
     */
    public function forUser(User $user): self
    {
        $children = array_values(array_map(
            fn (MenuItem $child): MenuItem => $child->forUser($user),
            array_filter($this->children, fn (MenuItem $child): bool => $child->isVisibleTo($user)),
        ));

        return new self($this->key, $this->label, $this->icon, $this->route, $this->permission, $this->roles, $children, $this->active);
    }
}
