<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DataScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreClientAccountRequest;
use App\Http\Requests\Api\V1\UpdateClientAccountRequest;
use App\Models\User;
use App\Services\ClientAccountService;
use App\Services\ClientAdminGate;
use App\Services\DataAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Akun & akses of a Klien in the app: list, read, add, change roles and data access, and reset a password.
 * Same rules as Manajemen User on the web.
 */
class ClientAccountApiController extends Controller
{
    public function __construct(private readonly ClientAccountService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        ClientAdminGate::ensureManagesAccounts($request->user());
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) ($validated['search'] ?? ''));

        $accounts = $this->accounts->manageableAccounts()
            ->with($this->relations())
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(30);

        return response()->json([
            'data' => collect($accounts->items())->map(fn (User $account): array => $this->accountData($account))->values(),
            'pagination' => [
                'current_page' => $accounts->currentPage(),
                'last_page' => $accounts->lastPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $account = ClientAdminGate::readableAccount($request->user(), $id);

        return response()->json(['data' => $this->accountData($account->load($this->relations()))]);
    }

    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        ClientAdminGate::ensureManagesAccounts($user);

        return response()->json([
            'roles' => $this->accounts->assignableRoles(),
            'scopes' => $this->accounts->grantableScopes($user),
            'companies' => $this->accounts->grantableCompanies($user),
            'divisions' => $this->accounts->grantableDivisions($user),
        ]);
    }

    public function store(StoreClientAccountRequest $request): JsonResponse
    {
        $result = $this->accounts->create($request->user(), $request->validated());

        return response()->json([
            'message' => $result['whatsapp']['api_sent'] ? 'Akun dibuat. Data login terkirim lewat WhatsApp.' : 'Akun dibuat. Kirim data login ini ke pemilik akun.',
            'data' => $this->accountData($result['user']->fresh($this->relations())),
            'credentials' => $this->credentials($result),
        ], 201);
    }

    public function update(UpdateClientAccountRequest $request): JsonResponse
    {
        $account = $this->accounts->update($request->account(), $request->validated());

        return response()->json([
            'message' => 'Akun disimpan.',
            'data' => $this->accountData($account->fresh($this->relations())),
        ]);
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $result = $this->accounts->resetPassword(ClientAdminGate::account($request->user(), $id));

        return response()->json([
            'message' => $result['whatsapp']['api_sent'] ? 'Password baru terkirim lewat WhatsApp.' : 'Password diganti. Kirim password baru ini ke pemilik akun.',
            'credentials' => $this->credentials($result),
        ]);
    }

    /**
     * @return list<string>
     */
    private function relations(): array
    {
        return ['roles', 'accessibleCompanies', 'accessibleDivisions', 'employee.company', 'intern.company', 'freelancer.company'];
    }

    /**
     * @return array<string, mixed>
     */
    private function accountData(User $account): array
    {
        $scope = DataAccessService::scopeOf($account);
        $worker = match (true) {
            $account->employee !== null => ['type' => 'employee', 'model' => $account->employee],
            $account->intern !== null => ['type' => 'intern', 'model' => $account->intern],
            $account->freelancer !== null => ['type' => 'freelancer', 'model' => $account->freelancer],
            default => null,
        };

        return [
            'id' => (int) $account->id,
            'name' => $account->name,
            'email' => $account->email,
            'phone' => $account->phone,
            'is_active' => (bool) $account->is_active,
            'roles' => $account->roles->pluck('name')->values()->all(),
            'data_scope' => $scope->value,
            'data_scope_label' => $scope->getLabel(),
            'companies' => $scope === DataScope::Companies
                ? $account->accessibleCompanies->map(fn ($company): array => ['id' => (int) $company->id, 'name' => $company->name])->values()->all()
                : [],
            'divisions' => $scope === DataScope::Divisions
                ? $account->accessibleDivisions->map(fn ($division): array => ['id' => (int) $division->id, 'name' => $division->name])->values()->all()
                : [],
            'worker' => $worker ? [
                'type' => $worker['type'],
                'id' => (int) $worker['model']->id,
                'name' => $worker['model']->full_name,
                'company' => $worker['model']->company?->name,
            ] : null,
            'can_edit' => $this->accounts->canManage($account),
        ];
    }

    /**
     * @param  array{email: string, password: string, whatsapp: array<string, mixed>}  $result
     * @return array{email: string, password: string, whatsapp_sent: bool, wa_url: string|null}
     */
    private function credentials(array $result): array
    {
        return [
            'email' => $result['email'],
            'password' => $result['password'],
            'whatsapp_sent' => (bool) ($result['whatsapp']['api_sent'] ?? false),
            'wa_url' => $result['whatsapp']['wa_url'] ?? null,
        ];
    }
}
