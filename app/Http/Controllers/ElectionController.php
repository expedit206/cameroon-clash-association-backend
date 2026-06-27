<?php

namespace App\Http\Controllers;

use App\Models\CaptainElection;
use App\Models\CaptainVote;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ElectionController extends Controller
{
    protected \App\Services\CocApiService $cocApi;

    public function __construct(\App\Services\CocApiService $cocApi)
    {
        $this->cocApi = $cocApi;
    }

    /**
     * Lance une élection dans le clan de l'utilisateur.
     */
    public function initiate(Request $request)
    {
        $user = $request->user();
        $clanTag = $user->current_clan_tag;

        if (!$clanTag) {
            return response()->json(['message' => "Vous n'êtes pas dans un clan."], 403);
        }

        // Vérifier si une élection est déjà en cours
        $existing = CaptainElection::where('clan_tag', $clanTag)
            ->where('status', 'open')
            ->first();

        if ($existing) {
            return response()->json(['message' => "Une élection est déjà en cours dans ce clan."], 422);
        }

        // 1. Vérification du clan via l'API CoC (Real-time)
        $cocClan = $this->cocApi->getClan($clanTag);
        if (!$cocClan) {
            return response()->json(['message' => "Impossible de récupérer les détails du clan CoC."], 422);
        }

        // 2. Vérification de la localisation (Cameroun uniquement)
        $location = $cocClan['location'] ?? null;
        if (!$location || $location['name'] !== 'Cameroon') {
            return response()->json(['message' => "Le tournoi CCA National League est exclusivement réservé aux clans d'origine Camerounaise sur Clash of Clans."], 403);
        }

        // 3. Vérification du rôle (Chef ou Sous-Chef uniquement peuvent lancer l'élection)
        $cocMember = collect($cocClan['memberList'] ?? [])->firstWhere('tag', $user->tag_coc);
        $userRole = $cocMember['role'] ?? null;
        if (!in_array($userRole, ['leader', 'coLeader'])) {
            return response()->json(['message' => "Seuls les Chefs et Sous-Chefs du clan peuvent lancer une élection de capitaine."], 403);
        }

        // 4. Vérification de l'éligibilité du lanceur (HDV 14+)
        if ($user->hdv_level < 14) {
             return response()->json(['message' => "Vous devez avoir un HDV 14 minimum pour initier une élection."], 403);
        }

        $election = CaptainElection::create([
            'clan_tag' => $clanTag,
            'competition_id' => 1, // Compétition par défaut pour le moment
            'status' => 'open',
            'ends_at' => Carbon::now()->addDays(2), // 48h pour voter
        ]);

        return response()->json([
            'message' => "Élection lancée avec succès pour 48h.",
            'election' => $election
        ], 201);
    }

    /**
     * Permet à un membre éligible de voter pour un candidat.
     */
    public function vote(Request $request, CaptainElection $election)
    {
        $voter = $request->user();

        if (!$election->isOpen()) {
            return response()->json(['message' => "Cette élection est clôturée."], 422);
        }

        if (!$voter->is_validated) {
            return response()->json(['message' => "Votre profil doit être validé par CCA pour voter."], 403);
        }

        if ($voter->current_clan_tag !== $election->clan_tag) {
            return response()->json(['message' => "Vous ne faites pas partie de ce clan."], 403);
        }

        $request->validate([
            'candidate_id' => 'required|exists:users,id'
        ]);

        $candidate = User::find($request->candidate_id);
        
        if ($candidate->current_clan_tag !== $election->clan_tag || !$candidate->is_validated) {
            return response()->json(['message' => "Ce candidat n'est pas éligible."], 422);
        }

        // Vérification HDV 14+ obligatoire pour être candidat
        if (($candidate->hdv_level ?? 0) < 14) {
            return response()->json(['message' => "Ce candidat n'a pas le HDV 14 minimum requis pour être élu Capitaine."], 422);
        }

        $vote = CaptainVote::updateOrCreate(
            ['election_id' => $election->id, 'voter_id' => $voter->id],
            ['candidate_id' => $candidate->id]
        );

        return response()->json([
            'message' => "Vote enregistré pour {$candidate->name}.",
            'vote' => $vote
        ]);
    }

    /**
     * Clôture une élection et désigne le vainqueur.
     */
    public function declareWinner(CaptainElection $election)
    {
        if ($election->status !== 'open') {
            return response()->json(['message' => "Cette élection est déjà clôturée."], 422);
        }

        // On compte les votes par candidat
        $results = CaptainVote::where('election_id', $election->id)
            ->select('candidate_id', \DB::raw('count(*) as total'))
            ->groupBy('candidate_id')
            ->orderByDesc('total')
            ->get();

        if ($results->isEmpty()) {
            $election->update(['status' => 'cancelled']);
            return response()->json(['message' => "Aucun vote n'a été enregistré. Élection annulée."]);
        }

        $winnerId = $results->first()->candidate_id;
        $winner = User::find($winnerId);

        // Mise à jour de l'élection
        $election->update([
            'winner_id' => $winnerId,
            'status' => 'closed'
        ]);

        // Mise à jour du clan
        $clan = \App\Models\Clan::where('tag_coc', $election->clan_tag)->first();
        if ($clan) {
            $clan->update(['captain_id' => $winnerId]);
            
            // On s'assure que le vainqueur a le rôle 'captain'
            $winner->update(['role' => 'captain']);
        }

        return response()->json([
            'message' => "L'élection est terminée. {$winner->name} est le nouveau capitaine.",
            'winner' => $winner
        ]);
    }

    /**
     * Récupère l'élection en cours pour le clan de l'utilisateur.
     */
    public function current(Request $request)
    {
        $user = $request->user();
        $clanTag = $request->clan_tag ?: $user->current_clan_tag;

        if (!$clanTag) {
            return response()->json(['message' => "Tag de clan manquant."], 400);
        }

        $election = CaptainElection::where('clan_tag', $clanTag)
            ->whereIn('status', ['open', 'closed'])
            ->with(['winner', 'votes'])
            ->withCount('votes')
            ->orderByDesc('created_at')
            ->first();

        return response()->json([
            'election' => $election
        ]);
    }
}
