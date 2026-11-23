<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluatorConflict extends Model
{
    protected $fillable = ['evaluator_id', 'university_id', 'active', 'note', 'recorded_by'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }
}
