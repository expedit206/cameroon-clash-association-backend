<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle MatchSubstitution.
 * 
 * Représente une demande de remplacement de joueur pour un match spécifique du tournoi.
 * Un capitaine de clan peut soumettre un remplacement entre un joueur titulaire du match (sortant)
 * et un joueur remplaçant validé de son roster (entrant).
 * 
 * @property int $id
 * @property int $tournament_match_id Match au cours duquel se déroule le remplacement.
 * @property int $clan_id Clan qui effectue le remplacement.
 * @property int $outgoing_player_id Joueur titulaire sortant.
 * @property int $incoming_player_id Joueur remplaçant entrant.
 * @property string $status Statut de la demande ('pending', 'approved', 'rejected').
 * @property bool $fee_paid Indique si les frais de remplacement (si applicables) ont été réglés.
 */
class MatchSubstitution extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_match_id',
        'clan_id',
        'outgoing_player_id',
        'incoming_player_id',
        'status',
        'fee_paid',
    ];

    protected $casts = [
        'fee_paid' => 'boolean',
    ];

    /**
     * Obtient le match du tournoi concerné par cette substitution.
     */
    public function tournamentMatch(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class, 'tournament_match_id');
    }

    /**
     * Obtient le clan ayant demandé cette substitution.
     */
    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    /**
     * Obtient le joueur titulaire qui doit sortir de la composition pour ce match.
     */
    public function outgoingPlayer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'outgoing_player_id');
    }

    /**
     * Obtient le joueur remplaçant qui intègre la composition du match.
     */
    public function incomingPlayer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'incoming_player_id');
    }
}
