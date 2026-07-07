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

    /**
     * Liste et recherche des joueurs.
     */
    public function index(Request $request)
    {
        $query = User::with('current_clan')->where('role', '!=', 'admin');

        // Recherche par tag, nom ou clan
        if ($search = $request->input('search')) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('tag_coc', 'like', "%{$search}%")
                  ->orWhere('current_clan_tag', 'like', "%{$search}%")
                  ->orWhereHas('current_clan', function($clanQ) use ($search) {
                      $clanQ->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Filtrage par statut
        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        $users = $query->orderBy('created_at', 'desc')->get();

        return response()->json($users);
    }

    /**
     * Supprimer un joueur définitivement.
     */
    public function destroy(User $user)
    {
        $user->delete();

        return response()->json([
            'message' => "L'utilisateur {$user->name} a été supprimé avec succès."
        ]);
    }

    /**
     * Promouvoir un joueur en capitaine.
     */
    public function makeCaptain(User $user)
    {
        if ($user->current_clan_tag) {
            $normalClanTag = strtoupper(trim($user->current_clan_tag));

            // Rétrograder tous les autres capitaines du même clan en simple joueur
            User::where('current_clan_tag', $normalClanTag)
                ->where('role', 'captain')
                ->where('id', '!=', $user->id)
                ->update(['role' => 'player']);

            // Mettre à jour le captain_id sur le clan si existant en base
            $clan = \App\Models\Clan::where('tag_coc', $normalClanTag)->first();
            if ($clan) {
                if ($clan->captain_id && $clan->captain_id != $user->id) {
                    User::where('id', $clan->captain_id)->update(['role' => 'player']);
                }
                $clan->update(['captain_id' => $user->id]);
            }
        }

        // Promouvoir l'utilisateur
        $user->update(['role' => 'captain']);

        return response()->json([
            'message' => "{$user->name} a été promu capitaine avec succès."
        ]);
    }

    /**
     * Liste les membres d'un clan avec leur statut d'inscription.
     */
    public function clanMembers(string $tagCoc, \App\Services\CocApiService $cocApi)
    {
        $cocMembers = $cocApi->getClanMembers($tagCoc);

        if ($cocMembers === null) {
            return response()->json(['message' => "Impossible de récupérer les membres du clan depuis l'API CoC."], 500);
        }

        $normalClanTag = strtoupper(trim($tagCoc));
        $platformUsers = User::where(function($q) use ($normalClanTag) {
                $q->where('current_clan_tag', $normalClanTag)
                  ->orWhere('current_clan_tag', str_replace('#', '', $normalClanTag));
            })
            ->get()
            ->keyBy(function($u) {
                return strtoupper(trim($u->tag_coc));
            });

        $members = array_map(function ($member) use ($platformUsers) {
            $tag = strtoupper(trim($member['tag']));
            $platformUser = $platformUsers->get($tag) ?? $platformUsers->get(str_replace('#', '', $tag));

            return [
                'tag_coc' => $tag,
                'name' => $member['name'],
                'townHallLevel' => $member['townHallLevel'],
                'role_coc' => $member['role'],
                'exp_level' => $member['expLevel'],
                'league_icon' => $member['leagueTier']['iconUrls']['small'] ?? null,
                'is_registered' => !!$platformUser,
                'platform_user' => $platformUser ? [
                    'id' => $platformUser->id,
                    'role' => $platformUser->role,
                    'status' => $platformUser->status,
                    'is_validated' => $platformUser->is_validated ?? false,
                ] : null,
            ];
        }, $cocMembers);

        return response()->json($members);
    }
}

