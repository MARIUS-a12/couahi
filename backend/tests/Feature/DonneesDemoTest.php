<?php

namespace Tests\Feature;

use App\Enums\ModeStock;
use App\Models\Etablissement;
use App\Models\Produit;
use Faker\Provider\Lorem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Les cas limites d'affichage sont dans la démo, pas découverts en
     * production : un catalogue en faux latin, court et sans accent, aurait
     * caché un défaut de nom long ou de retour à la ligne.
     */
    #[DataProvider('etablissementsAuCatalogueReel')]
    public function test_chaque_catalogue_couvre_les_cas_limites(string $slug): void
    {
        $this->seed();
        $etablissement = Etablissement::where('slug', $slug)->firstOrFail();
        $produits = Produit::pourTousEtablissements()->where('etablissement_id', $etablissement->id)
            ->with(['medias' => fn ($requete) => $requete->pourTousEtablissements(), 'variantes' => fn ($requete) => $requete->pourTousEtablissements()])
            ->get();
        $publies = $produits->filter(fn (Produit $produit) => $produit->statut->value === 'publie');

        $this->assertTrue($publies->contains(fn (Produit $produit) => mb_strlen($produit->nom) >= 60), 'un nom long');
        $this->assertTrue($publies->contains(fn (Produit $produit) => preg_match('/[àâçéèêëîïôûùü]/iu', $produit->nom) === 1), 'un nom accentué');
        $this->assertTrue($publies->contains(fn (Produit $produit) => $produit->variantes->isNotEmpty()), 'un produit à variantes');
        $this->assertTrue($publies->contains(fn (Produit $produit) => ! $produit->disponible), 'un produit en rupture');
        $this->assertTrue($publies->contains(fn (Produit $produit) => $produit->medias->isEmpty()), 'un produit sans image');
        $this->assertTrue($produits->contains(fn (Produit $produit) => $produit->statut->value === 'archive'), 'un produit archivé');

        // Plus aucun nom fabriqué par Faker : chaque nom contient au moins
        // un mot hors du vocabulaire latin de son générateur.
        $latin = (new \ReflectionClass(Lorem::class))->getStaticPropertyValue('wordList');
        foreach ($produits as $produit) {
            $mots = preg_split('/[^\p{L}]+/u', mb_strtolower($produit->nom), -1, PREG_SPLIT_NO_EMPTY);
            $this->assertNotEmpty(array_diff($mots, $latin), "nom de démo factice : {$produit->nom}");
        }
    }

    public static function etablissementsAuCatalogueReel(): array
    {
        return ['chez-awa' => ['chez-awa'], 'maquis-du-port' => ['maquis-du-port']];
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
