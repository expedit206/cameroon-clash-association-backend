<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BetTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_number',
        'market_id',
        'creator_id',
        'creator_option_id',
        'side',
        'rule_version',
        'taker_id',
        'taker_option_id',
        'amount',
        'odds',
        'gross_payout',
        'commission_percentage',
        'commission_amount',
        'net_payout',
        'status',
        'winner_id',
        'risk_score',
        'review_required',
        'expires_at',
        'matched_at',
        'settled_at',
    ];

    protected $casts = [
        'amount'                => 'integer',
        'rule_version'          => 'integer',
        'odds'                  => 'decimal:2',
        'gross_payout'          => 'integer',
        'commission_percentage' => 'decimal:2',
        'commission_amount'     => 'integer',
        'net_payout'            => 'integer',
        'risk_score'            => 'integer',
        'review_required'       => 'boolean',
        'expires_at'            => 'datetime',
        'matched_at'            => 'datetime',
        'settled_at'            => 'datetime',
    ];

    // ─── Relations ────────────────────────────────────────────────────────────

    public function market()
    {
        return $this->belongsTo(BetMarket::class, 'market_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function taker()
    {
        return $this->belongsTo(User::class, 'taker_id');
    }

    public function creatorOption()
    {
        return $this->belongsTo(BetOption::class, 'creator_option_id');
    }

    public function takerOption()
    {
        return $this->belongsTo(BetOption::class, 'taker_option_id');
    }

    public function winner()
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeMatched($query)
    {
        return $query->where('status', 'matched');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['open', 'matched', 'locked']);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('creator_id', $userId)->orWhere('taker_id', $userId);
        });
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isMatched(): bool
    {
        return $this->status === 'matched';
    }

    public function isSettled(): bool
    {
        return $this->status === 'settled';
    }

    public function canBeCancelled(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Génère un numéro de ticket lisible unique.
     */
    public static function generateTicketNumber(): string
    {
        do {
            $number = 'TCK-' . strtoupper(substr(md5(uniqid()), 0, 6));
        } while (static::where('ticket_number', $number)->exists());

        return $number;
    }
}
