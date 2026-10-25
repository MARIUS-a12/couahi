<?php

namespace Tests\Feature\Vitrine;

use App\Enums\Canal;
use App\Enums\SourceCommande;
use App\Models\Client;
use App\Models\Commande;
use App\Models\Etablissement;
use App\Models\Produit;
use App\Services\Livraison\GererZonesLivraison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape 6B — POST /api/vitrine/commandes. Le noyau de création (CreerCommande)
 * est déjà testé pour lui-même (voir tests/Feature/Commandes/CreerCommandeTest) :
 * ces tests couvrent uniquement ce que CE contrôleur ajoute — recalcul des
 * prix/frais depuis la base, statut publié, client par téléphone, canal/source
 * web — jamais la mécanique de réservation elle-même.
 */
class CommandeVitrineApiTest extends TestCase
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

    private function payloadClient(array $surcharge = []): array
    {
        return array_merge([
            'nom' => 'Fatou Koné',
            'telephone' => '0701020304',
        ], $surcharge);
    }

    /**
     * Commune et quartier sont obligatoires depuis le correctif livraison —
     * en texte libre ici (chez-awa n'a par défaut aucune zone de livraison
     * active dans ces tests, sauf test_8 qui en crée une explicitement).
     */
    private function payloadLivraison(array $surcharge = []): array
    {
        return array_merge([
            'commune' => 'Cocody',
            'quartier' => 'Angré 7e tranche',
        ], $surcharge);
    }

    public function test_1_un_prix_envoye_par_le_navigateur_est_ignore(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create([
            'prix' => 2000,
            'quantite_stock' => 10,
            'quantite_reservee' => 0,
        ]);

        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [
                // prix et total : jamais validés, donc jamais lus — le total
                // de la commande doit venir du prix 2000 réel, pas de 1.
                ['produit_id' => $produit->id, 'quantite' => 2, 'prix' => 1, 'total' => 2],
            ],
            'client' => $this->payloadClient(),
            ...$this->payloadLivraison(),
            'cle_idempotence' => 'cle-prix-ignore',
        ]);

        $reponse->assertStatus(201);
        $commande = Commande::where('numero', $reponse->json('numero'))->firstOrFail();
        $this->assertSame(4000, $commande->sous_total);
        $this->assertSame(4000, $commande->total);
    }

    public function test_2_quantite_superieure_au_stock_est_refusee_et_nomme_larticle(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create([
            'nom' => 'Jus de Bissap',
            'quantite_stock' => 2,
            'quantite_reservee' => 0,
        ]);

        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 5]],
            'client' => $this->payloadClient(),
            ...$this->payloadLivraison(),
            'cle_idempotence' => 'cle-stock-insuffisant',
        ]);

        $reponse->assertStatus(422);
        $this->assertStringContainsString('Jus de Bissap', $reponse->json('message'));
        $this->assertSame(0, Commande::count());
    }

    public function test_3_meme_cle_idempotence_deux_fois_ne_cree_quune_commande(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create([
            'quantite_stock' => 10,
            'quantite_reservee' => 0,
        ]);
        $corps = [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => $this->payloadClient(),
            ...$this->payloadLivraison(),
            'cle_idempotence' => 'cle-rejouee',
        ];

        $premiere = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', $corps);
        $seconde = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', $corps);

        $premiere->assertStatus(201);
        $seconde->assertStatus(201);
        $this->assertSame($premiere->json('numero'), $seconde->json('numero'));
        $this->assertSame(1, Commande::count());
        $this->assertSame(1, $produit->fresh()->quantite_reservee);
    }

    public function test_4_deux_cles_differentes_creent_deux_commandes(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create([
            'quantite_stock' => 10,
            'quantite_reservee' => 0,
        ]);

        $premiere = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => $this->payloadClient(),
            ...$this->payloadLivraison(),
            'cle_idempotence' => 'cle-a',
        ]);
        $seconde = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => $this->payloadClient(),
            ...$this->payloadLivraison(),
            'cle_idempotence' => 'cle-b',
        ]);

        $premiere->assertStatus(201);
        $seconde->assertStatus(201);
        $this->assertNotSame($premiere->json('numero'), $seconde->json('numero'));
        $this->assertSame(2, Commande::count());
    }

    public function test_5_meme_numero_local_et_international_designent_le_meme_client(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create([
            'quantite_stock' => 10,
            'quantite_reservee' => 0,
        ]);

        $premiere = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => $this->payloadClient(['telephone' => '0701020304']),
            ...$this->payloadLivraison(),
            'cle_idempotence' => 'cle-local',
        ]);
        $seconde = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => $this->payloadClient(['telephone' => '+225 07 01 02 03 04']),
            ...$this->payloadLivraison(),
            'cle_idempotence' => 'cle-international',
        ]);

        $premiere->assertStatus(201);
        $seconde->assertStatus(201);
        $commandeUn = Commande::where('numero', $premiere->json('numero'))->firstOrFail();
        $commandeDeux = Commande::where('numero', $seconde->json('numero'))->firstOrFail();
        $this->assertSame($commandeUn->client_id, $commandeDeux->client_id);
        $this->assertSame(1, Client::count());
    }

    public function test_6_meme_telephone_sur_deux_etablissements_cree_deux_clients(): void
    {
        $this->seed();
        $chezAwa = $this->chezAwa();
        $maquisDuPort = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
        $produitAwa = Produit::factory()->for($chezAwa)->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);
        $produitMaquis = Produit::factory()->for($maquisDuPort)->publie()->create(['disponible' => true]);

        $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produitAwa->id, 'quantite' => 1]],
            'client' => $this->payloadClient(),
            ...$this->payloadLivraison(),
            'cle_idempotence' => 'cle-awa',
        ])->assertStatus(201);

        $this->depuis('maquis-du-port.localhost')->postJson('http://maquis-du-port.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produitMaquis->id, 'quantite' => 1]],
            'client' => $this->payloadClient(),
            ...$this->payloadLivraison(),
            'cle_idempotence' => 'cle-maquis',
        ])->assertStatus(201);

        $this->assertSame(2, Client::pourTousEtablissements()->count());
    }

    public function test_7_la_commande_porte_le_canal_et_la_source_web(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create([
            'quantite_stock' => 10,
            'quantite_reservee' => 0,
        ]);

        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => $this->payloadClient(),
            ...$this->payloadLivraison(),
            'cle_idempotence' => 'cle-canal-source',
        ]);

        $commande = Commande::where('numero', $reponse->json('numero'))->firstOrFail();
        $this->assertSame(Canal::Web, $commande->canal);
        $this->assertSame(SourceCommande::PanierWeb, $commande->source);
    }

    public function test_8_le_frais_de_livraison_vient_de_la_zone_en_base(): void
    {
        $this->seed();
        $chezAwa = $this->chezAwa();
        $produit = Produit::factory()->for($chezAwa)->publie()->create([
            'prix' => 1000,
            'quantite_stock' => 10,
            'quantite_reservee' => 0,
        ]);
        // "Cocody" : correspond au nom de la zone ET à la commune par défaut
        // de payloadLivraison() — c'est désormais la commune choisie, pas un
        // zone_livraison_id séparé, qui détermine la zone (voir le correctif
        // livraison : "on ne demande pas deux fois la même chose").
        // Les zones ne se facturent que si le commerçant l'a choisi (voir
        // Etablissement::zonesLivraisonProposees).
        app(GererZonesLivraison::class)->choisirFacturation($chezAwa, true);
        $chezAwa->zonesLivraison()->create(['nom' => 'Cocody', 'frais' => 1500]);

        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => $this->payloadClient(),
            ...$this->payloadLivraison(),
            'frais_livraison' => 1, // ignoré : jamais dans les champs validés
            'cle_idempotence' => 'cle-livraison',
        ]);

        $reponse->assertStatus(201);
        $commande = Commande::where('numero', $reponse->json('numero'))->firstOrFail();
        $this->assertSame(1500, $commande->frais_livraison);
        $this->assertSame(2500, $commande->total);
    }

    public function test_12_le_stock_est_reserve_sans_decrementer_le_stock_physique(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create([
            'quantite_stock' => 10,
            'quantite_reservee' => 0,
        ]);

        $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 3]],
            'client' => $this->payloadClient(),
            ...$this->payloadLivraison(),
            'cle_idempotence' => 'cle-reservation',
        ])->assertStatus(201);

        $produit->refresh();
        $this->assertSame(10, $produit->quantite_stock);
        $this->assertSame(3, $produit->quantite_reservee);
    }
}
