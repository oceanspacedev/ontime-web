<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;

trait PreventsDeletionWhileInUse
{
    /**
     * Explain why the record cannot be deleted, or return null when deleting is safe.
     */
    abstract public function deletionBlockedReason(): ?string;

    /**
     * Cancel the delete (Eloquent returns false) while the record is still referenced,
     * so cascading foreign keys never remove workers or their history.
     */
    public static function bootPreventsDeletionWhileInUse(): void
    {
        static::deleting(fn (Model $model): bool => $model->deletionBlockedReason() === null);
    }
}
