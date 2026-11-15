<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Company;
use App\Services\ClientAdminGate;
use App\Services\CompanyProvisioningService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add (POST /client/companies) or change (PUT /client/companies/{id}) a Badan Usaha from the app.
 */
class ClientCompanyRequest extends FormRequest
{
    private ?Company $company = null;

    public function authorize(): bool
    {
        if ($this->route('id') === null) {
            ClientAdminGate::ensureCreatesCompanies($this->user());
        } else {
            $this->company = ClientAdminGate::company($this->user(), $this->route('id'));
        }

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();
        $tenantId = $this->company
            ? $this->company->tenant_id
            : ($user->isPlatformAdmin() ? null : $user->tenant_id);

        return CompanyProvisioningService::rules($tenantId === null ? null : (int) $tenantId, $this->company);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CompanyProvisioningService::messages();
    }

    public function company(): ?Company
    {
        return $this->company;
    }
}
