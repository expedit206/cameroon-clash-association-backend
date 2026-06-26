<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Clan extends Model
{
    use HasFactory;

    protected $fillable = [
        'tag_coc',
        'name',
        'captain_id',
        'badge_url',
        'clan_level',
        'status',
        'rejection_reason',
        'total_points',
        'seasons_played',
        'titles_won',
    ];

    /**
     * Get the captain of the clan.
     */
    public function captain()
    {
        return $this->belongsTo(User::class, 'captain_id');
    }

    /**
     * Get the clan's registrations.
     */
    public function registrations()
    {
        return $this->hasMany(ClanRegistration::class);
    }

    /**
     * Get the matches where this clan plays as home.
     */
    public function homeMatches()
    {
        return $this->hasMany(TournamentMatch::class, 'clan_home_id');
    }

    /**
     * Get the matches where this clan plays as away.
     */
    public function awayMatches()
    {
        return $this->hasMany(TournamentMatch::class, 'clan_away_id');
    }
}
