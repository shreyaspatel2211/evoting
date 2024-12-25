<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Event extends Model
{
    use HasFactory;

    public function scopeActive($query)
    {
        return $query->where('end_date', '>=', Carbon::today());
    }
}
