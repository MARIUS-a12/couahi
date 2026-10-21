<?php

namespace Tests\Feature\Paiements;

use App\Enums\StatutCommande;
use App\Jobs\LibererReservationsExpirees;
use App\Models\Commande;
use App\Models\Etablissement;
use App\Models\JournalAudit;
use App\Models\Produit;
use App\Models\Role;
use App\Models\User;
use App\Services\Stock\LibererReservation;
use App\Services\Utilisateurs\CreerRattachementUtilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le cas CMD-000002 de la démo : une commande Mobile Money payée en partie
 * (10 000 sur 15 000), puis annulée. Jamais "payée" tant qu'il manque de
 * l'argent ; une décision signalée en priorité ; une cliente qui lit
 * franchement qu'un remboursement lui est dû ; un remboursement que le
 * commerçant peut enfin solder. Boutique wax-adjame : Mobile Money seul
 * (voir EtablissementsDemoSeeder), administratrice Fatou.
 */
class PaiementPartielEtRemboursementTest extends TestCase
{
    use RefreshDatabase;

    private const HOTE = 'wax-adjame.localhost';

    private function depuis(): static
    {
        return $this->withHeader('Referer', 'http://'.self::HOTE.':8000');
    }

    private function url(string $chemin): string
    {
        return 'http://'.self::HOTE.':8000'.$chemin;
    }

    private function connecte(string $email = 'fatou@wax-adjame.test'): static
    {
        $this->depuis()->postJson($this->url('/api/connexion'), ['email' => $email, 'mot_de_passe' => 'motdepasse'])->assertStatus(200);

        return $this;
    }

    private function wax(): Etablissement
    {
        return Etablissement::where('slug', 'wax-adjame')->firstOrFail();
    }

    /** Une commande Mobile Money de 15 000 FCFA, passée depuis la vitrine. */
    private function commander(string $cle = 'cle-partiel'): Commande
    {
        $produit = Produit::factory()->for($this->wax())->publie()->create([
            'prix' => 15000, 'quantite_stock' => 10, 'quantite_reservee' => 0, 'disponible' => true,
        ]);

        $numero = $this->depuis()->postJson($this->url('/api/vitrine/commandes'), [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => ['nom' => 'Bamba Aïcha', 'telephone' => '0544332211'],
            'commune' => 'Adjamé',
            'quartier' => 'Liberté',
            'cle_idempotence' => $cle,
            'mode_paiement' => 'mobile_money',
        ])->assertStatus(201)->json('numero');

        return Commande::pourTousEtablissements()->where('etablissement_id', $this->wax()->id)->where('numero', $numero)->firstOrFail();
    }

    private function confirmerPaiement(Commande $commande, int $montant = 10000): void
    {
        $this->depuis()->postJson($this->url("/api/commandes/{$commande->id}/confirmer-paiement"), ['montant_recu' => $montant])->assertStatus(200);
    }

    private function annuler(Commande $commande): void
    {
        $this->depuis()->postJson($this->url("/api/commandes/{$commande->id}/annuler"), ['motif_type' => 'client_annule'])->assertStatus(200);
    }

    private function detail(Commande $commande): array
    {
        return $this->depuis()->getJson($this->url("/api/commandes/{$commande->id}"))->assertStatus(200)->json('data');
    }

    private function pageCliente(Commande $commande): array
    {
        return $this->getJson($this->url("/api/vitrine/commandes/{$commande->numero}?jeton={$commande->jeton_acces}"))->assertStatus(200)->json('data');
    }

    public function test_1_un_paiement_inferieur_au_total_ne_produit_jamais_letat_payee(): void
    {
        $this->seed();
        $this->connecte();
        $commande = $this->commander();
        $this->confirmerPaiement($commande, 10000);

        $paiement = $this->detail($commande)['paiement'];
        $this->assertTrue($paiement['partiel']);
        $this->assertSame(5000, $paiement['reste_du']);

        // Même 3 heures plus tard : jamais le signal "payée — à traiter".
        $this->travel(3)->hours();
        $this->assertFalse($this->detail($commande)['paiement']['a_traiter_en_urgence']);

        // Et la cliente lit le reste à envoyer, pas un paiement réglé.
        $page = $this->pageCliente($commande);
        $this->assertSame(10000, $page['montant_recu']);
        $this->assertSame(5000, $page['reste_du']);
        $this->assertNotNull($page['mobile_money']);
    }

    public function test_un_paiement_complet_nest_pas_partiel(): void
    {
        $this->seed();
        $this->connecte();
        $commande = $this->commander();
        $this->confirmerPaiement($commande, 15000);

        $paiement = $this->detail($commande)['paiement'];
        $this->assertFalse($paiement['partiel']);
        $this->assertNull($paiement['reste_du']);
        $this->assertFalse($paiement['partielle_a_decider']);
    }

    public function test_2_une_commande_partiellement_payee_apparait_dans_le_signalement_prioritaire(): void
    {
        $this->seed();
        $this->connecte();
        $commande = $this->commander();
        $this->confirmerPaiement($commande, 10000);

        $ligne = collect($this->depuis()->getJson($this->url('/api/commandes'))->assertStatus(200)->json('data'))
            ->firstWhere('id', $commande->id);
        $this->assertTrue($ligne['paiement']['partielle_a_decider']);

        // Le client a versé de l'argent : elle n'expire pas, et garde son stock.
        $this->travel(2)->days();
        (new LibererReservationsExpirees)->handle(app(LibererReservation::class));
        $this->assertSame(StatutCommande::AttentePaiement, $commande->fresh()->statut);

        // Une fois acceptée, la décision est prise : plus de signal prioritaire, le reste dû demeure.
        $this->depuis()->patchJson($this->url("/api/commandes/{$commande->id}/confirmer"))->assertStatus(200);
        $paiement = $this->detail($commande)['paiement'];
        $this->assertFalse($paiement['partielle_a_decider']);
        $this->assertSame(5000, $paiement['reste_du']);
    }

    public function test_3_la_page_cliente_dune_commande_annulee_avec_paiement_mentionne_le_remboursement_du(): void
    {
        $this->seed();
        $this->connecte();
        $commande = $this->commander();
        $this->confirmerPaiement($commande, 10000);
        $this->annuler($commande);

        $page = $this->pageCliente($commande);
        $this->assertSame('annulee', $page['statut']);
        $this->assertSame(10000, $page['a_rembourser']);
        $this->assertNull($page['reste_du'], 'une commande annulée ne demande plus rien');
        $this->assertNull($page['mobile_money'], 'aucune instruction de transfert sur une commande annulée');
    }

    public function test_une_commande_annulee_sans_paiement_ne_donne_plus_dinstructions_de_transfert(): void
    {
        $this->seed();
        $this->connecte();
        $commande = $this->commander();
        $this->annuler($commande);

        $page = $this->pageCliente($commande);
        $this->assertNull($page['mobile_money']);
        $this->assertNull($page['a_rembourser']);
    }

    public function test_4_le_remboursement_enregistre_fait_disparaitre_la_mention_et_reste_dans_lhistorique(): void
    {
        $this->seed();
        $this->connecte();
        $commande = $this->commander();
        $this->confirmerPaiement($commande, 10000);
        $this->annuler($commande);
        $this->assertSame(10000, $this->detail($commande)['paiement']['a_rembourser']);

        $reponse = $this->depuis()->postJson($this->url("/api/commandes/{$commande->id}/rembourser"), [
            'montant' => 10000,
            'reference' => 'OM-RB-5521',
        ])->assertStatus(200);

        $this->assertNull($reponse->json('data.paiement.a_rembourser'));
        $this->assertSame(10000, $reponse->json('data.paiement.remboursement.montant'));
        $this->assertSame('Fatou Diallo', $reponse->json('data.paiement.remboursement.par'));
        $this->assertSame('annulee', $reponse->json('data.statut'), 'le remboursement ne change pas le statut');

        $entree = collect($reponse->json('data.historique'))->firstWhere('evenement', 'remboursement');
        $this->assertNotNull($entree);
        $this->assertStringContainsString('10', $entree['motif']);
        $this->assertStringContainsString('OM-RB-5521', $entree['motif']);

        $journal = JournalAudit::where('action', 'remboursement_commande_enregistre')->sole();
        $this->assertSame(User::where('email', 'fatou@wax-adjame.test')->value('id'), $journal->utilisateur_id);
        $this->assertSame(10000, $journal->details['montant_rembourse']);
        $this->assertSame($commande->id, $journal->details['commande_id']);

        // La cliente le lit aussi, et la mention "À rembourser" a disparu pour elle.
        $page = $this->pageCliente($commande);
        $this->assertNull($page['a_rembourser']);
        $this->assertSame(10000, $page['remboursement']['montant']);

        // Une seconde fois : refusé, rien de plus au journal.
        $this->depuis()->postJson($this->url("/api/commandes/{$commande->id}/rembourser"), ['montant' => 10000])->assertStatus(422);
        $this->assertSame(1, JournalAudit::where('action', 'remboursement_commande_enregistre')->count());
    }

    public function test_un_remboursement_ne_depasse_jamais_le_montant_recu_ni_ne_vise_une_commande_sans_dette(): void
    {
        $this->seed();
        $this->connecte();
        $commande = $this->commander();
        $this->confirmerPaiement($commande, 10000);

        // Pas encore annulée : rien à rembourser.
        $this->depuis()->postJson($this->url("/api/commandes/{$commande->id}/rembourser"), ['montant' => 10000])->assertStatus(422);

        $this->annuler($commande);
        $this->depuis()->postJson($this->url("/api/commandes/{$commande->id}/rembourser"), ['montant' => 15000])
            ->assertStatus(422)->assertJsonValidationErrors(['montant']);
        $this->assertNull($commande->fresh()->rembourse_le);
    }

    public function test_5_sans_gerer_commandes_lenregistrement_du_remboursement_renvoie_403(): void
    {
        $this->seed();
        $this->connecte();
        $commande = $this->commander();
        $this->confirmerPaiement($commande, 10000);
        $this->annuler($commande);
        $this->postJson($this->url('/api/deconnexion'));
        $this->app['auth']->forgetGuards();

        $gestionnaire = User::create([
            'name' => 'Gestionnaire Stock',
            'email' => 'stock@wax-adjame.test',
            'password' => 'motdepasse',
            'mot_de_passe_defini' => true,
        ]);
        app(CreerRattachementUtilisateur::class)->executer($gestionnaire, $this->wax(), Role::where('nom', 'gestionnaire_stock')->value('id'));

        $this->connecte('stock@wax-adjame.test');
        $this->depuis()->postJson($this->url("/api/commandes/{$commande->id}/rembourser"), ['montant' => 10000])->assertStatus(403);
        // 403 avant toute validation : un corps invalide ne révèle pas les règles.
        $this->depuis()->postJson($this->url("/api/commandes/{$commande->id}/rembourser"), [])->assertStatus(403);
        $this->assertNull($commande->fresh()->rembourse_le);
    }

    public function test_6_aucune_transition_nest_acceptee_sur_une_commande_annulee_ou_livree(): void
    {
        $this->seed();
        $this->connecte();

        $annulee = $this->commander('cle-annulee');
        $this->annuler($annulee);

        $livree = $this->commander('cle-livree');
        $this->depuis()->patchJson($this->url("/api/commandes/{$livree->id}/confirmer"))->assertStatus(200);
        $this->depuis()->patchJson($this->url("/api/commandes/{$livree->id}/marquer-prete"))->assertStatus(200);
        $this->depuis()->patchJson($this->url("/api/commandes/{$livree->id}/marquer-livree"))->assertStatus(200);

        foreach ([[$annulee, StatutCommande::Annulee], [$livree, StatutCommande::Livree]] as [$commande, $statut]) {
            foreach (['confirmer', 'marquer-prete', 'marquer-livree'] as $action) {
                $this->depuis()->patchJson($this->url("/api/commandes/{$commande->id}/{$action}"))->assertStatus(422);
            }
            $this->depuis()->postJson($this->url("/api/commandes/{$commande->id}/annuler"), ['motif_type' => 'client_annule'])->assertStatus(422);

            $this->assertSame($statut, $commande->fresh()->statut, "{$statut->value} : aucune transition ne doit passer");
        }
    }

    public function test_7_lavertissement_de_remboursement_porte_le_montant_reellement_recu(): void
    {
        $this->seed();
        $this->connecte();
        $commande = $this->commander();
        $this->confirmerPaiement($commande, 10000);
        $this->annuler($commande);

        $paiement = $this->detail($commande)['paiement'];
        // Le montant reçu, pas le total de la commande — et toujours un entier.
        $this->assertSame(10000, $paiement['a_rembourser']);
        $this->assertSame(10000, $paiement['montant_recu']);
        $this->assertIsInt($paiement['a_rembourser']);
    }
}
