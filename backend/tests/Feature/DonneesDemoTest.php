<?php

namespace Tests\Feature;

use App\Enums\ModeStock;
use App\Models\Categorie;
use App\Models\Etablissement;
use App\Models\OptionProduit;
use App\Models\Produit;
use App\Models\VarianteProduit;
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

    /**
     * B1 — la démo montre chaque forme du modèle des variantes : une
     * option nommée comme un commerçant la nommerait, deux dimensions sans
     * produit cartésien, une image par variante, une sous-catégorie. Et
     * sur TOUTE la démo, l'invariant que la base ne sait pas garantir :
     * chaque variante porte exactement une valeur par option de son
     * produit, et sa signature est faite de ces valeurs.
     */
    public function test_les_variantes_de_la_demo_sont_des_combinaisons_completes(): void
    {
        $this->seed();

        $options = fn (string $slug) => OptionProduit::pourTousEtablissements()
            ->where('produit_id', Produit::pourTousEtablissements()->where('slug', $slug)->value('id'))
            ->orderBy('ordre')->pluck('nom')->all();
        $this->assertSame(['Conditionnement'], $options('riz-parfume-long-grain'));
        $this->assertSame(['Longueur'], $options('wax-imprime-bouche-de-sika'));
        $this->assertSame(['Portion'], $options('poulet-braise'));
        $this->assertSame(['Pointure', 'Coloris'], $options('sandales-en-cuir-tresse'));

        $sandales = Produit::pourTousEtablissements()->where('slug', 'sandales-en-cuir-tresse')
            ->with(['variantes' => fn ($requete) => $requete->pourTousEtablissements(), 'medias' => fn ($requete) => $requete->pourTousEtablissements()])
            ->sole();
        $this->assertCount(12, $sandales->variantes, '7 pointures en noir, 5 en marron : jamais les 14 du produit cartésien');
        $this->assertTrue($sandales->variantes->every(fn (VarianteProduit $variante) => $sandales->medias->contains('id', $variante->media_id)), 'chaque variante a pour image une photo de son produit');
        $this->assertCount(2, $sandales->variantes->pluck('media_id')->unique(), 'une photo par coloris');

        $sousCategorie = Categorie::pourTousEtablissements()->whereKey($sandales->categorie_id)->sole();
        $this->assertSame('Chaussures', Categorie::pourTousEtablissements()->whereKey($sousCategorie->parent_id)->value('nom'));

        $variantes = VarianteProduit::pourTousEtablissements()->with(['valeurs' => fn ($requete) => $requete->pourTousEtablissements()])->get();
        $this->assertGreaterThanOrEqual(20, $variantes->count());
        foreach ($variantes as $variante) {
            $optionsDuProduit = OptionProduit::pourTousEtablissements()->where('produit_id', $variante->produit_id)->pluck('id')->sort()->values()->all();
            $this->assertSame($optionsDuProduit, $variante->valeurs->pluck('pivot.option_id')->sort()->values()->all(), "{$variante->nom} : une valeur par option");
            $this->assertSame(VarianteProduit::signaturePour($variante->valeurs->pluck('id')), $variante->signature);
        }
    }
}
