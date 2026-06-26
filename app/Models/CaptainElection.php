<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
