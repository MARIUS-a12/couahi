<?php

namespace App\Console\Commands\Concurrence;

use App\Enums\Canal;
use App\Enums\SourceCommande;
use App\Exceptions\ArticleIndisponibleException;
use App\Exceptions\SelectionVarianteInvalideException;
use App\Models\Etablissement;
use App\Services\Commandes\CreerCommande;
use App\Services\Commandes\LigneCommandeDemandee;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Point d'entrée process-isolé pour les tests de concurrence réelle
 * (voir Tests\ConcurrenceTestCase) : Windows n'a pas pcntl_fork, la seule
 * façon de tester une VRAIE concurrence PostgreSQL est donc de lancer
 * plusieurs processus `php artisan` indépendants (composant Process de
 * Symfony), chacun avec sa propre connexion PDO, plutôt que des threads ou
 * coroutines internes à un même processus PHP qui partageraient une
 * transaction.
 *
 * Le résultat est imprimé en JSON sur stdout, seule sortie de la commande,
 * pour que le processus parent puisse le décoder sans ambiguïté. Une
 * exception métier attendue (article indisponible, sélection invalide) est
 * un résultat normal du point de vue du test de concurrence — elle est
 * encodée dans le JSON avec un code de sortie SUCCESS. Toute autre exception
 * remonte telle quelle : Artisan l'imprime sur stderr et sort en échec, ce
 * que Tests\ConcurrenceTestCase::decoderResultat() fait échouer bruyamment.
 *
 * Avec --delai-verrou (lock_timeout de la session) : une attente anormale
 * échoue franchement au lieu de bloquer le test, et une erreur de BASE
 * (interblocage 40P01, délai de verrou dépassé 55P03...) est encodée avec
 * son SQLSTATE, pour qu'un test puisse affirmer qu'aucune n'est survenue.
 */
class CreerCommandeConcurrence extends Command
{
    protected $signature = 'concurrence:creer-commande
        {--etablissement= : id de l\'établissement}
        {--client= : id du client}
        {--lignes= : JSON, ex. [{"produit_id":1,"variante_id":null,"quantite":1}]}
        {--canal=web : web|whatsapp}
        {--source=panier_web}
        {--cle= : clé d\'idempotence}
        {--delai-verrou= : lock_timeout PostgreSQL de la session, ex. 5s}';

    protected $description = 'Exécute un seul appel à CreerCommande dans un processus séparé (résultat JSON sur stdout).';

    public function handle(CreerCommande $service): int
    {
        // pourTousEtablissements() : ce processus n'a et n'aura jamais de
        // contexte d'établissement ambiant (voir ScopeEtablissement) — les id
        // reçus en option sont la seule source de vérité.
        $etablissement = Etablissement::findOrFail((int) $this->option('etablissement'));
        $client = $etablissement->clients()->pourTousEtablissements()->findOrFail((int) $this->option('client'));

        $lignes = array_map(
            fn (array $ligne) => new LigneCommandeDemandee(
                (int) $ligne['produit_id'],
                isset($ligne['variante_id']) ? (int) $ligne['variante_id'] : null,
                (int) $ligne['quantite'],
            ),
            json_decode((string) $this->option('lignes'), true, flags: JSON_THROW_ON_ERROR),
        );

        if ($this->option('delai-verrou')) {
            DB::statement('set lock_timeout = '.DB::getPdo()->quote((string) $this->option('delai-verrou')));
        }

        try {
            $commande = $service->executer(
                $etablissement,
                $client,
                $lignes,
                Canal::from($this->option('canal')),
                SourceCommande::from($this->option('source')),
                (string) $this->option('cle'),
            );

            $this->line(json_encode([
                'ok' => true,
                'commande_id' => $commande->id,
                'numero' => $commande->numero,
            ]));
        } catch (ArticleIndisponibleException $e) {
            $this->line(json_encode([
                'ok' => false,
                'raison' => 'indisponible',
                'article' => $e->nomArticle(),
            ]));
        } catch (SelectionVarianteInvalideException $e) {
            $this->line(json_encode([
                'ok' => false,
                'raison' => 'selection_invalide',
                'message' => $e->getMessage(),
            ]));
        } catch (QueryException $e) {
            // Seulement quand le test l'a demandé : ailleurs, une erreur de
            // base doit toujours faire échouer bruyamment (voir docblock),
            // jamais compter comme un échec métier ordinaire.
            if (! $this->option('delai-verrou')) {
                throw $e;
            }

            $this->line(json_encode([
                'ok' => false,
                'raison' => 'base',
                'sqlstate' => $e->getCode(),
                'message' => $e->getMessage(),
            ]));
        }

        return self::SUCCESS;
    }
}
