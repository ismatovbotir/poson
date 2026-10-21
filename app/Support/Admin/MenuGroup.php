<?php

namespace App\Support\Admin;

/**
 * A titled section of the admin menu. The "dashboard" group has no heading
 * and is not collapsible.
 */
final readonly class MenuGroup
{
    /**
     * @param  string  $label  Lang key (menu.groups.*)
     * @param  list<MenuItem>  $items
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $items,
        public bool $collapsible = true,
    ) {}

    public function isActive(): bool
    {
        foreach ($this->items as $item) {
            if ($item->isActive()) {
                return true;
            }
        }

        return false;
    }

    public function item(string $key): ?MenuItem
    {
        foreach ($this->items as $item) {
            if ($item->key === $key) {
                return $item;
            }
        }

        return null;
    }
}
