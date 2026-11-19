<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\ShiftSchedule;
use App\Services\WorkerAccountService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Read-only check before a company is switched to "blank = not scheduled" (company_policies.uses_shift_roster).
 * Per company it lists what the switch would change: whether it has working schedule rows at all, the
 * workers with a default shift who never had a dated schedule (they stay on fixed hours, expected Monday to
 * Friday), the workers without a default shift and without any schedule in the coming days (they can only
 * check in through "Masuk di luar jadwal" with approval), and the schedule rows that are neither a day off
 * nor name a shift (read as a blank day). Nothing is written.
 */
class RosterPreflight extends Command
{
    /** Days ahead (today included) a worker without a default shift should already have a schedule. */
    public const LOOKAHEAD_DAYS = 14;

    protected $signature = 'attendance:roster-preflight
        {--company=* : Only these companies (id or code)}
        {--limit=20 : Names listed per group (0 = counts only)}';

    protected $description = 'Show, per company, who is affected when the company switches to shift rosters (a day without a schedule is not scheduled); read-only';

    /** @var array<string, class-string<Employee|Intern|Freelancer>> */
    private const WORKER_TYPES = [
        'employee_id' => Employee::class,
        'intern_id' => Intern::class,
        'freelancer_id' => Freelancer::class,
    ];

    public function handle(): int
    {
        $filters = array_values(array_filter((array) $this->option('company'), fn (mixed $value): bool => filled($value)));
        $limit = max(0, (int) $this->option('limit'));
        $from = today()->toDateString();
        $until = today()->addDays(self::LOOKAHEAD_DAYS - 1)->toDateString();

        $companies = Company::query()
            ->with('policy')
            ->when($filters !== [], fn ($query) => $query->where(fn ($match) => $match
                ->whereIn('id', array_filter($filters, fn (string $value): bool => ctype_digit($value)))
                ->orWhereIn('code', $filters)))
            ->orderBy('name')
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('Tidak ada badan usaha yang cocok.');

            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            $this->reportCompany($company, $from, $until, $limit);
        }

        return self::SUCCESS;
    }

    private function reportCompany(Company $company, string $from, string $until, int $limit): void
    {
        $workers = $this->activeWorkers($company);
        $workingRows = 0;
        $shiftlessRows = 0;
        $fixedHours = collect();
        $needsCover = collect();
        $rosteredDefaultNeedsCover = collect();

        foreach (self::WORKER_TYPES as $column => $model) {
            if ($workers->get($column, collect())->isEmpty()) {
                continue;
            }

            $workingRows += $this->scheduleRows($company, $column, $model)->where('is_day_off', false)->whereNotNull('shift_id')->count();
            $shiftlessRows += $this->scheduleRows($company, $column, $model)->where('is_day_off', false)->whereNull('shift_id')->count();
            // On a roster today: the first roster row (not an approved cover's own row) is dated today or
            // earlier, the rule SessionResolver and the attendance ledger apply. A worker whose rows all lie
            // ahead keeps the fixed hours until the first of them.
            $rosteredByToday = ShiftSchedule::whereRosterRow($this->scheduleRows($company, $column, $model))
                ->whereDate('date', '<=', $from)
                ->distinct()
                ->pluck($column)
                ->map(fn (mixed $id): int => (int) $id)
                ->flip();
            $upcoming = $this->scheduleRows($company, $column, $model)
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $until)
                ->distinct()
                ->pluck($column)
                ->map(fn (mixed $id): int => (int) $id)
                ->flip();

            foreach ($workers->get($column, collect()) as $worker) {
                $id = (int) $worker->getKey();

                if ($worker->shift_id !== null && ! $rosteredByToday->has($id)) {
                    $fixedHours->push($this->describe($worker));
                } elseif ($worker->shift_id === null && ! $upcoming->has($id)) {
                    $needsCover->push($this->describe($worker));
                } elseif (! $upcoming->has($id)) {
                    // Rostered before: the default shift no longer gives standing hours once the roster is on.
                    $rosteredDefaultNeedsCover->push($this->describe($worker));
                }
            }
        }

        $this->newLine();
        $this->line(sprintf('<info>%s</info> (id %d%s) - jadwal shift: %s', $company->name, $company->id, filled($company->code) ? ', '.$company->code : '', $company->policy?->uses_shift_roster ? 'AKTIF' : 'belum aktif'));
        $this->line(sprintf('  Pekerja aktif: %d', $workers->flatten()->count()));
        $this->line(sprintf('  Baris jadwal kerja (dengan shift): %d%s', $workingRows, $workingRows === 0 ? ' - belum ada jadwal, setelah diaktifkan hanya pekerja jam tetap yang diharapkan masuk' : ''));
        $this->line(sprintf('  Baris jadwal tanpa shift dan bukan libur (dibaca sebagai hari kosong): %d', $shiftlessRows));
        $this->group('Punya shift default dan belum mulai dijadwalkan (tetap jam tetap Senin-Jumat sampai tanggal jadwal pertamanya)', $fixedHours, $limit);
        $this->group(sprintf('Tanpa shift default dan tanpa jadwal %d hari ke depan (hanya bisa lewat "Masuk di luar jadwal")', self::LOOKAHEAD_DAYS), $needsCover, $limit);
        $this->group(sprintf('Punya shift default, pernah dijadwalkan, tanpa jadwal %d hari ke depan (hanya bisa lewat "Masuk di luar jadwal")', self::LOOKAHEAD_DAYS), $rosteredDefaultNeedsCover, $limit);
    }

    /**
     * The company's workers that are not ended, by schedule column.
     *
     * @return Collection<string, Collection<int, Employee|Intern|Freelancer>>
     */
    private function activeWorkers(Company $company): Collection
    {
        return collect(self::WORKER_TYPES)->map(fn (string $model): Collection => $this->activeWorkersQuery($company, $model)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'shift_id', 'company_id']));
    }

    /**
     * @param  class-string<Employee|Intern|Freelancer>  $model
     * @return Builder<Employee|Intern|Freelancer>
     */
    private function activeWorkersQuery(Company $company, string $model): Builder
    {
        return $model::query()
            ->where('company_id', $company->id)
            ->where(fn ($status) => $status->whereNull('status')->orWhereNotIn('status', WorkerAccountService::ENDED_STATUSES));
    }

    /**
     * The schedule rows of the company's active workers of one kind.
     *
     * @param  class-string<Employee|Intern|Freelancer>  $model
     * @return Builder<ShiftSchedule>
     */
    private function scheduleRows(Company $company, string $column, string $model): Builder
    {
        return ShiftSchedule::query()->whereIn($column, $this->activeWorkersQuery($company, $model)->select('id'));
    }

    private function describe(Model $worker): string
    {
        $type = match (true) {
            $worker instanceof Employee => 'karyawan',
            $worker instanceof Intern => 'magang',
            default => 'freelancer',
        };

        return "{$worker->full_name} ({$type} #{$worker->getKey()})";
    }

    /**
     * @param  Collection<int, string>  $names
     */
    private function group(string $title, Collection $names, int $limit): void
    {
        $this->line(sprintf('  %s: %d', $title, $names->count()));

        if ($limit === 0) {
            return;
        }

        foreach ($names->take($limit) as $name) {
            $this->line('    - '.$name);
        }

        if ($names->count() > $limit) {
            $this->line(sprintf('    ... dan %d lainnya', $names->count() - $limit));
        }
    }
}
