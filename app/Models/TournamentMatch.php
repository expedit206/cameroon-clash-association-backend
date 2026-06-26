<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TournamentMatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'competition_id',
        'round',
        'match_number',
        'clan_home_id',
        'clan_away_id',
        'scheduled_at',
        'host_clan_id',
        'status',
        'winner_clan_id',
        'forfeit_clan_id',
        'total_stars_home',
        'total_stars_away',
        'total_destruction_home',
        'total_destruction_away',
        'result_screenshot_url',
        'validated_by',
        'validated_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'validated_at' => 'datetime',
        'match_number' => 'integer',
        'total_stars_home' => 'integer',
        'total_stars_away' => 'integer',
        'total_destruction_home' => 'decimal:2',
        'total_destruction_away' => 'decimal:2',
    ];

    /**
     * Get the competition this match belongs to.
     */
    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    /**
     * Get the home clan.
     */
    public function clanHome()
    {
        return $this->belongsTo(Clan::class, 'clan_home_id');
    }

    /**
     * Get the away clan.
     */
    public function clanAway()
    {
        return $this->belongsTo(Clan::class, 'clan_away_id');
    }

    /**
     * Get the clan that hosts the war.
     */
    public function hostClan()
    {
        return $this->belongsTo(Clan::class, 'host_clan_id');
    }

    /**
     * Get the individual duels for this match.
     */
    public function duels()
    {
        return $this->hasMany(Duel::class, 'match_id');
    }

    /**
     * Get the winner clan.
     */
    public function winnerClan()
    {
        return $this->belongsTo(Clan::class, 'winner_clan_id');
    }

    /**
     * Get the admin who validated the result.
     */
    public function validatedBy()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /**
     * Get the claims for this match.
     */
    public function claims()
    {
        return $this->hasMany(Claim::class, 'match_id');
    }
}
