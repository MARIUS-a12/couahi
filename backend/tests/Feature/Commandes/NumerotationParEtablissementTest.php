<?php

namespace Tests\Feature\Commandes;

use App\Enums\Canal;
use App\Enums\SourceCommande;
use App\Models\Etablissement;
use App\Models\Produit;
use App\Services\Commandes\CreerCommande;
use App\Services\Commandes\GenererNumeroCommande;
use App\Services\Commandes\LigneCommandeDemandee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Chaque établissement numérote ses commandes depuis CMD-000001 : un
 * nouveau commerçant ne voit jamais un numéro qui trahirait l'activité des
 * autres boutiques. La concurrence réelle est vérifiée sur PostgreSQL (voir
 * NumerotationConcurrenteTest) ; ici, la règle elle-même.
 */
class NumerotationParEtablissementTest extends TestCase
{
    use RefreshDatabase;

    private function commander(Etablissement $etablissement, string $cle): string
    {
        $client = $etablissement->clients()->pourTousEtablissements()->firstOrCreate(['nom' => 'Client Test', 'etablissement_id' => $etablissement->id]);
        $produit = Produit::factory()->for($etablissement)->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);

        return app(CreerCommande::class)->executer(
            $etablissement, $client, [new LigneCommandeDemandee($produit->id, null, 1)],
            Canal::Web, SourceCommande::PanierWeb, $cle,
        )->numero;
    }

    public function test_chaque_etablissement_commence_a_1_et_compte_independamment(): void
    {
        $a = Etablissement::factory()->boutique()->create();
        $b = Etablissement::factory()->boutique()->create();

        $this->assertSame('CMD-000001', $this->commander($a, 'a-1'));
        $this->assertSame('CMD-000002', $this->commander($a, 'a-2'));
        $this->assertSame('CMD-000001', $this->commander($b, 'b-1'));
        $this->assertSame('CMD-000003', $this->commander($a, 'a-3'));
    }

    public function test_une_commande_annulee_par_rollback_rend_son_numero(): void
    {
        $etablissement = Etablissement::factory()->boutique()->create();
        $generateur = app(GenererNumeroCommande::class);

        try {
            DB::transaction(function () use ($generateur, $etablissement) {
                $this->assertSame('CMD-000001', $generateur->executer($etablissement->id));

                throw new RuntimeException('échec après la prise du numéro');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('CMD-000001', $this->commander($etablissement, 'apres-echec'));
    }

    public function test_la_migration_reprend_apres_le_plus_grand_numero_deja_attribue(): void
    {
        // Numéros de l'ancienne séquence GLOBALE : entrelacés entre boutiques.
        $a = Etablissement::factory()->boutique()->create();
        $b = Etablissement::factory()->boutique()->create();
        $neuve = Etablissement::factory()->boutique()->create();
        $this->commander($a, 'a');
        $this->commander($b, 'b');

        $migration = require database_path('migrations/2026_10_20_000001_numeroter_commandes_par_etablissement.php');
        $migration->down();
        DB::table('commandes')->where('etablissement_id', $a->id)->update(['numero' => 'CMD-000016']);
        DB::table('commandes')->where('etablissement_id', $b->id)->update(['numero' => 'CMD-000017']);
        $migration->up();

        // Aucune commande existante renumérotée, aucune collision possible,
        // et une boutique sans commande repart à 1.
        $this->assertSame('CMD-000017', $this->commander($a, 'a-apres'));
        $this->assertSame('CMD-000018', $this->commander($b, 'b-apres'));
        $this->assertSame('CMD-000001', $this->commander($neuve, 'neuve'));
    }
}
