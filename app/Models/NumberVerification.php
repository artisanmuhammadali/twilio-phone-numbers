<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NumberVerification extends Model
{
    protected $fillable =[
        'number',
        'code',
        'callSid',
        'status',
        'response',
        'user_id',
        'voice',
        'code_as_text',
        'formated_code',
        'connection_id'
    ];
}
