<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\ModeStock;
use App\Enums\StatutProduit;
use App\Models\Etablissement;
use App\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lot A — un type saisi de travers à la création se corrige tant que
 * l'établissement n'a aucun produit : il ne décide que du mode de stock
 * des produits créés ensuite (voir EtablissementController::modifierType).
 */
class ModifierTypeEtablissementTest extends TestCase
{
    use RefreshDatabase;

    private function connecte(string $hote, string $email): static
    {
        $this->withHeader('Referer', "http://{$hote}:8000")
            ->postJson("http://{$hote}:8000/api/connexion", ['email' => $email, 'mot_de_passe' => 'motdepasse'])
            ->assertStatus(200);

        return $this;
    }

    private function modifierType(Etablissement $etablissement, string $type)
    {
        return $this->patchJson("http://admin.localhost:8000/api/etablissements/{$etablissement->id}/type", ['type' => $type]);
    }

    public function test_sans_produit_le_type_se_corrige_et_decide_du_mode_des_produits_suivants(): void
    {
        $this->seed();
        $this->connecte('admin.localhost', 'super@plateforme.test');
        $etablissement = Etablissement::factory()->create(['type' => 'boutique']);

        $this->getJson("http://admin.localhost:8000/api/etablissements/{$etablissement->id}")
            ->assertJsonPath('data.nombre_produits', 0);

        $this->modifierType($etablissement, 'restaurant')
            ->assertStatus(200)
            ->assertJsonPath('data.type', 'restaurant');

        $this->assertTrue($etablissement->fresh()->estRestaurant());
        $produit = $etablissement->fresh()->produits()->create(['nom' => 'Garba', 'slug' => 'garba', 'prix' => 1000]);
        $this->assertSame(ModeStock::Interrupteur, $produit->mode_stock);
    }

    public function test_des_quil_y_a_un_produit_le_type_est_fige(): void
    {
        $this->seed();
        $this->connecte('admin.localhost', 'super@plateforme.test');
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $nombre = Produit::pourTousEtablissements()->where('etablissement_id', $chezAwa->id)->count();

        $reponse = $this->modifierType($chezAwa, 'restaurant');

        $reponse->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->assertSame(
            "Le type ne peut plus être changé : cet établissement a déjà {$nombre} produits. Le mode de stock se change désormais produit par produit.",
            $reponse->json('errors.type.0'),
        );
        $this->assertSame('boutique', $chezAwa->fresh()->type);
    }

    public function test_un_produit_archive_suffit_a_figer_le_type(): void
    {
        $this->seed();
        $this->connecte('admin.localhost', 'super@plateforme.test');
        $etablissement = Etablissement::factory()->create(['type' => 'boutique']);
        $etablissement->produits()->create(['nom' => 'Pagne', 'slug' => 'pagne', 'prix' => 5000, 'statut' => StatutProduit::Archive]);

        $this->modifierType($etablissement, 'restaurant')->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->assertSame('boutique', $etablissement->fresh()->type);
    }

    public function test_un_type_inconnu_est_refuse(): void
    {
        $this->seed();
        $this->connecte('admin.localhost', 'super@plateforme.test');
        $etablissement = Etablissement::factory()->create(['type' => 'boutique']);

        $this->modifierType($etablissement, 'epicerie')->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_un_admin_detablissement_ne_peut_pas_changer_le_type(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();

        $this->patchJson("http://chez-awa.localhost:8000/api/etablissements/{$chezAwa->id}/type", ['type' => 'restaurant'])
            ->assertStatus(403);
        $this->assertSame('boutique', $chezAwa->fresh()->type);
    }
}
