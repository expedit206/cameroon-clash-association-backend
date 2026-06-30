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
            return redirect($frontendUrl . '/tournaments/register?payment=error&message=reference_missing');
        }

        $status = $this->notchPayService->checkAndFulfillTransaction($reference);

        if ($status === 'confirmed') {
            return redirect($frontendUrl . '/tournaments/register?payment=success&reference=' . $reference);
        } elseif ($status === 'failed') {
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

        // Standard NotchPay payload may contain the reference in different places:
        // usually $payload['data']['reference'] or $payload['reference']
        $reference = $payload['data']['reference'] ?? $payload['reference'] ?? null;

        if (!$reference) {
            Log::warning('[NotchPay] Webhook: reference was not found in payload.');
            return response()->json(['message' => 'No reference found'], 400);
        }

        $result = $this->notchPayService->checkAndFulfillTransaction($reference);

        return response()->json([
            'message' => 'Processed',
            'status' => $result
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

        // If local payment status isn't confirmed yet, double check directly with NotchPay
        if ($payment->status !== 'confirmed') {
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
