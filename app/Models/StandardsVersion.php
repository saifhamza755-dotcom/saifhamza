<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StandardsVersion extends Model
{
    protected $fillable = ['name', 'version', 'status', 'effective_from', 'created_by'];

    protected function casts(): array
    {
        return ['effective_from' => 'date'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function standards(): HasMany
    {
        return $this->hasMany(Standard::class)->orderBy('sort_order');
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(EvaluationCycle::class);
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft' && ! $this->cycles()->exists();
    }
}
