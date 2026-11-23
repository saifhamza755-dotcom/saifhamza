<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccreditationRequest extends Model
{
    protected $fillable = ['external_evaluation_id', 'university_id', 'evaluation_cycle_id', 'rationale', 'status', 'submitted_by', 'submitted_at', 'review_notes'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(ExternalEvaluation::class, 'external_evaluation_id');
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(EvaluationCycle::class, 'evaluation_cycle_id');
    }
}
