<?php

namespace Tests\Feature\Vitrine;

use App\Enums\StatutProduit;
use App\Models\Categorie;
use App\Models\Etablissement;
use App\Models\Produit;
use App\Models\VarianteProduit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape 6A — API publique de la vitrine (tests 1 à 4 et 8 de la spec).
 * Aucune connexion nulle part dans ce fichier : c'est tout le point de la
 * vitrine. Réutilise chez-awa et maquis-du-port de la démo de l'Étape 1
 * pour bénéficier de leurs domaines déjà résolvables.
 */
class VitrineProduitsApiTest extends TestCase
{
    use RefreshDatabase;

    private function depuis(string $hote): static
    {
        return $this->withHeader('Referer', "http://{$hote}:8000");
    }

    private function chezAwa(): Etablissement
    {
        return Etablissement::where('slug', 'chez-awa')->firstOrFail();
    }

    public function test_1a_produit_archive_renvoie_404(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->create(['statut' => StatutProduit::Archive]);

        $this->depuis('chez-awa.localhost')
            ->getJson("http://chez-awa.localhost:8000/api/vitrine/produits/{$produit->id}")
            ->assertStatus(404);
    }

    public function test_1b_produit_brouillon_renvoie_404(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->brouillon()->create();

        $this->depuis('chez-awa.localhost')
            ->getJson("http://chez-awa.localhost:8000/api/vitrine/produits/{$produit->id}")
            ->assertStatus(404);
    }

    public function test_2_produit_dun_autre_etablissement_renvoie_404(): void
    {
        $this->seed();
        $maquisDuPort = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
        $produitMaquis = Produit::factory()->for($maquisDuPort)->publie()->create();

        $this->depuis('chez-awa.localhost')
            ->getJson("http://chez-awa.localhost:8000/api/vitrine/produits/{$produitMaquis->id}")
            ->assertStatus(404);
    }

    public function test_3_aucun_champ_sensible_dans_la_reponse(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create();
        VarianteProduit::factory()->pourProduit($produit)->create();

        $champsInterdits = [
            'quantite_stock',
            'quantite_reservee',
            'etablissement_id',
            'cout',
            'prix_achat',
            'marge',
        ];

        $liste = $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/vitrine/produits');
        $liste->assertStatus(200);
        foreach ($champsInterdits as $champ) {
            $liste->assertJsonMissingPath("data.0.{$champ}");
            $liste->assertJsonMissingPath("data.0.variantes.0.{$champ}");
        }

        $fiche = $this->depuis('chez-awa.localhost')->getJson("http://chez-awa.localhost:8000/api/vitrine/produits/{$produit->id}");
        $fiche->assertStatus(200);
        foreach ($champsInterdits as $champ) {
            $fiche->assertJsonMissingPath("data.{$champ}");
            $fiche->assertJsonMissingPath("data.variantes.0.{$champ}");
        }
    }

    public function test_3b_le_prix_barre_nest_expose_que_sil_depasse_le_prix(): void
    {
        $this->seed();
        $promo = Produit::factory()->for($this->chezAwa())->publie()->create(['nom' => 'Promo', 'prix' => 15000, 'prix_barre' => 20000]);
        $faux = Produit::factory()->for($this->chezAwa())->publie()->create(['nom' => 'Faux', 'prix' => 15000, 'prix_barre' => 15000]);
        $sans = Produit::factory()->for($this->chezAwa())->publie()->create(['nom' => 'Sans', 'prix' => 15000, 'prix_barre' => null]);

        $liste = collect($this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/vitrine/produits?par_page=100')->assertStatus(200)->json('data'))->keyBy('id');
        $this->assertSame(20000, $liste[$promo->id]['prix_barre']);
        $this->assertNull($liste[$faux->id]['prix_barre'], 'égal au prix : aucune promotion à annoncer');
        $this->assertNull($liste[$sans->id]['prix_barre']);

        $this->depuis('chez-awa.localhost')->getJson("http://chez-awa.localhost:8000/api/vitrine/produits/{$promo->id}")->assertJsonPath('data.prix_barre', 20000);
        $this->depuis('chez-awa.localhost')->getJson("http://chez-awa.localhost:8000/api/vitrine/produits/{$faux->id}")->assertJsonPath('data.prix_barre', null);
    }

    public function test_4_aucune_route_vitrine_nexige_authentification(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create();

        $this->depuis('chez-awa.localhost')
            ->getJson('http://chez-awa.localhost:8000/api/vitrine/produits')
            ->assertStatus(200);

        $this->depuis('chez-awa.localhost')
            ->getJson("http://chez-awa.localhost:8000/api/vitrine/produits/{$produit->id}")
            ->assertStatus(200);

        $this->depuis('chez-awa.localhost')
            ->getJson('http://chez-awa.localhost:8000/api/vitrine/categories')
            ->assertStatus(200);

        $this->depuis('chez-awa.localhost')
            ->getJson('http://chez-awa.localhost:8000/api/vitrine/etablissement')
            ->assertStatus(200);

        $this->depuis('chez-awa.localhost')
            ->postJson("http://chez-awa.localhost:8000/api/vitrine/produits/{$produit->id}/lien-whatsapp")
            ->assertStatus(200);
    }

    public function test_8_recherche_et_filtre_categorie(): void
    {
        $this->seed();
        $chezAwa = $this->chezAwa();
        $boissons = Categorie::factory()->for($chezAwa)->create(['nom' => 'Boissons']);
        $snacks = Categorie::factory()->for($chezAwa)->create(['nom' => 'Snacks']);

        Produit::factory()->for($chezAwa)->publie()->create(['nom' => 'Jus de Bissap', 'categorie_id' => $boissons->id]);
        Produit::factory()->for($chezAwa)->publie()->create(['nom' => 'Jus de Gingembre', 'categorie_id' => $boissons->id]);
        Produit::factory()->for($chezAwa)->publie()->create(['nom' => 'Chips Plantain', 'categorie_id' => $snacks->id]);

        $recherche = $this->depuis('chez-awa.localhost')
            ->getJson('http://chez-awa.localhost:8000/api/vitrine/produits?recherche=Bissap');
        $recherche->assertStatus(200);
        $recherche->assertJsonCount(1, 'data');
        $recherche->assertJsonPath('data.0.nom', 'Jus de Bissap');

        $filtreCategorie = $this->depuis('chez-awa.localhost')
            ->getJson("http://chez-awa.localhost:8000/api/vitrine/produits?categorie={$snacks->id}");
        $filtreCategorie->assertStatus(200);
        $filtreCategorie->assertJsonCount(1, 'data');
        $filtreCategorie->assertJsonPath('data.0.nom', 'Chips Plantain');
    }
}
