<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Services\ClientAccountService;
use App\Services\ClientAdminGate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Change a login account's roles, data access and status from the app. Nobody edits their own account.
 */
class UpdateClientAccountRequest extends FormRequest
{
    private User $account;

    public function authorize(): bool
    {
        $this->account = ClientAdminGate::account($this->user(), $this->route('id'));

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return app(ClientAccountService::class)->rules($this->user(), $this->all(), $this->account);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ClientAccountService::messages();
    }

    public function account(): User
    {
        return $this->account;
    }
}
