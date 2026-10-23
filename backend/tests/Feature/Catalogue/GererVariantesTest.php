<?php

namespace Tests\Feature\Catalogue;

use App\Enums\Canal;
use App\Enums\SourceCommande;
use App\Enums\StatutReservation;
use App\Models\Etablissement;
use App\Models\LigneCommande;
use App\Models\Produit;
use App\Models\VarianteProduit;
use App\Services\Catalogue\GererVariantes;
use App\Services\Commandes\CreerCommande;
use App\Services\Commandes\LigneCommandeDemandee;
use App\Services\Stock\ConsommerReservation;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * GererVariantes, seul auteur de la composition des variantes. Deux
 * libellés à ne pas confondre : le nom d'affichage de la variante, qui
 * suit ses valeurs, et le libellé recopié sur une ligne de commande, gelé
 * pour toujours (document comptable).
 */
class GererVariantesTest extends TestCase
{
    use RefreshDatabase;

    private Etablissement $etablissement;

    private GererVariantes $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->etablissement = Etablissement::factory()->boutique()->create();
        app(ContexteEtablissement::class)->definir($this->etablissement);
        $this->service = app(GererVariantes::class);
    }

    private function sandales(): Produit
    {
        return Produit::factory()->for($this->etablissement)->publie()->create(['nom' => 'Sandales en cuir tressé', 'prix' => 18000, 'quantite_stock' => 0]);
    }

    private function commander(Produit $produit, VarianteProduit $variante, string $cle)
    {
        return app(CreerCommande::class)->executer(
            $this->etablissement,
            $this->etablissement->clients()->create(['nom' => 'Awa Cliente']),
            [new LigneCommandeDemandee($produit->id, $variante->id, 1)],
            Canal::Web,
            SourceCommande::PanierWeb,
            $cle,
        );
    }

    public function test_renommer_une_valeur_change_le_nom_de_la_variante_jamais_la_ligne_de_commande(): void
    {
        $produit = $this->sandales();
        $pointure = $this->service->ajouterOption($produit, 'Pointure');
        $coloris = $this->service->ajouterOption($produit, 'Coloris');
        $noir = $this->service->ajouterValeur($coloris, 'Noir');
        $variante = $this->service->creerVariante($produit, [$noir, $this->service->ajouterValeur($pointure, '39')], ['quantite_stock' => 4]);
        $this->assertSame('39 / Noir', $variante->nom, 'dans l\'ordre des options, quel que soit l\'ordre donné');
        $signature = $variante->signature;

        app(ConsommerReservation::class)->executer($this->commander($produit, $variante, 'vendue'));
        $enAttente = $this->commander($produit, $variante, 'en-attente');

        $this->service->renommerValeur($noir, 'Noir mat');

        $this->assertSame('39 / Noir mat', $variante->fresh()->nom);
        $this->assertSame($signature, $variante->fresh()->signature);
        $this->assertSame(
            ['Sandales en cuir tressé — 39 / Noir', 'Sandales en cuir tressé — 39 / Noir'],
            LigneCommande::orderBy('id')->pluck('nom_produit_capture')->all(),
            'ce qui a été vendu ce jour-là, pour toujours',
        );
        $this->assertSame(StatutReservation::Active, $enAttente->reservations()->first()->statut);
    }

    /**
     * Le cas le plus fréquent de la vraie vie : la couleur découverte après
     * coup. Les variantes existantes prennent la valeur indiquée, dans la
     * même transaction, et ne perdent rien.
     */
    public function test_ajouter_une_option_a_des_variantes_existantes(): void
    {
        $produit = Produit::factory()->for($this->etablissement)->publie()->create(['nom' => 'T-shirt col rond', 'quantite_stock' => 0]);
        $taille = $this->service->ajouterOption($produit, 'Taille');
        $variantes = collect(['M' => 12, 'L' => 5, 'XL' => 2])->map(
            fn (int $stock, string $libelle) => $this->service->creerVariante($produit, [$this->service->ajouterValeur($taille, $libelle)], ['quantite_stock' => $stock])
        );
        app(ConsommerReservation::class)->executer($this->commander($produit, $variantes['M'], 'vendue'));
        $enAttente = $this->commander($produit, $variantes['L'], 'en-attente');
        $avant = VarianteProduit::orderBy('id')->get(['id', 'quantite_stock', 'quantite_reservee', 'prix', 'statut'])->toArray();
        $lignesAvant = LigneCommande::orderBy('id')->get(['id', 'variante_id', 'nom_produit_capture'])->toArray();

        $couleur = $this->service->ajouterOption($produit, 'Couleur', 'Blanc');

        $this->assertSame($avant, VarianteProduit::orderBy('id')->get(['id', 'quantite_stock', 'quantite_reservee', 'prix', 'statut'])->toArray());
        $this->assertSame($lignesAvant, LigneCommande::orderBy('id')->get(['id', 'variante_id', 'nom_produit_capture'])->toArray());
        $this->assertSame(['M / Blanc', 'L / Blanc', 'XL / Blanc'], VarianteProduit::orderBy('id')->pluck('nom')->all());
        $this->assertSame(StatutReservation::Active, $enAttente->reservations()->first()->statut);
        $this->assertSame(['Blanc'], $couleur->valeurs->pluck('libelle')->all());

        $this->service->creerVariante($produit, [$taille->valeurs->firstWhere('libelle', 'M'), $this->service->ajouterValeur($couleur, 'Bleu')], ['quantite_stock' => 6]);
        $this->assertSame(4, VarianteProduit::count());
        $this->artisan('catalogue:verifier-variantes')->assertSuccessful();
    }

    public function test_ajouter_une_option_sans_dire_la_valeur_des_variantes_existantes_est_refuse(): void
    {
        $produit = $this->sandales();
        $taille = $this->service->ajouterOption($produit, 'Pointure');
        $this->service->creerVariante($produit, [$this->service->ajouterValeur($taille, '40')]);

        try {
            $this->service->ajouterOption($produit, 'Coloris');
            $this->fail('Une option ajoutée sans valeur laisserait des variantes incomplètes.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('valeur_des_variantes_existantes', $exception->errors());
        }

        $this->assertSame(['Pointure'], $produit->options()->pluck('nom')->all(), 'rien n\'a été écrit');
    }

    public function test_une_variante_porte_exactement_une_valeur_par_option(): void
    {
        $produit = $this->sandales();
        $pointure = $this->service->ajouterOption($produit, 'Pointure');
        $coloris = $this->service->ajouterOption($produit, 'Coloris');
        $p39 = $this->service->ajouterValeur($pointure, '39');
        $p40 = $this->service->ajouterValeur($pointure, '40');
        $noir = $this->service->ajouterValeur($coloris, 'Noir');
        $archivee = $this->service->ajouterValeur($coloris, 'Fauve');
        $archivee->update(['archivee_le' => now()]);
        $ailleurs = $this->service->ajouterValeur($this->service->ajouterOption($this->sandales(), 'Coloris'), 'Noir');

        foreach ([
            'il manque le coloris' => [$p39],
            'deux pointures' => [$p39, $p40, $noir],
            'valeur archivée' => [$p39, $archivee],
            'valeur d\'un autre produit' => [$p39, $ailleurs],
        ] as $cas => $valeurs) {
            try {
                $this->service->creerVariante($produit, $valeurs);
                $this->fail("Refus attendu : {$cas}");
            } catch (ValidationException) {
            }
        }

        $this->assertSame(0, VarianteProduit::where('produit_id', $produit->id)->count());
    }
}
