<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountRequest extends Model
{
    protected $fillable = ['name', 'email', 'role', 'note', 'status', 'review_note', 'reviewed_by'];
}
