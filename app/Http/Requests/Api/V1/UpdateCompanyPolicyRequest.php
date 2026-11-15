<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Company;
use App\Services\ClientAdminGate;
use App\Services\CompanyPolicyService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Change the attendance policy of a Badan Usaha from the app, with the panel's own rules.
 */
class UpdateCompanyPolicyRequest extends FormRequest
{
    private Company $company;

    public function authorize(): bool
    {
        $this->company = ClientAdminGate::company($this->user(), $this->route('id'));

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return CompanyPolicyService::rules($this->company);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CompanyPolicyService::messages();
    }

    public function company(): Company
    {
        return $this->company;
    }
}
