<?php

namespace App\Traits;

use App\Services\WorkerAccountService;
use App\Services\WorkerDeletionBlockedException;
use Illuminate\Database\Eloquent\Model;

/**
 * Model-level delete guard of employees, interns and freelancers, so every caller (panel, webhook, tinker,
 * future API endpoints) keeps the payroll history: deleting a worker with history throws, and deleting one
 * without history switches off the worker account it was linked to, so the phone number is free again.
 */
trait GuardsWorkerDeletion
{
    public static function bootGuardsWorkerDeletion(): void
    {
        static::deleting(function (Model $worker): void {
            if ($reason = $worker->deletionBlockedReason()) {
                throw new WorkerDeletionBlockedException($reason);
            }
        });

        static::deleted(fn (Model $worker) => app(WorkerAccountService::class)->releaseAccountOf($worker));
    }

    /**
     * Why the worker may not be deleted, or null when deleting is safe.
     */
    public function deletionBlockedReason(): ?string
    {
        return app(WorkerAccountService::class)->deletionBlockedReason($this);
    }
}
