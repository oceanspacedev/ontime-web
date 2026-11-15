<?php

namespace App\Services;

use App\Enums\DataScope;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Adding and changing a Badan Usaha, shared by the web panel and the app: the code is unique inside
 * one Klien, a new company starts with the default approval flows, and a PIC who lists companies one
 * by one keeps the new company in reach.
 */
final class CompanyProvisioningService
{
    public const CODE_TAKEN_MESSAGE = 'Kode ini sudah dipakai badan usaha lain di klien ini.';

    public function __construct(private readonly ApprovalFlowService $approvalFlows) {}

    /**
     * Keep a company code unique inside one Klien (companies without a Klien among themselves).
     */
    public static function uniqueCodeRule(Unique $rule, ?int $tenantId): Unique
    {
        return $tenantId === null ? $rule->whereNull('tenant_id') : $rule->where('tenant_id', $tenantId);
    }

    /**
     * Validation rules for a company sent as plain data (the app).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?int $tenantId, ?Company $company = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', self::uniqueCodeRule(Rule::unique('companies', 'code')->ignore($company?->id), $tenantId)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'name.required' => 'Nama badan usaha wajib diisi.',
            'code.required' => 'Kode badan usaha wajib diisi.',
            'code.unique' => self::CODE_TAKEN_MESSAGE,
            'email.email' => 'Format email tidak valid.',
            'latitude.between' => 'Latitude harus di antara -90 dan 90.',
            'longitude.between' => 'Longitude harus di antara -180 dan 180.',
        ];
    }

    /**
     * Add a Badan Usaha to the actor's Klien (the platform Superadmin's has none).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Company
    {
        return DB::transaction(function () use ($actor, $data): Company {
            $company = new Company($this->attributes($data));
            $company->tenant_id = $actor->isPlatformAdmin() ? null : $actor->tenant_id;
            $company->is_active = (bool) ($data['is_active'] ?? true);
            $company->save();

            $this->provision($company, $actor);

            return $company;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, array $data): Company
    {
        $company->fill($this->attributes($data));

        if (array_key_exists('is_active', $data)) {
            $company->is_active = (bool) $data['is_active'];
        }

        $company->save();

        return $company;
    }

    /**
     * What a new company needs after it is saved. Without approval flows its workers cannot file any
     * request, so it starts with the default stages.
     */
    public function provision(Company $company, ?User $actor): void
    {
        $this->approvalFlows->createDefaultFlows($company);

        // A PIC whose data access lists companies one by one keeps the new one in reach.
        if ($actor && DataAccessService::isTenantOwner($actor) && DataAccessService::scopeOf($actor) === DataScope::Companies) {
            $actor->accessibleCompanies()->syncWithoutDetaching([$company->id]);
            DataAccessService::flush();
        }
    }

    /**
     * Companies whose settings the user manages.
     *
     * @return Builder<Company>
     */
    public static function manageableCompanies(User $user): Builder
    {
        $companyIds = DataAccessService::manageableCompanyIds($user);

        return Company::query()
            ->when($companyIds !== null, fn (Builder $query) => $companyIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn('id', $companyIds));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return collect($data)->only(['name', 'code', 'email', 'phone', 'address', 'latitude', 'longitude'])->all();
    }
}
