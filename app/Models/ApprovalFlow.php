<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalFlow extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'division_id',
        'request_type',
        'step_number',
        'step_order',
        'name',
        'approver_type',
        'approver_role',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'step_number' => 'integer',
            'step_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Set when the flow is a division's own rule; null for the company-wide flow.
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /**
     * The company-wide flow, without the division rules.
     */
    public function scopeCompanyWide(Builder $query): Builder
    {
        return $query->whereNull('division_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
