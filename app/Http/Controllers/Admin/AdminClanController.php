<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Clan;
use Illuminate\Http\Request;

/**
 * Gestion des clans par l'administration.
 */
class AdminClanController extends Controller
{
    /**
     * Liste des clans en attente de validation (Phase 1).
     */
    public function pendingClans()
    {
        $clans = Clan::where('status', 'pending')
            ->with('captain')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($clans);
    }

    /**
     * Valider un clan et passer le capitaine en rôle 'captain' officiellement.
     */
    public function validateClan(Clan $clan)
    {
        $clan->update(['status' => 'validated']);

        // Assurer que le capitaine a le bon rôle
        if ($clan->captain && $clan->captain->role !== 'admin') {
            $clan->captain->update(['role' => 'captain']);
        }

        return response()->json([
            'message' => "Le clan {$clan->name} a été validé."
        ]);
    }

    /**
     * Refuser une candidature de clan.
     */
    public function rejectClan(Clan $clan)
    {
        $clan->update(['status' => 'rejected']);

        return response()->json([
            'message' => "Le clan {$clan->name} a été refusé."
        ]);
    }
}
