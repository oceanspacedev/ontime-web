<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Models\Tenant;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /** @var array{pic_name: string, pic_email: string, pic_phone?: string|null} */
    private array $pic = ['pic_name' => '', 'pic_email' => ''];

    /**
     * The PIC fields make the account, not the Klien row.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pic = [
            'pic_name' => (string) ($data['pic_name'] ?? ''),
            'pic_email' => (string) ($data['pic_email'] ?? ''),
            'pic_phone' => $data['pic_phone'] ?? null,
        ];

        return Arr::except($data, ['pic_name', 'pic_email', 'pic_phone']);
    }

    protected function afterCreate(): void
    {
        /** @var Tenant $tenant */
        $tenant = $this->record;

        TenantResource::openPic($tenant, $this->pic);
    }
}
