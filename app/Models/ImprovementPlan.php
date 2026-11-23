<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImprovementPlan extends Model
{
    protected $fillable = [
        'evaluation_cycle_id', 'university_id', 'college_id', 'source_type', 'source_id',
        'title', 'status', 'created_by', 'activated_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(EvaluationCycle::class, 'evaluation_cycle_id');
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ImprovementAction::class);
    }
}
