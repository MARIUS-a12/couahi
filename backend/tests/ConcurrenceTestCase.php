<?php

namespace Tests;

use App\Models\Client;
use App\Models\Etablissement;
use App\Support\Tenancy\ContexteEtablissement;
use Symfony\Component\Process\Process;

/**
 * Base des tests de concurrence RÉELLE : plusieurs processus `php artisan`
 * séparés (Process de Symfony — Windows n'a pas pcntl_fork), chacun avec sa
 * propre connexion PDO à `saas_boutiques_test`, lancés puis attendus pour
 * qu'ils se recouvrent réellement dans le temps.
 *
 * PostgresTestCase enveloppe chaque test dans une transaction annulée en fin
 * de test — parfait pour l'isolation rapide, mais invisible depuis un AUTRE
 * processus tant qu'elle n'est pas validée. connectionsToTransact() vide
 * désactive cette enveloppe : les données créées ici sont de VRAIS commits,
 * visibles des processus enfants. En contrepartie, rien n'est nettoyé
 * automatiquement — nettoyerAvec() enregistre chaque établissement créé pour
 * le supprimer (et tout ce qui en dépend, par cascade) en tearDown().
 */
abstract class ConcurrenceTestCase extends PostgresTestCase
{
    /** @var list<int> */
    private array $etablissementsACreer = [];

    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        if ($this->etablissementsACreer !== []) {
            Etablissement::whereIn('id', $this->etablissementsACreer)->delete();
        }

        parent::tearDown();
    }

    /**
     * Enregistre un établissement fraîchement créé pour suppression en
     * tearDown (cascade sur clients, produits, variantes, commandes...), et
     * pose le contexte ambiant sur lui : ScopeEtablissement filtre TOUJOURS
     * désormais (plus d'échappatoire console) — sans ce contexte, les
     * assertions du test lui-même (ex. `$commande->reservations()->count()`)
     * lèveraient, alors qu'elles portent sur cet établissement précis. Les
     * processus concurrence:* enfants, eux, n'en héritent jamais (nouveau
     * processus, nouveau conteneur) et restent corrects par leurs propres
     * moyens (voir CreerCommande, pourTousEtablissements()).
     */
    protected function nettoyerAvec(Etablissement $etablissement): Etablissement
    {
        $this->etablissementsACreer[] = $etablissement->id;

        app(ContexteEtablissement::class)->definir($etablissement);

        return $etablissement;
    }

    /**
     * @param  list<array{produit_id:int,variante_id?:int|null,quantite:int}>  $lignes
     * @return list<string> arguments artisan (sans "php artisan")
     */
    protected function commandeCreerCommande(
        Etablissement $etablissement,
        Client $client,
        array $lignes,
        string $cle,
        string $canal = 'web',
        string $source = 'panier_web',
        ?string $delaiVerrou = null,
    ): array {
        return [
            'concurrence:creer-commande',
            '--etablissement='.$etablissement->id,
            '--client='.$client->id,
            '--lignes='.json_encode($lignes),
            '--canal='.$canal,
            '--source='.$source,
            '--cle='.$cle,
            ...($delaiVerrou ? ['--delai-verrou='.$delaiVerrou] : []),
        ];
    }

    /**
     * @return list<string>
     */
    protected function commandeLibererExpirees(): array
    {
        return ['concurrence:liberer-expirees'];
    }

    /**
     * Démarre TOUS les processus avant d'attendre le premier : c'est ce qui
     * les fait réellement se chevaucher, contrairement à un run()/wait() par
     * processus qui les exécuterait en série malgré les apparences.
     *
     * $environnement complète celui des enfants — ex. CACHE_STORE=database
     * pour un verrou de cache, que le store "array" des tests ne partagerait
     * jamais entre deux processus.
     *
     * @param  list<list<string>>  $commandes  chaque élément : arguments artisan (sans "php artisan")
     * @param  array<string,string>  $environnement
     * @return list<array{exitCode:int,output:string,errorOutput:string}>
     */
    protected function executerEnParallele(array $commandes, int $timeout = 30, array $environnement = []): array
    {
        $processus = array_map(function (array $arguments) use ($timeout, $environnement): Process {
            $process = new Process(
                [PHP_BINARY, base_path('artisan'), ...$arguments],
                base_path(),
                ['DB_CONNECTION' => 'pgsql_testing', ...$environnement],
                null,
                $timeout,
            );
            $process->start();

            return $process;
        }, $commandes);

        foreach ($processus as $process) {
            $process->wait();
        }

        return array_map(fn (Process $p) => [
            'exitCode' => $p->getExitCode(),
            'output' => trim($p->getOutput()),
            'errorOutput' => $p->getErrorOutput(),
        ], $processus);
    }

    /**
     * Décode la sortie JSON d'un processus concurrence:*. Un processus qui
     * n'a pas produit de JSON a échoué de façon inattendue (bug réel, pas un
     * résultat métier normal) : on fait échouer le test immédiatement, avec
     * stdout ET stderr, plutôt que de laisser un json_decode(null) silencieux
     * masquer la vraie cause.
     *
     * @param  array{exitCode:int,output:string,errorOutput:string}  $resultat
     */
    protected function decoderResultat(array $resultat): array
    {
        $donnees = json_decode($resultat['output'], true);

        if (! is_array($donnees)) {
            $this->fail(
                "Sortie inattendue d'un processus concurrence:* (code de sortie {$resultat['exitCode']}) :\n".
                "--- STDOUT ---\n{$resultat['output']}\n".
                "--- STDERR ---\n{$resultat['errorOutput']}"
            );
        }

        return $donnees;
    }

    /**
     * @param  list<array<string,mixed>>  $resultats
     */
    protected function resume(array $resultats): string
    {
        return json_encode($resultats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
