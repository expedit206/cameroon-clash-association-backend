<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Competition extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'season_number',
        'format',
        'max_teams',
        'registration_fee',
        'status',
        'registration_opens_at',
        'registration_closes_at',
        'starts_at',
        'ends_at',
        'prize_1st',
        'prize_2nd',
        'prize_3rd',
    ];

    protected $casts = [
        'registration_opens_at' => 'datetime',
        'registration_closes_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'max_teams' => 'integer',
        'registration_fee' => 'integer',
        'prize_1st' => 'integer',
        'prize_2nd' => 'integer',
        'prize_3rd' => 'integer',
    ];

    /**
     * Get the registrations for this competition.
     */
    public function registrations()
    {
        return $this->hasMany(ClanRegistration::class);
    }

    /**
     * Get the matches for this competition.
     */
    public function matches()
    {
        return $this->hasMany(TournamentMatch::class);
    }
}
