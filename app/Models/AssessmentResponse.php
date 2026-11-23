<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentResponse extends Model
{
    protected $fillable = ['self_assessment_id', 'indicator_id', 'score', 'notes', 'updated_by'];

    protected function casts(): array
    {
        return ['score' => 'decimal:2'];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(SelfAssessment::class, 'self_assessment_id');
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function evidenceFiles(): HasMany
    {
        return $this->hasMany(EvidenceFile::class);
    }
}
