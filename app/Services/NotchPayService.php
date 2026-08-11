<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use NotchPay\NotchPay;
use NotchPay\Payment as NotchPayment;
use App\Models\Payment;
use App\Models\ClanRegistration;

class NotchPayService
{
    public function __construct()
    {
        NotchPay::setApiKey(env('NOTCHPAY_API_KEY'));
        NotchPay::setPrivateKey(env('NOTCHPAY_PRIVATE_KEY'));
    }

    /**
     * Initializes a NotchPay transaction
     */
    public function initializePayment(array $data)
    {
        try {
            $response = NotchPayment::initialize([
                'amount' => $data['amount'],
                'email' => $data['email'],
                'currency' => $data['currency'] ?? 'XAF',
                'reference' => $data['reference'],
                'callback' => env('NOTCHPAY_CALLBACK_URL', url('/api/notchpay/callback')),
                'description' => $data['description'] ?? 'Frais d\'inscription CCA',
                'metadata' => $data['metadata'] ?? [],
            ]);

            return $response;
        } catch (\Exception $e) {
            Log::error('NotchPay Initialize Exception: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Verifies a NotchPay transaction
     */
    public function verifyPayment($reference)
    {
        try {
            return NotchPayment::verify($reference);
        } catch (\Exception $e) {
            Log::error('NotchPay Verify Exception: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Traitement spécifique des dépôts Clash Bet Wallet (Domaine Wallet uniquement).
     * Ne touche AUCUNEMENT à la table `payments` réservée aux inscriptions des clans.
     */
    public function checkAndFulfillWalletDeposit(string $reference): string
    {
        try {
            $notchPayment = $this->verifyPayment($reference);
            
            // Transformer tout en tableau PHP associatif pour éliminer les problèmes de typage objet/tableau
            $raw = json_decode(json_encode($notchPayment), true);
            Log::info("NotchPay Wallet Deposit raw response for $reference: " . json_encode($raw));

            $transaction = $raw['transaction'] ?? $raw['payment'] ?? $raw;
            $status = strtolower($transaction['status'] ?? $raw['status'] ?? '');

            // Si le statut vaut 'ok' (confirmation HTTP), extraire le statut réel de la transaction
            if (($status === 'ok' || empty($status)) && isset($transaction['status'])) {
                $status = strtolower($transaction['status']);
            }

            Log::info("NotchPay Wallet Deposit check reference $reference, parsed status: $status");

            if (in_array($status, ['complete', 'completed', 'success', 'successful', 'confirmed', 'ok'])) {
                $metadata = $transaction['metadata'] ?? $raw['metadata'] ?? [];

                // Récupérer le merchant reference s'il existe
                $merchantRef = $metadata['reference'] ?? $transaction['reference'] ?? $reference;

                // Récupérer les infos depuis le cache
                $depositInfo = cache()->get("wallet_deposit_{$merchantRef}") 
                    ?? cache()->get("wallet_deposit_{$reference}") 
                    ?? [];

                $userId = $depositInfo['user_id'] 
                    ?? $metadata['user_id'] 
                    ?? $transaction['metadata']['user_id'] ?? null;

                $amount = $depositInfo['amount'] 
                    ?? $transaction['amount'] 
                    ?? $raw['amount'] ?? null;

                if (!$userId || !$amount) {
                    Log::error("NotchPay Wallet Deposit: Informations manquantes (User ID: " . json_encode($userId) . ", Amount: " . json_encode($amount) . ") pour $reference");
                    return 'error';
                }

                $user = \App\Models\User::find($userId);
                if ($user) {
                    $wallet = $user->getOrCreateWallet();

                    // Empêcher les doublons
                    $alreadyCredited = $wallet->transactions()
                        ->whereIn('reference', array_filter([$reference, $merchantRef]))
                        ->exists();

                    if (!$alreadyCredited) {
                        $wallet->credit(
                            (int) $amount,
                            'deposit',
                            "Rechargement NotchPay (#{$merchantRef})",
                            $merchantRef,
                            'App\\Models\\Wallet'
                        );
                        Log::info("NotchPay: Solde du Wallet #{$wallet->id} (User #{$user->id}) crédité avec succès (+{$amount} FCFA).");
                    }
                    return 'confirmed';
                }
            } elseif (in_array($status, ['failed', 'expired', 'canceled', 'cancelled'])) {
                return 'failed';
            }

            return $status ?: 'pending';
        } catch (\Exception $e) {
            Log::error("Erreur vérification dépôt wallet NotchPay ($reference): " . $e->getMessage());
            return 'error';
        }
    }

    /**
     * Check status and fulfill for Tournament Clan Registrations (Domaine Tournois uniquement)
     */
    public function checkAndFulfillTransaction($reference): string
    {
        $paymentRecord = Payment::where('reference', $reference)->first();
        if (!$paymentRecord) {
            Log::warning("Payment with reference $reference not found locally in payments table.");
            return 'not_found';
        }

        if ($paymentRecord->status === 'confirmed') {
            return 'confirmed';
        }

        try {
            $notchPayment = $this->verifyPayment($reference);

            $transaction = $notchPayment->transaction ?? $notchPayment->payment ?? $notchPayment;
            $status = strtolower($transaction->status ?? $notchPayment->status ?? '');

            if (($status === 'ok' || empty($status)) && isset($transaction->status)) {
                $status = strtolower($transaction->status);
            }

            Log::info("Checking Tournament Payment reference $reference, parsed status: $status");

            if (in_array($status, ['complete', 'success', 'completed', 'successful', 'confirmed', 'ok'])) {
                $paymentRecord->update([
                    'status'       => 'confirmed',
                    'confirmed_at' => now(),
                    'notes'        => 'Validé automatiquement via NotchPay Callback/Webhook'
                ]);

                if ($paymentRecord->clan_registration_id) {
                    $this->checkAndFulfillRegistration($paymentRecord->clan_registration_id);
                }

                return 'confirmed';
            } elseif (in_array($status, ['failed', 'expired', 'canceled', 'cancelled'])) {
                $paymentRecord->update(['status' => 'failed']);
                return 'failed';
            }

            return $status ?: 'pending';
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            Log::error("Error checking transaction $reference: " . $msg);

            if (str_contains(strtolower($msg), 'not found')) {
                return 'reference_not_found';
            }

            return 'error';
        }
    }

    /**
     * Checks if all required payments for the registration are confirmed, and updates its status as well.
     */
    public function checkAndFulfillRegistration($registrationId): void
    {
        $registration = ClanRegistration::find($registrationId);
        if (!$registration) {
            return;
        }

        // Fetch roster players
        $rosterPlayers = $registration->players()->get();
        $starters = $rosterPlayers->filter(fn($p) => !$p->is_substitute);

        // Check if all starters have confirmed payments
        $allStartersPaid = true;
        foreach ($starters as $starter) {
            $hasPaid = Payment::where('clan_registration_id', $registrationId)
                ->where('user_id', $starter->player_id)
                ->where('status', 'confirmed')
                ->exists();
            if (!$hasPaid) {
                $allStartersPaid = false;
                break;
            }
        }

        if ($allStartersPaid && $starters->count() >= 5) {
            // All 5 starters have paid, auto-update registration to "confirmed" (or paid)
            $registration->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'notes' => 'Roster validé automatiquement après réception de tous les paiements NotchPay'
            ]);
            Log::info("Registration $registrationId fully confirmed automatically.");
        }
    }
}
