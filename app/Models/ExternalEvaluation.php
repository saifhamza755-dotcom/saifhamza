<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExternalEvaluation extends Model
{
    protected $fillable = [
        'university_report_id', 'evaluation_team_id', 'evaluator_id', 'status', 'total_score', 'assigned_by',
        'assigned_at', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_notes', 'strengths', 'weaknesses', 'recommendations',
    ];

    protected function casts(): array
    {
        return ['total_score' => 'decimal:2', 'assigned_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(UniversityReport::class, 'university_report_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(ExternalEvaluationResponse::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(EvaluationTeam::class, 'evaluation_team_id');
    }

    public function accreditationDecision(): HasOne
    {
        return $this->hasOne(AccreditationDecision::class);
    }
}
