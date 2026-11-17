<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Company;
use App\Models\CompanyLocation;
use App\Services\ClientAdminGate;
use App\Services\CompanyPolicyService;
use App\Services\GeofenceService;
use Closure;
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
            'radius_meters' => ['nullable', 'integer', 'min:1', 'max:'.CompanyPolicyService::MAX_GEOFENCE_RADIUS_METERS],
            'division_id' => ['nullable', 'integer', Rule::exists('divisions', 'id')->where('company_id', $this->company->id)],
            'is_active' => ['sometimes', 'boolean', $this->keepsGpsLocationRule()],
        ];
    }

    /**
     * While GPS is required, the last active location (with no HQ coordinates) cannot be switched off:
     * check-in would otherwise have nothing to be checked against.
     */
    private function keepsGpsLocationRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $deactivates = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false;

            if ($deactivates && $this->location?->is_active && GeofenceService::wouldLeaveGpsWithoutLocation($this->company, [$this->location->id])) {
                $fail(CompanyPolicyService::GPS_WITHOUT_LOCATION_MESSAGE);
            }
        };
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
            'radius_meters.max' => 'Radius geofence maksimal '.CompanyPolicyService::MAX_GEOFENCE_RADIUS_METERS.' meter.',
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
