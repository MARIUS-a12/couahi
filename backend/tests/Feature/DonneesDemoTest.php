<?php

namespace Tests\Feature;

use App\Enums\ModeStock;
use App\Models\Etablissement;
use App\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le seeder de démonstration couvre les configurations de paiement réelles :
 * aucune (chez-awa, maquis-du-port) et Mobile Money seul avec du stock
 * compté (wax-adjame) — le cas qui a révélé que l'expiration des commandes
 * ne s'exécutait jamais. Si ce test casse, la démo ne montre plus ce cas.
 */
class DonneesDemoTest extends TestCase
{
    use RefreshDatabase;

    private function modePropose(string $hote): ?string
    {
        return $this->getJson("http://{$hote}:8000/api/vitrine/etablissement")->assertStatus(200)->json('data.mode_paiement');
    }

    public function test_les_configurations_de_paiement_de_la_demo(): void
    {
        $this->seed();

        $this->assertNull($this->modePropose('chez-awa.localhost'));
        $this->assertNull($this->modePropose('maquis-du-port.localhost'));
        $this->assertSame('mobile_money', $this->modePropose('wax-adjame.localhost'));
    }

    public function test_wax_adjame_vend_en_stock_compte_et_couvre_les_cas_du_catalogue(): void
    {
        $this->seed();
        $wax = Etablissement::where('slug', 'wax-adjame')->firstOrFail();
        $produits = Produit::pourTousEtablissements()->where('etablissement_id', $wax->id)
            ->with(['medias' => fn ($requete) => $requete->pourTousEtablissements(), 'variantes' => fn ($requete) => $requete->pourTousEtablissements()])
            ->get();

        $this->assertTrue($produits->every(fn (Produit $produit) => $produit->mode_stock === ModeStock::Compte));
        $this->assertTrue($produits->contains(fn (Produit $produit) => $produit->medias->count() > 1), 'un produit avec plusieurs photos');
        $this->assertTrue($produits->contains(fn (Produit $produit) => $produit->medias->isEmpty()), 'un produit sans photo');
        $this->assertTrue($produits->contains(fn (Produit $produit) => $produit->variantes->isNotEmpty()), 'un produit à variantes');
        $this->assertTrue($produits->contains(fn (Produit $produit) => $produit->quantite_stock === 0 && ! $produit->disponible), 'un produit épuisé');
        $this->assertTrue($produits->contains(fn (Produit $produit) => $produit->statut->value === 'archive'), 'un produit archivé');
        $this->assertNotNull($wax->logo_media_id, 'un logo');
    }

    public function test_son_administratrice_se_connecte(): void
    {
        $this->seed();

        $this->withHeader('Referer', 'http://wax-adjame.localhost:8000')->postJson('http://wax-adjame.localhost:8000/api/connexion', [
            'email' => 'fatou@wax-adjame.test',
            'mot_de_passe' => 'motdepasse',
        ])->assertStatus(200);
    }
}
