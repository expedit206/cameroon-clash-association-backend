<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CocApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Synchronise les données CoC (clan, HDV, niveau) de tous les joueurs actifs.
 * Peut être exécuté manuellement : php artisan coc:sync-users
 * Ou planifié via le scheduler Laravel (toutes les 6h par exemple).
 */
class SyncUsersCocData extends Command
{
    protected $signature = 'coc:sync-users 
                            {--limit=0 : Nombre maximum d\'utilisateurs à traiter (0 = tous)}
                            {--chunk=50 : Nombre d\'utilisateurs traités par batch}';

    protected $description = 'Synchronise les données Clash of Clans (clan actuel, HDV, niveau) de tous les joueurs avec l\'API officielle.';

    public function __construct(protected CocApiService $cocApi)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $chunk = (int) $this->option('chunk');

        $query = User::where('role', '!=', 'admin')
            ->where('is_active', true)
            ->whereNotNull('tag_coc');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = $query->count();
        $this->info("Synchronisation de {$total} joueurs...");

        $updated = 0;
        $errors  = 0;

        $query->chunk($chunk, function ($users) use (&$updated, &$errors) {
            foreach ($users as $user) {
                try {
                    $cocPlayer = $this->cocApi->getPlayer($user->tag_coc);

                    if (!$cocPlayer) {
                        $errors++;
                        continue;
                    }

                    $newClanTag = $cocPlayer['clan']['tag'] ?? null;

                    $user->update([
                        'name'             => $cocPlayer['name']            ?? $user->name,
                        'hdv_level'        => $cocPlayer['townHallLevel']   ?? $user->hdv_level,
                        'current_clan_tag' => $newClanTag,
                        'exp_level'        => $cocPlayer['expLevel']        ?? $user->exp_level,
                        'league_icon'      => $cocPlayer['league']['iconUrls']['small'] 
                                             ?? $cocPlayer['league']['iconUrls']['medium'] 
                                             ?? $user->league_icon,
                    ]);

                    $updated++;

                    // Log si le clan a changé
                    if ($user->getOriginal('current_clan_tag') !== $newClanTag) {
                        Log::info("[CoC Sync] Clan mis à jour pour {$user->tag_coc}", [
                            'old' => $user->getOriginal('current_clan_tag'),
                            'new' => $newClanTag,
                        ]);
                    }

                    // Petite pause pour ne pas surcharger l'API CoC
                    usleep(200_000); // 200ms entre chaque requête
                } catch (\Exception $e) {
                    $errors++;
                    Log::error("[CoC Sync] Erreur pour {$user->tag_coc}: " . $e->getMessage());
                }
            }
        });

        $this->info("✓ Sync terminée : {$updated} mis à jour, {$errors} erreurs.");

        return Command::SUCCESS;
    }
}
