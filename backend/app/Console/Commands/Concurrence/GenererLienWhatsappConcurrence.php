<?php

namespace App\Console\Commands\Concurrence;

use App\Models\Produit;
use App\Services\Vitrine\GenerateurLienWhatsapp;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Console\Command;

/**
 * Un seul appel à GenerateurLienWhatsapp dans un processus séparé, pour le
 * test de concurrence réelle de son verrou (voir Tests\ConcurrenceTestCase
 * et GenerationLienWhatsappConcurrenteTest). --depart : instant convenu
 * (microtime) auquel tous les processus lancés ensemble appellent le
 * service — sans ce point de rendez-vous, le temps de démarrage de chaque
 * processus (plusieurs centaines de ms) les sérialiserait d'office et le
 * test passerait même sans verrou. Résultat en JSON sur stdout.
 */
class GenererLienWhatsappConcurrence extends Command
{
    protected $signature = 'concurrence:generer-lien-whatsapp
        {--produit= : id du produit}
        {--visiteur= : clé de visiteur (empreinte)}
        {--depart= : microtime(true) auquel appeler le service}';

    protected $description = 'Exécute un seul appel à GenerateurLienWhatsapp dans un processus séparé (résultat JSON sur stdout).';

    public function handle(GenerateurLienWhatsapp $generateur): int
    {
        // Ce processus n'a aucun contexte ambiant : on pose celui de
        // l'établissement du produit, comme ResoudreEtablissement le ferait
        // pour une vraie requête de la vitrine.
        $produit = Produit::pourTousEtablissements()->findOrFail((int) $this->option('produit'));
        app(ContexteEtablissement::class)->definir($produit->etablissement);

        $attente = (float) $this->option('depart') - microtime(true);
        if ($attente > 0) {
            usleep((int) ($attente * 1_000_000));
        }

        $url = $generateur->generer($produit, null, '2250700000000', (string) $this->option('visiteur'));
        preg_match('/\[REF:([A-Za-z0-9]{8})\]/', rawurldecode($url), $m);

        $this->line(json_encode(['ok' => true, 'reference' => $m[1] ?? null]));

        return self::SUCCESS;
    }
}
