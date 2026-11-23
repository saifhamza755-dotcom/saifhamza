<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalEvaluationResponse extends Model
{
    protected $fillable = ['external_evaluation_id', 'college_id', 'indicator_id', 'score', 'notes'];

    protected function casts(): array
    {
        return ['score' => 'decimal:2'];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(ExternalEvaluation::class);
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }
}
