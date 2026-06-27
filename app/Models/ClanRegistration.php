<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClanRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'clan_id',
        'competition_id',
        'status',
        'seed_number',
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
