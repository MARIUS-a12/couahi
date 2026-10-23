<?php

namespace Tests\Feature\Catalogue;

use App\Models\OptionProduit;
use App\Models\Produit;
use App\Models\VarianteProduit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * catalogue:verifier-variantes contrôle ce que la base ne garantit pas :
 * une valeur pour chaque option, une signature et un nom qui suivent les
 * valeurs. Chaque incohérence est fabriquée en contournant le service
 * (comme le ferait un bug), et la commande doit la nommer.
 */
class VerifierCoherenceVariantesTest extends TestCase
{
    use RefreshDatabase;

    private function sandale(string $nom): VarianteProduit
    {
        $produit = Produit::pourTousEtablissements()->where('slug', 'sandales-en-cuir-tresse')->firstOrFail();

        return VarianteProduit::pourTousEtablissements()->where('produit_id', $produit->id)->where('nom', $nom)->firstOrFail();
    }

    public function test_la_demo_est_coherente(): void
    {
        $this->seed();

        $this->artisan('catalogue:verifier-variantes')
            ->expectsOutputToContain('Aucune incohérence : 20 variante(s) de 4 produit(s) vérifiée(s).')
            ->assertSuccessful();
    }

    public function test_une_variante_a_qui_il_manque_une_valeur_est_signalee(): void
    {
        $this->seed();
        $variante = $this->sandale('41 / Marron');
        $coloris = OptionProduit::pourTousEtablissements()->where('produit_id', $variante->produit_id)->where('nom', 'Coloris')->firstOrFail();
        $variante->valeurs()->wherePivot('option_id', $coloris->id)->detach();

        $this->artisan('catalogue:verifier-variantes')
            ->expectsOutputToContain("wax-adjame / Sandales en cuir tressé / variante #{$variante->id} « 41 / Marron » : aucune valeur pour l'option « Coloris »")
            ->assertFailed();
    }

    public function test_une_signature_ou_un_nom_qui_ne_suit_plus_les_valeurs_est_signale(): void
    {
        $this->seed();
        $signature = $this->sandale('40 / Noir');
        $signature->signature = '1-2';
        $signature->save();
        $nom = $this->sandale('43 / Noir');
        $nom->nom = '43 noir';
        $nom->save();

        $this->artisan('catalogue:verifier-variantes')
            ->expectsOutputToContain("variante #{$signature->id} « 40 / Noir » : signature « 1-2 » qui ne correspond pas à ses valeurs")
            ->expectsOutputToContain("variante #{$nom->id} « 43 noir » : nom d'affichage attendu « 43 / Noir »")
            ->assertFailed();
    }

    public function test_on_peut_ne_verifier_quun_etablissement(): void
    {
        $this->seed();
        $this->sandale('41 / Marron')->valeurs()->detach();

        $this->artisan('catalogue:verifier-variantes', ['--etablissement' => 'chez-awa'])
            ->expectsOutputToContain('Aucune incohérence : 3 variante(s) de 1 produit(s) vérifiée(s).')
            ->assertSuccessful();
        $this->artisan('catalogue:verifier-variantes', ['--etablissement' => 'wax-adjame'])->assertFailed();
    }
}
