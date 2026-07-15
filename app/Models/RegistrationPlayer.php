<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Modèle RegistrationPlayer.
 * 
 * Représente un joueur (titulaire ou remplaçant) composant le roster d'un clan inscrit à un tournoi.
 * Contient la position d'HDV (entre 14 et 18) et si le joueur sert de remplaçant.
 * 
 * @property int $id
 * @property int $clan_registration_id ID de la relation d'inscription du clan.
 * @property int $player_id ID de l'utilisateur joueur.
 * @property int $hdv_position Niveau d'HDV assigné pour le roster (14 à 18).
 * @property bool $is_substitute Si le joueur est sur le banc / remplaçant.
 * @property \Carbon\Carbon|null $verified_at Date de vérification de l'éligibilité.
 */
class RegistrationPlayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'clan_registration_id',
        'player_id',
        'hdv_position',
        'is_substitute',
        'verified_at',
    ];

    protected $casts = [
        'is_substitute' => 'boolean',
        'verified_at' => 'datetime',
        'hdv_position' => 'integer',
    ];

    /**
     * Get the registration this player belongs to.
     */
    public function registration()
    {
        return $this->belongsTo(ClanRegistration::class, 'clan_registration_id');
    }

    /**
     * Get the user account of the player.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'player_id');
    }
}
