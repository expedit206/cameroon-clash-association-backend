<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClanRegistration;
use Illuminate\Http\Request;

/**
 * Gestion des inscriptions et paiements par l'administration.
 */
class AdminRegistrationController extends Controller
{
    /**
     * Liste toutes les inscriptions (Phase 2 et 3).
     */
    public function index()
    {
        $registrations = ClanRegistration::with(['clan', 'players.user', 'payment'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($registrations);
    }

    /**
     * Confirmer une inscription manuellement.
     * Cette méthode est déjà dans AdminUserController, mais il est plus propre de l'avoir ici.
     * Je vais rediriger l'appel ou la déplacer.
     */
    public function confirm(Request $request, ClanRegistration $registration)
    {
        // On délègue à la méthode existante pour l'instant ou on ré-implémente
        // Pour éviter la redondance, je vais juste appeler la logique.
        
        $confirmedCount = ClanRegistration::where('competition_id', $registration->competition_id)
            ->where('status', 'confirmed')
            ->count();

        if ($confirmedCount >= 16) {
            return response()->json(['message' => "La limite de 16 clans est atteinte."], 422);
        }

        $registration->update([
            'status' => 'confirmed',
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
            'seed_number' => $confirmedCount + 1,
        ]);

        if ($registration->payment) {
            $registration->payment->update([
                'status' => 'completed',
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
            ]);
        }

        return response()->json(['message' => "Inscription confirmée !"]);
    }
}
