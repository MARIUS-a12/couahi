<?php

namespace Tests\Feature\Livraison;

use App\Enums\Canal;
use App\Enums\SourceCommande;
use App\Enums\StatutZoneLivraison;
use App\Models\Etablissement;
use App\Models\Produit;
use App\Models\ZoneLivraison;
use App\Services\Commandes\CreerCommande;
use App\Services\Commandes\LigneCommandeDemandee;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * L'écran « Livraison » du commerçant : facturer selon la commune ou non,
 * et ses zones. Puis ce que la vitrine en fait : une zone ne se facture
 * que si le commerçant a choisi de facturer, même par requête forgée.
 */
class LivraisonApiTest extends TestCase
{
    use RefreshDatabase;

    private const HOTE = 'http://chez-awa.localhost:8000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function connecte(string $hote = 'chez-awa.localhost', string $email = 'awa@chez-awa.test'): static
    {
        $this->withHeader('Referer', "http://{$hote}:8000")
            ->postJson("http://{$hote}:8000/api/connexion", ['email' => $email, 'mot_de_passe' => 'motdepasse'])
            ->assertStatus(200);

        return $this;
    }

    private function chezAwa(): Etablissement
    {
        return Etablissement::where('slug', 'chez-awa')->firstOrFail();
    }

    private function ajouter(string $nom, int $frais): TestResponse
    {
        return $this->postJson(self::HOTE.'/api/parametres/livraison/zones', ['nom' => $nom, 'frais' => $frais]);
    }

    private function zone(string $nom): ZoneLivraison
    {
        return ZoneLivraison::pourTousEtablissements()->where('etablissement_id', $this->chezAwa()->id)->where('nom', $nom)->firstOrFail();
    }

    private ?Produit $produitEnStock = null;

    private function commanderSurLaVitrine(string $commune, bool $autreCommune = false): TestResponse
    {
        // Un produit bien approvisionné : plusieurs commandes par test, et
        // la livraison seule doit pouvoir les faire échouer.
        $produit = $this->produitEnStock ??= Produit::factory()->for($this->chezAwa())->publie()->create(['quantite_stock' => 50, 'quantite_reservee' => 0]);

        return $this->postJson(self::HOTE.'/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => ['nom' => 'Koffi', 'telephone' => '0701020304'],
            'commune' => $commune,
            'autre_commune' => $autreCommune,
            'quartier' => 'Niangon',
            'cle_idempotence' => 'livraison-'.md5($commune.microtime()),
        ]);
    }

    public function test_le_commercant_cree_modifie_et_retire_ses_zones(): void
    {
        $this->connecte();
        $this->putJson(self::HOTE.'/api/parametres/livraison', ['par_commune' => true])->assertOk()->assertJsonPath('data.par_commune', true);

        $this->ajouter('Yopougon', 1500)->assertOk();
        $reponse = $this->ajouter('Cocody', 0)->assertOk();
        $this->assertSame([['Cocody', 0], ['Yopougon', 1500]], array_map(fn ($z) => [$z['nom'], $z['frais']], $reponse->json('data.zones')));

        $this->putJson(self::HOTE."/api/parametres/livraison/zones/{$this->zone('Yopougon')->id}", ['nom' => 'Yopougon', 'frais' => 2000])
            ->assertOk()->assertJsonPath('data.zones.1.frais', 2000);

        $this->deleteJson(self::HOTE."/api/parametres/livraison/zones/{$this->zone('Cocody')->id}")->assertOk()->assertJsonCount(1, 'data.zones');
        $this->assertSame(0, ZoneLivraison::pourTousEtablissements()->where('etablissement_id', $this->chezAwa()->id)->where('nom', 'Cocody')->count(), 'jamais utilisée : effacée');
    }

    public function test_une_zone_deja_facturee_est_archivee_jamais_effacee(): void
    {
        $this->connecte();
        $this->putJson(self::HOTE.'/api/parametres/livraison', ['par_commune' => true]);
        $this->ajouter('Yopougon', 1500);
        $this->commanderSurLaVitrine('Yopougon')->assertCreated();

        $this->deleteJson(self::HOTE."/api/parametres/livraison/zones/{$this->zone('Yopougon')->id}")->assertOk()->assertJsonCount(0, 'data.zones');

        $this->assertSame(StatutZoneLivraison::Inactif, $this->zone('Yopougon')->statut);
        $commande = $this->chezAwa()->commandes()->pourTousEtablissements()->latest('id')->firstOrFail();
        $this->assertSame($this->zone('Yopougon')->id, $commande->zone_livraison_id);
        $this->assertSame(1500, $commande->frais_livraison);
    }

    public function test_modifier_les_frais_ne_change_aucune_commande_passee(): void
    {
        $this->connecte();
        $this->putJson(self::HOTE.'/api/parametres/livraison', ['par_commune' => true]);
        $this->ajouter('Yopougon', 1500);
        $this->commanderSurLaVitrine('Yopougon')->assertCreated();

        $this->putJson(self::HOTE."/api/parametres/livraison/zones/{$this->zone('Yopougon')->id}", ['nom' => 'Yopougon', 'frais' => 3000])->assertOk();

        $this->assertSame(1500, $this->chezAwa()->commandes()->pourTousEtablissements()->latest('id')->value('frais_livraison'));
    }

    public function test_deux_zones_du_meme_nom_sont_refusees(): void
    {
        $this->connecte();
        $this->ajouter('Cocody', 1000)->assertOk();

        $this->ajouter('  cocody ', 500)->assertStatus(422)->assertJsonValidationErrors('nom');
    }

    public function test_des_frais_negatifs_ou_a_virgule_sont_refuses(): void
    {
        $this->connecte();

        $this->ajouter('Cocody', -500)->assertStatus(422)->assertJsonValidationErrors('frais');
        $this->postJson(self::HOTE.'/api/parametres/livraison/zones', ['nom' => 'Cocody', 'frais' => 1500.5])->assertStatus(422)->assertJsonValidationErrors('frais');
    }

    public function test_un_operateur_sans_gerer_parametres_recoit_403(): void
    {
        $this->connecte('maquis-du-port.localhost', 'yao@maquis-du-port.test');

        $this->getJson('http://maquis-du-port.localhost:8000/api/parametres/livraison')->assertForbidden();
        $this->postJson('http://maquis-du-port.localhost:8000/api/parametres/livraison/zones', ['nom' => 'Plateau', 'frais' => 1000])->assertForbidden();
        $this->putJson('http://maquis-du-port.localhost:8000/api/parametres/livraison/autre-commune', ['frais' => 1000])->assertForbidden();
    }

    public function test_la_zone_dun_autre_etablissement_est_introuvable(): void
    {
        $waxAdjame = Etablissement::where('slug', 'wax-adjame')->firstOrFail();
        app(ContexteEtablissement::class)->definir($waxAdjame);
        $zoneAutre = $waxAdjame->zonesLivraison()->create(['nom' => 'Adjamé', 'frais' => 500]);
        app(ContexteEtablissement::class)->definir(null);
        $this->connecte();

        $this->putJson(self::HOTE."/api/parametres/livraison/zones/{$zoneAutre->id}", ['nom' => 'Piratée', 'frais' => 0])->assertNotFound();
        $this->deleteJson(self::HOTE."/api/parametres/livraison/zones/{$zoneAutre->id}")->assertNotFound();
        $this->assertSame(500, $zoneAutre->fresh()->frais);
    }

    public function test_sans_facturation_la_vitrine_ne_propose_aucune_zone_et_ne_facture_rien(): void
    {
        $this->connecte();
        $this->ajouter('Yopougon', 1500);
        // Des zones existent, mais le commerçant ne facture pas la livraison.
        $this->putJson(self::HOTE.'/api/parametres/livraison', ['par_commune' => false]);

        $this->getJson(self::HOTE.'/api/vitrine/zones-livraison')->assertOk()->assertJsonCount(0, 'data');
        $this->commanderSurLaVitrine('Yopougon')->assertCreated();

        $commande = $this->chezAwa()->commandes()->pourTousEtablissements()->latest('id')->firstOrFail();
        $this->assertNull($commande->zone_livraison_id, 'une zone non proposée ne se facture pas, même nommée');
        $this->assertSame(0, $commande->frais_livraison);
        // Et la commune reste libre : une commune absente des zones passe.
        $this->commanderSurLaVitrine('Marcory')->assertCreated();
    }

    public function test_avec_facturation_la_commune_doit_etre_dans_la_liste_et_ses_frais_sappliquent(): void
    {
        $this->connecte();
        $this->putJson(self::HOTE.'/api/parametres/livraison', ['par_commune' => true]);
        $this->ajouter('Yopougon', 1500);

        $this->getJson(self::HOTE.'/api/vitrine/zones-livraison')->assertOk()->assertJsonPath('data.0.nom', 'Yopougon');
        $this->commanderSurLaVitrine('Marcory')->assertStatus(422)->assertJsonValidationErrors('commune');
        $this->commanderSurLaVitrine('Yopougon')->assertCreated();

        $commande = $this->chezAwa()->commandes()->pourTousEtablissements()->latest('id')->firstOrFail();
        $this->assertSame(1500, $commande->frais_livraison);
        $this->assertSame($commande->sous_total + 1500, $commande->total);
    }

    public function test_une_zone_archivee_ne_se_choisit_plus(): void
    {
        $this->connecte();
        $this->putJson(self::HOTE.'/api/parametres/livraison', ['par_commune' => true]);
        $this->ajouter('Yopougon', 1500);
        $this->ajouter('Cocody', 1000);
        $this->commanderSurLaVitrine('Yopougon')->assertCreated();
        $this->deleteJson(self::HOTE."/api/parametres/livraison/zones/{$this->zone('Yopougon')->id}");

        $this->commanderSurLaVitrine('Yopougon')->assertStatus(422)->assertJsonValidationErrors('commune');
    }

    /**
     * La saisie manuelle (lot C) passera par CreerCommande avec une zone
     * résolue de la même façon : ce test fixe que le service applique les
     * frais de la zone qu'on lui donne, et rien d'autre.
     */
    public function test_creer_commande_reprend_les_frais_de_la_zone(): void
    {
        $etablissement = $this->chezAwa();
        app(ContexteEtablissement::class)->definir($etablissement);
        $zone = $etablissement->zonesLivraison()->create(['nom' => 'Plateau', 'frais' => 2500]);
        $produit = Produit::where('slug', 'attieke-frais-du-jour')->firstOrFail();

        $commande = app(CreerCommande::class)->executer(
            $etablissement,
            $etablissement->clients()->create(['nom' => 'Client Plateau']),
            [new LigneCommandeDemandee($produit->id, null, 1)],
            Canal::Web,
            SourceCommande::PanierWeb,
            'livraison-service',
            zoneLivraison: $zone,
        );

        $this->assertSame(2500, $commande->frais_livraison);
        $this->assertSame($produit->prix + 2500, $commande->total);
    }

    /**
     * Perdre un client de Bingerville coûte plus qu'un total sans frais : sa
     * commune passe, aucune zone, aucun frais, livraison à convenir.
     */
    public function test_autre_commune_passe_sans_frais_ni_zone(): void
    {
        $this->connecte();
        $this->putJson(self::HOTE.'/api/parametres/livraison', ['par_commune' => true]);
        $this->ajouter('Cocody', 1500);

        $this->commanderSurLaVitrine('Bingerville', autreCommune: true)->assertCreated();

        $commande = $this->chezAwa()->commandes()->pourTousEtablissements()->latest('id')->firstOrFail();
        $this->assertSame('Bingerville', $commande->commune);
        $this->assertNull($commande->zone_livraison_id);
        $this->assertSame(0, $commande->frais_livraison);
        $this->assertSame($commande->sous_total, $commande->total);
    }

    /**
     * « Autre commune » ne sert pas à éviter des frais connus : taper une
     * commune de la liste est refusé, avec de quoi corriger.
     */
    public function test_autre_commune_refuse_une_commune_de_la_liste(): void
    {
        $this->connecte();
        $this->putJson(self::HOTE.'/api/parametres/livraison', ['par_commune' => true]);
        $this->ajouter('Cocody', 1500);

        $reponse = $this->commanderSurLaVitrine(' cocody', autreCommune: true)->assertStatus(422);

        $this->assertStringContainsString('est dans la liste', $reponse->json('errors.commune.0'));
        $this->assertSame(0, $this->chezAwa()->commandes()->pourTousEtablissements()->count());
    }

    private function autreCommune(?int $frais): TestResponse
    {
        return $this->putJson(self::HOTE.'/api/parametres/livraison/autre-commune', ['frais' => $frais]);
    }

    private function derniereCommande()
    {
        return $this->chezAwa()->commandes()->pourTousEtablissements()->latest('id')->firstOrFail();
    }

    public function test_le_commercant_fixe_modifie_et_retire_les_frais_de_autre_commune(): void
    {
        $this->connecte();
        $this->putJson(self::HOTE.'/api/parametres/livraison', ['par_commune' => true]);
        $this->ajouter('Cocody', 1500);

        $this->getJson(self::HOTE.'/api/parametres/livraison')->assertJsonPath('autre_commune', null);
        $this->autreCommune(3000)->assertOk()->assertJsonPath('data.autre_commune', ['frais' => 3000])
            ->assertJsonCount(1, 'data.zones')->assertJsonPath('data.zones.0.nom', 'Cocody');
        $this->autreCommune(2500)->assertOk()->assertJsonPath('data.autre_commune', ['frais' => 2500]);
        $this->assertSame(1, ZoneLivraison::pourTousEtablissements()->where('autre_commune', true)->count(), 'une seule zone « Autre commune »');

        $this->autreCommune(null)->assertOk()->assertJsonPath('data.autre_commune', null);
        $this->assertSame(0, ZoneLivraison::pourTousEtablissements()->where('autre_commune', true)->count(), 'jamais facturée : effacée');
    }

    public function test_des_frais_de_autre_commune_invalides_sont_refuses(): void
    {
        $this->connecte();

        $this->autreCommune(-1)->assertStatus(422)->assertJsonValidationErrors('frais');
        $this->putJson(self::HOTE.'/api/parametres/livraison/autre-commune', ['frais' => 12.5])->assertStatus(422);
        $this->putJson(self::HOTE.'/api/parametres/livraison/autre-commune', [])->assertStatus(422);
    }

    public function test_une_commune_hors_liste_paie_les_frais_fixes_de_autre_commune(): void
    {
        $this->connecte();
        $this->putJson(self::HOTE.'/api/parametres/livraison', ['par_commune' => true]);
        $this->ajouter('Cocody', 1500);
        $this->autreCommune(3000);

        $this->getJson(self::HOTE.'/api/vitrine/zones-livraison')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('autre_commune', ['frais' => 3000]);
        $this->commanderSurLaVitrine('Bingerville', autreCommune: true)->assertCreated();

        $commande = $this->derniereCommande();
        $this->assertSame('Bingerville', $commande->commune);
        $this->assertSame(3000, $commande->frais_livraison);
        $this->assertSame($commande->sous_total + 3000, $commande->total);
        $this->assertTrue(ZoneLivraison::pourTousEtablissements()->findOrFail($commande->zone_livraison_id)->autre_commune);

        // Une commune de la liste garde ses propres frais, et reste refusée en « Autre ».
        $this->commanderSurLaVitrine('Cocody')->assertCreated();
        $this->assertSame(1500, $this->derniereCommande()->frais_livraison);
        $this->commanderSurLaVitrine('cocody', autreCommune: true)->assertStatus(422);
    }

    public function test_changer_ou_retirer_autre_commune_ne_touche_aucune_commande_passee(): void
    {
        $this->connecte();
        $this->putJson(self::HOTE.'/api/parametres/livraison', ['par_commune' => true]);
        $this->ajouter('Cocody', 1500);
        $this->autreCommune(3000);
        $this->commanderSurLaVitrine('Bingerville', autreCommune: true)->assertCreated();
        $passee = $this->derniereCommande();

        $this->autreCommune(4000);
        $this->assertSame(3000, $passee->fresh()->frais_livraison);

        $this->autreCommune(null)->assertJsonPath('data.autre_commune', null);
        $this->assertSame(StatutZoneLivraison::Inactif, ZoneLivraison::pourTousEtablissements()->findOrFail($passee->zone_livraison_id)->statut, 'déjà facturée : archivée, jamais effacée');
        $this->commanderSurLaVitrine('Grand-Bassam', autreCommune: true)->assertCreated();
        $this->assertSame(0, $this->derniereCommande()->frais_livraison, 'de nouveau à convenir');
        $this->assertNull($this->derniereCommande()->zone_livraison_id);
    }

    public function test_sans_commune_listee_les_frais_fixes_valent_pour_toute_commune(): void
    {
        $this->connecte();
        $this->putJson(self::HOTE.'/api/parametres/livraison', ['par_commune' => true]);
        $this->autreCommune(2000);

        $this->getJson(self::HOTE.'/api/vitrine/zones-livraison')->assertJsonCount(0, 'data')->assertJsonPath('autre_commune', ['frais' => 2000]);
        $this->commanderSurLaVitrine('Marcory')->assertCreated();

        $this->assertSame(2000, $this->derniereCommande()->frais_livraison);
    }

    public function test_sans_facturation_autre_commune_ne_facture_rien(): void
    {
        $this->connecte();
        $this->putJson(self::HOTE.'/api/parametres/livraison', ['par_commune' => true]);
        $this->autreCommune(2000);
        $this->putJson(self::HOTE.'/api/parametres/livraison', ['par_commune' => false]);

        $this->getJson(self::HOTE.'/api/vitrine/zones-livraison')->assertJsonPath('autre_commune', null);
        $this->commanderSurLaVitrine('Marcory', autreCommune: true)->assertCreated();

        $this->assertSame(0, $this->derniereCommande()->frais_livraison);
        $this->getJson(self::HOTE.'/api/parametres/livraison')->assertJsonPath('data.autre_commune', ['frais' => 2000]);
    }

    public function test_la_zone_autre_commune_ne_se_modifie_ni_ne_se_retire_comme_une_commune(): void
    {
        $this->connecte();
        $this->putJson(self::HOTE.'/api/parametres/livraison', ['par_commune' => true]);
        $this->autreCommune(2000);
        $zone = ZoneLivraison::pourTousEtablissements()->where('autre_commune', true)->firstOrFail();

        $this->putJson(self::HOTE."/api/parametres/livraison/zones/{$zone->id}", ['nom' => 'Cocody', 'frais' => 0])->assertNotFound();
        $this->deleteJson(self::HOTE."/api/parametres/livraison/zones/{$zone->id}")->assertNotFound();
        $this->assertSame(2000, $zone->fresh()->frais);
        // Une vraie commune nommée « Autre commune » ne la heurte pas.
        $this->ajouter('Autre commune', 500)->assertOk();
    }
}
