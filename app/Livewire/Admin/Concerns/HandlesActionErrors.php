<?php

namespace App\Livewire\Admin\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Glue between admin components and Actions that report problems with
 * ValidationException (e.g. "parent_id" → shown on "form.parent_id", or a
 * blocked delete → error toast).
 */
trait HandlesActionErrors
{
    /**
     * Run an action; its error keys get the form prefix ("slug" → "form.slug").
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     *
     * @throws ValidationException
     */
    protected function runFormAction(callable $callback, string $prefix = 'form.'): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $key): array => [$prefix.$key => $messages])
                    ->all(),
            );
        }
    }

    /**
     * Run an action; on a ValidationException show its first message as an
     * error toast instead. Returns whether the action succeeded.
     */
    protected function runToastAction(callable $callback): bool
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: collect($exception->errors())->flatten()->first(), type: 'error');

            return false;
        }

        return true;
    }
}
