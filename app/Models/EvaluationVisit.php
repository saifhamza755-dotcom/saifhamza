<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationVisit extends Model
{
    protected $fillable = ['evaluation_team_id', 'visit_date', 'visit_time', 'agenda', 'results', 'status', 'updated_by'];

    protected function casts(): array
    {
        return ['visit_date' => 'date'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(EvaluationTeam::class, 'evaluation_team_id');
    }
}
