<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Modèle Competition.
 * 
 * Définit une saison de tournoi sur la plateforme.
 * Regroupe les paramètres comme le format (limite directe/groupe), les tarifs d'inscription,
 * les limites d'équipes (généralement 16 max) et les récompenses (prizepool).
 * 
 * @property int $id
 * @property string $name Nom de la compétition.
 * @property string $slug Slug URL unique.
 * @property int $season_number Numéro de saison.
 * @property string $format Format du tournoi ('elimination_directe', 'groupes').
 * @property int $max_teams Nombre maximum de clans admissibles.
 * @property int $registration_fee Frais d'inscription globaux en FCFA.
 * @property string $status Statut du tournoi ('draft', 'open', 'closed', 'in_progress', 'finished').
 * @property \Carbon\Carbon $registration_opens_at Date d'ouverture des inscriptions.
 * @property \Carbon\Carbon $registration_closes_at Date de fermeture des inscriptions.
 * @property \Carbon\Carbon|null $starts_at Date de début du tournoi.
 * @property \Carbon\Carbon|null $ends_at Date de fin d'événement.
 * @property int $prize_1st Récompense pour le vainqueur en FCFA.
 * @property int $prize_2nd Récompense pour le finaliste en FCFA.
 * @property int $prize_3rd Récompense pour le troisième en FCFA.
 */
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
