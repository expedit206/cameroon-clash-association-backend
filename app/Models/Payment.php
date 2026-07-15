<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Modèle Payment.
 * 
 * Représente un paiement individuel ou un frais d'inscription pour un joueur dans un tournoi.
 * Les détails de transaction sont interfacés avec la passerelle NotchPay / MeSomb.
 * 
 * @property int $id
 * @property int $clan_registration_id Lien vers la table clan_registrations.
 * @property int|null $user_id ID de l'utilisateur qui a payé.
 * @property string $player_tag Tag CoC du joueur concerné par ce paiement.
 * @property int $amount Montant de la transaction (généralement 1000 FCFA par joueur).
 * @property string $currency Devise utilisée (XAF).
 * @property string $reference Référence unique générée par/pour NotchPay.
 * @property string $status État du paiement ('pending', 'confirmed', 'rejected').
 * @property int|null $confirmed_by ID de l'administrateur de validation manuelle si applicable.
 * @property \Carbon\Carbon|null $confirmed_at Date de validation du paiement.
 * @property string|null $payment_method Moyen utilisé (ex: mtn_momo, orange_money).
 * @property string|null $phone_number Numéro Momo payeur.
 * @property string|null $notes Remarques optionnelles d'administration.
 */
class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'clan_registration_id',
        'user_id',
        'player_tag',
        'amount',
        'currency',
        'reference',
        'status',
        'confirmed_by',
        'confirmed_at',
        'payment_method',
        'phone_number',
        'notes',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'amount' => 'integer',
    ];

    /**
     * Get the registration associated with this payment.
     */
    public function registration()
    {
        return $this->belongsTo(ClanRegistration::class, 'clan_registration_id');
    }

    /**
     * Get the admin who confirmed the payment.
     */
    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
