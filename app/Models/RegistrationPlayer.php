<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
