<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UniversityReport extends Model
{
    protected $fillable = [
        'evaluation_cycle_id', 'university_id', 'status', 'overall_score', 'college_count',
        'standards_snapshot', 'executive_summary', 'recommendations', 'prepared_by',
        'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'overall_score' => 'decimal:2', 'standards_snapshot' => 'array',
            'submitted_at' => 'datetime', 'reviewed_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(EvaluationCycle::class, 'evaluation_cycle_id');
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function externalEvaluation(): HasOne
    {
        return $this->hasOne(ExternalEvaluation::class);
    }
}
