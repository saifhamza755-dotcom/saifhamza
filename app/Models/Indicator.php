<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Indicator extends Model
{
    protected $fillable = [
        'standard_id', 'code', 'name_ar', 'name_en', 'description', 'weight',
        'evidence_required', 'sort_order', 'active',
    ];

    protected function casts(): array
    {
        return ['weight' => 'decimal:2', 'evidence_required' => 'boolean', 'active' => 'boolean'];
    }

    public function standard(): BelongsTo
    {
        return $this->belongsTo(Standard::class);
    }
}
