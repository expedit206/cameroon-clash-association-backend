<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modèle CaptainElection.
 * 
 * Représente un scrutin d'élection de capitaine pour un clan particulier lors d'un tournoi.
 * Les membres du clan votent parmi des candidats pour désigner le capitaine officiel.
 * 
 * @property int $id
 * @property string $clan_tag Tag CoC du clan concerné par le scrutin.
 * @property int $competition_id Édition du tournoi.
 * @property string $status Statut de l'élection ('open', 'closed', 'cancelled').
 * @property int|null $winner_id ID de l'utilisateur vainqueur élu.
 * @property \Carbon\Carbon $ends_at Date et heure de clôture automatique du scrutin.
 */
class CaptainElection extends Model
{
    protected $fillable = [
        'clan_tag',
        'competition_id',
        'status',
        'winner_id',
        'ends_at'
    ];

    protected $casts = [
        'ends_at' => 'datetime'
    ];

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    public function winner()
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    public function votes()
    {
        return $this->hasMany(CaptainVote::class, 'election_id');
    }

    public function isOpen()
    {
        return $this->status === 'open' && $this->ends_at->isFuture();
    }
}
