<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Bet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'market_id',
        'option_id',
        'amount',
        'executed_odds',
        'potential_payout',
        'status',
        'actual_payout',
        'settled_at',
    ];

    protected $casts = [
        'amount'          => 'integer',
        'executed_odds'   => 'decimal:2',
        'potential_payout'=> 'integer',
        'actual_payout'   => 'integer',
        'settled_at'      => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function market()
    {
        return $this->belongsTo(BetMarket::class, 'market_id');
    }

    public function option()
    {
        return $this->belongsTo(BetOption::class, 'option_id');
    }

    /**
     * Scope: paris actifs (non encore réglés).
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: paris d'un utilisateur.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
