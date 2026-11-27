<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetRequest extends Model
{
    protected $fillable = [
        'account',
        'status',
        'review_note',
        'user_id',
        'reviewed_by',
    ];
}
