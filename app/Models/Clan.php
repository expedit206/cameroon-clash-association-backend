<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Modèle Clan.
 * 
 * Représente un clan Clash of Clans enregistré sur la plateforme.
 * Contient les statistiques du clan pour le classement et la liaison vers son capitaine.
 * 
 * @property int $id
 * @property string $tag_coc Tag unique du clan (#XXXXX).
 * @property string $name Nom officiel du clan.
 * @property int $captain_id ID de l'utilisateur désigné comme capitaine du clan.
 * @property string|null $badge_url Lien vers le visuel / logo du clan.
 * @property int|null $clan_level Niveau officiel dans Clash of Clans.
 * @property string $status Statut d'approbation administrative ('pending', 'validated', 'rejected').
 * @property string|null $rejection_reason Raison du refus le cas échéant.
 * @property int $total_points Points totaux cumulés pour le leaderboard.
 * @property int $seasons_played Nombre total de saisons jouées par ce clan.
 * @property int $titles_won Nombre total de championnats remportés.
 */
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
