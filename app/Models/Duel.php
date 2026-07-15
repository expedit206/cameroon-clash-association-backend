<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Modèle Duel.
 * 
 * Représente un affrontement individuel entre deux joueurs (un hôte et un invité)
 * de même niveau d'HDV au cours d'un match de tournoi (1 match = 5 duels).
 * Permet de suivre le nombre d'étoiles, la destruction et de décerner le titre de MVP.
 * 
 * @property int $id
 * @property int $match_id ID du match de tournoi global.
 * @property int $hdv_level Niveau d'HDV de l'affrontement (14 à 18).
 * @property int $player_home_id ID de l'utilisateur joueur du clan hôte.
 * @property int $player_away_id ID de l'utilisateur joueur du clan invité.
 * @property int|null $stars_home Étoiles inscrites par le joueur hôte.
 * @property int|null $stars_away Étoiles inscrites par le joueur invité.
 * @property float|null $destruction_home Pourcentage de destruction du joueur hôte.
 * @property float|null $destruction_away Pourcentage de destruction du joueur invité.
 * @property bool $is_mvp_home Si le joueur hôte est nommé MVP du duel.
 * @property bool $is_mvp_away Si le joueur invité est nommé MVP du duel.
 * @property string|null $winner Résultat ('home', 'away', 'draw').
 */
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
