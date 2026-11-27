<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImprovementAction extends Model
{
    protected $fillable = [
        'improvement_plan_id', 'standard_id', 'indicator_id', 'gap_description', 'action_description',
        'responsible_party', 'priority', 'starts_on', 'due_on', 'progress', 'status', 'notes', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'due_on' => 'date', 'progress' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ImprovementPlan::class, 'improvement_plan_id');
    }

    public function standard(): BelongsTo
    {
        return $this->belongsTo(Standard::class);
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** يعيد true عندما يتجاوز الإجراء موعده ولم يصل إلى إنجاز كامل. */
    public function isOverdue(): bool
    {
        return $this->status !== 'completed' && $this->due_on->isPast();
    }
}
