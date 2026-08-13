<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'balance',
        'locked_amount',
        'total_deposited',
        'total_won',
        'total_withdrawn',
    ];

    protected $casts = [
        'balance'          => 'integer',
        'locked_amount'    => 'integer',
        'total_deposited'  => 'integer',
        'total_won'        => 'integer',
        'total_withdrawn'  => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class)->latest();
    }

    /**
     * Crédite le solde disponible et enregistre la transaction.
     */
    public function credit(int $amount, string $type, string $description = '', ?string $reference = null, ?string $referenceType = null): WalletTransaction
    {
        $before = $this->balance;
        $this->increment('balance', $amount);
        if ($type === 'deposit') {
            $this->increment('total_deposited', $amount);
        }
        $this->refresh();

        return $this->transactions()->create([
            'type'           => $type,
            'amount'         => $amount,
            'balance_before' => $before,
            'balance_after'  => $this->balance,
            'reference'      => $reference,
            'reference_type' => $referenceType,
            'description'    => $description,
        ]);
    }

    /**
     * Débite le solde disponible et verrouille le montant (pour un pari).
     */
    public function lockForBet(int $amount, string $description = '', ?string $reference = null): WalletTransaction
    {
        if ($this->balance < $amount) {
            throw new \Exception('Solde insuffisant.');
        }
        $before = $this->balance;
        $this->decrement('balance', $amount);
        $this->increment('locked_amount', $amount);
        $this->refresh();

        return $this->transactions()->create([
            'type'           => 'bet_place',
            'amount'         => $amount,
            'balance_before' => $before,
            'balance_after'  => $this->balance,
            'reference'      => $reference,
            'reference_type' => 'App\\Models\\Bet',
            'description'    => $description,
        ]);
    }

    /**
     * Déverrouille le montant misé (gagné ou remboursé) et crédite le solde.
     */
    public function unlockAndCredit(int $lockedAmount, int $creditAmount, string $type, string $description = '', ?string $reference = null): WalletTransaction
    {
        $before = $this->balance;
        $this->decrement('locked_amount', $lockedAmount);
        $this->increment('balance', $creditAmount);

        if ($type === 'bet_win') {
            $this->increment('total_won', $creditAmount);
        }

        $this->refresh();

        return $this->transactions()->create([
            'type'           => $type,
            'amount'         => $creditAmount,
            'balance_before' => $before,
            'balance_after'  => $this->balance,
            'reference'      => $reference,
            'reference_type' => 'App\\Models\\Bet',
            'description'    => $description,
        ]);
    }

    /**
     * Débit pour retrait (montant brut, la fee est calculée ailleurs).
     */
    public function debitForWithdrawal(int $amount, int $fee, string $description = '', ?string $reference = null): WalletTransaction
    {
        if ($this->balance < $amount) {
            throw new \Exception('Solde insuffisant pour le retrait.');
        }
        $before = $this->balance;
        $this->decrement('balance', $amount);
        $this->increment('total_withdrawn', $amount - $fee);
        $this->refresh();

        return $this->transactions()->create([
            'type'           => 'withdrawal',
            'amount'         => $amount,
            'balance_before' => $before,
            'balance_after'  => $this->balance,
            'reference'      => $reference,
            'reference_type' => 'App\\Models\\Withdrawal',
            'description'    => $description,
        ]);
    }

    // ─── Méthodes P2P Tickets ─────────────────────────────────────────────────

    /**
     * Verrouille la mise lors de la création d'un ticket P2P.
     */
    public function lockTicket(int $amount, string $description = '', ?string $reference = null): WalletTransaction
    {
        if ($this->balance < $amount) {
            throw new \Exception('Solde insuffisant.');
        }
        $before = $this->balance;
        $this->decrement('balance', $amount);
        $this->increment('locked_amount', $amount);
        $this->refresh();

        return $this->transactions()->create([
            'type'           => 'bet_lock',
            'amount'         => $amount,
            'balance_before' => $before,
            'balance_after'  => $this->balance,
            'reference'      => $reference,
            'reference_type' => 'App\\Models\\BetTicket',
            'description'    => $description,
        ]);
    }

    /**
     * Déverrouille la mise (annulation ou remboursement) et recrédite le solde disponible.
     */
    public function unlockTicket(int $amount, string $type, string $description = '', ?string $reference = null): WalletTransaction
    {
        $before = $this->balance;
        $this->decrement('locked_amount', max(0, $amount));
        $this->increment('balance', $amount);
        $this->refresh();

        return $this->transactions()->create([
            'type'           => $type, // bet_cancel | bet_refund
            'amount'         => $amount,
            'balance_before' => $before,
            'balance_after'  => $this->balance,
            'reference'      => $reference,
            'reference_type' => 'App\\Models\\BetTicket',
            'description'    => $description,
        ]);
    }

    /**
     * Règlement gagnant : déverrouille la mise + crédite le gain net.
     */
    public function settleTicketWin(int $lockedAmount, int $netPayout, string $description = '', ?string $reference = null): WalletTransaction
    {
        $before = $this->balance;
        $this->decrement('locked_amount', $lockedAmount);
        $this->increment('balance', $netPayout);
        $this->increment('total_won', $netPayout - $lockedAmount); // profit réel
        $this->refresh();

        return $this->transactions()->create([
            'type'           => 'bet_settlement',
            'amount'         => $netPayout,
            'balance_before' => $before,
            'balance_after'  => $this->balance,
            'reference'      => $reference,
            'reference_type' => 'App\\Models\\BetTicket',
            'description'    => $description,
        ]);
    }

    /**
     * Règlement perdant : déverrouille la mise (qui reste perdue).
     */
    public function settleTicketLoss(int $lockedAmount, string $description = '', ?string $reference = null): WalletTransaction
    {
        $before = $this->balance;
        $this->decrement('locked_amount', $lockedAmount);
        $this->refresh();

        return $this->transactions()->create([
            'type'           => 'bet_settlement',
            'amount'         => $lockedAmount,
            'balance_before' => $before,
            'balance_after'  => $this->balance,
            'reference'      => $reference,
            'reference_type' => 'App\\Models\\BetTicket',
            'description'    => '[PERTE] ' . $description,
        ]);
    }
}
