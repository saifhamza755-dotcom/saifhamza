<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceFile extends Model
{
    protected $fillable = [
        'assessment_response_id', 'title', 'original_name', 'stored_path', 'mime_type',
        'size_bytes', 'sha256', 'status', 'uploaded_by', 'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(AssessmentResponse::class, 'assessment_response_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
