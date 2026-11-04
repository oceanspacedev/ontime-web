<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DataScope;
use App\Http\Controllers\Controller;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Holiday;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use App\Services\DataAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterDataApiController extends Controller
{
    public function companies(Request $request): JsonResponse
    {
        $companies = DataAccessService::scopeCompanies(Company::with(['locations', 'policy']), $request->user(), 'companies.id')->get();

        return response()->json(['data' => $companies]);
    }

    public function showCompany(Request $request, int $id): JsonResponse
    {
        $company = DataAccessService::scopeCompanies(Company::with(['locations', 'policy']), $request->user(), 'companies.id')->findOrFail($id);

        return response()->json(['data' => $company]);
    }

    public function companyLocations(Request $request): JsonResponse
    {
        $query = DataAccessService::scopeCompanies(CompanyLocation::with('company'), $request->user());

        if ($request->has('company_id')) {
            $query->where('company_id', $request->input('company_id'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function showCompanyLocation(Request $request, int $id): JsonResponse
    {
        $location = DataAccessService::scopeCompanies(CompanyLocation::with('company'), $request->user())->findOrFail($id);

        return response()->json(['data' => $location]);
    }

    public function companyPolicies(Request $request): JsonResponse
    {
        $query = DataAccessService::scopeCompanies(CompanyPolicy::with('company'), $request->user());

        if ($request->has('company_id')) {
            $query->where('company_id', $request->input('company_id'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function showCompanyPolicy(Request $request, int $id): JsonResponse
    {
        $policy = DataAccessService::scopeCompanies(CompanyPolicy::with('company'), $request->user())->findOrFail($id);

        return response()->json(['data' => $policy]);
    }

    public function divisions(Request $request): JsonResponse
    {
        $query = DataAccessService::scopeCompanies(Division::with('company'), $request->user());

        if ($request->has('company_id')) {
            $query->where('company_id', $request->input('company_id'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function showDivision(Request $request, int $id): JsonResponse
    {
        $division = DataAccessService::scopeCompanies(Division::with('company'), $request->user())->findOrFail($id);

        return response()->json(['data' => $division]);
    }

    public function jobTitles(Request $request): JsonResponse
    {
        $query = JobTitle::with('division');

        if ($request->has('division_id')) {
            $query->where('division_id', $request->input('division_id'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function showJobTitle(int $id): JsonResponse
    {
        $jobTitle = JobTitle::with('division')->findOrFail($id);

        return response()->json(['data' => $jobTitle]);
    }

    public function jobLevels(): JsonResponse
    {
        return response()->json(['data' => JobLevel::all()]);
    }

    public function showJobLevel(int $id): JsonResponse
    {
        $jobLevel = JobLevel::findOrFail($id);

        return response()->json(['data' => $jobLevel]);
    }

    public function employees(Request $request): JsonResponse
    {
        $query = DataAccessService::scopeWorkers(Employee::with(['company', 'division', 'jobTitle', 'jobLevel', 'user']), $request->user());

        if ($request->has('company_id')) {
            $query->where('company_id', $request->input('company_id'));
        }
        if ($request->has('division_id')) {
            $query->where('division_id', $request->input('division_id'));
        }
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $employees = $query->paginate(20);

        return response()->json(['data' => $employees]);
    }

    public function showEmployee(Request $request, int $id): JsonResponse
    {
        $employee = DataAccessService::scopeWorkers(Employee::with(['company', 'division', 'jobTitle', 'jobLevel', 'user']), $request->user())->findOrFail($id);

        return response()->json(['data' => $employee]);
    }

    public function interns(Request $request): JsonResponse
    {
        $query = DataAccessService::scopeWorkers(Intern::with(['company', 'division', 'mentor', 'user']), $request->user());

        if ($request->has('company_id')) {
            $query->where('company_id', $request->input('company_id'));
        }

        $interns = $query->paginate(20);

        return response()->json(['data' => $interns]);
    }

    public function showIntern(Request $request, int $id): JsonResponse
    {
        $intern = DataAccessService::scopeWorkers(Intern::with(['company', 'division', 'mentor', 'user']), $request->user())->findOrFail($id);

        return response()->json(['data' => $intern]);
    }

    public function freelancers(Request $request): JsonResponse
    {
        $query = DataAccessService::scopeWorkers(Freelancer::with(['company', 'division', 'supervisor', 'user']), $request->user());

        if ($request->has('company_id')) {
            $query->where('company_id', $request->input('company_id'));
        }

        $freelancers = $query->paginate(20);

        return response()->json(['data' => $freelancers]);
    }

    public function showFreelancer(Request $request, int $id): JsonResponse
    {
        $freelancer = DataAccessService::scopeWorkers(Freelancer::with(['company', 'division', 'supervisor', 'user']), $request->user())->findOrFail($id);

        return response()->json(['data' => $freelancer]);
    }

    public function holidays(Request $request): JsonResponse
    {
        $query = Holiday::query();

        if ($request->has('year')) {
            $query->whereYear('date', $request->input('year'));
        }

        return response()->json(['data' => $query->orderBy('date')->get()]);
    }

    public function showHoliday(int $id): JsonResponse
    {
        $holiday = Holiday::findOrFail($id);

        return response()->json(['data' => $holiday]);
    }

    public function approvalFlows(Request $request): JsonResponse
    {
        $query = DataAccessService::scopeCompanies(ApprovalFlow::with('company'), $request->user());

        if ($request->has('company_id')) {
            $query->where('company_id', $request->input('company_id'));
        }
        if ($request->has('request_type')) {
            $query->where('request_type', $request->input('request_type'));
        }

        return response()->json(['data' => $query->orderBy('company_id')->orderBy('request_type')->orderBy('step_order')->get()]);
    }

    public function showApprovalFlow(Request $request, int $id): JsonResponse
    {
        $flow = DataAccessService::scopeCompanies(ApprovalFlow::with('company'), $request->user())->findOrFail($id);

        return response()->json(['data' => $flow]);
    }

    /**
     * Approver and BOD accounts with their data access. company_id / division_id keep only the
     * approvers whose data access covers that placement.
     */
    public function approvers(Request $request): JsonResponse
    {
        $companyId = $request->input('company_id');
        $divisionId = $request->input('division_id');

        if ($divisionId && ! $companyId) {
            $companyId = Division::query()->whereKey($divisionId)->value('company_id');
        }

        $approvers = $this->approverQuery($request)->get()
            ->when($companyId || $divisionId, fn ($users) => $users->filter(
                fn (User $approver): bool => DataAccessService::canAccessPlacement($approver, $companyId, $divisionId),
            ))
            ->values()
            ->map(fn (User $approver): array => $this->approverPayload($approver));

        return response()->json(['data' => $approvers]);
    }

    public function showApprover(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->approverPayload($this->approverQuery($request)->findOrFail($id))]);
    }

    public function users(Request $request): JsonResponse
    {
        $query = DataAccessService::scopeUsers(User::with(['roles', 'employee', 'intern', 'freelancer']), $request->user());

        if ($request->has('email')) {
            $query->where('email', 'like', '%'.$request->input('email').'%');
        }

        $users = $query->paginate(20);

        return response()->json(['data' => $users]);
    }

    public function showUser(Request $request, int $id): JsonResponse
    {
        $user = DataAccessService::scopeUsers(User::with(['roles', 'permissions', 'employee', 'intern', 'freelancer']), $request->user())->findOrFail($id);

        return response()->json(['data' => $user]);
    }

    private function approverQuery(Request $request): Builder
    {
        return DataAccessService::scopeUsers(User::query(), $request->user())
            ->with(['roles', 'accessibleCompanies', 'accessibleDivisions.company'])
            ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', ['Approver', 'BOD']))
            ->orderBy('name');
    }

    /**
     * @return array<string, mixed>
     */
    private function approverPayload(User $approver): array
    {
        $scope = DataAccessService::scopeOf($approver);

        return [
            'id' => $approver->id,
            'user_id' => $approver->id,
            'name' => $approver->name,
            'email' => $approver->email,
            'roles' => $approver->roles->pluck('name')->values(),
            'data_scope' => $scope->value,
            'data_scope_label' => $scope->getLabel(),
            'companies' => $scope === DataScope::Companies
                ? $approver->accessibleCompanies->map->only(['id', 'code', 'name'])->values()
                : [],
            'divisions' => $scope === DataScope::Divisions
                ? $approver->accessibleDivisions->map(fn (Division $division): array => [
                    'id' => $division->id,
                    'name' => $division->name,
                    'company_id' => $division->company_id,
                    'company_name' => $division->company?->name,
                ])->values()
                : [],
        ];
    }
}
