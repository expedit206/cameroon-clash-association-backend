<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BetMarket extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'title',
        'description',
        'category',
        'rule_definition',
        'rule_version',
        'evaluation_snapshot',
        'winning_side',
        'status',
        'liquidity_weight',
        'total_pool',
        'cancelled_reason',
        'betting_closes_at',
    ];

    protected $casts = [
        'liquidity_weight'    => 'integer',
        'total_pool'          => 'integer',
        'rule_version'        => 'integer',
        'rule_definition'     => 'array',
        'evaluation_snapshot' => 'array',
        'betting_closes_at'   => 'datetime',
    ];

    /**
     * Le match CCA auquel ce marché est lié.
     */
    public function match()
    {
        return $this->belongsTo(TournamentMatch::class, 'match_id');
    }

    /**
     * Les options de pari (Équipe A / Équipe B).
     */
    public function options()
    {
        return $this->hasMany(BetOption::class, 'market_id');
    }

    /**
     * Tous les paris placés sur ce marché.
     */
    public function bets()
    {
        return $this->hasMany(Bet::class, 'market_id');
    }

    /**
     * Scope: marchés ouverts.
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /**
     * Scope: marchés accessibles avec les données du match et des options.
     */
    public function scopeWithDetails($query)
    {
        return $query->with([
            'match.clanHome',
            'match.clanAway',
            'options',
        ]);
    }

    /**
     * Vérifie si le marché est actif pour les paris.
     */
    public function isOpen(): bool
    {
        if ($this->status !== 'open') {
            return false;
        }
        if ($this->betting_closes_at && now()->gte($this->betting_closes_at)) {
            return false;
        }
        return true;
    }
}
