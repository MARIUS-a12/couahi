<?php

namespace Tests\Feature\Commandes;

use App\Models\Commande;
use App\Models\Etablissement;
use App\Models\EtablissementUtilisateur;
use App\Models\Permission;
use App\Models\Produit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape 9 — gestion des commandes du back-office. Réutilise les comptes de
 * démo (voir UtilisateursDemoSeeder) : Awa est admin de chez-awa, Marius est
 * le super-admin. Les commandes réelles sont créées en passant par l'API
 * vitrine (même chemin qu'un vrai client), jamais en les insérant
 * directement en base — c'est ce qui garantit que la réservation de stock
 * existe vraiment avant de tester confirmer/annuler dessus.
 */
class CommandeControllerApiTest extends TestCase
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

    /**
     * Crée une vraie commande (avec sa réservation de stock) en passant par
     * l'API vitrine, pour le produit donné, quantité 2.
     */
    private function creerCommande(Produit $produit, string $cleIdempotence): string
    {
        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 2]],
            'client' => ['nom' => 'Fatou Koné', 'telephone' => '0701020304'],
            'commune' => 'Cocody',
            'quartier' => 'Angré 7e tranche',
            'cle_idempotence' => $cleIdempotence,
        ]);

        $reponse->assertStatus(201);

        return $reponse->json('numero');
    }

    public function test_1_liste_filtre_par_statut_et_recherche_par_numero(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);
        $numero = $this->creerCommande($produit, 'cle-liste-1');
        $this->creerCommande($produit, 'cle-liste-2');

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $parStatut = $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/commandes?statut=attente_paiement');
        $parStatut->assertStatus(200);
        $this->assertCount(2, $parStatut->json('data'));

        $parRecherche = $this->depuis('chez-awa.localhost')->getJson("http://chez-awa.localhost:8000/api/commandes?recherche={$numero}");
        $parRecherche->assertStatus(200);
        $this->assertCount(1, $parRecherche->json('data'));
        $this->assertSame($numero, $parRecherche->json('data.0.numero'));

        $parTelephone = $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/commandes?recherche=0701020304');
        $this->assertCount(2, $parTelephone->json('data'));
    }

    public function test_2_detail_expose_lignes_et_historique(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);
        $numero = $this->creerCommande($produit, 'cle-detail');
        $commande = Commande::where('numero', $numero)->firstOrFail();

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $reponse = $this->depuis('chez-awa.localhost')->getJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}");

        $reponse->assertStatus(200);
        $this->assertSame('attente_paiement', $reponse->json('data.statut'));
        $this->assertCount(1, $reponse->json('data.lignes'));
        $this->assertSame(2, $reponse->json('data.lignes.0.quantite'));
        $this->assertCount(1, $reponse->json('data.historique'));
        $this->assertSame('En attente', $reponse->json('data.historique.0.nouveau_statut'));
    }

    public function test_3_confirmer_consomme_la_reservation_et_decremente_le_stock(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);
        $numero = $this->creerCommande($produit, 'cle-confirmer');
        $commande = Commande::where('numero', $numero)->firstOrFail();
        $this->assertSame(2, $produit->fresh()->quantite_reservee);

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $reponse = $this->depuis('chez-awa.localhost')->patchJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/confirmer");

        $reponse->assertStatus(200);
        $this->assertSame('payee', $reponse->json('data.statut'));
        $this->assertSame(8, $produit->fresh()->quantite_stock);
        $this->assertSame(0, $produit->fresh()->quantite_reservee);
    }

    public function test_4_transition_interdite_renvoie_422_nommant_le_statut_actuel(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);
        $numero = $this->creerCommande($produit, 'cle-interdite');
        $commande = Commande::where('numero', $numero)->firstOrFail();

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        // Encore en attente : "marquer prête" exige "payee" d'abord.
        $refus = $this->depuis('chez-awa.localhost')->patchJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/marquer-prete");
        $refus->assertStatus(422);
        $this->assertStringContainsString('En attente', $refus->json('message'));

        // Livrée est terminale : plus aucune transition, y compris annuler.
        $this->depuis('chez-awa.localhost')->patchJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/confirmer")->assertStatus(200);
        $this->depuis('chez-awa.localhost')->patchJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/marquer-prete")->assertStatus(200);
        $this->depuis('chez-awa.localhost')->patchJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/marquer-livree")->assertStatus(200);

        $refusLivree = $this->depuis('chez-awa.localhost')->patchJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/marquer-livree");
        $refusLivree->assertStatus(422);
        $this->assertStringContainsString('Livrée', $refusLivree->json('message'));

        $refusAnnulation = $this->depuis('chez-awa.localhost')->postJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/annuler", [
            'motif_type' => 'autre',
            'motif_autre' => 'Peu importe',
        ]);
        $refusAnnulation->assertStatus(422);
        $this->assertStringContainsString('Livrée', $refusAnnulation->json('message'));
    }

    public function test_5_cycle_complet_jusqua_livree(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);
        $numero = $this->creerCommande($produit, 'cle-cycle');
        $commande = Commande::where('numero', $numero)->firstOrFail();

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $this->depuis('chez-awa.localhost')->patchJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/confirmer")->assertStatus(200);
        $prete = $this->depuis('chez-awa.localhost')->patchJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/marquer-prete");
        $prete->assertStatus(200);
        $this->assertSame('prete', $prete->json('data.statut'));

        $livree = $this->depuis('chez-awa.localhost')->patchJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/marquer-livree");
        $livree->assertStatus(200);
        $this->assertSame('livree', $livree->json('data.statut'));

        $detail = $this->depuis('chez-awa.localhost')->getJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}");
        $this->assertCount(4, $detail->json('data.historique'));
    }

    public function test_6_annulation_exige_un_motif_valide(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);
        $numero = $this->creerCommande($produit, 'cle-motif');
        $commande = Commande::where('numero', $numero)->firstOrFail();

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $sansMotif = $this->depuis('chez-awa.localhost')->postJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/annuler", []);
        $sansMotif->assertStatus(422);
        $sansMotif->assertJsonValidationErrors(['motif_type']);

        $autreSansTexte = $this->depuis('chez-awa.localhost')->postJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/annuler", [
            'motif_type' => 'autre',
        ]);
        $autreSansTexte->assertStatus(422);
        $autreSansTexte->assertJsonValidationErrors(['motif_autre']);

        $valide = $this->depuis('chez-awa.localhost')->postJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/annuler", [
            'motif_type' => 'client_injoignable',
        ]);
        $valide->assertStatus(200);
        $this->assertSame('annulee', $valide->json('data.statut'));
    }

    public function test_7_annuler_une_commande_confirmee_restaure_le_stock(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);
        $numero = $this->creerCommande($produit, 'cle-annulation-confirmee');
        $commande = Commande::where('numero', $numero)->firstOrFail();

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $this->depuis('chez-awa.localhost')->patchJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/confirmer")->assertStatus(200);
        $this->assertSame(8, $produit->fresh()->quantite_stock);

        $annulation = $this->depuis('chez-awa.localhost')->postJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/annuler", [
            'motif_type' => 'article_indisponible',
        ]);

        $annulation->assertStatus(200);
        $this->assertSame('annulee', $annulation->json('data.statut'));
        $this->assertSame(10, $produit->fresh()->quantite_stock);
    }

    public function test_8_statistiques_sont_reelles(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0, 'prix' => 1500]);
        $numeroConfirmee = $this->creerCommande($produit, 'cle-stats-confirmee');
        $this->creerCommande($produit, 'cle-stats-attente');

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $commandeConfirmee = Commande::where('numero', $numeroConfirmee)->firstOrFail();
        $this->depuis('chez-awa.localhost')->patchJson("http://chez-awa.localhost:8000/api/commandes/{$commandeConfirmee->id}/confirmer")->assertStatus(200);

        $stats = $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/commandes/statistiques');

        $stats->assertStatus(200);
        $this->assertSame(2, $stats->json('commandes_du_jour'));
        $this->assertSame(1, $stats->json('en_attente'));
        $this->assertSame(3000, $stats->json('chiffre_affaires_jour'));
        $this->assertSame(3000, $stats->json('panier_moyen_mois'));
    }

    public function test_9_qui_voit_les_commandes_sans_traiter_commandes_recoit_403_sur_confirmer(): void
    {
        $this->seed();
        $chezAwa = $this->chezAwa();
        $produit = Produit::factory()->for($chezAwa)->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);
        $numero = $this->creerCommande($produit, 'cle-permission');
        $commande = Commande::where('numero', $numero)->firstOrFail();

        // Un rôle de test dédié prouve que la frontière de la policy
        // fonctionne, indépendamment des attributions actuelles des rôles
        // réels (voir RolesEtPermissionsSeeder ; le gestionnaire de stock
        // est dans ce cas, voir PermissionsCommandesTest).
        $roleConsultationSeule = Role::create(['nom' => 'consultation_commandes_test', 'libelle' => 'Test consultation seule']);
        $roleConsultationSeule->permissions()->attach(Permission::where('nom', 'voir_commandes')->firstOrFail());

        $utilisateur = User::factory()->create(['email' => 'consultation@chez-awa.test', 'password' => self::MOT_DE_PASSE, 'mot_de_passe_defini' => true]);
        EtablissementUtilisateur::create([
            'etablissement_id' => $chezAwa->id,
            'utilisateur_id' => $utilisateur->id,
            'statut' => 'actif',
        ])->roles()->attach($roleConsultationSeule->id);

        $this->connecte('chez-awa.localhost', 'consultation@chez-awa.test');

        $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/commandes')->assertStatus(200);

        $this->depuis('chez-awa.localhost')
            ->patchJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/confirmer")
            ->assertStatus(403);
    }
}
