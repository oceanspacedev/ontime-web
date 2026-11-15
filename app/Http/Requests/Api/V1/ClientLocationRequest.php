<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Company;
use App\Models\CompanyLocation;
use App\Services\ClientAdminGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add (POST /client/companies/{id}/locations) or change (PUT /client/locations/{id}) an attendance
 * location. Its brand must be a division of the same company.
 */
class ClientLocationRequest extends FormRequest
{
    private Company $company;

    private ?CompanyLocation $location = null;

    public function authorize(): bool
    {
        if ($this->routeIs('api.v1.client.locations.update')) {
            $this->location = ClientAdminGate::location($this->user(), $this->route('id'));
            $this->company = $this->location->company;
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
            'address' => ['nullable', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['nullable', 'integer', 'min:1'],
            'division_id' => ['nullable', 'integer', Rule::exists('divisions', 'id')->where('company_id', $this->company->id)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama lokasi wajib diisi.',
            'latitude.required' => 'Latitude wajib diisi.',
            'longitude.required' => 'Longitude wajib diisi.',
            'latitude.between' => 'Latitude harus di antara -90 dan 90.',
            'longitude.between' => 'Longitude harus di antara -180 dan 180.',
            'radius_meters.min' => 'Radius geofence minimal 1 meter.',
            'division_id.exists' => 'Brand harus divisi dari badan usaha ini.',
        ];
    }

    public function company(): Company
    {
        return $this->company;
    }

    public function location(): ?CompanyLocation
    {
        return $this->location;
    }
}
