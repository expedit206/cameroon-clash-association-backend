<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Duel extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'hdv_level',
        'player_home_id',
        'player_away_id',
        'stars_home',
        'stars_away',
        'destruction_home',
        'destruction_away',
        'is_mvp_home',
        'is_mvp_away',
        'winner',
    ];

    protected $casts = [
        'is_mvp_home' => 'boolean',
        'is_mvp_away' => 'boolean',
        'hdv_level' => 'integer',
        'stars_home' => 'integer',
        'stars_away' => 'integer',
        'destruction_home' => 'decimal:2',
        'destruction_away' => 'decimal:2',
    ];

    /**
     * Get the match this duel belongs to.
     */
    public function match()
    {
        return $this->belongsTo(TournamentMatch::class, 'match_id');
    }

    /**
     * Get the home player.
     */
    public function playerHome()
    {
        return $this->belongsTo(User::class, 'player_home_id');
    }

    /**
     * Get the away player.
     */
    public function playerAway()
    {
        return $this->belongsTo(User::class, 'player_away_id');
    }
}
