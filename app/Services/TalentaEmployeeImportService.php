<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Imports the Talenta "list employee" directory export (JSON).
 *
 * Employees are matched by NIP (Talenta `id_employee`). Companies (`branch`), top-level divisions
 * (`organization`), job titles (`job`) and job levels (`title`) are matched by name and created when
 * missing. Existing employees keep their deeper division and their join date, because the directory
 * only carries the top-level organization and no join date.
 */
class TalentaEmployeeImportService
{
    /** @var array<string, int> */
    private const JOB_LEVEL_ORDERS = [
        'STAFF' => 1,
        'TEAM LEADER' => 2,
        'COORDINATOR' => 3,
        'MANAGER' => 4,
        'CHIEF' => 5,
        'BOD' => 6,
    ];

    /** @var array<string, Company> keyed by normalized name */
    private array $companies = [];

    /** @var array<int, array{parent_id: int|null, name: string, company_id: int}> */
    private array $divisions = [];

    /** @var array<string, JobLevel> keyed by normalized name */
    private array $jobLevels = [];

    /** @var array<string, JobTitle> keyed by "division_id|normalized name" */
    private array $jobTitles = [];

    /**
     * @var array{created: int, updated: int, unchanged: int, reactivated: int, moved: int, deactivated: int, skipped: int, new_companies: list<string>, new_divisions: int, new_job_titles: int, errors: list<string>}
     */
    private array $summary;

    public function __construct(private ApprovalFlowService $approvalFlowService) {}

    /**
     * @param  list<int>|null  $allowedCompanyIds  null = every company; otherwise only rows of these companies are imported and no company is created
     * @return array{success: bool, message: string, dry_run: bool, total: int, created: int, updated: int, unchanged: int, reactivated: int, moved: int, deactivated: int, skipped: int, new_companies: list<string>, new_divisions: int, new_job_titles: int, errors: list<string>}
     */
    public function importFromContent(string $content, bool $dryRun = false, bool $deactivateMissing = false, ?array $allowedCompanyIds = null): array
    {
        $this->resetSummary();

        try {
            $rows = $this->extractRows($this->decode($content));
        } catch (RuntimeException $exception) {
            return $this->result(false, $exception->getMessage(), $dryRun, 0);
        }

        if ($rows === []) {
            return $this->result(false, 'Tidak ada data karyawan di dalam file JSON.', $dryRun, 0);
        }

        DB::beginTransaction();

        try {
            $this->warmCaches();

            $seenNips = [];
            $seenCompanyIds = [];

            foreach ($rows as $index => $row) {
                $label = 'Baris #'.($index + 1);

                if (! is_array($row)) {
                    $this->reject("{$label}: data harus berupa object JSON.");

                    continue;
                }

                $label .= ' ('.trim((string) ($row['id_employee'] ?? '-')).')';

                try {
                    $employee = DB::transaction(fn (): ?Employee => $this->importRow($row, $label, $allowedCompanyIds));
                } catch (Throwable $exception) {
                    $this->warmCaches();
                    $this->reject("{$label}: ".$exception->getMessage());

                    continue;
                }

                if ($employee) {
                    $seenNips[] = $employee->nip;
                    $seenCompanyIds[$employee->company_id] = true;
                }
            }

            if ($deactivateMissing && $seenCompanyIds !== []) {
                $this->summary['deactivated'] = Employee::query()
                    ->whereIn('company_id', array_keys($seenCompanyIds))
                    ->where('status', 'active')
                    ->whereNotIn('nip', $seenNips)
                    ->update(['status' => 'inactive']);
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            report($exception);

            return $this->result(false, 'Import dibatalkan karena terjadi kesalahan: '.$exception->getMessage(), $dryRun, count($rows));
        }

        $message = $dryRun
            ? 'Pratinjau selesai. Belum ada data yang disimpan.'
            : 'Import karyawan Talenta selesai.';

        return $this->result(true, $message, $dryRun, count($rows));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<int>|null  $allowedCompanyIds
     */
    private function importRow(array $row, string $label, ?array $allowedCompanyIds): ?Employee
    {
        $nip = trim((string) ($row['id_employee'] ?? ''));
        $fullName = trim(preg_replace('/\s+/', ' ', trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''))) ?? '');
        $branch = trim((string) ($row['branch'] ?? ''));
        $organization = trim((string) ($row['organization'] ?? ''));
        $job = trim((string) ($row['job'] ?? ''));
        $title = trim((string) ($row['title'] ?? '')) ?: 'STAFF';

        foreach (['NIP (id_employee)' => $nip, 'nama' => $fullName, 'badan usaha (branch)' => $branch, 'organisasi' => $organization, 'posisi (job)' => $job] as $field => $value) {
            if ($value === '') {
                throw new RuntimeException("kolom {$field} kosong.");
            }
        }

        $employee = Employee::query()->with(['division', 'jobTitle'])->where('nip', $nip)->first();

        if ($employee && $allowedCompanyIds !== null && ! in_array((int) $employee->company_id, $allowedCompanyIds, true)) {
            $this->skip("{$label}: karyawan terdaftar di badan usaha di luar akses Anda.");

            return null;
        }

        $company = $this->companyFor($branch, $allowedCompanyIds);

        if (! $company) {
            $this->skip("{$label}: badan usaha \"{$branch}\" di luar akses Anda.");

            return null;
        }

        $talentaId = filled($row['id'] ?? null) ? (string) $row['id'] : null;

        if ($talentaId && Employee::query()->where('talenta_id', $talentaId)->where('nip', '!=', $nip)->exists()) {
            throw new RuntimeException("ID Talenta {$talentaId} sudah dipakai karyawan lain.");
        }

        $keepsDivision = $employee
            && (int) $employee->company_id === (int) $company->id
            && $this->sameName($this->rootDivisionName((int) $employee->division_id), $organization);

        $divisionId = $keepsDivision ? (int) $employee->division_id : $this->rootDivisionFor($company, $organization);

        $jobTitleId = $keepsDivision && $employee->jobTitle && $this->sameName($employee->jobTitle->name, $job)
            ? (int) $employee->job_title_id
            : $this->jobTitleFor($divisionId, $job);

        $attributes = [
            'talenta_id' => $talentaId,
            'company_id' => $company->id,
            'division_id' => $divisionId,
            'job_level_id' => $this->jobLevelFor($title),
            'job_title_id' => $jobTitleId,
            'full_name' => $fullName,
            'email' => filled($row['email'] ?? null) ? trim((string) $row['email']) : $employee?->email,
            'phone' => filled($row['mobile_phone'] ?? null) ? (string) $row['mobile_phone'] : ($row['phone'] ?? $employee?->phone),
            'gender' => $row['gender'] ?? $employee?->gender,
            'birth_date' => $this->parseDate($row['birth_date'] ?? null) ?? $employee?->birth_date,
            'marital_status' => $row['marital_status'] ?? $employee?->marital_status,
            'tax_status' => $row['tax_status'] ?? $employee?->tax_status,
            'avatar_url' => $row['avatar'] ?? $employee?->avatar_url,
            'status' => 'active',
        ];

        if ($this->parseDate($row['join_date'] ?? null)) {
            $attributes['join_date'] = $this->parseDate($row['join_date']);
        }

        if (! $employee) {
            $this->summary['created']++;

            return Employee::create(['nip' => $nip] + $attributes);
        }

        if ($employee->status !== 'active') {
            $this->summary['reactivated']++;
        }

        if ((int) $employee->company_id !== (int) $company->id || (int) $employee->division_id !== $divisionId) {
            $this->summary['moved']++;
        }

        $employee->fill($attributes);

        if ($employee->isDirty()) {
            $employee->save();
            $this->summary['updated']++;
        } else {
            $this->summary['unchanged']++;
        }

        return $employee;
    }

    /**
     * @param  list<int>|null  $allowedCompanyIds
     */
    private function companyFor(string $branch, ?array $allowedCompanyIds): ?Company
    {
        $company = $this->companies[$this->normalize($branch)] ?? null;

        if ($company) {
            return $allowedCompanyIds === null || in_array((int) $company->id, $allowedCompanyIds, true) ? $company : null;
        }

        if ($allowedCompanyIds !== null) {
            return null;
        }

        $company = Company::create(['name' => $branch, 'code' => $this->companyCodeFor($branch), 'is_active' => true]);
        $this->approvalFlowService->createDefaultFlows($company);

        $this->companies[$this->normalize($branch)] = $company;
        $this->summary['new_companies'][] = $branch;

        return $company;
    }

    private function rootDivisionFor(Company $company, string $organization): int
    {
        foreach ($this->divisions as $id => $division) {
            if ($division['company_id'] === (int) $company->id && $division['parent_id'] === null && $this->sameName($division['name'], $organization)) {
                return $id;
            }
        }

        $division = Division::create(['company_id' => $company->id, 'parent_id' => null, 'name' => $organization, 'is_active' => true]);
        $this->divisions[(int) $division->id] = ['parent_id' => null, 'name' => $organization, 'company_id' => (int) $company->id];
        $this->summary['new_divisions']++;

        return (int) $division->id;
    }

    private function rootDivisionName(int $divisionId): ?string
    {
        $visited = [];
        $current = $divisionId;

        while (isset($this->divisions[$current]) && ! isset($visited[$current])) {
            $visited[$current] = true;

            if ($this->divisions[$current]['parent_id'] === null) {
                return $this->divisions[$current]['name'];
            }

            $current = $this->divisions[$current]['parent_id'];
        }

        return null;
    }

    private function jobTitleFor(int $divisionId, string $name): int
    {
        $key = $divisionId.'|'.$this->normalize($name);

        if (! isset($this->jobTitles[$key])) {
            $this->jobTitles[$key] = JobTitle::create(['division_id' => $divisionId, 'name' => $name]);
            $this->summary['new_job_titles']++;
        }

        return (int) $this->jobTitles[$key]->id;
    }

    private function jobLevelFor(string $title): int
    {
        $key = $this->normalize($title);

        $this->jobLevels[$key] ??= JobLevel::create([
            'name' => Str::upper($title),
            'level_order' => self::JOB_LEVEL_ORDERS[$key] ?? ((int) JobLevel::max('level_order') + 1),
        ]);

        return (int) $this->jobLevels[$key]->id;
    }

    private function companyCodeFor(string $name): string
    {
        $words = collect(preg_split('/[^A-Z0-9]+/', Str::upper($name)) ?: [])
            ->filter()
            ->reject(fn (string $word): bool => in_array($word, ['PT', 'CV', 'TBK'], true))
            ->values();

        $base = $words->count() > 1
            ? $words->map(fn (string $word): string => $word[0])->implode('')
            : Str::substr((string) ($words->first() ?? 'BU'), 0, 6);

        $code = $base;
        $suffix = 2;

        while (Company::query()->where('code', $code)->exists()) {
            $code = $base.$suffix++;
        }

        return $code;
    }

    private function warmCaches(): void
    {
        $this->companies = Company::query()->get()->keyBy(fn (Company $company): string => $this->normalize($company->name))->all();

        $this->divisions = Division::query()
            ->get(['id', 'parent_id', 'name', 'company_id'])
            ->mapWithKeys(fn (Division $division): array => [(int) $division->id => [
                'parent_id' => $division->parent_id === null ? null : (int) $division->parent_id,
                'name' => (string) $division->name,
                'company_id' => (int) $division->company_id,
            ]])
            ->all();

        $this->jobLevels = JobLevel::query()->get()->keyBy(fn (JobLevel $level): string => $this->normalize($level->name))->all();

        $this->jobTitles = JobTitle::query()
            ->whereNotNull('division_id')
            ->get()
            ->keyBy(fn (JobTitle $title): string => $title->division_id.'|'.$this->normalize($title->name))
            ->all();
    }

    private function parseDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        foreach (['d M Y', 'Y-m-d', 'd-m-Y', 'd/m/Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, (string) $value)->toDateString();
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function normalize(?string $value): string
    {
        return Str::upper(trim(preg_replace('/\s+/', ' ', (string) $value) ?? ''));
    }

    private function sameName(?string $first, ?string $second): bool
    {
        return $first !== null && $second !== null && $this->normalize($first) === $this->normalize($second);
    }

    private function decode(string $content): mixed
    {
        $content = trim(preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content);

        if ($content === '') {
            throw new RuntimeException('File JSON kosong.');
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Format JSON tidak valid: '.json_last_error_msg().'.');
        }

        return $decoded;
    }

    /**
     * Accepts the raw Talenta response ({"data": {"data": [...]}}), {"data": [...]} or a plain list.
     *
     * @return list<mixed>
     */
    private function extractRows(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['data']['data']) && is_array($decoded['data']['data'])) {
            return array_values($decoded['data']['data']);
        }

        if (isset($decoded['data']) && is_array($decoded['data']) && array_is_list($decoded['data'])) {
            return $decoded['data'];
        }

        return array_is_list($decoded) ? $decoded : [];
    }

    private function reject(string $message): void
    {
        $this->summary['errors'][] = $message;
    }

    private function skip(string $message): void
    {
        $this->summary['skipped']++;
        $this->summary['errors'][] = $message;
    }

    private function resetSummary(): void
    {
        $this->summary = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'reactivated' => 0,
            'moved' => 0,
            'deactivated' => 0,
            'skipped' => 0,
            'new_companies' => [],
            'new_divisions' => 0,
            'new_job_titles' => 0,
            'errors' => [],
        ];
    }

    /**
     * @return array{success: bool, message: string, dry_run: bool, total: int, created: int, updated: int, unchanged: int, reactivated: int, moved: int, deactivated: int, skipped: int, new_companies: list<string>, new_divisions: int, new_job_titles: int, errors: list<string>}
     */
    private function result(bool $success, string $message, bool $dryRun, int $total): array
    {
        return ['success' => $success, 'message' => $message, 'dry_run' => $dryRun, 'total' => $total] + $this->summary;
    }
}
