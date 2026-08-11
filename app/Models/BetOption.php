<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BetOption extends Model
{
    protected $fillable = [
        'market_id',
        'label',
        'clan_id',
        'current_pool',
        'reserved_payout',
    ];

    protected $casts = [
        'current_pool'    => 'integer',
        'reserved_payout' => 'integer',
    ];

    public function market()
    {
        return $this->belongsTo(BetMarket::class, 'market_id');
    }

    public function clan()
    {
        return $this->belongsTo(Clan::class);
    }

    public function bets()
    {
        return $this->hasMany(Bet::class, 'option_id');
    }
}
