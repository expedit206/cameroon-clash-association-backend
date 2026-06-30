<?php

namespace App\Http\Controllers;

use App\Models\ClanRegistration;
use App\Models\Competition;
use App\Models\RegistrationPlayer;
use App\Models\User;
use App\Services\CocApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Gère les inscriptions des clans aux compétitions (Phase 2 et 3).
 */
class RegistrationController extends Controller
{
    protected CocApiService $cocApi;
    protected \App\Services\NotchPayService $notchPayService;

    public function __construct(CocApiService $cocApi, \App\Services\NotchPayService $notchPayService)
    {
        $this->cocApi = $cocApi;
        $this->notchPayService = $notchPayService;
    }

    /**
     * Récupère les membres du clan CoC éligibles pour l'inscription.
     */
    public function getEligibleMembers(Request $request, Competition $competition)
    {
        $user = $request->user();
        $clan = $user->capitained_clan;

        if (!$clan) {
            return response()->json(['message' => "Vous n'avez pas de clan enregistré."], 403);
        }

        $members = $this->cocApi->getClanMembers($clan->tag_coc);

        if (!$members) {
            return response()->json(['message' => "Impossible de récupérer les membres du clan."], 422);
        }

        // Filtrer les membres déjà inscrits dans cette compétition via d'autres clans (optionnel mais recommandé)
        // Pour l'instant, on retourne la liste brute. En V2, on vérifiera si le tag est déjà dans registration_players.

        return response()->json($members);
    }

    /**
     * Soumettre la composition de l'équipe (Phase 2).
     */
    public function registerTeam(Request $request, Competition $competition)
    {
        $user = $request->user();
        $clan = $user->capitained_clan;
        $clanTag = $user->current_clan_tag;

        // Si le clan n'est pas dans la BD, on vérifie s'il y a une élection gagnée
        if (!$clan) {
            $election = \App\Models\CaptainElection::where('clan_tag', $clanTag)
                ->where('competition_id', $competition->id)
                ->where('winner_id', $user->id)
                ->where('status', 'closed')
                ->first();

            if (!$election) {
                return response()->json(['message' => "Seul le capitaine élu par le clan peut soumettre le roster."], 403);
            }

            // Création JIT du clan
            $cocClan = $this->cocApi->getClan($clanTag);
            $clan = \App\Models\Clan::create([
                'tag_coc' => strtoupper($clanTag),
                'name' => $cocClan['name'] ?? 'Clan Inconnu',
                'captain_id' => $user->id,
                'badge_url' => $cocClan['badgeUrls']['medium'] ?? null,
                'clan_level' => $cocClan['clanLevel'] ?? 1,
                'status' => 'pending', // Validation finale par l'admin lors du roster
            ]);
            
            // Mettre à jour le rôle de l'user
            $user->update(['role' => 'captain']);
        }

        // Si on est ici, soit le clan existait, soit il vient d'être créé.
        // On vérifie une dernière fois si l'user est bien le vainqueur de l'élection
        $election = \App\Models\CaptainElection::where('clan_tag', $clan->tag_coc)
            ->where('competition_id', $competition->id)
            ->where('winner_id', $user->id)
            ->where('status', 'closed')
            ->first();

        if (!$election) {
            return response()->json(['message' => "Seul le capitaine élu pour ce tournoi peut soumettre le roster."], 403);
        }

        $validator = Validator::make($request->all(), [
            'players' => 'required|array|min:5|max:10', // 5 titulaires + jusqu'à 5 remplaçants
            'players.*.tag_coc' => 'required|string',
            'players.*.name' => 'required|string',
            'players.*.townHallLevel' => 'required|integer|between:14,18',
            'players.*.is_substitute' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        return DB::transaction(function () use ($request, $competition, $clan) {
            // 1. Créer ou récupérer l'inscription
            $registration = ClanRegistration::firstOrCreate([
                'clan_id' => $clan->id,
                'competition_id' => $competition->id,
            ], [
                'status' => 'pending_payment',
            ]);

            // 2. Nettoyer la composition précédente si elle existe
            $registration->players()->delete();

            // 3. Ajouter les joueurs
            foreach ($request->players as $playerData) {
                // On cherche l'utilisateur par son tag, ou on en crée un "fantôme" si pas encore inscrit(si le joueur n'est pas encore inscrit dans notre plateforme on refusera son appartenance a une equipe)
                $player = User::firstOrCreate(
                    ['tag_coc' => strtoupper($playerData['tag_coc'])],
                    [
                        'name' => $playerData['name'],
                        'password' => bcrypt('password_default'), // Password temporaire
                        'role' => 'player',
                        'hdv_level' => $playerData['townHallLevel'],
                        'status' => 'validated', // Validé tacitement car vérifié par le capitaine
                    ]
                );

                RegistrationPlayer::create([
                    'clan_registration_id' => $registration->id,
                    'player_id' => $player->id,
                    'hdv_position' => $playerData['townHallLevel'],
                    'is_substitute' => $playerData['is_substitute'],
                    'verified_at' => now(),
                ]);
            }

            return response()->json([
                'message' => "Composition d'équipe enregistrée. Passez maintenant au paiement.",
                'registration' => $registration->load('players.user')
            ]);
        });
    }

    /**
     * Récupérer le statut de l'inscription du clan connecté.
     */
    public function status(Request $request, Competition $competition)
    {
        $user = $request->user();
        $clan = $user->capitained_clan;
        
        $registration = null;

        if ($clan) {
            $registration = ClanRegistration::where('clan_id', $clan->id)
                ->where('competition_id', $competition->id)
                ->first();
        }

        // Si pas capitaine ou pas de registration trouvée via le clan possédé, on cherche via les rosters
        if (!$registration) {
            $playerId = $user->id;
            $registration = ClanRegistration::where('competition_id', $competition->id)
                ->whereHas('players', function($query) use ($playerId) {
                    $query->where('player_id', $playerId);
                })
                ->first();
        }

        if (!$registration) {
            return response()->json(['message' => 'Aucune inscription trouvée pour votre clan dans cette compétition.'], 404);
        }

        return response()->json($registration->load(['players.user', 'payments', 'clan']));
    }

    /**
     * Initier le paiement individuel (Phase 3).
     */
    public function initiatePayment(Request $request, ClanRegistration $registration)
    {
        $request->validate([
            'for_player_tag' => 'nullable|string', // Optional: if someone pays for a teammate
        ]);

        $user = $request->user();
        $targetUser = $user;

        // Si on paie pour un coéquipier
        if ($request->for_player_tag) {
            $targetUser = User::where('tag_coc', strtoupper($request->for_player_tag))->first();
            if (!$targetUser) {
                return response()->json(['message' => "Joueur introuvable."], 404);
            }
        }

        // Vérifier si le joueur est dans le roster
        $rosterPlayer = RegistrationPlayer::where('clan_registration_id', $registration->id)
            ->where('player_id', $targetUser->id)
            ->first();

        if (!$rosterPlayer) {
            return response()->json(['message' => "Ce joueur ne fait pas partie du roster enregistré."], 422);
        }

        // Règle : Seuls les TITULAIRES paient à l'inscription
        if ($rosterPlayer->is_substitute) {
            return response()->json(['message' => "Les remplaçants ne paient pas de frais d'inscription à cette étape."], 422);
        }

        // Vérifier si déjà payé
        $alreadyPaid = \App\Models\Payment::where('clan_registration_id', $registration->id)
            ->where('user_id', $targetUser->id)
            ->where('status', 'confirmed')
            ->exists();

        if ($alreadyPaid) {
            return response()->json(['message' => "Ce joueur a déjà payé ses frais de participation."], 422);
        }

        // Configuration du paiement NotchPay
        $reference = 'PAY_' . $registration->id . '_' . $targetUser->id . '_' . time();
        $amount = 1000;
        $dummyEmail = str_replace('#', '', $targetUser->tag_coc) . '@cca.espacecameroun.com';

        // Supprimer les anciennes tentatives de paiement en attente
        \App\Models\Payment::where('clan_registration_id', $registration->id)
            ->where('user_id', $targetUser->id)
            ->where('status', 'pending')
            ->delete();

        // Enregistrer la tentative de paiement localement
        $payment = \App\Models\Payment::create([
            'clan_registration_id' => $registration->id,
            'user_id' => $targetUser->id,
            'player_tag' => $targetUser->tag_coc,
            'amount' => $amount,
            'currency' => 'XAF',
            'reference' => $reference,
            'status' => 'pending',
            'payment_method' => 'momo',
            'phone_number' => $targetUser->phone_whatsapp ?? '',
        ]);

        try {
            $notchPayment = $this->notchPayService->initializePayment([
                'amount' => $amount,
                'email' => $dummyEmail,
                'reference' => $reference,
                'description' => "Frais de participation de " . $targetUser->name . " (" . $targetUser->tag_coc . ")",
                'metadata' => [
                    'payment_id' => $payment->id,
                    'registration_id' => $registration->id,
                    'user_id' => $targetUser->id,
                ],
            ]);

            return response()->json([
                'success' => true,
                'redirect_url' => $notchPayment->authorization_url,
                'reference' => $reference,
                'message' => 'Paiement initialisé avec succès.'
            ]);
        } catch (\Exception $e) {
            $payment->delete();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'initialisation du paiement NotchPay : ' . $e->getMessage()
            ], 500);
        }
    }
}
