<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaptainVote extends Model
{
    protected $fillable = [
        'election_id',
        'voter_id',
        'candidate_id'
    ];

    public function election()
    {
        return $this->belongsTo(CaptainElection::class, 'election_id');
    }

    public function voter()
    {
        return $this->belongsTo(User::class, 'voter_id');
    }

    public function candidate()
    {
        return $this->belongsTo(User::class, 'candidate_id');
    }
}
