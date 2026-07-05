<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['guest_id', 'role', 'message', 'payload', 'provider'])]
class ChatHistory extends Model
{
    protected $casts = [
        'payload' => 'array',
    ];
}
