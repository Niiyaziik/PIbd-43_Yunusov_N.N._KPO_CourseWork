<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticker',
        'name',
        'isin',
        'is_available',
    ];
    
    protected $casts = [
        'is_available' => 'boolean',
    ];
}

