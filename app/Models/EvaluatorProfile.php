<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluatorProfile extends Model
{
    protected $fillable = ['user_id', 'employer_university_id', 'specialty'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(University::class, 'employer_university_id');
    }
}
