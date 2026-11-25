<?php

namespace App\Actions\Office;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Staff who can work Virtual office chats (plan 41 §4, VO-9): active users
 * with office.chats. An assignment target must be one of them; a content
 * manager (no office.chats) is refused.
 */
class OfficeStaff
{
    /**
     * @return Collection<int, User>
     */
    public static function assignable(): Collection
    {
        return User::query()->where('is_active', true)->with('roles.permissions', 'permissions')->orderBy('name')->get()
            ->filter(fn (User $user): bool => $user->can('office.chats'))
            ->values();
    }

    /**
     * Active staff accounts (the "employee" link of a chat or bot user).
     *
     * @return Collection<int, User>
     */
    public static function active(): Collection
    {
        return User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @throws ValidationException
     */
    public static function requireAssignable(int $userId, string $field = 'assignee'): User
    {
        $user = User::query()->whereKey($userId)->where('is_active', true)->first();

        if (! $user instanceof User || ! $user->can('office.chats')) {
            throw ValidationException::withMessages([$field => __('office.errors.assignee_invalid')]);
        }

        return $user;
    }

    /**
     * @throws ValidationException
     */
    public static function requireActive(int $userId, string $field = 'staff_user'): User
    {
        $user = User::query()->whereKey($userId)->where('is_active', true)->first();

        if (! $user instanceof User) {
            throw ValidationException::withMessages([$field => __('office.errors.staff_invalid')]);
        }

        return $user;
    }
}
