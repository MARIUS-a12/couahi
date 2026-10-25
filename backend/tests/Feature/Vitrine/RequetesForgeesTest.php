<?php

namespace Tests\Feature\Vitrine;

use App\Enums\StatutProduit;
use App\Enums\StatutVariante;
use App\Models\Commande;
use App\Models\Etablissement;
use App\Models\Produit;
use App\Models\ReferenceWhatsapp;
use App\Models\ValeurOption;
use App\Models\VarianteProduit;
use App\Services\Catalogue\GererVariantes;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Un écran qui n'affiche pas un bouton n'est pas une protection : seul le
 * serveur l'est. Chaque cas est une requête forgée, envoyée directement aux
 * quatre routes publiques qui acceptent un produit ou une variante —
 * commande, vérification du panier, lien WhatsApp, fiche produit — et
 * chacune doit refuser, sans rien créer ni réserver.
 *
 * B3 : le sélecteur de la fiche choisit une combinaison de valeurs, mais
 * n'envoie que l'identifiant de la variante qu'elle désigne. Les cas
 * « combinaison » ci-dessous forgent cet identifiant sur les sandales de
 * wax-adjame (Pointure × Coloris) : variante d'un autre produit, valeur
 * archivée, ou aucune variante pour un produit qui en exige une.
 */
class RequetesForgeesTest extends TestCase
{
    use RefreshDatabase;

    private function produit(string $slug): Produit
    {
        return Produit::pourTousEtablissements()->where('slug', $slug)->firstOrFail();
    }

    private function variante(string $slugProduit, string $nom): VarianteProduit
    {
        return VarianteProduit::pourTousEtablissements()->where('produit_id', $this->produit($slugProduit)->id)->where('nom', $nom)->firstOrFail();
    }

    /**
     * @return array{0: string, 1: int, 2: int|null} hôte, produit_id, variante_id
     */
    private function preparer(string $cas): array
    {
        return match ($cas) {
            'produit en brouillon' => ['chez-awa', Produit::factory()->for(Etablissement::where('slug', 'chez-awa')->firstOrFail())->create([
                'nom' => 'Gari en préparation', 'slug' => 'gari-en-preparation', 'prix' => 800, 'quantite_stock' => 10, 'statut' => StatutProduit::Brouillon,
            ])->id, null],
            'produit archivé' => ['chez-awa', $this->produit('lait-caille-maison-50-cl')->id, null],
            'plat épuisé (disponibilité manuelle)' => ['maquis-du-port', $this->produit('riz-sauce-graine')->id, null],
            'variante archivée' => (function () {
                $variante = $this->variante('riz-parfume-long-grain', '1 kg');
                $variante->update(['statut' => StatutVariante::Inactif]);

                return ['chez-awa', $variante->produit_id, $variante->id];
            })(),
            'produit d’un autre établissement' => ['chez-awa', $this->produit('garba')->id, null],
            'variante d’un autre établissement' => ['chez-awa', $this->produit('riz-parfume-long-grain')->id, $this->variante('poulet-braise', 'Demi')->id],
            'combinaison inexistante' => ['chez-awa', $this->produit('riz-parfume-long-grain')->id, 999999],
            'combinaison d’un autre produit' => ['wax-adjame', $this->produit('sandales-en-cuir-tresse')->id, $this->variante('wax-imprime-bouche-de-sika', '2 yards')->id],
            'combinaison dont une valeur est archivée' => (function () {
                $variante = $this->variante('sandales-en-cuir-tresse', '40 / Marron');
                $etablissement = Etablissement::where('slug', 'wax-adjame')->firstOrFail();
                app(ContexteEtablissement::class)->definir($etablissement);
                app(GererVariantes::class)->archiverValeur(
                    ValeurOption::pourTousEtablissements()->whereIn('id', $variante->valeurs()->pluck('valeurs_option.id'))->where('libelle', 'Marron')->firstOrFail()
                );
                app(ContexteEtablissement::class)->definir(null);

                return ['wax-adjame', $variante->produit_id, $variante->id];
            })(),
            'combinaison incomplète (aucune variante choisie)' => ['wax-adjame', $this->produit('sandales-en-cuir-tresse')->id, null],
        };
    }

    public static function cas(): array
    {
        return collect([
            'produit en brouillon',
            'produit archivé',
            'plat épuisé (disponibilité manuelle)',
            'variante archivée',
            'produit d’un autre établissement',
            'variante d’un autre établissement',
            'combinaison inexistante',
            'combinaison d’un autre produit',
            'combinaison dont une valeur est archivée',
        ])->mapWithKeys(fn (string $cas) => [$cas => [$cas]])->all();
    }

    /**
     * Les cas de cas(), plus un qui n'a de sens qu'à l'achat : la fiche
     * d'un produit à variantes se consulte sans choix, mais rien ne
     * s'achète sans une combinaison complète.
     */
    public static function casAchat(): array
    {
        return self::cas() + ['combinaison incomplète (aucune variante choisie)' => ['combinaison incomplète (aucune variante choisie)']];
    }

    #[DataProvider('casAchat')]
    public function test_la_commande_est_refusee(string $cas): void
    {
        $this->seed();
        [$slug, $produitId, $varianteId] = $this->preparer($cas);
        $hote = "http://{$slug}.localhost:8000";
        $commune = $this->getJson("{$hote}/api/vitrine/zones-livraison")->json('data.0.nom') ?? 'Cocody';
        $reservees = $this->totalReserve();

        $this->postJson("{$hote}/api/vitrine/commandes", [
            'lignes' => [['produit_id' => $produitId, 'variante_id' => $varianteId, 'quantite' => 1]],
            'client' => ['nom' => 'Requête forgée', 'telephone' => '0701020304'],
            'commune' => $commune,
            'quartier' => 'Angré',
            'cle_idempotence' => 'forgee-'.md5($cas),
        ])->assertStatus(422);

        $this->assertSame(0, Commande::pourTousEtablissements()->count(), 'aucune commande créée');
        $this->assertSame($reservees, $this->totalReserve(), 'aucun stock réservé');
    }

    #[DataProvider('casAchat')]
    public function test_le_panier_ne_le_compte_pas(string $cas): void
    {
        $this->seed();
        [$slug, $produitId, $varianteId] = $this->preparer($cas);

        $reponse = $this->postJson("http://{$slug}.localhost:8000/api/vitrine/panier/verifier", [
            'lignes' => [['produit_id' => $produitId, 'variante_id' => $varianteId, 'quantite' => 1]],
        ])->assertStatus(200);

        $this->assertNotContains($reponse->json('lignes.0.statut'), ['disponible', 'prix_modifie'], "statut renvoyé : {$reponse->json('lignes.0.statut')}");
        $this->assertSame(0, $reponse->json('sous_total'));
    }

    #[DataProvider('casAchat')]
    public function test_aucun_lien_whatsapp_nest_genere(string $cas): void
    {
        $this->seed();
        [$slug, $produitId, $varianteId] = $this->preparer($cas);

        $statut = $this->postJson("http://{$slug}.localhost:8000/api/vitrine/produits/{$produitId}/lien-whatsapp", ['variante_id' => $varianteId])->status();

        $this->assertContains($statut, [404, 422], "statut HTTP : {$statut}");
        $this->assertSame(0, ReferenceWhatsapp::pourTousEtablissements()->count(), 'aucune référence créée');
    }

    #[DataProvider('cas')]
    public function test_la_fiche_ne_le_propose_pas_a_lachat(string $cas): void
    {
        $this->seed();
        [$slug, $produitId, $varianteId] = $this->preparer($cas);

        $reponse = $this->getJson("http://{$slug}.localhost:8000/api/vitrine/produits/{$produitId}");

        if ($reponse->status() === 404) {
            $this->addToAssertionCount(1);

            return;
        }

        $reponse->assertStatus(200);
        $proposees = collect($reponse->json('data.variantes'))->where('disponible', true)->pluck('id');
        $this->assertTrue(
            $varianteId === null ? ! $reponse->json('data.disponible') : ! $proposees->contains($varianteId),
            'la fiche ne doit pas présenter cet article comme achetable',
        );
    }

    private function totalReserve(): int
    {
        return (int) Produit::pourTousEtablissements()->sum('quantite_reservee') + (int) VarianteProduit::pourTousEtablissements()->sum('quantite_reservee');
    }
}
