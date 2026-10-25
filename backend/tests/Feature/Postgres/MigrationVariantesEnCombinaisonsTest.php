<?php

namespace Tests\Feature\Postgres;

use App\Enums\Canal;
use App\Enums\SourceCommande;
use App\Enums\StatutReservation;
use App\Models\Etablissement;
use App\Models\LigneCommande;
use App\Models\OptionProduit;
use App\Models\Produit;
use App\Models\ReservationStock;
use App\Models\VarianteProduit;
use App\Services\Commandes\CreerCommande;
use App\Services\Commandes\LigneCommandeDemandee;
use App\Services\Stock\ConsommerReservation;
use Illuminate\Support\Facades\Schema;
use Tests\PostgresTestCase;

/**
 * B1 — la migration des variantes à nom libre en combinaisons ne perd
 * rien. Le test revient au schéma d'avant B1 (rollback des trois
 * migrations), y recrée les 8 variantes de démo telles qu'elles
 * existaient (avec une commande confirmée et une réservation active),
 * relance la migration et compare ligne à ligne.
 */
class MigrationVariantesEnCombinaisonsTest extends PostgresTestCase
{
    private const MIGRATIONS_B1 = [
        'database/migrations/2026_10_25_000000_creer_options_valeurs_et_combinaisons.php',
        'database/migrations/2026_10_25_000001_migrer_variantes_en_combinaisons.php',
        'database/migrations/2026_10_25_000002_ajouter_parent_aux_categories.php',
    ];

    public function test_les_8_variantes_de_demo_deviennent_des_combinaisons_sans_rien_perdre(): void
    {
        // --step compte les DERNIÈRES migrations passées, avant le filtre par
        // --path : avec un « 3 » fixe, la première migration ajoutée après
        // B1 suffisait à laisser une migration de B1 en place. On recule donc
        // de toutes les migrations depuis la première de B1 ; celles qui ne
        // sont pas de B1 sont écartées par --path et restent appliquées.
        $depuisB1 = count(array_filter(
            glob(database_path('migrations/*.php')),
            fn (string $fichier) => basename($fichier) >= basename(self::MIGRATIONS_B1[0]),
        ));
        $this->artisan('migrate:rollback', ['--database' => 'pgsql_testing', '--path' => self::MIGRATIONS_B1, '--step' => $depuisB1, '--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('options_produit'), 'le schéma est bien revenu avant B1');
        $this->assertTrue(Schema::hasColumn('variantes_produit', 'attributs_json'));

        [$riz, $poulet, $wax] = $this->recreerLesVariantesDAvant();

        $avant = $this->instantane();
        $this->assertCount(8, $avant['variantes']);

        $this->artisan('migrate', ['--database' => 'pgsql_testing', '--path' => self::MIGRATIONS_B1, '--force' => true])->assertSuccessful();

        $apres = $this->instantane();
        $this->assertCount(8, $apres['variantes'], 'compte avant = compte après');
        $this->assertSame($avant, $apres, 'identifiants, prix, stocks, réservés, disponibilité, statut, dates, lignes de commande et réservations identiques');
        $this->assertFalse(Schema::hasColumn('variantes_produit', 'attributs_json'));

        $this->assertSame(
            ['Conditionnement' => ['1 kg', 'Sac de 5 kg', 'Sac de 25 kg'], 'Portion' => ['Quart', 'Demi'], 'Longueur' => ['2 yards', '6 yards', '12 yards']],
            collect([$riz, $poulet, $wax])->mapWithKeys(fn (Produit $produit) => $this->optionsDe($produit))->all(),
        );

        foreach (VarianteProduit::pourTousEtablissements()->with(['valeurs' => fn ($requete) => $requete->pourTousEtablissements()])->get() as $variante) {
            $this->assertCount(1, $variante->valeurs, "{$variante->nom} porte exactement une valeur");
            $this->assertSame($variante->nom, $variante->valeurs->first()->libelle);
            $this->assertSame((string) $variante->valeurs->first()->id, $variante->signature);
        }
    }

    /**
     * @return array{0: Produit, 1: Produit, 2: Produit}
     */
    private function recreerLesVariantesDAvant(): array
    {
        $chezAwa = Etablissement::factory()->create(['slug' => 'chez-awa', 'type' => 'boutique']);
        $maquis = Etablissement::factory()->create(['slug' => 'maquis-du-port', 'type' => 'restaurant']);
        $waxAdjame = Etablissement::factory()->create(['slug' => 'wax-adjame', 'type' => 'boutique']);

        $riz = $chezAwa->produits()->create(['nom' => 'Riz parfumé long grain', 'slug' => 'riz-parfume-long-grain', 'prix' => 1000, 'statut' => 'publie']);
        $poulet = $maquis->produits()->create(['nom' => 'Poulet braisé', 'slug' => 'poulet-braise', 'prix' => 1500, 'statut' => 'publie']);
        $wax = $waxAdjame->produits()->create(['nom' => 'Wax imprimé « Bouche de Sika »', 'slug' => 'wax-imprime-bouche-de-sika', 'prix' => 6000, 'statut' => 'publie']);

        $lignes = [
            [$riz, '1 kg', 1000, 40, true],
            [$riz, 'Sac de 5 kg', 4500, 12, true],
            [$riz, 'Sac de 25 kg', 21000, 3, true],
            [$poulet, 'Quart', 1500, 0, true],
            [$poulet, 'Demi', 3000, 0, false],
            [$wax, '2 yards', 6000, 8, true],
            [$wax, '6 yards', 14000, 5, true],
            [$wax, '12 yards', 26000, 1, true],
        ];
        foreach ($lignes as [$produit, $nom, $prix, $stock, $disponible]) {
            $variante = (new VarianteProduit(['produit_id' => $produit->id, 'nom' => $nom, 'prix' => $prix, 'quantite_stock' => $stock, 'disponible' => $disponible]))
                ->pourEtablissement($produit->etablissement_id);
            // Une date passée : une migration qui réécrirait updated_at le
            // trahirait, ce qu'une date de la seconde même ne ferait pas.
            $variante->timestamps = false;
            $variante->created_at = $variante->updated_at = '2026-10-01 09:30:00';
            $variante->save();
        }

        $sacDe5 = VarianteProduit::pourTousEtablissements()->where('nom', 'Sac de 5 kg')->firstOrFail();
        $sixYards = VarianteProduit::pourTousEtablissements()->where('nom', '6 yards')->firstOrFail();
        $creer = app(CreerCommande::class);
        $creer->executer($chezAwa, $chezAwa->clients()->create(['nom' => 'Client en attente']), [new LigneCommandeDemandee($riz->id, $sacDe5->id, 2)], Canal::Web, SourceCommande::PanierWeb, 'avant-b1-attente');
        $confirmee = $creer->executer($waxAdjame, $waxAdjame->clients()->create(['nom' => 'Client confirmé']), [new LigneCommandeDemandee($wax->id, $sixYards->id, 1)], Canal::Web, SourceCommande::PanierWeb, 'avant-b1-confirmee');
        app(ConsommerReservation::class)->executer($confirmee);

        $this->assertSame(StatutReservation::Active, ReservationStock::pourTousEtablissements()->where('variante_id', $sacDe5->id)->value('statut'));

        return [$riz, $poulet, $wax];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function instantane(): array
    {
        return [
            'variantes' => VarianteProduit::pourTousEtablissements()->orderBy('id')->get()
                ->map(fn (VarianteProduit $variante) => $variante->only(['id', 'etablissement_id', 'produit_id', 'nom', 'reference', 'prix', 'quantite_stock', 'quantite_reservee', 'disponible', 'statut']) + ['updated_at' => (string) $variante->updated_at])
                ->all(),
            'lignes_commande' => LigneCommande::pourTousEtablissements()->orderBy('id')->get()
                ->map(fn (LigneCommande $ligne) => $ligne->only(['id', 'produit_id', 'variante_id', 'nom_produit_capture', 'prix_unitaire', 'quantite', 'total']))
                ->all(),
            'reservations' => ReservationStock::pourTousEtablissements()->orderBy('id')->get()
                ->map(fn (ReservationStock $reservation) => $reservation->only(['id', 'variante_id', 'quantite']) + ['statut' => $reservation->statut->value])
                ->all(),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function optionsDe(Produit $produit): array
    {
        $option = OptionProduit::pourTousEtablissements()->where('produit_id', $produit->id)->sole();

        return [$option->nom => $option->valeurs()->pourTousEtablissements()->pluck('libelle')->all()];
    }
}
