<?php

namespace App\Filament\Imports\Concerns;

use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared lookups for worker importers. Companies, divisions, job levels, shifts and supervisors are
 * written as names (as in the export) and resolved here, limited to the companies the importing
 * user manages.
 */
trait ResolvesWorkerPlacement
{
    /** @var array<string, Company> */
    private array $companyCache = [];

    protected function importingUser(): ?User
    {
        return $this->getImport()->user;
    }

    /**
     * Stop when an existing record belongs to a company the importing user does not manage.
     */
    protected function guardExistingRecord(Model $record): void
    {
        if ($record->exists && ! $this->importingUser()?->canManageCompany($record->getAttribute('company_id'))) {
            $this->failRow('Data ini terdaftar di badan usaha di luar akses Anda.');
        }
    }

    protected function companyFromState(mixed $value): Company
    {
        $key = Str::upper(trim((string) $value));

        if ($key === '') {
            $this->failRow('Badan usaha wajib diisi.');
        }

        $company = $this->companyCache[$key] ??= Company::query()
            ->whereRaw('UPPER(code) = ?', [$key])
            ->orWhereRaw('UPPER(name) = ?', [$key])
            ->first() ?? $this->failRow("Badan usaha \"{$value}\" tidak ditemukan.");

        if (! $this->importingUser()?->canManageCompany($company->id)) {
            $this->failRow("Badan usaha \"{$company->name}\" di luar akses Anda.");
        }

        return $company;
    }

    /**
     * Accepts a full path ("DISTRIBUTION SALES > DEPO BANDUNG") or a name that is unique in the company.
     */
    protected function divisionFromState(Company $company, mixed $value): Division
    {
        $parts = array_values(array_filter(array_map('trim', explode('>', (string) $value)), 'strlen'));

        if ($parts === []) {
            $this->failRow('Divisi wajib diisi.');
        }

        if (count($parts) > 1) {
            $division = null;

            foreach ($parts as $part) {
                $division = Division::query()
                    ->where('company_id', $company->id)
                    ->where('parent_id', $division?->id)
                    ->whereRaw('UPPER(name) = ?', [Str::upper($part)])
                    ->first() ?? $this->failRow("Divisi \"{$value}\" tidak ditemukan di {$company->name}.");
            }

            return $division;
        }

        $matches = Division::query()
            ->where('company_id', $company->id)
            ->whereRaw('UPPER(name) = ?', [Str::upper($parts[0])])
            ->get();

        return match ($matches->count()) {
            1 => $matches->first(),
            0 => $this->failRow("Divisi \"{$parts[0]}\" tidak ditemukan di {$company->name}."),
            default => $this->failRow("Divisi \"{$parts[0]}\" ada di beberapa tempat. Tulis lengkap, misalnya \"Induk > {$parts[0]}\"."),
        };
    }

    protected function jobLevelIdFromState(mixed $value): int
    {
        $name = trim((string) $value);

        return (int) (JobLevel::query()->whereRaw('UPPER(name) = ?', [Str::upper($name)])->value('id')
            ?? $this->failRow("Level jabatan \"{$name}\" tidak ditemukan."));
    }

    /**
     * Job titles are created in the division when they do not exist yet, like the Talenta import.
     */
    protected function jobTitleIdFor(Division $division, mixed $value): int
    {
        $name = trim((string) $value);

        $jobTitle = JobTitle::query()
            ->where(fn ($query) => $query->where('division_id', $division->id)->orWhereNull('division_id'))
            ->whereRaw('UPPER(name) = ?', [Str::upper($name)])
            ->orderByRaw('division_id IS NULL')
            ->first();

        return (int) ($jobTitle ?? JobTitle::create(['division_id' => $division->id, 'name' => $name]))->id;
    }

    protected function shiftIdFromState(Company $company, mixed $value): ?int
    {
        $name = trim((string) $value);

        if ($name === '') {
            return null;
        }

        return (int) (Shift::query()->where('company_id', $company->id)->whereRaw('UPPER(name) = ?', [Str::upper($name)])->value('id')
            ?? $this->failRow("Shift \"{$name}\" tidak ditemukan di {$company->name}."));
    }

    /**
     * Mentor / PIC, written as the employee NIP.
     */
    protected function employeeIdFromNip(mixed $value, string $label): ?int
    {
        $nip = trim((string) $value);

        if ($nip === '') {
            return null;
        }

        $employee = Employee::query()->where('nip', $nip)->first() ?? $this->failRow("{$label} dengan NIP {$nip} tidak ditemukan.");

        if (! $this->importingUser()?->canManageCompany($employee->company_id)) {
            $this->failRow("{$label} dengan NIP {$nip} di luar akses Anda.");
        }

        return (int) $employee->id;
    }

    /**
     * Shared column placement: company, division, shift, and dropping a shift of a previous company.
     * Returns the division the record was placed in.
     */
    protected function placeInCompany(Model $record): Division
    {
        $company = $this->companyFromState($this->data['company'] ?? null);
        $division = $this->divisionFromState($company, $this->data['division'] ?? null);
        $movedCompany = (int) $record->getOriginal('company_id') !== (int) $company->id;

        $record->setAttribute('company_id', $company->id);
        $record->setAttribute('division_id', $division->id);

        if (filled($this->data['shift'] ?? null)) {
            $record->setAttribute('shift_id', $this->shiftIdFromState($company, $this->data['shift']));
        } elseif ($movedCompany) {
            $record->setAttribute('shift_id', null);
        }

        return $division;
    }

    public static function castDate(mixed $state): mixed
    {
        if (blank($state)) {
            return null;
        }

        // Excel keeps dates as day numbers since 1899-12-30 when the cell has no date format.
        if (is_numeric($state) && (float) $state >= 20000 && (float) $state <= 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $state)->toDateString();
        }

        foreach (['Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'd-m-Y', 'd M Y', 'd F Y', 'j M Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, trim((string) $state));

                if ($date !== false) {
                    return $date->toDateString();
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $state;
    }

    public static function castGender(mixed $state): mixed
    {
        return match (Str::lower(trim((string) $state))) {
            '' => null,
            'l', 'laki-laki', 'laki laki', 'pria', 'male', 'm' => 'Male',
            'p', 'perempuan', 'wanita', 'female', 'f' => 'Female',
            default => $state,
        };
    }

    /**
     * @param  array<string, string>  $aliases  lowercase alias => stored value
     */
    public static function castChoice(mixed $state, array $aliases): mixed
    {
        $key = Str::lower(trim((string) $state));

        return $key === '' ? null : ($aliases[$key] ?? $state);
    }

    protected function failRow(string $message): never
    {
        throw new RowImportFailedException($message);
    }
}
