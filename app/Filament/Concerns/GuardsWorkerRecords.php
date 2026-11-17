<?php

namespace App\Filament\Concerns;

use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Services\WorkerAccountService;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;
use WeakMap;

/**
 * Guards shared by the employee, intern and freelancer resources:
 *
 * - A worker with attendance, overtime, leave, correction or resignation history cannot be deleted (the
 *   foreign keys would cascade the payroll history away); their contract is ended instead.
 * - A phone number belongs to one active worker or account on the platform (WhatsApp OTP login finds the
 *   account by number).
 * - A contract cannot end before it starts.
 * - The "Akun User" picker only links an account that no other worker profile uses.
 */
trait GuardsWorkerRecords
{
    /**
     * deletionBlockedReason() per record object, so a table does not query the history twice per row.
     *
     * @var WeakMap<Model, string|null>|null
     */
    private static ?WeakMap $deletionBlockedReasons = null;

    /**
     * Delete action that is disabled (with the reason as tooltip) for a worker with history, and refuses
     * on the server as well in case the history appeared after the page was loaded.
     */
    public static function workerDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->disabled(fn (Model $record): bool => static::workerDeletionBlockedReason($record) !== null)
            ->tooltip(fn (Model $record): ?string => static::workerDeletionBlockedReason($record))
            ->before(function (DeleteAction $action, Model $record): void {
                $reason = static::workerDeletionBlockedReason($record, fresh: true);

                if ($reason === null) {
                    return;
                }

                Notification::make()->title('Data tidak dapat dihapus')->body($reason)->danger()->send();
                $action->cancel();
            });
    }

    /**
     * Bulk delete that skips every worker with history and says why.
     */
    public static function workerDeleteBulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->using(function (DeleteBulkAction $action, Collection $records): void {
                $records->each(function (Model $record) use ($action): void {
                    if (static::workerDeletionBlockedReason($record, fresh: true) !== null) {
                        $action->reportBulkProcessingFailure(
                            'has_history',
                            fn (int $count): string => "{$count} data tidak dihapus karena sudah punya riwayat absensi, lembur, cuti, koreksi, atau resign. Akhiri kontrak atau ubah statusnya.",
                        );

                        return;
                    }

                    try {
                        $record->delete() || $action->reportBulkProcessingFailure();
                    } catch (Throwable $exception) {
                        $action->reportBulkProcessingFailure();
                        report($exception);
                    }
                });
            });
    }

    /**
     * Why the worker cannot be deleted, or null when it can.
     */
    public static function workerDeletionBlockedReason(Model $record, bool $fresh = false): ?string
    {
        if (! $record instanceof Employee && ! $record instanceof Intern && ! $record instanceof Freelancer) {
            return null;
        }

        self::$deletionBlockedReasons ??= new WeakMap;

        if ($fresh || ! self::$deletionBlockedReasons->offsetExists($record)) {
            // A table row carries the history flag of WorkerAccountService::withWorkHistoryFlag(); a fresh check queries.
            self::$deletionBlockedReasons[$record] = app(WorkerAccountService::class)->deletionBlockedReason($record, useLoadedFlag: ! $fresh);
        }

        return self::$deletionBlockedReasons[$record];
    }

    /**
     * Table query of a worker resource with the history flag, so the delete action of every row reads it
     * instead of querying the history per row.
     *
     * @param  Builder<Employee|Intern|Freelancer>  $query
     * @return Builder<Employee|Intern|Freelancer>
     */
    public static function withWorkHistoryFlag(Builder $query): Builder
    {
        return app(WorkerAccountService::class)->withWorkHistoryFlag($query);
    }

    /**
     * Validation rule for the phone field: the number may not belong to another active worker or account.
     *
     * Only checked when the save makes the worker hold the number anew (new worker, changed number, or an
     * ended worker set working again), so workers who already shared a number before this rule stay editable,
     * and saving a worker as ended never fails on the number.
     *
     * @return Closure(?Model, Get): Closure
     */
    public static function uniqueWorkerPhoneRule(): Closure
    {
        return fn (?Model $record, Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record, $get): void {
            if (blank($value)) {
                return;
            }

            $accounts = app(WorkerAccountService::class);
            $worker = $record instanceof Employee || $record instanceof Intern || $record instanceof Freelancer ? $record : null;

            if (static::formEndsWorker($get, $worker)) {
                return;
            }

            if ($worker?->exists) {
                $phoneChanged = WorkerAccountService::normalizePhone((string) $value) !== WorkerAccountService::normalizePhone($worker->getRawOriginal('phone'));

                if (! $phoneChanged && ! $accounts->hasEnded($worker)) {
                    return;
                }
            }

            if ($accounts->phoneIsTaken((string) $value, $worker)) {
                $fail(WorkerAccountService::PHONE_TAKEN_MESSAGE);
            }
        };
    }

    /**
     * Whether the form saves the worker as left: an ended status or, for an intern or freelancer, an end
     * date already behind us.
     */
    private static function formEndsWorker(Get $get, Employee|Intern|Freelancer|null $worker): bool
    {
        if (in_array($get('status'), WorkerAccountService::ENDED_STATUSES, true)) {
            return true;
        }

        $endDate = $worker instanceof Employee ? null : $get('end_date');

        if (blank($endDate)) {
            return false;
        }

        try {
            return CarbonImmutable::parse($endDate)->startOfDay()->lessThan(today());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Validation rule for the contract end date: not before the start date (when one is filled in).
     *
     * @return Closure(Get): Closure
     */
    public static function contractEndDateRule(string $startDateField = 'start_date'): Closure
    {
        return fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $startDateField): void {
            $startDate = $get($startDateField);

            if (blank($value) || blank($startDate)) {
                return;
            }

            try {
                $isBeforeStart = CarbonImmutable::parse($value)->startOfDay()->lessThan(CarbonImmutable::parse($startDate)->startOfDay());
            } catch (Throwable) {
                return;
            }

            if ($isBeforeStart) {
                $fail('Tanggal selesai tidak boleh sebelum tanggal mulai.');
            }
        };
    }

    /**
     * Validation rule for "Akun User": the account must be one the picker offers (or the worker's current
     * account) and may not be linked to another employee, intern or freelancer.
     *
     * @return Closure(?Model, Get): Closure
     */
    public static function linkableAccountRule(): Closure
    {
        return fn (?Model $record, Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record, $get): void {
            if (blank($value)) {
                return;
            }

            $worker = $record instanceof Employee || $record instanceof Intern || $record instanceof Freelancer ? $record : null;
            $isCurrentAccount = $worker?->exists && (string) $worker->getOriginal('user_id') === (string) $value;

            if (! $isCurrentAccount && ! array_key_exists((int) $value, static::assignableUserOptions($get('company_id')))) {
                $fail('Akun ini tidak bisa dipilih.');

                return;
            }

            if ($reason = app(WorkerAccountService::class)->accountLinkBlockedReason($value, $worker)) {
                $fail($reason);
            }
        };
    }
}
