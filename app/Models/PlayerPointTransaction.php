<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PlayerPointTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'player_id',
        'duel_id',
        'competition_id',
        'points',
        'reason',
    ];

    /**
     * Get the player that received the points.
     */
    public function player()
    {
        return $this->belongsTo(User::class, 'player_id');
    }

    /**
     * Get the duel that triggered the points.
     */
    public function duel()
    {
        return $this->belongsTo(Duel::class);
    }

    /**
     * Get the competition.
     */
    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }
}
