<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClientCompanyRequest;
use App\Http\Requests\Api\V1\ClientDivisionRequest;
use App\Http\Requests\Api\V1\ClientLocationRequest;
use App\Http\Requests\Api\V1\UpdateCompanyPolicyRequest;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Services\ClientAdminGate;
use App\Services\CompanyPolicyService;
use App\Services\CompanyProvisioningService;
use App\Services\GeofenceService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The PIC or Admin running their Klien from the app: Badan Usaha, attendance policy, attendance
 * locations and divisions. Same rules as the web panel; approval flows, shifts and import/export stay
 * on the web.
 */
class ClientAdminApiController extends Controller
{
    public function __construct(private readonly CompanyProvisioningService $companies) {}

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $permissions = ClientAdminGate::permissions($user);

        abort_unless($permissions['can_manage_companies'] || $permissions['can_manage_accounts'], 403, 'Pengaturan klien hanya untuk PIC atau Admin.');

        $companies = $permissions['can_manage_companies']
            ? CompanyProvisioningService::manageableCompanies($user)->orderBy('name')->get()
            : new Collection;

        $tenant = $user->tenant;

        return response()->json([
            'permissions' => $permissions,
            'tenant' => $tenant ? ['id' => (int) $tenant->id, 'name' => $tenant->name] : null,
            'companies' => $this->companySummaries($companies),
        ]);
    }

    public function storeCompany(ClientCompanyRequest $request): JsonResponse
    {
        $company = $this->companies->create($request->user(), $request->validated());

        return response()->json([
            'message' => 'Badan usaha ditambahkan.',
            'data' => $this->companySummaries(new Collection([$company]))[0],
        ], 201);
    }

    public function updateCompany(ClientCompanyRequest $request): JsonResponse
    {
        $company = $this->companies->update($request->company(), $request->validated());

        return response()->json([
            'message' => 'Badan usaha disimpan.',
            'data' => $this->companySummaries(new Collection([$company]))[0],
        ]);
    }

    public function policy(Request $request, int $id): JsonResponse
    {
        $company = ClientAdminGate::company($request->user(), $id);

        return response()->json([
            'data' => $this->policyData($company),
            'options' => [
                'selfie_modes' => CompanyPolicyService::selfieModeChoices(),
                'mock_location_actions' => $this->choices(CompanyPolicyService::mockLocationActionOptions()),
                'face_fail_actions' => $this->choices(CompanyPolicyService::faceFailActionOptions()),
            ],
            'has_locations' => GeofenceService::hasConfiguredLocations($company),
        ]);
    }

    public function updatePolicy(UpdateCompanyPolicyRequest $request): JsonResponse
    {
        $company = $request->company();
        CompanyPolicyService::save($company, $request->validated());

        return response()->json([
            'message' => 'Kebijakan absensi disimpan.',
            'data' => $this->policyData($company->fresh('policy')),
        ]);
    }

    public function locations(Request $request, int $id): JsonResponse
    {
        $company = ClientAdminGate::company($request->user(), $id);

        return response()->json([
            'data' => $company->locations()->orderBy('name')->get()->map(fn (CompanyLocation $location): array => $this->locationData($location))->values(),
        ]);
    }

    public function storeLocation(ClientLocationRequest $request): JsonResponse
    {
        $location = $request->company()->locations()->create([
            ...$request->validated(),
            'is_active' => (bool) $request->validated('is_active', true),
        ]);

        return response()->json(['message' => 'Lokasi ditambahkan.', 'data' => $this->locationData($location->fresh())], 201);
    }

    public function updateLocation(ClientLocationRequest $request): JsonResponse
    {
        $location = $request->location();
        $location->update($request->validated());

        return response()->json(['message' => 'Lokasi disimpan.', 'data' => $this->locationData($location->fresh())]);
    }

    public function divisions(Request $request, int $id): JsonResponse
    {
        $company = ClientAdminGate::company($request->user(), $id);
        $divisions = $company->divisions()->get();
        $paths = $this->divisionPaths($divisions);

        return response()->json([
            'data' => $divisions
                ->map(fn (Division $division): array => $this->divisionData($division, $paths[$division->id]))
                ->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)
                ->values(),
        ]);
    }

    public function storeDivision(ClientDivisionRequest $request): JsonResponse
    {
        $division = $request->company()->divisions()->create([
            ...$request->validated(),
            'is_active' => (bool) $request->validated('is_active', true),
        ]);

        return response()->json(['message' => 'Divisi ditambahkan.', 'data' => $this->divisionData($division->fresh(), $division->pathName())], 201);
    }

    public function updateDivision(ClientDivisionRequest $request): JsonResponse
    {
        $division = $request->division();
        $division->update($request->validated());

        return response()->json(['message' => 'Divisi disimpan.', 'data' => $this->divisionData($division->fresh(), $division->pathName())]);
    }

    /**
     * @param  Collection<int, Company>  $companies
     * @return list<array<string, mixed>>
     */
    private function companySummaries(Collection $companies): array
    {
        if ($companies->isEmpty()) {
            return [];
        }

        $companies->load('policy')->loadCount(['locations', 'divisions', 'employees']);
        $ids = $companies->modelKeys();
        $interns = Intern::query()->whereIn('company_id', $ids)->selectRaw('company_id, count(*) as total')->groupBy('company_id')->pluck('total', 'company_id');
        $freelancers = Freelancer::query()->whereIn('company_id', $ids)->selectRaw('company_id, count(*) as total')->groupBy('company_id')->pluck('total', 'company_id');

        return $companies->map(fn (Company $company): array => [
            'id' => (int) $company->id,
            'name' => $company->name,
            'code' => $company->code,
            'email' => $company->email,
            'phone' => $company->phone,
            'address' => $company->address,
            'latitude' => $company->latitude !== null ? (float) $company->latitude : null,
            'longitude' => $company->longitude !== null ? (float) $company->longitude : null,
            'is_active' => (bool) $company->is_active,
            'mode_label' => $company->policy?->modeLabel() ?? 'Klik saja',
            'locations_count' => (int) $company->locations_count,
            'divisions_count' => (int) $company->divisions_count,
            'workers_count' => (int) $company->employees_count + (int) ($interns[$company->id] ?? 0) + (int) ($freelancers[$company->id] ?? 0),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function policyData(Company $company): array
    {
        $policy = $company->policy;

        return [
            ...CompanyPolicyService::formState($company),
            'work_start_time' => substr((string) ($policy?->work_start_time ?? '08:00'), 0, 5),
            'work_end_time' => substr((string) ($policy?->work_end_time ?? '17:00'), 0, 5),
            'mode_label' => $policy?->modeLabel() ?? 'Klik saja',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function locationData(CompanyLocation $location): array
    {
        return [
            'id' => (int) $location->id,
            'company_id' => (int) $location->company_id,
            'division_id' => $location->division_id !== null ? (int) $location->division_id : null,
            'name' => $location->name,
            'address' => $location->address,
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'radius_meters' => $location->radius_meters !== null ? (int) $location->radius_meters : null,
            'is_active' => (bool) $location->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function divisionData(Division $division, string $path): array
    {
        return [
            'id' => (int) $division->id,
            'company_id' => (int) $division->company_id,
            'parent_id' => $division->parent_id !== null ? (int) $division->parent_id : null,
            'name' => $division->name,
            'code' => $division->code,
            'is_active' => (bool) $division->is_active,
            'path' => $path,
        ];
    }

    /**
     * "Parent > Child" for every division of one company, built from the loaded list.
     *
     * @param  Collection<int, Division>  $divisions
     * @return array<int, string>
     */
    private function divisionPaths(Collection $divisions): array
    {
        $byId = $divisions->keyBy('id');

        return $byId->map(function (Division $division) use ($byId): string {
            $names = [];
            $visited = [];

            for ($node = $division; $node && ! isset($visited[$node->id]); $node = $node->parent_id ? $byId->get($node->parent_id) : null) {
                $visited[$node->id] = true;
                array_unshift($names, $node->name);
            }

            return implode(' > ', $names);
        })->all();
    }

    /**
     * @param  array<string, string>  $options
     * @return list<array{value: string, label: string}>
     */
    private function choices(array $options): array
    {
        return collect($options)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all();
    }
}
