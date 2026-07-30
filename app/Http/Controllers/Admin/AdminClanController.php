<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Clan;
use App\Services\CocApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /**
     * Liste tous les clans (filtrable par statut) avec le capitaine et le roster de tournoi.
     */
    public function index(Request $request)
    {
        $query = Clan::with([
            'captain:id,name,tag_coc,phone_whatsapp',
            'registrations' => function ($q) {
                $q->where('status', 'confirmed')
                  ->with(['players.user:id,name,tag_coc,hdv_level'])
                  ->latest();
            }
        ]);

        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        $clans = $query->orderBy('created_at', 'desc')->get();

        return response()->json($clans);
    }

    /**
     * Supprimer un clan (et ses inscriptions associées).
     */
    public function destroy(Clan $clan)
    {
        $name = $clan->name;

        // Supprimer les inscriptions liées pour éviter la violation de contrainte FK
        \App\Models\ClanRegistration::where('clan_id', $clan->id)->delete();

        $clan->delete();

        return response()->json([
            'message' => "Le clan {$name} et ses inscriptions ont été supprimés avec succès."
        ]);
    }

    /**
     * Changer le capitaine du clan.
     */
    public function updateCaptain(Request $request, Clan $clan)
    {
        $request->validate([
            'captain_id' => 'required|exists:users,id'
        ]);

        $newCaptain = \App\Models\User::find($request->captain_id);
        
        // Optionnel : on repasse l'ancien capitaine en simple 'player'
        if ($clan->captain && $clan->captain->role === 'captain') {
            $clan->captain->update(['role' => 'player']);
        }

        $clan->update(['captain_id' => $newCaptain->id]);
        
        if ($newCaptain->role !== 'admin') {
            $newCaptain->update(['role' => 'captain']);
        }

        return response()->json([
            'message' => "Le capitaine du clan {$clan->name} a été mis à jour."
        ]);
    }

    /**
     * Modifier le roster d'un clan pour un tournoi (changer titulaire/remplaçant).
     * Reçoit: players[] = [{ registration_player_id, is_substitute }]
     */
    public function updateRoster(Request $request, Clan $clan)
    {
        $request->validate([
            'players' => 'required|array',
            'players.*.registration_player_id' => 'required|integer|exists:registration_players,id',
            'players.*.is_substitute' => 'required|boolean',
        ]);

        foreach ($request->players as $playerData) {
            \App\Models\RegistrationPlayer::where('id', $playerData['registration_player_id'])
                ->update(['is_substitute' => $playerData['is_substitute']]);
        }

        return response()->json([
            'message' => "Le roster du clan {$clan->name} a été mis à jour avec succès."
        ]);
    }

    /**
     * Ajouter un joueur au roster du tournoi (inscription confirmée du clan).
     */
    public function addRosterPlayer(Request $request, Clan $clan)
    {
        $request->validate([
            'user_id'       => 'required|integer|exists:users,id',
            'hdv_position'  => 'nullable|integer|min:1|max:18',
            'is_substitute' => 'boolean',
        ]);

        // Trouver l'inscription confirmée la plus récente
        $registration = \App\Models\ClanRegistration::where('clan_id', $clan->id)
            ->where('status', 'confirmed')
            ->latest()
            ->firstOrFail();

        // Vérifier que le joueur n'est pas déjà dans le roster
        $already = \App\Models\RegistrationPlayer::where('clan_registration_id', $registration->id)
            ->where('player_id', $request->user_id)
            ->exists();

        if ($already) {
            return response()->json(['message' => 'Ce joueur est déjà dans le roster.'], 422);
        }

        $player = \App\Models\RegistrationPlayer::create([
            'clan_registration_id' => $registration->id,
            'player_id'            => $request->user_id,
            'hdv_position'         => $request->hdv_position ?? 0,
            'is_substitute'        => $request->is_substitute ?? false,
        ]);

        $player->load('user:id,name,tag_coc,hdv_level');

        return response()->json([
            'message' => 'Joueur ajouté au roster avec succès.',
            'player'  => $player,
        ]);
    }

    /**
     * Retirer un joueur du roster du tournoi.
     */
    public function removeRosterPlayer(Clan $clan, \App\Models\RegistrationPlayer $registrationPlayer)
    {
        $registrationPlayer->delete();

        return response()->json([
            'message' => 'Joueur retiré du roster avec succès.'
        ]);
    }

    /**
     * Liste les joueurs du clan en temps réel (via CoC API) qui ont un compte sur la plateforme et ne sont pas encore dans le roster.
     */
    public function availablePlayers(Clan $clan, CocApiService $cocApi)
    {
        // 1. Joueurs déjà dans le roster confirmés
        $registration = \App\Models\ClanRegistration::where('clan_id', $clan->id)
            ->where('status', 'confirmed')
            ->latest()
            ->first();

        $alreadyInRoster = [];
        if ($registration) {
            $alreadyInRoster = \App\Models\RegistrationPlayer::where('clan_registration_id', $registration->id)
                ->pluck('player_id')
                ->toArray();
        }

        // 2. Tenter de récupérer les membres du clan en temps réel depuis l'API Clash of Clans
        $cocMembers = $cocApi->getClanMembers($clan->tag_coc);

        if ($cocMembers && is_array($cocMembers)) {
            // Extraire tous les tags des membres CoC
            $memberTags = array_map(function($m) {
                return strtoupper(trim($m['tag']));
            }, $cocMembers);

            $cleanTags = array_map(function($tag) {
                return str_replace('#', '', $tag);
            }, $memberTags);

            $allTags = array_unique(array_merge($memberTags, $cleanTags));

            // Trouver les utilisateurs enregistrés sur la plateforme dont le tag_coc ou current_clan_tag correspond
            $players = \App\Models\User::whereNotIn('id', $alreadyInRoster)
                ->where(function($q) use ($allTags, $clan) {
                    $clanTagUpper = strtoupper(trim($clan->tag_coc));
                    $cleanClanTag = str_replace('#', '', $clanTagUpper);

                    $q->whereIn(DB::raw('UPPER(tag_coc)'), $allTags)
                      ->orWhereIn(DB::raw("UPPER(REPLACE(tag_coc, '#', ''))"), $allTags)
                      ->orWhere('current_clan_tag', $clanTagUpper)
                      ->orWhere('current_clan_tag', $cleanClanTag);
                })
                ->select('id', 'name', 'tag_coc', 'hdv_level')
                ->orderBy('name')
                ->get();
        } else {
            // Fallback si l'API CoC est indisponible
            $clanTagUpper = strtoupper(trim($clan->tag_coc));
            $cleanClanTag = str_replace('#', '', $clanTagUpper);

            $players = \App\Models\User::whereNotIn('id', $alreadyInRoster)
                ->where(function($q) use ($clanTagUpper, $cleanClanTag) {
                    $q->where('current_clan_tag', $clanTagUpper)
                      ->orWhere('current_clan_tag', $cleanClanTag)
                      ->orWhereNull('current_clan_tag');
                })
                ->select('id', 'name', 'tag_coc', 'hdv_level')
                ->orderBy('name')
                ->get();
        }

        return response()->json($players);
    }
}
