<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class University extends Model
{
    protected $fillable = ['name_ar', 'name_en', 'code', 'type', 'city', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function colleges(): HasMany
    {
        return $this->hasMany(College::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(UserAssignment::class);
    }

    public function evaluationCycles(): BelongsToMany
    {
        return $this->belongsToMany(EvaluationCycle::class, 'evaluation_cycle_university')->withTimestamps();
    }

    public function reports(): HasMany
    {
        return $this->hasMany(UniversityReport::class);
    }
}
