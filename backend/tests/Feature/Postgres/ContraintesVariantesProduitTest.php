<?php

namespace Tests\Feature\Postgres;

use App\Models\Etablissement;
use App\Models\Produit;
use App\Models\VarianteProduit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

/**
 * Chaque CHECK posé par la migration de garde-fous sur `variantes_produit`
 * est prouvé ici en tentant réellement l'insertion invalide sur
 * PostgreSQL. Voir ContraintesProduitsTest pour pourquoi statut_valide
 * passe par une insertion SQL brute plutôt que par le modèle.
 */
class ContraintesVariantesProduitTest extends PostgresTestCase
{
    /**
     * Même remarque que ContraintesProduitsTest : quantite_stock négatif
     * viole aussi nécessairement quantite_reservee_sous_stock, impossible à
     * isoler seule.
     */
    public function test_quantite_stock_negative_est_refusee(): void
    {
        $produit = Produit::factory()->for(Etablissement::factory())->create();

        $this->assertInsertionRefuseeParUneDesContraintes(
            fn () => VarianteProduit::factory()->pourProduit($produit)->create(['quantite_stock' => -1]),
            ['variantes_produit_quantite_stock_positive', 'variantes_produit_quantite_reservee_sous_stock'],
        );

        $this->assertSame(0, VarianteProduit::pourTousEtablissements()->count());
    }

    public function test_quantite_reservee_negative_est_refusee(): void
    {
        $produit = Produit::factory()->for(Etablissement::factory())->create();

        $this->assertInsertionRefuseeParContrainte(
            fn () => VarianteProduit::factory()->pourProduit($produit)->create(['quantite_reservee' => -1]),
            'variantes_produit_quantite_reservee_positive',
        );

        $this->assertSame(0, VarianteProduit::pourTousEtablissements()->count());
    }

    public function test_quantite_reservee_superieure_au_stock_est_refusee(): void
    {
        $produit = Produit::factory()->for(Etablissement::factory())->create();

        $this->assertInsertionRefuseeParContrainte(
            fn () => VarianteProduit::factory()->pourProduit($produit)->create([
                'quantite_stock' => 5,
                'quantite_reservee' => 9,
            ]),
            'variantes_produit_quantite_reservee_sous_stock',
        );

        $this->assertSame(0, VarianteProduit::pourTousEtablissements()->count());
    }

    public function test_prix_negatif_est_refuse(): void
    {
        $produit = Produit::factory()->for(Etablissement::factory())->create();

        $this->assertInsertionRefuseeParContrainte(
            fn () => VarianteProduit::factory()->pourProduit($produit)->create(['prix' => -100]),
            'variantes_produit_prix_positif',
        );

        $this->assertSame(0, VarianteProduit::pourTousEtablissements()->count());
    }

    public function test_statut_invalide_est_refuse(): void
    {
        $produit = Produit::factory()->for(Etablissement::factory())->create();

        $this->assertInsertionRefuseeParContrainte(
            fn () => DB::table('variantes_produit')->insert([
                'etablissement_id' => $produit->etablissement_id,
                'produit_id' => $produit->id,
                'nom' => 'Variante test brute',
                // Obligatoire depuis B1 (une variante est une combinaison) :
                // une signature quelconque, pour n'isoler que le statut.
                'signature' => '1',
                'quantite_stock' => 0,
                'quantite_reservee' => 0,
                'disponible' => true,
                'statut' => 'invalide',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'variantes_produit_statut_valide',
        );

        $this->assertSame(0, VarianteProduit::pourTousEtablissements()->count());
    }

    private function assertInsertionRefuseeParContrainte(callable $insertion, string $nomContrainte): void
    {
        $this->assertInsertionRefuseeParUneDesContraintes($insertion, [$nomContrainte]);
    }

    private function assertInsertionRefuseeParUneDesContraintes(callable $insertion, array $nomsContraintes): void
    {
        try {
            DB::transaction($insertion);

            $this->fail('L\'insertion aurait dû être refusée par l\'une de ces contraintes : '.implode(', ', $nomsContraintes));
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());

            $trouvee = array_filter($nomsContraintes, fn (string $nom) => str_contains($e->getMessage(), $nom));
            $this->assertNotEmpty($trouvee, "Aucune des contraintes attendues n'apparaît dans : {$e->getMessage()}");
        }
    }
}
