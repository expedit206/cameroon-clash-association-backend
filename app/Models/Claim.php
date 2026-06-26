<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Claim extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'submitted_by',
        'clan_id',
        'description',
        'evidence_urls',
        'status',
        'resolved_by',
        'resolution_note',
        'resolved_at',
        'expires_at',
    ];

    protected $casts = [
        'evidence_urls' => 'array',
        'resolved_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the match this claim is about.
     */
    public function match()
    {
        return $this->belongsTo(TournamentMatch::class, 'match_id');
    }

    /**
     * Get the user who submitted the claim.
     */
    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Get the clan that filed the claim.
     */
    public function clan()
    {
        return $this->belongsTo(Clan::class);
    }

    /**
     * Get the admin who resolved the claim.
     */
    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
