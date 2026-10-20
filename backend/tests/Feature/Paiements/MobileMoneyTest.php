<?php

namespace Tests\Feature\Paiements;

use App\Enums\ModePaiement;
use App\Enums\OperateurMobileMoney;
use App\Enums\StatutCommande;
use App\Models\Commande;
use App\Models\Etablissement;
use App\Models\JournalAudit;
use App\Models\Produit;
use App\Models\Role;
use App\Models\User;
use App\Services\Utilisateurs\CreerRattachementUtilisateur;
use App\Support\Vitrine\CacheVitrine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape 13 — paiement par transfert Mobile Money, confirmé à la main par le
 * commerçant. Comptes de démo (voir UtilisateursDemoSeeder) : Awa admin de
 * chez-awa, Adjoua admin et Yao opérateur de maquis-du-port.
 */
class MobileMoneyTest extends TestCase
{
    use RefreshDatabase;

    private function depuis(string $hote): static
    {
        return $this->withHeader('Referer', "http://{$hote}:8000");
    }

    private function connecte(string $hote, string $email): static
    {
        $this->depuis($hote)->postJson("http://{$hote}:8000/api/connexion", [
            'email' => $email,
            'mot_de_passe' => 'motdepasse',
        ])->assertStatus(200);

        return $this;
    }

    private function etablissement(string $slug): Etablissement
    {
        return Etablissement::where('slug', $slug)->firstOrFail();
    }

    private function configurerCinetpay(string $slug): void
    {
        $etablissement = $this->etablissement($slug);
        $etablissement->cinetpay_site_id = '105900000';
        $etablissement->cinetpay_cle_api = 'cle-api-fictive-1234567890';
        $etablissement->cinetpay_secret = 'secret-fictif-abcdefghij';
        $etablissement->save();
        CacheVitrine::invalider($etablissement->id);
    }

    private function configurerMobileMoney(string $slug): void
    {
        $etablissement = $this->etablissement($slug);
        $etablissement->mm_numero = '+2250707080910';
        $etablissement->mm_operateur = OperateurMobileMoney::Wave;
        $etablissement->mm_beneficiaire = 'KOFFI YAO ARMAND';
        $etablissement->save();
        CacheVitrine::invalider($etablissement->id);
    }

    private function modePropose(string $hote)
    {
        return $this->getJson("http://{$hote}:8000/api/vitrine/etablissement")->assertStatus(200)->json('data.mode_paiement');
    }

    /** @return array{numero: string, jeton: string, commande: Commande} */
    private function commanderEnMobileMoney(string $hote = 'maquis-du-port.localhost', string $cle = 'cle-mm'): array
    {
        $slug = str_replace('.localhost', '', $hote);
        $produit = Produit::factory()->for($this->etablissement($slug))->publie()->create([
            'prix' => 20309,
            'quantite_stock' => 10,
            'quantite_reservee' => 0,
            'disponible' => true,
        ]);

        $reponse = $this->depuis($hote)->postJson("http://{$hote}:8000/api/vitrine/commandes", [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => ['nom' => 'Client Test', 'telephone' => '0701020304'],
            'commune' => 'Port-Bouët',
            'quartier' => 'Vridi',
            'cle_idempotence' => $cle,
            'mode_paiement' => 'mobile_money',
        ])->assertStatus(201);

        $commande = Commande::pourTousEtablissements()->where('numero', $reponse->json('numero'))->firstOrFail();

        return ['numero' => $reponse->json('numero'), 'jeton' => $reponse->json('jeton'), 'commande' => $commande];
    }

    private function confirmerPaiement(string $hote, Commande $commande, array $corps = ['montant_recu' => 20309])
    {
        return $this->depuis($hote)->postJson("http://{$hote}:8000/api/commandes/{$commande->id}/confirmer-paiement", $corps);
    }

    public function test_1_sans_aucune_configuration_aucun_moyen_de_paiement_nest_propose(): void
    {
        $this->seed();

        $this->assertNull($this->modePropose('maquis-du-port.localhost'));
        $this->assertFalse($this->getJson('http://maquis-du-port.localhost:8000/api/vitrine/etablissement')->json('data.paiement_disponible'));
    }

    public function test_2_avec_cinetpay_seul_cinetpay_est_propose(): void
    {
        $this->seed();
        $this->configurerCinetpay('chez-awa');

        $this->assertSame('cinetpay', $this->modePropose('chez-awa.localhost'));
    }

    public function test_3_avec_le_mobile_money_seul_le_mobile_money_est_propose(): void
    {
        $this->seed();
        $this->configurerMobileMoney('maquis-du-port');

        $this->assertSame('mobile_money', $this->modePropose('maquis-du-port.localhost'));
    }

    public function test_4_avec_les_deux_seul_cinetpay_est_propose_et_une_commande_mobile_money_est_refusee(): void
    {
        $this->seed();
        $this->configurerCinetpay('chez-awa');
        $this->configurerMobileMoney('chez-awa');

        $this->assertSame('cinetpay', $this->modePropose('chez-awa.localhost'));

        $produit = Produit::factory()->for($this->etablissement('chez-awa'))->publie()->create(['quantite_stock' => 5, 'quantite_reservee' => 0]);
        $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => ['nom' => 'Client Test', 'telephone' => '0701020304'],
            'commune' => 'Cocody',
            'quartier' => 'Angré',
            'cle_idempotence' => 'cle-deux-modes',
            'mode_paiement' => 'mobile_money',
        ])->assertStatus(422)->assertJsonValidationErrors(['mode_paiement']);
    }

    public function test_5_une_configuration_partielle_est_refusee(): void
    {
        $this->seed();
        $this->connecte('maquis-du-port.localhost', 'adjoua@maquis-du-port.test');

        $this->depuis('maquis-du-port.localhost')
            ->putJson('http://maquis-du-port.localhost:8000/api/parametres/mobile-money', [
                'mm_numero' => '07 07 08 09 10',
                'mm_operateur' => 'wave',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mm_beneficiaire']);

        $this->depuis('maquis-du-port.localhost')
            ->putJson('http://maquis-du-port.localhost:8000/api/parametres/mobile-money', [
                'mm_numero' => '07 07 08 09 10',
                'mm_operateur' => 'paypal',
                'mm_beneficiaire' => 'KOFFI YAO ARMAND',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mm_operateur']);

        $this->assertFalse($this->etablissement('maquis-du-port')->mobileMoneyEstConfigure());
        $this->assertNull($this->etablissement('maquis-du-port')->mm_numero);
    }

    public function test_6_un_numero_aux_deux_formats_donne_la_meme_valeur_stockee(): void
    {
        $this->seed();
        $this->connecte('maquis-du-port.localhost', 'adjoua@maquis-du-port.test');
        $stockees = [];

        foreach (['07 07 08 09 10', '+225 07 07 08 09 10'] as $saisie) {
            $this->depuis('maquis-du-port.localhost')
                ->putJson('http://maquis-du-port.localhost:8000/api/parametres/mobile-money', [
                    'mm_numero' => $saisie,
                    'mm_operateur' => 'wave',
                    'mm_beneficiaire' => '  KOFFI   YAO ARMAND ',
                ])
                ->assertStatus(200)
                ->assertJsonPath('data.numero_affiche', '07 07 08 09 10')
                ->assertJsonPath('data.beneficiaire', 'KOFFI YAO ARMAND');

            $stockees[] = $this->etablissement('maquis-du-port')->mm_numero;
        }

        $this->assertSame(['+2250707080910', '+2250707080910'], $stockees);
        $this->assertSame(2, JournalAudit::where('action', 'mobile_money_configure')->count());
    }

    public function test_7_le_client_ne_peut_pas_confirmer_un_paiement(): void
    {
        $this->seed();
        $this->configurerMobileMoney('maquis-du-port');
        ['numero' => $numero, 'commande' => $commande] = $this->commanderEnMobileMoney();

        // Sans session : la route du back-office refuse.
        $this->confirmerPaiement('maquis-du-port.localhost', $commande)->assertStatus(401);

        // Aucune route publique ne le permet, même en devinant son chemin.
        $this->depuis('maquis-du-port.localhost')
            ->postJson("http://maquis-du-port.localhost:8000/api/vitrine/commandes/{$numero}/confirmer-paiement", ['montant_recu' => 20309])
            ->assertStatus(404);

        // Et une commande créée en prétendant être déjà payée ne l'est pas.
        $produit = Produit::factory()->for($this->etablissement('maquis-du-port'))->publie()->create(['disponible' => true]);
        $reponse = $this->depuis('maquis-du-port.localhost')->postJson('http://maquis-du-port.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => ['nom' => 'Client Malin', 'telephone' => '0701020399'],
            'commune' => 'Port-Bouët',
            'quartier' => 'Vridi',
            'cle_idempotence' => 'cle-malin',
            'mode_paiement' => 'mobile_money',
            'paiement_confirme_le' => now()->toIso8601String(),
            'paiement_montant_recu' => 20309,
        ])->assertStatus(201);

        $this->assertNull($commande->fresh()->paiement_confirme_le);
        $this->assertNull(Commande::pourTousEtablissements()->where('numero', $reponse->json('numero'))->value('paiement_confirme_le'));
        $this->getJson("http://maquis-du-port.localhost:8000/api/vitrine/commandes/{$numero}?jeton={$commande->jeton_acces}")
            ->assertJsonPath('data.paiement_confirme', false);
    }

    public function test_8_sans_gerer_commandes_la_confirmation_renvoie_403(): void
    {
        $this->seed();
        $this->configurerMobileMoney('maquis-du-port');
        ['commande' => $commande] = $this->commanderEnMobileMoney();

        $gestionnaire = User::create([
            'name' => 'Gestionnaire Stock',
            'email' => 'stock@maquis-du-port.test',
            'password' => 'motdepasse',
            'mot_de_passe_defini' => true,
        ]);
        app(CreerRattachementUtilisateur::class)->executer(
            $gestionnaire,
            $this->etablissement('maquis-du-port'),
            Role::where('nom', 'gestionnaire_stock')->value('id'),
        );

        $this->connecte('maquis-du-port.localhost', 'stock@maquis-du-port.test');
        $this->confirmerPaiement('maquis-du-port.localhost', $commande)->assertStatus(403);
        $this->assertNull($commande->fresh()->paiement_confirme_le);
    }

    public function test_9_confirmer_le_paiement_ne_change_pas_le_statut_de_la_commande(): void
    {
        $this->seed();
        $this->configurerMobileMoney('maquis-du-port');
        ['commande' => $commande] = $this->commanderEnMobileMoney();
        $this->assertSame(StatutCommande::AttentePaiement, $commande->statut);

        $this->connecte('maquis-du-port.localhost', 'yao@maquis-du-port.test');
        $this->confirmerPaiement('maquis-du-port.localhost', $commande, ['montant_recu' => 20309, 'reference' => 'TX-12345'])
            ->assertStatus(200)
            ->assertJsonPath('data.statut', 'attente_paiement')
            ->assertJsonPath('data.paiement.confirme', true)
            ->assertJsonPath('data.paiement.confirme_par', 'Yao Kouadio')
            ->assertJsonPath('data.paiement.reference', 'TX-12345');

        $fraiche = $commande->fresh();
        $this->assertSame(StatutCommande::AttentePaiement, $fraiche->statut);
        $this->assertSame(ModePaiement::MobileMoney, $fraiche->mode_paiement);

        // Une seconde confirmation est refusée, rien n'est écrasé.
        $this->confirmerPaiement('maquis-du-port.localhost', $commande, ['montant_recu' => 1])->assertStatus(422);
        $this->assertSame(20309, $commande->fresh()->paiement_montant_recu);

        $journal = JournalAudit::where('action', 'paiement_commande_confirme')->sole();
        $this->assertSame(20309, $journal->details['montant_recu']);
        $this->assertSame('TX-12345', $journal->details['reference']);
        $this->assertSame(User::where('email', 'yao@maquis-du-port.test')->value('id'), $journal->utilisateur_id);
    }

    public function test_10_un_montant_different_du_total_est_enregistre_et_lecart_apparait_dans_lhistorique(): void
    {
        $this->seed();
        $this->configurerMobileMoney('maquis-du-port');
        ['commande' => $commande] = $this->commanderEnMobileMoney();

        $this->connecte('maquis-du-port.localhost', 'yao@maquis-du-port.test');
        $reponse = $this->confirmerPaiement('maquis-du-port.localhost', $commande, ['montant_recu' => 20000])
            ->assertStatus(200)
            ->assertJsonPath('data.paiement.montant_recu', 20000)
            ->assertJsonPath('data.paiement.ecart', -309);

        $entree = collect($reponse->json('data.historique'))->firstWhere('evenement', 'paiement_confirme');
        $this->assertNotNull($entree);
        $this->assertStringContainsString('Écart', $entree['motif']);
        $this->assertStringContainsString('309', $entree['motif']);
        $this->assertSame($entree['ancien_statut'], $entree['nouveau_statut']);
    }

    public function test_11_un_commercant_de_a_ne_peut_pas_confirmer_un_paiement_de_b(): void
    {
        $this->seed();
        $this->configurerMobileMoney('maquis-du-port');
        ['commande' => $commande] = $this->commanderEnMobileMoney();

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $this->confirmerPaiement('chez-awa.localhost', $commande)->assertStatus(404);

        $this->assertNull($commande->fresh()->paiement_confirme_le);
    }

    public function test_la_page_de_confirmation_donne_les_instructions_sans_jamais_dire_paye(): void
    {
        $this->seed();
        $this->configurerMobileMoney('maquis-du-port');
        ['numero' => $numero, 'jeton' => $jeton, 'commande' => $commande] = $this->commanderEnMobileMoney();

        $this->getJson("http://maquis-du-port.localhost:8000/api/vitrine/commandes/{$numero}?jeton={$jeton}")
            ->assertStatus(200)
            ->assertJsonPath('data.mode_paiement', 'mobile_money')
            ->assertJsonPath('data.paiement_confirme', false)
            ->assertJsonPath('data.mobile_money.numero_affiche', '07 07 08 09 10')
            ->assertJsonPath('data.mobile_money.operateur_libelle', 'Wave')
            ->assertJsonPath('data.mobile_money.beneficiaire', 'KOFFI YAO ARMAND');

        // Le délai de réservation laisse au commerçant le temps de voir
        // l'argent arriver : jamais les 15 minutes d'une commande web.
        $this->assertTrue($commande->expire_le->greaterThan(now()->addHours(23)));
    }

    public function test_un_commercant_sans_gerer_parametres_ne_configure_pas_le_mobile_money(): void
    {
        $this->seed();
        $this->connecte('maquis-du-port.localhost', 'yao@maquis-du-port.test');

        $this->depuis('maquis-du-port.localhost')
            ->putJson('http://maquis-du-port.localhost:8000/api/parametres/mobile-money', [
                'mm_numero' => '0707080910',
                'mm_operateur' => 'wave',
                'mm_beneficiaire' => 'KOFFI YAO ARMAND',
            ])
            ->assertStatus(403);
    }
}
