<?php

namespace App\Http\Controllers\ClashBet;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Withdrawal;
use App\Services\NotchPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class WalletController extends Controller
{
    public const MIN_WITHDRAWAL = 1000; // FCFA

    public function __construct(
        private readonly NotchPayService $notchPayService
    ) {}

    private function checkPublicAccess(): ?\Illuminate\Http\JsonResponse
    {
        $publicEnabled = AppSetting::clashBetPublicEnabled();
        $isAdmin = Auth::check() && (Auth::user()->is_admin || Auth::user()->role === 'admin');

        if (!$publicEnabled && !$isAdmin) {
            return response()->json([
                'success' => false,
                'public_enabled' => false,
                'message' => 'Le module Clash Bet P2P est actuellement désactivé par l\'administration.',
            ], 403);
        }

        return null;
    }

    /**
     * GET /clash-bet/wallet
     * Solde et informations du wallet de l'utilisateur.
     */
    public function show()
    {
        $user   = Auth::user();
        $wallet = $user->getOrCreateWallet();

        return response()->json([
            'balance'         => $wallet->balance,
            'locked_amount'   => $wallet->locked_amount,
            'total_balance'   => $wallet->balance + $wallet->locked_amount,
            'total_deposited' => $wallet->total_deposited,
            'total_won'       => $wallet->total_won,
            'total_withdrawn' => $wallet->total_withdrawn,
        ]);
    }

    /**
     * GET /clash-bet/wallet/history
     * Historique des transactions du wallet.
     */
    public function history(Request $request)
    {
        $user   = Auth::user();
        $wallet = $user->getOrCreateWallet();

        $type = $request->query('type'); // deposit|bet_place|bet_win|bet_refund|withdrawal

        $query = $wallet->transactions();
        if ($type) {
            $query->where('type', $type);
        }

        $transactions = $query->paginate(25);

        return response()->json($transactions);
    }

    /**
     * POST /clash-bet/wallet/deposit
     * Initier un dépôt via NotchPay Mobile Money.
     */
    public function deposit(Request $request)
    {
        if ($accessDenied = $this->checkPublicAccess()) {
            return $accessDenied;
        }

        $request->validate([
            'amount'         => 'required|integer|min:100|max:50000',
            'payment_method' => 'nullable|string',
        ]);

        $user      = Auth::user();
        $reference = 'WALLET-' . strtoupper(Str::random(12));
        $amount    = $request->amount;

        try {
            $response = $this->notchPayService->initializePayment([
                'amount'      => $amount,
                'email'       => $user->email ?? 'player@clashkamer.com',
                'currency'    => 'XAF',
                'reference'   => $reference,
                'description' => "CCA Wallet {$user->name}",
                'metadata'    => [
                    'type'       => 'wallet_deposit',
                    'user_id'    => $user->id,
                    'wallet_id'  => $user->getOrCreateWallet()->id,
                    'reference'  => $reference,
                ],
            ]);

            // Stocker en cache les infos du dépôt wallet (valable 24h)
            $depositData = [
                'user_id'   => $user->id,
                'wallet_id' => $user->getOrCreateWallet()->id,
                'amount'    => $amount,
                'reference' => $reference,
            ];

            cache()->put("wallet_deposit_{$reference}", $depositData, now()->addHours(24));

            // Extraire la référence NotchPay (trx.XXXXX) si elle est renvoyée dès l'initialisation
            $rawResponse = json_decode(json_encode($response), true);
            $trxRef = $rawResponse['transaction']['reference'] ?? $rawResponse['reference'] ?? $rawResponse['data']['reference'] ?? null;
            if ($trxRef && $trxRef !== $reference) {
                cache()->put("wallet_deposit_{$trxRef}", $depositData, now()->addHours(24));
            }

            return response()->json([
                'success'      => true,
                'reference'    => $reference,
                'checkout_url' => $response->authorization_url ?? $response->data->authorization_url ?? null,
                'amount'       => $amount,
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /clash-bet/wallet/verify-deposit/{reference}
     * Vérifier et créditer manuellement un dépôt via la référence NotchPay.
     */
    public function verifyDeposit($reference)
    {
        $status = $this->notchPayService->checkAndFulfillWalletDeposit($reference);
        $user   = Auth::user();
        $wallet = $user->getOrCreateWallet();

        $isSuccess = in_array($status, ['confirmed', 'complete', 'success', 'completed']);

        return response()->json([
            'success' => $isSuccess,
            'status'  => $status,
            'balance' => $wallet->balance,
            'message' => $isSuccess ? 'Dépôt validé et crédité avec succès !' : 'Statut actuel du paiement : ' . $status,
        ]);
    }

    /**
     * POST /clash-bet/wallet/withdraw
     * Soumettre une demande de retrait.
     */
    public function withdraw(Request $request)
    {
        if ($accessDenied = $this->checkPublicAccess()) {
            return $accessDenied;
        }

        $request->validate([
            'amount'         => 'required|integer|min:100',
            'account_name'   => 'required|string|min:3|max:100',
            'phone_number'   => 'required|string|min:8|max:15',
            'payment_method' => 'required|in:mtn_momo,orange_money',
        ]);

        $user   = Auth::user();
        $wallet = $user->getOrCreateWallet();
        $amount = $request->amount;

        if ($wallet->balance < $amount) {
            return response()->json([
                'success' => false,
                'message' => "Solde insuffisant. Disponible : {$wallet->balance} FCFA.",
            ], 422);
        }

        // Vérifier qu'il n'y a pas déjà un retrait en attente
        $hasPending = Withdrawal::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($hasPending) {
            return response()->json([
                'success' => false,
                'message' => 'Vous avez déjà un retrait en cours de traitement.',
            ], 422);
        }

        $feeRate   = AppSetting::clashBetWithdrawalFee() / 100;
        $fee       = (int) round($amount * $feeRate);
        $netAmount = $amount - $fee;

        // Débit immédiat du wallet + création de la demande
        $withdrawal = \Illuminate\Support\Facades\DB::transaction(function () use ($user, $wallet, $amount, $fee, $netAmount, $request) {
            $withdrawal = Withdrawal::create([
                'user_id'        => $user->id,
                'account_name'   => $request->account_name,
                'amount'         => $amount,
                'fee'            => $fee,
                'net_amount'     => $netAmount,
                'phone_number'   => $request->phone_number,
                'payment_method' => $request->payment_method,
                'status'         => 'pending',
            ]);

            $feePercentage = AppSetting::clashBetWithdrawalFee();
            $wallet->debitForWithdrawal(
                $amount,
                $fee,
                "Retrait Mobile Money — Frais {$feePercentage}%: {$fee} FCFA",
                (string) $withdrawal->id
            );

            $withdrawal->update(['notchpay_reference' => 'WITHDRAW-' . $withdrawal->id]);
            return $withdrawal;
        });

        $feePercentage = AppSetting::clashBetWithdrawalFee();
        return response()->json([
            'success'       => true,
            'withdrawal_id' => $withdrawal->id,
            'amount'        => $amount,
            'fee'           => $fee,
            'fee_details'   => "{$feePercentage}% de frais de retrait = {$fee} FCFA",
            'net_amount'    => $netAmount,
            'status'        => 'pending',
            'message'       => "Votre demande de retrait de {$netAmount} FCFA a été soumise. Un administrateur la traitera sous 24h.",
        ], 201);
    }
}
