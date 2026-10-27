<?php

namespace App\Actions\Catalog;

use App\Models\RollType;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Delete a roll type. Blocked while products use it (detach it from them or
 * deactivate it instead), also when a product attaches it between the check
 * and the delete (the restricting foreign key). Quote and lead lines keep
 * their snapshots; their roll_type_id is set to null by the foreign key.
 */
class DeleteRollType
{
    /**
     * @throws ValidationException
     */
    public function handle(RollType $rollType): void
    {
        if ($rollType->isAttached()) {
            throw static::attached();
        }

        try {
            $rollType->delete();
        } catch (QueryException $exception) {
            // SQLSTATE 23000: integrity constraint violation (product_roll_type restricts the delete).
            if ((string) $exception->getCode() === '23000') {
                throw static::attached();
            }

            throw $exception;
        }
    }

    private static function attached(): ValidationException
    {
        return ValidationException::withMessages(['roll_type' => __('admin.roll_types.errors.attached')]);
    }
}
