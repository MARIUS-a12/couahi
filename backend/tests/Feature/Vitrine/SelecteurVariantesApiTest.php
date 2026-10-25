<?php

namespace Tests\Feature\Vitrine;

use App\Enums\StatutVariante;
use App\Models\Etablissement;
use App\Models\OptionProduit;
use App\Models\Produit;
use App\Models\ValeurOption;
use App\Models\VarianteProduit;
use App\Services\Catalogue\GererVariantes;
use App\Support\Tenancy\ContexteEtablissement;
use App\Support\Vitrine\CacheVitrine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * B3 — ce que la fiche publique donne au sélecteur par dimension : les
 * options et leurs valeurs dans l'ordre du commerçant, et pour chaque
 * variante sa combinaison et sa photo. Sur les sandales de wax-adjame
 * (Pointure × Coloris, le marron seulement du 40 au 44, une photo par
 * coloris).
 */
class SelecteurVariantesApiTest extends TestCase
{
    use RefreshDatabase;

    private Etablissement $waxAdjame;

    private Produit $sandales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->waxAdjame = Etablissement::where('slug', 'wax-adjame')->firstOrFail();
        app(ContexteEtablissement::class)->definir($this->waxAdjame);
        $this->sandales = Produit::where('slug', 'sandales-en-cuir-tresse')->firstOrFail();
    }

    private function fiche(Produit $produit): TestResponse
    {
        return $this->getJson("http://wax-adjame.localhost:8000/api/vitrine/produits/{$produit->id}")->assertStatus(200);
    }

    private function valeur(string $libelle): ValeurOption
    {
        return ValeurOption::whereIn('option_id', OptionProduit::where('produit_id', $this->sandales->id)->pluck('id'))
            ->where('libelle', $libelle)
            ->firstOrFail();
    }

    public function test_les_options_et_leurs_valeurs_suivent_lordre_du_commercant(): void
    {
        $coloris = OptionProduit::where('produit_id', $this->sandales->id)->where('nom', 'Coloris')->firstOrFail();
        // Marron d'abord : ni l'ordre alphabétique (qui le donnerait aussi),
        // ni l'ordre de création (Noir) — d'où le second contrôle ci-dessous.
        app(GererVariantes::class)->reordonnerValeurs($coloris, [$this->valeur('Marron')->id, $this->valeur('Noir')->id]);

        $options = $this->fiche($this->sandales)->json('data.options');

        $this->assertSame(['Pointure', 'Coloris'], array_column($options, 'nom'));
        $this->assertSame(['39', '40', '41', '42', '43', '44', '45'], array_column($options[0]['valeurs'], 'libelle'));
        $this->assertSame(['Marron', 'Noir'], array_column($options[1]['valeurs'], 'libelle'));
    }

    public function test_les_valeurs_ne_sont_jamais_triees_par_libelle(): void
    {
        $options = $this->fiche($this->sandales)->json('data.options');

        // Noir a été saisi avant Marron : l'alphabet donnerait l'inverse.
        $this->assertSame(['Noir', 'Marron'], array_column($options[1]['valeurs'], 'libelle'));
    }

    public function test_chaque_variante_porte_sa_combinaison_et_sa_photo(): void
    {
        [$photoNoir, $photoMarron] = $this->sandales->medias()->orderByPivot('ordre')->pluck('medias.id')->all();
        $variantes = collect($this->fiche($this->sandales)->json('data.variantes'));

        $this->assertCount(12, $variantes);

        $noir39 = $variantes->firstWhere('nom', '39 / Noir');
        $attendues = [$this->valeur('39')->id, $this->valeur('Noir')->id];
        sort($attendues);
        $this->assertSame($attendues, $noir39['valeurs']);
        $this->assertSame($photoNoir, $noir39['photo_id']);

        $this->assertSame($photoMarron, $variantes->firstWhere('nom', '40 / Marron')['photo_id']);
    }

    public function test_une_valeur_archivee_disparait_des_choix_avec_ses_variantes(): void
    {
        app(GererVariantes::class)->archiverValeur($this->valeur('Marron'));

        $reponse = $this->fiche($this->sandales);

        $this->assertSame(['Noir'], array_column($reponse->json('data.options.1.valeurs'), 'libelle'));
        $this->assertCount(7, $reponse->json('data.variantes'));
    }

    /**
     * Le 45 n'existe qu'en noir : il reste listé (le client doit voir qu'il
     * existe), c'est le sélecteur qui le désactive une fois le marron
     * choisi. Une valeur portée par aucune variante active, elle, n'existe
     * pas pour le client.
     */
    public function test_une_valeur_sans_aucune_variante_active_nest_pas_proposee(): void
    {
        $this->assertContains('45', array_column($this->fiche($this->sandales)->json('data.options.0.valeurs'), 'libelle'));

        $pointure = OptionProduit::where('produit_id', $this->sandales->id)->where('nom', 'Pointure')->firstOrFail();
        app(GererVariantes::class)->ajouterValeur($pointure, '46');
        VarianteProduit::where('produit_id', $this->sandales->id)->where('nom', '45 / Noir')->firstOrFail()->update(['statut' => StatutVariante::Inactif]);
        CacheVitrine::invalider($this->waxAdjame->id);

        $libelles = array_column($this->fiche($this->sandales)->json('data.options.0.valeurs'), 'libelle');
        $this->assertNotContains('46', $libelles);
        $this->assertNotContains('45', $libelles);
    }

    public function test_un_produit_sans_option_na_ni_options_ni_variantes(): void
    {
        $simple = Produit::where('slug', 'kita-tisse-main')->firstOrFail();

        $reponse = $this->fiche($simple);

        $this->assertSame([], $reponse->json('data.options'));
        $this->assertSame([], $reponse->json('data.variantes'));
    }
}
