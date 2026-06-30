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
     * Check status and fulfill if successful
     */
    public function checkAndFulfillTransaction($reference): string
    {
        $paymentRecord = Payment::where('reference', $reference)->first();
        if (!$paymentRecord) {
            Log::warning("Payment with reference $reference not found locally.");
            return 'not_found';
        }

        if ($paymentRecord->status === 'confirmed') {
            return 'confirmed';
        }

        try {
            $notchPayment = $this->verifyPayment($reference);

            $status = strtolower($notchPayment->status ?? '');
            if (empty($status) && isset($notchPayment->transaction->status)) {
                $status = strtolower($notchPayment->transaction->status);
            }

            Log::info("Checking NotchPay reference $reference, status: $status");

            if (in_array($status, ['complete', 'success', 'completed', 'successful'])) {
                // Confirm the payment locally
                $paymentRecord->update([
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'notes' => 'Validé automatiquement via NotchPay Callback/Webhook'
                ]);

                // Fulfill registration check (e.g. check if all starters have paid)
                $this->checkAndFulfillRegistration($paymentRecord->clan_registration_id);

                return 'confirmed';
            } elseif (in_array($status, ['failed', 'expired'])) {
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
