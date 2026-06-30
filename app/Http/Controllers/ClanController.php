<?php

namespace App\Http\Controllers;

use App\Models\Clan;
use App\Models\User;
use App\Services\CocApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Gère les clans et les candidatures des capitaines.
 */
class ClanController extends Controller
{
    protected CocApiService $cocApi;

    public function __construct(CocApiService $cocApi)
    {
        $this->cocApi = $cocApi;
    }

    /**
     * Soumission d'une candidature de clan par un capitaine.
     */
    public function submit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tag_coc' => 'required|string',
            'name' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        // 1. Vérification du clan via l'API CoC
        $cocClan = $this->cocApi->getClan($request->tag_coc);

        if (!$cocClan) {
            return response()->json([
                'message' => "Le clan CoC {$request->tag_coc} est introuvable."
            ], 422);
        }

        // 2. Création ou mise à jour du clan
        $clan = Clan::updateOrCreate(
            ['tag_coc' => strtoupper($request->tag_coc)],
            [
                'name' => $request->name,
                'captain_id' => $user->id,
                'badge_url' => $cocClan['badgeUrls']['medium'] ?? null,
                'clan_level' => $cocClan['clanLevel'] ?? null,
                'status' => 'pending',
            ]
        );

        // Si l'utilisateur était un simple joueur, il devient capitaine
        if ($user->role === 'player') {
            $user->update(['role' => 'captain']);
        }

        return response()->json([
            'message' => "Candidature du clan {$clan->name} soumise avec succès !",
            'clan' => $clan
        ], 201);
    }

    /**
     * Récupère les membres du clan actuel de l'utilisateur avec leur statut sur la plateforme.
     */
    public function myClanMembers(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->status !== 'validated') {
            return response()->json(['message' => "Votre profil doit être validé par la CCA pour accéder aux détails de votre clan."], 403);
        }

        $clanTag = $user->current_clan_tag;

        // \Illuminate\Support\Facades\Log::info('[My Clan] Request received', [
        //     'user_id'   => $user->id,
        //     'user_name' => $user->name,
        //     'clan_tag'  => $clanTag,
        // ]);

        if (!$clanTag) {
            return response()->json(['message' => "Tag de clan manquant. Veuillez synchroniser votre profil."], 400);
        }

        // 1. Récupérer les membres via l'API CoC
        $cocMembers = $this->cocApi->getClanMembers($clanTag);

        // \Illuminate\Support\Facades\Log::info('[My Clan] CoC members received', [
        //     'count' => $cocMembers ? count($cocMembers) : 'null (API failure)'
        // ]);

        if ($cocMembers === null) {
            return response()->json(['message' => "Impossible de récupérer les membres du clan depuis l'API CoC."], 500);
        }

        // 2. Récupérer les utilisateurs de la plateforme qui sont dans ce clan
        // On normalise les tags pour assurer une correspondance robuste
        $normalClanTag = strtoupper(trim($clanTag));
        $platformUsers = User::where(function($q) use ($normalClanTag) {
                $q->where('current_clan_tag', $normalClanTag)
                  ->orWhere('current_clan_tag', str_replace('#', '', $normalClanTag));
            })
            ->get()
            ->keyBy(function($u) {
                return strtoupper(trim($u->tag_coc));
            });

        // 3. Fusionner les données
        $members = array_map(function ($member) use ($platformUsers) {
            $tag = strtoupper(trim($member['tag']));
            $platformUser = $platformUsers->get($tag) ?? $platformUsers->get(str_replace('#', '', $tag));

            return [
                'id' => $platformUser?->id, // On aplatit l'ID pour faciliter l'accès au vote
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

        // 4. Vérifier si une élection est en cours
        $activeElection = \App\Models\CaptainElection::where('clan_tag', $clanTag)
            ->where('status', 'open')
            ->with(['votes']) // On pourrait charger les votes pour voir si l'user a déjà voté
            ->first();

        return response()->json([
            'clan_tag' => $clanTag,
            'members' => $members,
            'active_election' => $activeElection
        ]);
    }

    /**
     * Liste des clans validés pour navigation publique.
     */
    public function index()
    {
        $clans = Clan::where('status', 'validated')->get();
        return response()->json($clans);
    }

    /**
     * Détails d'un clan directement depuis l'API CoC (aucun enregistrement BDD requis).
     * Le tag arrive sans '#' depuis l'URL (ex: 2GGLPPL8L), le service CocApi gère le formatage.
     */
    public function show(string $tag)
    {
        // Décoder au cas où il y aurait un encodage résiduel
        $decodedTag = urldecode($tag);
        \Illuminate\Support\Facades\Log::info("[Clan Show] tag reçu: " . $decodedTag);

        // Appel direct à l'API CoC via le service dédié
        $cocClan = $this->cocApi->getClan($decodedTag);
        // $cocClan = $this->cocApi->getClan($decodedTag);

        if (!$cocClan) {
            return response()->json([
                'message' => "Clan '{$decodedTag}' introuvable sur l'API Clash of Clans."
            ], 404);
        }

        // Formater la réponse pour correspondre à ce qu'attend le frontend
        return response()->json([
            'name'        => $cocClan['name'],
            'tag_coc'     => $cocClan['tag'],
            'clan_level'  => $cocClan['clanLevel'],
            'badge_url'   => $cocClan['badgeUrls']['medium'] ?? null,
            'members'     => $cocClan['members'] ?? [],
            'description' => $cocClan['description'] ?? null,
            'type'        => $cocClan['type'] ?? null,
            'location'    => $cocClan['location'] ?? null,
        ]);
    }
}
