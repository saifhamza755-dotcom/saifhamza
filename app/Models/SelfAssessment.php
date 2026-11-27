<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SelfAssessment extends Model
{
    protected $fillable = [
        'evaluation_cycle_id', 'university_id', 'college_id', 'status', 'total_score', 'report_notes',
        'created_by', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return ['total_score' => 'decimal:2', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
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

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(AssessmentResponse::class);
    }

    /** يحدد هل ما زال تقرير الكلية يسمح بالتعديل بحسب حالته وحالة دورة التقييم. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'returned'], true) && $this->cycle->status === 'open';
    }
}
