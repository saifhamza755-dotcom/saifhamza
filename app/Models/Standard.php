<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Standard extends Model
{
    protected $fillable = [
        'standards_version_id', 'code', 'name_ar', 'name_en', 'weight', 'sort_order', 'active',
    ];

    protected function casts(): array
    {
        return ['weight' => 'decimal:2', 'active' => 'boolean'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(StandardsVersion::class, 'standards_version_id');
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(Indicator::class)->orderBy('sort_order');
    }
}
