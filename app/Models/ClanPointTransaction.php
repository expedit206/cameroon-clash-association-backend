<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClanPointTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'clan_id',
        'competition_id',
        'points',
        'reason',
        'match_id',
    ];

    /**
     * Get the clan that received the points.
     */
    public function clan()
    {
        return $this->belongsTo(Clan::class);
    }

    /**
     * Get the competition.
     */
    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    /**
     * Get the match that triggered the points (if any).
     */
    public function match()
    {
        return $this->belongsTo(TournamentMatch::class, 'match_id');
    }
}
