<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\NotchPayService;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotchPayController extends Controller
{
    protected NotchPayService $notchPayService;

    public function __construct(NotchPayService $notchPayService)
    {
        $this->notchPayService = $notchPayService;
    }

    /**
     * Handles the GET callback redirect from NotchPay after web payment flow.
     */
    public function callback(Request $request): RedirectResponse
    {
        $reference = $request->query('reference') ?? $request->query('trx_reference');
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        Log::info('[NotchPay] Callback received with reference: ' . ($reference ?? 'NONE'));

        if (!$reference) {
            Log::warning('[NotchPay] Callback: Reference was missing in request parameters.');
            return redirect($frontendUrl . '/clash-bet/wallet?payment=error&message=reference_missing');
        }

        // 1. Déterminer si c'est un dépôt de wallet (par préfixe, cache, ou inspection des métadonnées NotchPay)
        $isWalletDeposit = str_starts_with($reference, 'WALLET-') || cache()->has("wallet_deposit_{$reference}");

        if (!$isWalletDeposit) {
            try {
                $notchPayment = $this->notchPayService->verifyPayment($reference);
                $metadata     = $notchPayment->transaction->metadata ?? null;
                if (is_array($metadata)) {
                    $metadata = (object) $metadata;
                }
                $type        = $metadata->type ?? null;
                $merchantRef = $metadata->reference ?? null;

                if ($type === 'wallet_deposit' || ($merchantRef && str_starts_with($merchantRef, 'WALLET-'))) {
                    $isWalletDeposit = true;
                }
            } catch (\Exception $e) {
                Log::warning("[NotchPay Callback] Impossible d'inspecter la référence $reference: " . $e->getMessage());
            }
        }

        if ($isWalletDeposit) {
            $status = $this->notchPayService->checkAndFulfillWalletDeposit($reference);

            if (in_array($status, ['confirmed', 'complete', 'success', 'completed'])) {
                return redirect($frontendUrl . '/clash-bet/wallet?payment=success&reference=' . $reference);
            } elseif (in_array($status, ['failed', 'expired', 'cancelled'])) {
                return redirect($frontendUrl . '/clash-bet/wallet?payment=failed&reference=' . $reference);
            }
            return redirect($frontendUrl . '/clash-bet/wallet?payment=pending&reference=' . $reference);
        }

        // 2. Sinon, c'est un paiement d'inscription de tournoi
        $status = $this->notchPayService->checkAndFulfillTransaction($reference);

        if (in_array($status, ['confirmed', 'complete', 'success', 'completed'])) {
            return redirect($frontendUrl . '/tournaments/register?payment=success&reference=' . $reference);
        } elseif (in_array($status, ['failed', 'expired', 'cancelled'])) {
            return redirect($frontendUrl . '/tournaments/register?payment=failed&reference=' . $reference);
        }

        return redirect($frontendUrl . '/tournaments/register?payment=pending&reference=' . $reference);
    }

    /**
     * Handles the POST webhook notifications from NotchPay (asynchronously).
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->all();
        Log::info('[NotchPay] Webhook received:', $payload);

        $reference = $payload['data']['reference'] ?? $payload['reference'] ?? null;

        if (!$reference) {
            Log::warning('[NotchPay] Webhook: reference was not found in payload.');
            return response()->json(['message' => 'No reference found'], 400);
        }

        $type        = $payload['data']['metadata']['type'] ?? $payload['metadata']['type'] ?? null;
        $merchantRef = $payload['data']['metadata']['reference'] ?? $payload['metadata']['reference'] ?? null;

        $isWalletDeposit = str_starts_with($reference, 'WALLET-')
            || $type === 'wallet_deposit'
            || ($merchantRef && str_starts_with($merchantRef, 'WALLET-'))
            || cache()->has("wallet_deposit_{$reference}");

        if ($isWalletDeposit) {
            $result = $this->notchPayService->checkAndFulfillWalletDeposit($reference);
        } else {
            $result = $this->notchPayService->checkAndFulfillTransaction($reference);
        }

        return response()->json([
            'message' => 'Processed',
            'status'  => $result
        ]);
    }

    /**
     * Retrieve local status of a payment.
     */
    public function getPaymentStatus(Request $request, $reference): JsonResponse
    {
        $payment = Payment::where('reference', $reference)->first();

        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Paiement introuvable.'], 404);
        }

        // If local payment status isn't complete yet, double check directly with NotchPay
        if ($payment->status !== 'complete') {
            $this->notchPayService->checkAndFulfillTransaction($reference);
            $payment->refresh();
        }

        return response()->json([
            'success' => true,
            'status' => $payment->status,
            'reference' => $reference,
        ]);
    }
}
