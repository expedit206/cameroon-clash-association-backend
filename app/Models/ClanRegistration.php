<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Modèle ClanRegistration.
 * 
 * Représente l'inscription d'un clan à une édition de compétition (tournoi).
 * Suit l'état de l'inscription (attente paiement, validée par l'admin, disqualifiée),
 * la référence du paiement NotchPay global, ainsi que la date de validation.
 * 
 * @property int $id
 * @property int $clan_id Clan inscrit.
 * @property int $competition_id Compétition concernée.
 * @property string $status Statut de l'inscription ('pending_payment', 'paid', 'confirmed', 'disqualified').
 * @property int|null $seed_number seed/place dans le bracket final (1 à 16).
 * @property \Carbon\Carbon|null $paid_at Date de confirmation du paiement en base.
 * @property int|null $confirmed_by ID de l'administrateur ayant vérifié/confirmé l'inscription.
 * @property \Carbon\Carbon|null $confirmed_at Date de confirmation administrative.
 * @property string|null $payment_reference Référence du paiement global NotchPay/MeSomb.
 * @property \Carbon\Carbon|null $registered_at Date d'inscription complète finale.
 */
class ClanRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'clan_id',
        'competition_id',
        'status',
        'seed_number',
        'group',
        'paid_at',
        'confirmed_by',
        'confirmed_at',
        'payment_reference',
        'registered_at',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'registered_at' => 'datetime',
        'seed_number' => 'integer',
    ];

    /**
     * Get the clan being registered.
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
     * Get the players (team composition) for this registration.
     */
    public function players()
    {
        return $this->hasMany(RegistrationPlayer::class);
    }

    /**
     * Get the admin who confirmed the registration.
     */
    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Get the payments associated with this registration (individual fees).
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
