<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationTeam extends Model
{
    protected $fillable = ['evaluation_cycle_id', 'university_id', 'name', 'created_by'];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(EvaluationCycle::class, 'evaluation_cycle_id');
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'evaluation_team_user')->withTimestamps();
    }

    public function visits(): HasMany
    {
        return $this->hasMany(EvaluationVisit::class);
    }
}
