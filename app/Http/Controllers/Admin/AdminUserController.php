<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Contrôleur pour la gestion des utilisateurs par les administrateurs.
 */
class AdminUserController extends Controller
{
    /**
     * Statistiques globales pour le dashboard admin.
     */
    public function stats()
    {
        return response()->json([
            'users' => \App\Models\User::count(),
            'pending_users' => \App\Models\User::where('status', 'pending')->count(),
            'clans' => \App\Models\Clan::count(),
            'pending_clans' => \App\Models\Clan::where('status', 'pending')->count(),
            'confirmed_registrations' => \App\Models\ClanRegistration::where('status', 'confirmed')->count(),
            'total_payments' => \App\Models\Payment::where('status', 'completed')->sum('amount'),
        ]);
    }
    /**
     * Liste des joueurs en attente de validation.
     */
    public function pendingUsers()
    {
        $users = User::where('status', 'pending')
            ->where('role', 'player')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($users);
    }

    /**
     * Valider un joueur après vérification de sa capture d'écran.
     */
    public function validateUser(User $user)
    {
        $user->update([
            'status' => 'validated',
        ]);

        return response()->json([
            'message' => "Le compte de {$user->name} a été validé avec succès."
        ]);
    }

    /**
     * Refuser un compte joueur.
     */
    public function rejectUser(Request $request, User $user)
    {
        $user->update([
            'status' => 'rejected',
        ]);

        return response()->json([
            'message' => "Le compte de {$user->name} a été refusé."
        ]);
    }

    /**
     * Confirmer une inscription (après vérification du paiement).
     */
    public function confirmRegistration(Request $request, \App\Models\ClanRegistration $registration)
    {
        // Vérifier si la limite des 16 clans est atteinte
        $confirmedCount = \App\Models\ClanRegistration::where('competition_id', $registration->competition_id)
            ->where('status', 'confirmed')
            ->count();

        if ($confirmedCount >= 16) {
            return response()->json(['message' => "La limite de 16 clans est déjà atteinte pour cette compétition."], 422);
        }

        $registration->update([
            'status' => 'confirmed',
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
            'seed_number' => $confirmedCount + 1,
        ]);

        // Mettre à jour le paiement associé
        if ($registration->payment) {
            $registration->payment->update([
                'status' => 'completed',
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
            ]);
        }

        return response()->json([
            'message' => "Inscription du clan {$registration->clan->name} confirmée !"
        ]);
    }
}
