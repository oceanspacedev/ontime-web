<?php

namespace App\Http\Requests\Api\V1;

use App\Services\ClientAccountService;
use App\Services\ClientAdminGate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a login account to the Klien from the app, with the panel's role and data access rules.
 */
class StoreClientAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        ClientAdminGate::ensureManagesAccounts($this->user());
        ClientAdminGate::ensureNotPlatformAdmin($this->user());

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return app(ClientAccountService::class)->rules($this->user(), $this->all());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ClientAccountService::messages();
    }
}
