<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'rule_template',
    ];

    protected $casts = [
        'rule_template' => 'array',
    ];
}
