<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class College extends Model
{
    protected $fillable = ['university_id', 'name_ar', 'name_en', 'code', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(UserAssignment::class);
    }

    public function evaluationCycles(): BelongsToMany
    {
        return $this->belongsToMany(EvaluationCycle::class, 'evaluation_cycle_college')
            ->withPivot('university_id')->withTimestamps();
    }

    public function selfAssessments(): HasMany
    {
        return $this->hasMany(SelfAssessment::class);
    }
}
