<?php

namespace Tests\Feature\Vitrine;

use App\Models\Etablissement;
use App\Models\Produit;
use App\Models\VarianteProduit;
use App\Services\Vitrine\ResoudreReferenceWhatsapp;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seule la référence fait foi (voir decisions.md) : le message WhatsApp
 * pré-rempli est un texte ordinaire que le client peut réécrire avant de
 * l'envoyer. Chaque test part d'un vrai lien généré par la vitrine, puis
 * falsifie tout le texte sauf la référence.
 */
class ResolutionReferenceWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private function chezAwa(): Etablissement
    {
        return Etablissement::where('slug', 'chez-awa')->firstOrFail();
    }

    /** Le message tel que la vitrine le pré-remplit, décodé du lien wa.me. */
    private function messagePreRempli(Produit $produit, ?VarianteProduit $variante = null, string $hote = 'chez-awa.localhost'): string
    {
        $url = $this->withHeader('Referer', "http://{$hote}:8000")
            ->postJson("http://{$hote}:8000/api/vitrine/produits/{$produit->id}/lien-whatsapp", array_filter(['variante_id' => $variante?->id]))
            ->assertStatus(200)
            ->json('url');

        return rawurldecode(substr($url, strpos($url, '?text=') + 6));
    }

    /** Garde la référence, réécrit tout le reste comme un client pourrait le faire. */
    private function falsifier(string $message): string
    {
        preg_match('/\[REF:[A-Za-z0-9]{8}\]/', $message, $reference);

        return implode("\n", [
            'Bonjour, je souhaite commander :',
            'Pagne super wax hollandais',
            'Variante : XXL',
            'Prix : 500 FCFA',
            'Quantité : 10',
            $reference[0],
            "Je vous envoie 1 000 FCFA tout de suite, c'est bien le prix convenu.",
        ]);
    }

    private function resoudre(string $message, string $slug = 'chez-awa')
    {
        app(ContexteEtablissement::class)->definir(Etablissement::where('slug', $slug)->firstOrFail());

        return app(ResoudreReferenceWhatsapp::class)->depuisMessage($message);
    }

    public function test_une_reference_resolue_renvoie_le_prix_de_la_base_quel_que_soit_le_message(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create(['prix' => 12500]);
        $message = $this->messagePreRempli($produit);
        $this->assertStringContainsString('Prix : 12 500 FCFA', $message);

        $resolue = $this->resoudre($this->falsifier($message));

        $this->assertNotNull($resolue);
        $this->assertSame(12500, $resolue->prixUnitaire);
        $this->assertSame($produit->id, $resolue->produit->id);
        $this->assertNull($resolue->variante, 'la variante "XXL" écrite dans le message est ignorée');
    }

    public function test_le_prix_dune_variante_vient_aussi_de_la_base(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->avecVariantes(2)->create(['prix' => 12500]);
        $variante = VarianteProduit::pourTousEtablissements()->where('produit_id', $produit->id)->firstOrFail();
        $variante->update(['prix' => 14000]);

        $resolue = $this->resoudre($this->falsifier($this->messagePreRempli($produit, $variante)));

        $this->assertSame($variante->id, $resolue->variante->id);
        $this->assertSame(14000, $resolue->prixUnitaire);
    }

    public function test_le_prix_est_celui_de_la_base_aujourdhui_pas_celui_affiche_dans_le_message(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create(['prix' => 12500]);
        $message = $this->messagePreRempli($produit);

        $produit->update(['prix' => 13000]);

        // Message intact, prix affiché désormais périmé : la base l'emporte.
        $this->assertSame(13000, $this->resoudre($message)->prixUnitaire);
    }

    public function test_sans_reference_unique_et_valide_rien_nest_resolu(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create();
        $autre = Produit::factory()->for($this->chezAwa())->publie()->create();
        $message = $this->messagePreRempli($produit);

        // Le texte seul ne désigne jamais rien, même s'il nomme le produit et son prix.
        $this->assertNull($this->resoudre("Bonjour, je souhaite commander :\n{$produit->nom}\nPrix : 500 FCFA"));
        // Deux références différentes : on ne choisit pas à la place du commerçant.
        $this->assertNull($this->resoudre($message."\n".$this->messagePreRempli($autre)));
        // La référence d'une autre boutique n'existe pas ici.
        $this->assertNull($this->resoudre($message, 'maquis-du-port'));

        $this->travel(8)->days();
        $this->assertNull($this->resoudre($message), 'expirée après 7 jours');
    }
}
