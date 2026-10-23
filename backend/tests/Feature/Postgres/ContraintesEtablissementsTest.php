<?php

namespace Tests\Feature\Postgres;

use App\Models\Etablissement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

/**
 * Le CHECK sur etablissements.type, prouvé en tentant réellement
 * l'insertion et la modification invalides sur PostgreSQL. type n'est pas
 * casté en enum côté modèle : Eloquent transmet la valeur telle quelle, et
 * c'est bien la base qui refuse.
 */
class ContraintesEtablissementsTest extends PostgresTestCase
{
    public function test_un_type_inconnu_est_refuse_a_la_creation(): void
    {
        $this->assertRefuseeParContrainte(fn () => Etablissement::factory()->create(['type' => 'epicerie']));

        $this->assertSame(0, Etablissement::count());
    }

    public function test_un_type_inconnu_est_refuse_en_modification(): void
    {
        $etablissement = Etablissement::factory()->create(['type' => 'boutique']);

        $this->assertRefuseeParContrainte(fn () => $etablissement->update(['type' => 'Restaurant']));

        $this->assertSame('boutique', $etablissement->fresh()->type);
    }

    public function test_les_deux_types_connus_sont_acceptes(): void
    {
        Etablissement::factory()->create(['type' => 'boutique']);
        Etablissement::factory()->create(['type' => 'restaurant']);

        $this->assertSame(2, Etablissement::count());
    }

    private function assertRefuseeParContrainte(callable $ecriture): void
    {
        try {
            DB::transaction($ecriture);

            $this->fail("L'écriture aurait dû être refusée par etablissements_type_valide.");
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
            $this->assertStringContainsString('etablissements_type_valide', $e->getMessage());
        }
    }
}
