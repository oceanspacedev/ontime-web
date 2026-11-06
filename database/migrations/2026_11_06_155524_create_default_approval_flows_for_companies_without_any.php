<?php

use App\Models\Company;
use App\Services\ApprovalFlowService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Companies created from the panel never got approval flows (only the Talenta import added them),
     * so their workers could not file any request. Give each of them the default stages.
     */
    public function up(): void
    {
        $service = app(ApprovalFlowService::class);

        Company::query()->whereDoesntHave('approvalFlows')->each(fn (Company $company) => $service->createDefaultFlows($company));
    }

    public function down(): void
    {
        // The flows may have been edited since; they are left in place.
    }
};
