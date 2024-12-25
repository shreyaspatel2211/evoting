<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CallbackRequest extends Model
{
    use HasFactory;
    protected $table = "callback_requests";
    protected $fillable = [
        'request',
    ];
}
