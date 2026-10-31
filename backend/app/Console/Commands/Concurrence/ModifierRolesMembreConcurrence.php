<?php

namespace App\Console\Commands\Concurrence;

use App\Models\EtablissementUtilisateur;
use App\Models\User;
use App\Services\Equipe\ModifierMembreEquipe;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Un seul appel à ModifierMembreEquipe dans un processus séparé, pour le
 * test de concurrence réelle du dernier administrateur (voir
 * DernierAdministrateurConcurrentTest). --depart : instant convenu auquel
 * tous les processus appellent le service, voir
 * GenererLienWhatsappConcurrence. Résultat en JSON sur stdout.
 */
class ModifierRolesMembreConcurrence extends Command
{
    protected $signature = 'concurrence:modifier-roles-membre
        {--membre= : id de la ligne etablissement_utilisateurs à modifier}
        {--acteur= : id de l\'utilisateur qui agit}
        {--roles= : ids des nouveaux rôles, séparés par des virgules}
        {--depart= : microtime(true) auquel appeler le service}';

    protected $description = 'Exécute un seul appel à ModifierMembreEquipe dans un processus séparé (résultat JSON sur stdout).';

    public function handle(ModifierMembreEquipe $modifier): int
    {
        $membre = EtablissementUtilisateur::findOrFail((int) $this->option('membre'));
        app(ContexteEtablissement::class)->definir($membre->etablissement);
        $acteur = User::findOrFail((int) $this->option('acteur'));
        $roles = array_map('intval', explode(',', (string) $this->option('roles')));

        $attente = (float) $this->option('depart') - microtime(true);
        if ($attente > 0) {
            usleep((int) ($attente * 1_000_000));
        }

        try {
            $modifier->executer($membre, null, $roles, $acteur, null);
            $this->line(json_encode(['ok' => true, 'membre' => $membre->id]));
        } catch (ValidationException $exception) {
            $this->line(json_encode(['ok' => false, 'membre' => $membre->id, 'refus' => $exception->errors()['role_ids'][0] ?? null], JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
