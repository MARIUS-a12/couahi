<?php

namespace Tests\Feature\Vitrine;

use App\Models\Commande;
use App\Models\Etablissement;
use App\Models\Produit;
use App\Services\Livraison\GererZonesLivraison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correctif livraison — commune et quartier remplacent email et note sur le
 * formulaire /commander. Réutilise les comptes de démo (voir
 * UtilisateursDemoSeeder) pour la partie back-office.
 */
class LivraisonCommandeApiTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE = 'motdepasse';

    private function depuis(string $hote): static
    {
        return $this->withHeader('Referer', "http://{$hote}:8000");
    }

    private function connecte(string $hote, string $email): static
    {
        $this->depuis($hote)->postJson("http://{$hote}:8000/api/connexion", [
            'email' => $email,
            'mot_de_passe' => self::MOT_DE_PASSE,
        ])->assertStatus(200);

        return $this;
    }

    private function chezAwa(): Etablissement
    {
        return Etablissement::where('slug', 'chez-awa')->firstOrFail();
    }

    private function payloadBase(Produit $produit, array $surcharge = []): array
    {
        return array_merge([
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => ['nom' => 'Fatou Koné', 'telephone' => '0701020304'],
            'commune' => 'Cocody',
            'quartier' => 'Angré 7e tranche',
            'cle_idempotence' => 'cle-livraison-'.uniqid(),
        ], $surcharge);
    }

    public function test_1_commande_sans_commune_refusee(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);

        $payload = $this->payloadBase($produit);
        unset($payload['commune']);

        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', $payload);

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['commune']);
        $this->assertSame(0, Commande::count());
    }

    public function test_2_commande_sans_quartier_refusee(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);

        $payload = $this->payloadBase($produit);
        unset($payload['quartier']);

        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', $payload);

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['quartier']);
        $this->assertSame(0, Commande::count());
    }

    public function test_3_commune_dune_zone_dun_autre_etablissement_refusee(): void
    {
        $this->seed();
        $chezAwa = $this->chezAwa();
        $maquisDuPort = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
        $produit = Produit::factory()->for($chezAwa)->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);

        // chez-awa a SES PROPRES zones actives : la commune doit donc être
        // choisie parmi elles, "Yopougon" (zone de l'AUTRE établissement)
        // n'en fait pas partie, même si elle existe ailleurs en base.
        // Les zones ne se facturent que si le commerçant l'a choisi (voir
        // Etablissement::zonesLivraisonProposees).
        app(GererZonesLivraison::class)->choisirFacturation($chezAwa, true);
        $chezAwa->zonesLivraison()->create(['nom' => 'Cocody', 'frais' => 1000]);
        $maquisDuPort->zonesLivraison()->create(['nom' => 'Yopougon', 'frais' => 500]);

        $reponse = $this->depuis('chez-awa.localhost')->postJson(
            'http://chez-awa.localhost:8000/api/vitrine/commandes',
            $this->payloadBase($produit, ['commune' => 'Yopougon']),
        );

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['commune']);
        $this->assertSame(0, Commande::count());
    }

    public function test_4_frais_de_livraison_reste_celui_de_la_zone_en_base(): void
    {
        $this->seed();
        $chezAwa = $this->chezAwa();
        $produit = Produit::factory()->for($chezAwa)->publie()->create(['prix' => 2000, 'quantite_stock' => 10, 'quantite_reservee' => 0]);
        // Les zones ne se facturent que si le commerçant l'a choisi (voir
        // Etablissement::zonesLivraisonProposees).
        app(GererZonesLivraison::class)->choisirFacturation($chezAwa, true);
        $chezAwa->zonesLivraison()->create(['nom' => 'Cocody', 'frais' => 1200]);

        $reponse = $this->depuis('chez-awa.localhost')->postJson(
            'http://chez-awa.localhost:8000/api/vitrine/commandes',
            $this->payloadBase($produit, ['frais_livraison' => 1]), // jamais dans les champs validés
        );

        $reponse->assertStatus(201);
        $commande = Commande::where('numero', $reponse->json('numero'))->firstOrFail();
        $this->assertSame(1200, $commande->frais_livraison);
        $this->assertSame(3200, $commande->total);
    }

    public function test_5_commande_valide_enregistre_commune_et_quartier_nettoyes(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);

        $reponse = $this->depuis('chez-awa.localhost')->postJson(
            'http://chez-awa.localhost:8000/api/vitrine/commandes',
            $this->payloadBase($produit, ['commune' => '  Marcory  ', 'quartier' => '  Près de la pharmacie  ']),
        );

        $reponse->assertStatus(201);
        $commande = Commande::where('numero', $reponse->json('numero'))->firstOrFail();
        $this->assertSame('Marcory', $commande->commune);
        $this->assertSame('Près de la pharmacie', $commande->quartier);
    }

    public function test_6_commande_sans_zone_active_accepte_une_commune_en_texte_libre(): void
    {
        $this->seed();
        // Aucune zone de livraison active pour chez-awa : commune doit donc
        // être acceptée en texte libre, pas restreinte à une liste vide.
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);

        $reponse = $this->depuis('chez-awa.localhost')->postJson(
            'http://chez-awa.localhost:8000/api/vitrine/commandes',
            $this->payloadBase($produit, ['commune' => 'Un quartier quelconque']),
        );

        $reponse->assertStatus(201);
        $commande = Commande::where('numero', $reponse->json('numero'))->firstOrFail();
        $this->assertSame('Un quartier quelconque', $commande->commune);
        $this->assertNull($commande->zone_livraison_id);
    }

    public function test_7_commandes_anterieures_sans_commune_restent_lisibles_en_back_office(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);

        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => ['nom' => 'Fatou Koné', 'telephone' => '0701020304'],
            'commune' => 'Cocody',
            'quartier' => 'Angré',
            'cle_idempotence' => 'cle-anterieure',
        ])->assertStatus(201);

        $commande = Commande::where('numero', $reponse->json('numero'))->firstOrFail();
        // Simule une commande créée avant ce correctif : les deux colonnes
        // redeviennent null, comme pour toute ligne déjà en base.
        $commande->forceFill(['commune' => null, 'quartier' => null])->save();

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $detail = $this->depuis('chez-awa.localhost')->getJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}");

        $detail->assertStatus(200);
        $this->assertNull($detail->json('data.commune'));
        $this->assertNull($detail->json('data.quartier'));
    }
}
