<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'tag_coc',
        'name',
        'password',
        'role',
        'hdv_level',
        'current_clan_tag',
        'phone_whatsapp',
        'is_active',
        'is_validated',
        'validated_at',
        'profile_status',
        'status',
        'screenshot_proof',
        'league_icon',
        'exp_level'
    ];

    protected $casts = [
        'is_validated' => 'boolean',
        'validated_at' => 'datetime',
    ];

    /**
     * Get the player's votes given in elections.
     */
    public function givenVotes()
    {
        return $this->hasMany(CaptainVote::class, 'voter_id');
    }

    /**
     * Get the player's votes received in elections.
     */
    public function receivedVotes()
    {
        return $this->hasMany(CaptainVote::class, 'candidate_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'hdv_level' => 'integer',
        ];
    }

    /**
     * Get the clan owned by the user (as captain).
     */
    public function capitained_clan()
    {
        return $this->hasOne(Clan::class, 'captain_id');
    }

    /**
     * Get the player's registrations (intermediate RegistrationPlayer records).
     */
    public function registration_player()
    {
        return $this->hasMany(RegistrationPlayer::class, 'player_id');
    }

    /**
     * Shortcut to get the actual ClanRegistration objects the player belongs to.
     */
    public function registrations()
    {
        return $this->hasManyThrough(
            ClanRegistration::class,
            RegistrationPlayer::class,
            'player_id',
            'id',
            'id',
            'clan_registration_id'
        );
    }
}
