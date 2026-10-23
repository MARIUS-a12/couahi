<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Base des tests de la suite `Postgres` : tout ce qui ne peut être vérifié
 * que sur un vrai moteur PostgreSQL (contraintes CHECK, verrous,
 * transactions). Bascule la connexion par défaut sur `pgsql_testing`
 * (base dédiée `saas_boutiques_test`) avant même que RefreshDatabase ne
 * migre quoi que ce soit, pour que ni les migrations ni les requêtes du
 * test ne touchent la base de développement ni la suite rapide SQLite.
 */
abstract class PostgresTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Bascule la connexion par défaut juste après le boot de l'application,
     * donc avant que RefreshDatabase (déclenché ensuite par setUpTraits())
     * ne migre ou n'ouvre sa transaction de test.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $app['config']->set('database.default', 'pgsql_testing');

        return $app;
    }

    /**
     * RefreshDatabase ne migre qu'une fois par processus (un seul drapeau,
     * RefreshDatabaseState::$migrated), quelle que soit la base. Dans la
     * suite complète, les tests SQLite passent d'abord et lèvent ce
     * drapeau : la base PostgreSQL n'était alors jamais remigrée, et ces
     * tests tournaient sur le schéma laissé par le dernier lancement isolé
     * — une nouvelle table y manquait (SQLSTATE 42P01). Cette base a donc
     * son propre drapeau.
     *
     * Recopie de Illuminate\Foundation\Testing\RefreshDatabase::
     * refreshTestDatabase(), Laravel 13.33.0 (lue dans vendor/ pour en
     * reprendre exactement le déroulé : migrateDatabases(), puis
     * setArtisan(null), puis beginDatabaseTransaction()), moins
     * updateLocalCacheOfInMemoryDatabases(), sans objet pour PostgreSQL.
     * Une mise à jour de Laravel qui renomme cette méthode ou ce drapeau
     * rendrait cette surcharge silencieusement inopérante :
     * RefreshDatabaseInchangeTest casse alors, et c'est ici qu'il faut
     * revenir (voir decisions.md, « Le harnais PostgreSQL recopie une
     * méthode de Laravel »).
     */
    private static bool $postgresMigree = false;

    protected function refreshTestDatabase(): void
    {
        if (! self::$postgresMigree) {
            $this->migrateDatabases();
            $this->app[Kernel::class]->setArtisan(null);
            self::$postgresMigree = true;
        }

        $this->beginDatabaseTransaction();
    }

    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => 'pgsql_testing',
            '--realpath' => true,
        ];
    }
}
