<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\PostgresTestCase;

/**
 * Tests\PostgresTestCase surcharge RefreshDatabase::refreshTestDatabase()
 * (Laravel 13.33.0) pour que la base PostgreSQL soit remigrée dans la
 * suite complète. Si Laravel renomme cette méthode ou son drapeau
 * RefreshDatabaseState::$migrated, la surcharge ne s'applique plus, sans
 * erreur — et les tests PostgreSQL retournent sur un schéma périmé. Ce
 * test casse avant.
 */
class RefreshDatabaseInchangeTest extends TestCase
{
    public function test_la_methode_surchargee_existe_toujours_dans_laravel(): void
    {
        $this->assertTrue(
            (new ReflectionClass(RefreshDatabase::class))->hasMethod('refreshTestDatabase'),
            'RefreshDatabase::refreshTestDatabase() a disparu : revoir PostgresTestCase::refreshTestDatabase().',
        );
        $this->assertTrue(
            (new ReflectionClass(RefreshDatabaseState::class))->hasProperty('migrated'),
            'RefreshDatabaseState::$migrated a disparu : revoir PostgresTestCase::refreshTestDatabase().',
        );
        // Le fichier, pas la classe déclarante : une méthode venue d'un
        // trait est « déclarée » par la classe qui l'utilise.
        $this->assertStringEndsWith(
            'PostgresTestCase.php',
            (new ReflectionClass(PostgresTestCase::class))->getMethod('refreshTestDatabase')->getFileName(),
            'PostgresTestCase ne surcharge plus refreshTestDatabase().',
        );
    }
}
