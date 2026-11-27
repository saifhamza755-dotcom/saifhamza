<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccreditationDecision extends Model
{
    protected $fillable = [
        'external_evaluation_id', 'university_id', 'evaluation_cycle_id', 'decision_number',
        'decision_type', 'status', 'final_score', 'valid_from', 'valid_until', 'rationale',
        'conditions', 'issued_by', 'issued_at', 'revoked_by', 'revoked_at', 'revocation_reason',
    ];

    protected function casts(): array
    {
        return [
            'final_score' => 'decimal:2', 'valid_from' => 'date', 'valid_until' => 'date',
            'issued_at' => 'datetime', 'revoked_at' => 'datetime',
        ];
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

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** يحول حالة قرار الاعتماد الداخلية إلى وصف عربي مناسب للواجهة. */
    public function displayStatus(): string
    {
        if ($this->status === 'revoked') {
            return 'revoked';
        }
        if ($this->valid_until?->isPast()) {
            return 'expired';
        }

        return 'active';
    }
}
