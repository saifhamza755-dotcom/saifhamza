<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationCycle extends Model
{
    protected $fillable = [
        'name', 'code', 'starts_on', 'ends_on', 'status', 'standards_version_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function standardsVersion(): BelongsTo
    {
        return $this->belongsTo(StandardsVersion::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function universities(): BelongsToMany
    {
        return $this->belongsToMany(University::class, 'evaluation_cycle_university')->withTimestamps();
    }

    public function colleges(): BelongsToMany
    {
        return $this->belongsToMany(College::class, 'evaluation_cycle_college')
            ->withPivot('university_id')->withTimestamps();
    }

    public function selfAssessments(): HasMany
    {
        return $this->hasMany(SelfAssessment::class);
    }

    public function universityReports(): HasMany
    {
        return $this->hasMany(UniversityReport::class);
    }
}
