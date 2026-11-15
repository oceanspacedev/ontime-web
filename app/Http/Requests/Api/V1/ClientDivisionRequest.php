<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Company;
use App\Models\Division;
use App\Rules\ValidDivisionParent;
use App\Services\ClientAdminGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add (POST /client/companies/{id}/divisions) or change (PUT /client/divisions/{id}) a division. The
 * parent must be a division of the same company and never one of its own sub-divisions.
 */
class ClientDivisionRequest extends FormRequest
{
    private Company $company;

    private ?Division $division = null;

    public function authorize(): bool
    {
        if ($this->routeIs('api.v1.client.divisions.update')) {
            $this->division = ClientAdminGate::division($this->user(), $this->route('id'));
            $this->company = $this->division->company;
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('divisions', 'id')->where('company_id', $this->company->id),
                new ValidDivisionParent($this->division?->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama divisi wajib diisi.',
            'parent_id.exists' => 'Parent divisi harus divisi dari badan usaha ini.',
        ];
    }

    public function company(): Company
    {
        return $this->company;
    }

    public function division(): ?Division
    {
        return $this->division;
    }
}
