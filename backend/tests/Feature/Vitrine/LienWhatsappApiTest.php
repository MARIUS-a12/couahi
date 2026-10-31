<?php

namespace Tests\Feature\Vitrine;

use App\Models\Etablissement;
use App\Models\Produit;
use App\Models\ReferenceWhatsapp;
use App\Models\VarianteProduit;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Étape 6A — POST /api/vitrine/produits/{id}/lien-whatsapp (tests 5 à 7 de
 * la spec). Le message est composé côté serveur ; ces tests vérifient le
 * contrat de la réponse ("url"), jamais un détail d'implémentation du
 * texte au-delà de ce que la spec exige explicitement (numéro, référence).
 */
class LienWhatsappApiTest extends TestCase
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

    private function extraireCode(string $url): string
    {
        $texte = urldecode($url);
        preg_match('/\[REF:([A-Za-z0-9]{8})\]/', $texte, $correspondances);

        return $correspondances[1] ?? $this->fail("Aucune référence [REF:XXXXXXXX] trouvée dans : {$texte}");
    }

    public function test_5_lien_contient_le_numero_et_une_reference_de_8_caracteres(): void
    {
        $this->seed();
        $chezAwa = $this->chezAwa();
        $produit = Produit::factory()->for($chezAwa)->publie()->create();

        $reponse = $this->depuis('chez-awa.localhost')
            ->postJson("http://chez-awa.localhost:8000/api/vitrine/produits/{$produit->id}/lien-whatsapp");
        $reponse->assertStatus(200);

        $url = $reponse->json('url');
        // Même priorité que VitrineProduitController::lienWhatsapp() :
        // telephone_whatsapp d'abord, telephone en repli — chez-awa a
        // désormais un vrai telephone_whatsapp de démo (voir
        // EtablissementsDemoSeeder), qui doit donc être celui utilisé ici.
        $numeroAttendu = preg_replace('/\D/', '', $chezAwa->telephone_whatsapp ?? $chezAwa->telephone);

        $this->assertStringStartsWith("https://wa.me/{$numeroAttendu}?text=", $url);
        $this->extraireCode($url);
    }

    /**
     * Deux appels SANS cookie partagé, donc deux visiteurs distincts : le
     * même visiteur qui reclique reçoit, lui, la même référence (voir
     * LienWhatsappDeduplicationTest).
     */
    public function test_6_deux_appels_creent_deux_references_distinctes_toutes_deux_resolvables(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create();

        $urlUn = $this->depuis('chez-awa.localhost')
            ->postJson("http://chez-awa.localhost:8000/api/vitrine/produits/{$produit->id}/lien-whatsapp")
            ->json('url');
        $urlDeux = $this->depuis('chez-awa.localhost')
            ->postJson("http://chez-awa.localhost:8000/api/vitrine/produits/{$produit->id}/lien-whatsapp")
            ->json('url');

        $codeUn = $this->extraireCode($urlUn);
        $codeDeux = $this->extraireCode($urlDeux);
        $this->assertNotSame($codeUn, $codeDeux);

        $referenceUn = ReferenceWhatsapp::pourTousEtablissements()->where('code', $codeUn)->firstOrFail();
        $referenceDeux = ReferenceWhatsapp::pourTousEtablissements()->where('code', $codeDeux)->firstOrFail();

        $this->assertSame($produit->id, $referenceUn->produit_id);
        $this->assertSame($produit->id, $referenceDeux->produit_id);
        $this->assertTrue($referenceUn->estValide());
        $this->assertTrue($referenceDeux->estValide());
    }

    public function test_7_reference_expiree_nest_plus_resolvable(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create();

        $url = $this->depuis('chez-awa.localhost')
            ->postJson("http://chez-awa.localhost:8000/api/vitrine/produits/{$produit->id}/lien-whatsapp")
            ->json('url');

        $reference = ReferenceWhatsapp::pourTousEtablissements()->where('code', $this->extraireCode($url))->firstOrFail();
        $this->assertTrue($reference->estValide());

        $reference->update(['expire_le' => now()->subDay()]);
        $this->assertFalse($reference->fresh()->estValide());
    }

    /**
     * Une variante précisée doit être reprise dans la référence enregistrée
     * — et une variante qui n'appartient pas à ce produit (même chez le même
     * établissement) doit être refusée plutôt que silencieusement ignorée.
     */
    public function test_variante_precisee_est_associee_a_la_reference(): void
    {
        $this->seed();
        $chezAwa = $this->chezAwa();
        $produit = Produit::factory()->for($chezAwa)->publie()->avecVariantes(1)->create();
        $variante = VarianteProduit::pourTousEtablissements()->where('produit_id', $produit->id)->firstOrFail();

        $autreProduit = Produit::factory()->for($chezAwa)->publie()->avecVariantes(1)->create();
        $varianteEtrangere = VarianteProduit::pourTousEtablissements()->where('produit_id', $autreProduit->id)->firstOrFail();

        $reponse = $this->depuis('chez-awa.localhost')->postJson(
            "http://chez-awa.localhost:8000/api/vitrine/produits/{$produit->id}/lien-whatsapp",
            ['variante_id' => $variante->id],
        );
        $reponse->assertStatus(200);
        $reference = ReferenceWhatsapp::pourTousEtablissements()->where('code', $this->extraireCode($reponse->json('url')))->firstOrFail();
        $this->assertSame($variante->id, $reference->variante_id);

        $this->depuis('chez-awa.localhost')->postJson(
            "http://chez-awa.localhost:8000/api/vitrine/produits/{$produit->id}/lien-whatsapp",
            ['variante_id' => $varianteEtrangere->id],
        )->assertStatus(404);
    }

    public function test_produit_non_publie_renvoie_404_sur_le_lien_whatsapp(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->brouillon()->create();

        $this->depuis('chez-awa.localhost')
            ->postJson("http://chez-awa.localhost:8000/api/vitrine/produits/{$produit->id}/lien-whatsapp")
            ->assertStatus(404);
    }

    /**
     * Le contrôle d'unicité du code lit toutes les boutiques (exception
     * nommée dans decisions.md, 31/10) : un code déjà pris ailleurs doit
     * être remplacé en silence, sans que la réponse trahisse son existence.
     * Le premier code tiré est forcé sur celui d'une référence du Maquis ;
     * les autres tirages (session, jetons) restent aléatoires.
     */
    public function test_un_code_deja_pris_par_une_autre_boutique_est_remplace_sans_rien_reveler(): void
    {
        $this->seed();
        $maquis = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
        app(ContexteEtablissement::class)->definir($maquis);
        $referenceMaquis = ReferenceWhatsapp::create([
            'code' => 'PrisAuMq',
            'produit_id' => Produit::factory()->for($maquis)->publie()->create()->id,
            'cle_visiteur' => 'visiteur-du-maquis',
            'expire_le' => now()->addDays(7),
        ]);
        app(ContexteEtablissement::class)->definir(null);
        $avant = $referenceMaquis->fresh()->getAttributes();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create();
        $codesForces = ['PrisAuMq', 'LibreAwa'];
        // Par référence : une fonction fléchée recopierait la liste à chaque
        // tirage et rendrait sans fin le code déjà pris.
        Str::createRandomStringsUsing(function (int $longueur) use (&$codesForces) {
            return $longueur === 8 && $codesForces !== []
                ? array_shift($codesForces)
                : substr(bin2hex(random_bytes($longueur)), 0, $longueur);
        });

        try {
            $reponse = $this->depuis('chez-awa.localhost')
                ->postJson("http://chez-awa.localhost:8000/api/vitrine/produits/{$produit->id}/lien-whatsapp");
        } finally {
            Str::createRandomStringsNormally();
        }

        $reponse->assertStatus(200);
        $this->assertSame('LibreAwa', $this->extraireCode($reponse->json('url')), 'un autre code est tiré');
        $this->assertSame([], $codesForces, 'le code pris a bien été tiré, puis écarté');
        $this->assertStringNotContainsString('PrisAuMq', $reponse->getContent(), 'la réponse ne cite pas le code de l’autre boutique');
        $this->assertSame(['url'], array_keys($reponse->json()), 'la réponse a sa forme habituelle, rien de plus');
        $this->assertSame($avant, $referenceMaquis->fresh()->getAttributes(), 'la référence du Maquis est intacte');
    }
}
