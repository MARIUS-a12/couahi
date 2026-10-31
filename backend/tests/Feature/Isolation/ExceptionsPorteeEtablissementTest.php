<?php

namespace Tests\Feature\Isolation;

use App\Enums\Canal;
use App\Enums\SourceCommande;
use App\Models\Abonnement;
use App\Models\Commande;
use App\Models\Etablissement;
use App\Models\OptionProduit;
use App\Models\Produit;
use App\Models\ValeurOption;
use App\Models\VarianteProduit;
use App\Services\Commandes\CreerCommande;
use App\Services\Commandes\LigneCommandeDemandee;
use App\Services\Livraison\GererZonesLivraison;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les exceptions à « jamais pourTousEtablissements() sur une route web »,
 * nommées dans decisions.md (31/10), prouvées une à une : une requête faite
 * depuis une boutique ne renvoie ni ne touche jamais rien d'une autre.
 * CreerCommande l'est déjà par RequetesForgeesTest (produit et variante
 * d'un autre établissement) et CommandeManuelleApiTest ; une zone d'un
 * autre établissement à la commande, par LivraisonCommandeApiTest.
 */
class ExceptionsPorteeEtablissementTest extends TestCase
{
    use RefreshDatabase;

    private const CHEZ_AWA = 'http://chez-awa.localhost:8000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function connecte(string $hote, string $email): static
    {
        $this->withHeader('Referer', "http://{$hote}:8000")
            ->postJson("http://{$hote}:8000/api/connexion", ['email' => $email, 'mot_de_passe' => 'motdepasse'])
            ->assertOk();

        return $this;
    }

    private function etablissement(string $slug): Etablissement
    {
        return Etablissement::where('slug', $slug)->firstOrFail();
    }

    /**
     * Exécute $fonction sous le contexte de $etablissement, comme le ferait
     * une requête de sa boutique, puis le retire.
     */
    private function dans(Etablissement $etablissement, callable $fonction): mixed
    {
        app(ContexteEtablissement::class)->definir($etablissement);

        try {
            return $fonction();
        } finally {
            app(ContexteEtablissement::class)->definir(null);
        }
    }

    public function test_la_liste_du_super_admin_compte_chaque_etablissement_a_part(): void
    {
        $chezAwa = $this->etablissement('chez-awa');
        Produit::factory()->for($chezAwa)->count(3)->create();
        $this->connecte('admin.localhost', 'super@plateforme.test');

        $lignes = $this->getJson('http://admin.localhost:8000/api/etablissements')->assertOk()->json('data');

        $comptes = [];
        foreach ($lignes as $ligne) {
            $this->assertSame(
                Produit::pourTousEtablissements()->where('etablissement_id', $ligne['id'])->count(),
                $ligne['produits_count'],
                "produits de {$ligne['nom']}",
            );
            $this->assertSame(
                Abonnement::pourTousEtablissements()->where('etablissement_id', $ligne['id'])->firstOrFail()->statut->value,
                $ligne['abonnement']['statut'],
                "abonnement de {$ligne['nom']}",
            );
            $comptes[] = $ligne['produits_count'];
        }
        $this->assertCount(count($comptes), array_unique($comptes), 'des comptes tous différents : un mélange se verrait');
    }

    public function test_la_livraison_dune_boutique_ne_montre_que_ses_zones(): void
    {
        $chezAwa = $this->etablissement('chez-awa');
        $maquis = $this->etablissement('maquis-du-port');
        $zones = app(GererZonesLivraison::class);
        $this->dans($chezAwa, function () use ($zones, $chezAwa) {
            $zones->choisirFacturation($chezAwa, true);
            $zones->ajouter($chezAwa, 'Zone de Chez Awa', 1000);
        });
        $this->dans($maquis, function () use ($zones, $maquis) {
            $zones->choisirFacturation($maquis, true);
            $zones->ajouter($maquis, 'Zone du Maquis', 500);
            $zones->definirAutreCommune($maquis, 2000);
        });
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $parametres = $this->getJson(self::CHEZ_AWA.'/api/parametres/livraison')->assertOk();
        $this->assertTrue($parametres->json('data.par_commune'));
        $this->assertNotContains('Zone du Maquis', collect($parametres->json('data.zones'))->pluck('nom')->all());
        $this->assertContains('Zone de Chez Awa', collect($parametres->json('data.zones'))->pluck('nom')->all());
        $this->assertNull($parametres->json('data.autre_commune'), 'les frais « Autre commune » du Maquis ne passent pas chez Awa');

        $saisie = $this->getJson(self::CHEZ_AWA.'/api/commandes/manuelles/zones')->assertOk();
        $this->assertNotContains('Zone du Maquis', collect($saisie->json('data'))->pluck('nom')->all());
        $this->assertNull($saisie->json('autre_commune'));
    }

    public function test_une_boutique_ne_touche_a_aucune_commande_dune_autre(): void
    {
        $maquis = $this->etablissement('maquis-du-port');
        $produit = Produit::factory()->for($maquis)->publie()->create(['prix' => 5000, 'quantite_stock' => 10, 'quantite_reservee' => 0, 'disponible' => true]);
        $commande = $this->dans($maquis, fn () => app(CreerCommande::class)->executer(
            $maquis,
            $maquis->clients()->create(['nom' => 'Client du Maquis', 'telephone' => '+2250701020304']),
            [new LigneCommandeDemandee($produit->id, null, 1)],
            Canal::Web,
            SourceCommande::PanierWeb,
            'commande-du-maquis',
        ));
        $avant = Commande::pourTousEtablissements()->findOrFail($commande->id)->getAttributes();
        $produitAvant = Produit::pourTousEtablissements()->findOrFail($produit->id)->getAttributes();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $this->getJson(self::CHEZ_AWA."/api/commandes/{$commande->id}")->assertNotFound();
        foreach (['confirmer', 'marquer-prete', 'marquer-livree'] as $action) {
            $this->patchJson(self::CHEZ_AWA."/api/commandes/{$commande->id}/{$action}")->assertNotFound();
        }
        $this->postJson(self::CHEZ_AWA."/api/commandes/{$commande->id}/annuler", ['motif_type' => 'client_annule'])->assertNotFound();
        $this->postJson(self::CHEZ_AWA."/api/commandes/{$commande->id}/confirmer-paiement", ['montant_recu' => 5000])->assertNotFound();
        $this->postJson(self::CHEZ_AWA."/api/commandes/{$commande->id}/rembourser", ['montant' => 5000])->assertNotFound();

        $this->assertSame($avant, Commande::pourTousEtablissements()->findOrFail($commande->id)->getAttributes(), 'la commande du Maquis est intacte');
        $this->assertSame($produitAvant, Produit::pourTousEtablissements()->findOrFail($produit->id)->getAttributes(), 'son produit et son stock aussi');
        $this->assertNotContains($commande->numero, collect($this->getJson(self::CHEZ_AWA.'/api/commandes')->json('data'))->pluck('numero')->all());
    }

    public function test_une_boutique_ne_touche_a_aucune_variante_dune_autre(): void
    {
        $sandales = Produit::pourTousEtablissements()->where('slug', 'sandales-en-cuir-tresse')->firstOrFail();
        $option = OptionProduit::pourTousEtablissements()->where('produit_id', $sandales->id)->orderBy('ordre')->firstOrFail();
        $valeur = ValeurOption::pourTousEtablissements()->where('option_id', $option->id)->orderBy('ordre')->firstOrFail();
        $variante = VarianteProduit::pourTousEtablissements()->where('produit_id', $sandales->id)->where('nom', '39 / Noir')->firstOrFail();
        $produitAwa = Produit::pourTousEtablissements()->where('slug', 'riz-parfume-long-grain')->firstOrFail();
        $avant = [$option->fresh()->getAttributes(), $valeur->fresh()->getAttributes(), $variante->fresh()->getAttributes()];
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $hote = self::CHEZ_AWA;

        // Le produit de Wax Adjamé lui-même.
        $this->getJson("{$hote}/api/produits/{$sandales->id}/variantes")->assertNotFound();
        $this->patchJson("{$hote}/api/produits/{$sandales->id}/variantes/{$variante->id}", ['quantite_stock' => 0])->assertNotFound();
        // Ses option, valeur et variante, glissées derrière un produit de Chez Awa.
        $this->patchJson("{$hote}/api/produits/{$produitAwa->id}/options/{$option->id}", ['nom' => 'Piège'])->assertNotFound();
        $this->deleteJson("{$hote}/api/produits/{$produitAwa->id}/options/{$option->id}")->assertNotFound();
        $this->postJson("{$hote}/api/produits/{$produitAwa->id}/options/{$option->id}/valeurs", ['libelle' => 'Piège'])->assertNotFound();
        $this->patchJson("{$hote}/api/produits/{$produitAwa->id}/valeurs/{$valeur->id}", ['libelle' => 'Piège'])->assertNotFound();
        $this->deleteJson("{$hote}/api/produits/{$produitAwa->id}/valeurs/{$valeur->id}")->assertNotFound();
        $this->patchJson("{$hote}/api/produits/{$produitAwa->id}/variantes/{$variante->id}", ['quantite_stock' => 0])->assertNotFound();
        $this->deleteJson("{$hote}/api/produits/{$produitAwa->id}/variantes/{$variante->id}")->assertNotFound();

        $this->assertSame($avant, [$option->fresh()->getAttributes(), $valeur->fresh()->getAttributes(), $variante->fresh()->getAttributes()], 'rien n’a bougé chez Wax Adjamé');
    }
}
