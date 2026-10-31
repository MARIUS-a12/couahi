<?php

namespace Tests\Feature\Catalogue;

use App\Models\Etablissement;
use App\Models\EtablissementUtilisateur;
use App\Models\Produit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape 10 — tests 1, 2, 3 : gerer_stock (rôle gestionnaire_stock) autorise
 * UNIQUEMENT l'ajustement de quantité (voir ProduitPolicy::ajusterStock et
 * AjusterStockProduitRequest), jamais le prix, le nom, la publication ni
 * l'archivage — qui restent réservés à gerer_catalogue. Un caissier (rôle
 * caissier, sans aucune des deux) ne peut ni créer ni modifier un produit.
 */
class StockEtRolesCatalogueApiTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE = 'motdepasse';

    private function depuis(string $hote): static
    {
        return $this->withHeader('Referer', "http://{$hote}:8000");
    }

    private function connecte(string $hote, string $email): static
    {
        $this->depuis($hote)->postJson("http://{$hote}:8000/api/connexion", [
            'email' => $email,
            'mot_de_passe' => self::MOT_DE_PASSE,
        ])->assertStatus(200);

        return $this;
    }

    private function creerMembre(Etablissement $etablissement, string $nomRole, string $email): User
    {
        $utilisateur = User::factory()->create(['email' => $email, 'password' => self::MOT_DE_PASSE, 'mot_de_passe_defini' => true]);

        EtablissementUtilisateur::create([
            'etablissement_id' => $etablissement->id,
            'utilisateur_id' => $utilisateur->id,
            'statut' => 'actif',
        ])->roles()->attach(Role::where('nom', $nomRole)->value('id'));

        return $utilisateur;
    }

    public function test_1_un_caissier_ne_peut_pas_creer_ni_modifier_un_produit(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $this->creerMembre($chezAwa, 'caissier', 'caissier@chez-awa.test');
        $produit = Produit::factory()->for($chezAwa)->publie()->create(['quantite_stock' => 5]);

        $this->connecte('chez-awa.localhost', 'caissier@chez-awa.test');

        $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/produits', [
            'nom' => 'Nouveau produit',
            'prix' => 1000,
        ])->assertStatus(403);

        $this->depuis('chez-awa.localhost')
            ->putJson("http://chez-awa.localhost:8000/api/produits/{$produit->id}", ['prix' => 2000])
            ->assertStatus(403);
    }

    public function test_2_un_gestionnaire_de_stock_peut_modifier_une_quantite_mais_pas_un_prix_ni_un_nom(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $this->creerMembre($chezAwa, 'gestionnaire_stock', 'stock@chez-awa.test');
        $produit = Produit::factory()->for($chezAwa)->publie()->create([
            'prix' => 1000,
            'nom' => 'Original',
            'quantite_stock' => 5,
        ]);

        $this->connecte('chez-awa.localhost', 'stock@chez-awa.test');

        $this->depuis('chez-awa.localhost')
            ->patchJson("http://chez-awa.localhost:8000/api/produits/{$produit->id}/stock", ['quantite_stock' => 42])
            ->assertStatus(200)
            ->assertJsonPath('data.quantite_stock', 42);

        $this->depuis('chez-awa.localhost')
            ->putJson("http://chez-awa.localhost:8000/api/produits/{$produit->id}", ['prix' => 5000, 'nom' => 'Renommé'])
            ->assertStatus(403);

        $produit->refresh();
        $this->assertSame(1000, $produit->prix);
        $this->assertSame('Original', $produit->nom);
    }

    public function test_3_un_gestionnaire_de_stock_ne_peut_ni_publier_ni_archiver_un_produit(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $this->creerMembre($chezAwa, 'gestionnaire_stock', 'stock2@chez-awa.test');
        $produit = Produit::factory()->for($chezAwa)->publie()->create(['quantite_stock' => 5]);

        $this->connecte('chez-awa.localhost', 'stock2@chez-awa.test');

        $this->depuis('chez-awa.localhost')
            ->deleteJson("http://chez-awa.localhost:8000/api/produits/{$produit->id}")
            ->assertStatus(403);

        $this->depuis('chez-awa.localhost')
            ->putJson("http://chez-awa.localhost:8000/api/produits/{$produit->id}", ['statut' => 'brouillon'])
            ->assertStatus(403);
    }
}
