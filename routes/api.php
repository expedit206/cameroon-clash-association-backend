<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ClanDiscoveryController;
use App\Http\Controllers\PlayerDiscoveryController;

// --- Authentification ---
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');

// --- Public Tournament Data ---
Route::get('/tournament/leaderboard', [\App\Http\Controllers\TournamentController::class, 'getLeaderboard']);
Route::get('/tournament/bracket', [\App\Http\Controllers\TournamentController::class, 'getBracket']);
Route::get('/tournament/clans', [\App\Http\Controllers\TournamentController::class, 'getClans']);
Route::get('/tournament/groups', [\App\Http\Controllers\TournamentController::class, 'getGroups']);

// --- Découverte des Clans & Joueurs ---
Route::get('/clans/cameroun', [ClanDiscoveryController::class, 'searchCamerounClans']);
Route::get('/players/cameroun', [PlayerDiscoveryController::class, 'getCamerounRankings']);
Route::middleware('auth:sanctum')->group(function () {
    // --- Profil & Auth ---
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/sync', [AuthController::class, 'syncCocData']);
    Route::post('/auth/submit-profile', [AuthController::class, 'submitProfileVerification']);

    // --- Clans & Élections ---
    Route::get('/clans', [\App\Http\Controllers\ClanController::class, 'index']);
    Route::get('/clans/my-clan/members', [\App\Http\Controllers\ClanController::class, 'myClanMembers']);
    Route::get('/clans/{tag}', [\App\Http\Controllers\ClanController::class, 'show']);
    // --- Actions restreintes (Profil vérifié requis) ---
    Route::middleware('verified_profile')->group(function () {
        Route::post('/clans/submit', [\App\Http\Controllers\ClanController::class, 'submit']);
        Route::post('/competitions/{competition}/pre-register', [\App\Http\Controllers\RegistrationController::class, 'preRegister']);
        Route::post('/competitions/{competition}/register-team', [\App\Http\Controllers\RegistrationController::class, 'registerTeam']);
        Route::post('/registrations/{registration}/pay', [\App\Http\Controllers\RegistrationController::class, 'initiatePayment']);
    });
    


    // --- Inscriptions & Equipes ---
    Route::get('/competitions/{competition}/registration/status', [\App\Http\Controllers\RegistrationController::class, 'status']);
    Route::get('/competitions/{competition}/registration/eligible-members', [\App\Http\Controllers\RegistrationController::class, 'getEligibleMembers']);

    // --- Administration ---
    Route::middleware('can:admin')->group(function () {
        // Dashboard Stats
        Route::get('/admin/stats', [\App\Http\Controllers\Admin\AdminUserController::class, 'stats']);
        Route::post('/admin/sync-coc-data', [\App\Http\Controllers\Admin\AdminUserController::class, 'syncCocData']);

        // Moderation Joueurs
        Route::get('/admin/users', [\App\Http\Controllers\Admin\AdminUserController::class, 'index']);
        Route::get('/admin/users/pending', [\App\Http\Controllers\Admin\AdminUserController::class, 'pendingUsers']);
        Route::put('/admin/users/{user}/validate', [\App\Http\Controllers\Admin\AdminUserController::class, 'validateUser']);
        Route::put('/admin/users/{user}/reject', [\App\Http\Controllers\Admin\AdminUserController::class, 'rejectUser']);
        Route::put('/admin/users/{user}/reset-password', [\App\Http\Controllers\Admin\AdminUserController::class, 'resetPassword']);
        Route::delete('/admin/users/{user}', [\App\Http\Controllers\Admin\AdminUserController::class, 'destroy']);
        Route::put('/admin/users/{user}/make-captain', [\App\Http\Controllers\Admin\AdminUserController::class, 'makeCaptain']);
        Route::get('/admin/clans/{tag_coc}/members', [\App\Http\Controllers\Admin\AdminUserController::class, 'clanMembers']);
        
        // Moderation Clans
        Route::get('/admin/clans', [\App\Http\Controllers\Admin\AdminClanController::class, 'index']);
        Route::get('/admin/clans/pending', [\App\Http\Controllers\Admin\AdminClanController::class, 'pendingClans']);
        Route::put('/admin/clans/{clan}/validate', [\App\Http\Controllers\Admin\AdminClanController::class, 'validateClan']);
        Route::put('/admin/clans/{clan}/reject', [\App\Http\Controllers\Admin\AdminClanController::class, 'rejectClan']);
        Route::delete('/admin/clans/{clan}', [\App\Http\Controllers\Admin\AdminClanController::class, 'destroy']);
        Route::put('/admin/clans/{clan}/captain', [\App\Http\Controllers\Admin\AdminClanController::class, 'updateCaptain']);
        Route::put('/admin/clans/{clan}/roster', [\App\Http\Controllers\Admin\AdminClanController::class, 'updateRoster']);
        Route::post('/admin/clans/{clan}/roster/add', [\App\Http\Controllers\Admin\AdminClanController::class, 'addRosterPlayer']);
        Route::delete('/admin/clans/{clan}/roster/{registrationPlayer}', [\App\Http\Controllers\Admin\AdminClanController::class, 'removeRosterPlayer']);
        Route::get('/admin/clans/{clan}/available-players', [\App\Http\Controllers\Admin\AdminClanController::class, 'availablePlayers']);

        // Inscriptions & Paiements
        Route::get('/admin/registrations', [\App\Http\Controllers\Admin\AdminRegistrationController::class, 'index']);
        Route::put('/admin/registrations/{registration}/confirm', [\App\Http\Controllers\Admin\AdminRegistrationController::class, 'confirm']);

        // Gestion Tournoi
        Route::get('/admin/competitions/{competition}/matches', [\App\Http\Controllers\Admin\AdminTournamentController::class, 'matches']);
        Route::post('/admin/competitions/{competition}/generate-bracket', [\App\Http\Controllers\Admin\AdminTournamentController::class, 'generateBracket']);
        Route::put('/admin/matches/{match}', [\App\Http\Controllers\Admin\AdminTournamentController::class, 'updateMatch']);
        Route::delete('/admin/matches/{match}', [\App\Http\Controllers\Admin\AdminTournamentController::class, 'deleteMatch']);
        Route::get('/admin/competitions/{competition}/confirmed-clans', [\App\Http\Controllers\Admin\AdminTournamentController::class, 'confirmedClans']);
        Route::post('/admin/competitions/{competition}/assign-group', [\App\Http\Controllers\Admin\AdminTournamentController::class, 'assignGroup']);
        Route::post('/admin/competitions/{competition}/create-match', [\App\Http\Controllers\Admin\AdminTournamentController::class, 'createMatch']);
        Route::get('/admin/competitions/{competition}/group-standings', [\App\Http\Controllers\Admin\AdminTournamentController::class, 'groupStandings']);
        Route::post('/admin/competitions/{competition}/generate-group-matches', [\App\Http\Controllers\Admin\AdminTournamentController::class, 'generateGroupMatches']);
    });

    // --- Suivi des Paiements Utilisateur ---
    Route::get('/payments/status/{reference}', [\App\Http\Controllers\NotchPayController::class, 'getPaymentStatus']);
});

// --- Public NotchPay Webhook & Callback (Outside Sanctum auth) ---
Route::get('/notchpay/callback', [\App\Http\Controllers\NotchPayController::class, 'callback'])->name('notchpay.callback');
Route::post('/notchpay/webhook', [\App\Http\Controllers\NotchPayController::class, 'webhook'])->name('notchpay.webhook');


