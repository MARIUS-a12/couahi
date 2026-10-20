<?php

namespace Tests\Feature\Paiements;

use App\Models\Etablissement;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Étape 6C-1 — configuration du paiement par établissement (identifiants
 * CinetPay chiffrés au repos, jamais renvoyés en clair). Réutilise les
 * comptes de démo (voir UtilisateursDemoSeeder) : Awa est admin de
 * chez-awa, Yao n'est qu'opérateur sur maquis-du-port, Marius est le
 * super-admin.
 */
class PaiementEtablissementApiTest extends TestCase
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

    private function payloadIdentifiants(array $surcharge = []): array
    {
        return array_merge([
            'cinetpay_site_id' => '105900000',
            'cinetpay_cle_api' => 'cle-api-fictive-1234567890',
            'cinetpay_secret' => 'secret-fictif-abcdefghij',
        ], $surcharge);
    }

    public function test_1_valeurs_illisibles_dans_la_colonne_brute(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $this->depuis('chez-awa.localhost')
            ->putJson('http://chez-awa.localhost:8000/api/parametres/paiement', $this->payloadIdentifiants())
            ->assertStatus(200);

        $ligneBrute = DB::table('etablissements')->where('id', $this->chezAwa()->id)->first();

        $this->assertStringNotContainsString('105900000', $ligneBrute->cinetpay_site_id);
        $this->assertStringNotContainsString('cle-api-fictive-1234567890', $ligneBrute->cinetpay_cle_api);
        $this->assertStringNotContainsString('secret-fictif-abcdefghij', $ligneBrute->cinetpay_secret);

        // Le modèle, lui, déchiffre correctement (le chiffrement protège la
        // base, pas l'application elle-même).
        $this->assertSame('105900000', $this->chezAwa()->cinetpay_site_id);
    }

    public function test_2_secret_jamais_en_clair_dans_aucune_reponse(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $ecriture = $this->depuis('chez-awa.localhost')
            ->putJson('http://chez-awa.localhost:8000/api/parametres/paiement', $this->payloadIdentifiants());

        $ecriture->assertStatus(200);
        $ecriture->assertJsonMissing(['cinetpay_secret']);
        $this->assertStringNotContainsString('secret-fictif-abcdefghij', $ecriture->getContent());

        $lecture = $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/parametres/paiement');
        $this->assertStringNotContainsString('secret-fictif-abcdefghij', $lecture->getContent());
        $this->assertArrayNotHasKey('cinetpay_secret', $lecture->json());

        // Même le super-admin, en dépannage, ne le reçoit jamais.
        $hoteSuperAdmin = config('tenancy.hote_super_admin');
        $this->connecte($hoteSuperAdmin, 'super@plateforme.test');
        $lectureSuperAdmin = $this->depuis($hoteSuperAdmin)
            ->getJson("http://{$hoteSuperAdmin}:8000/api/etablissements/{$this->chezAwa()->id}/paiement");
        $this->assertStringNotContainsString('secret-fictif-abcdefghij', $lectureSuperAdmin->getContent());
        $this->assertArrayNotHasKey('cinetpay_secret', $lectureSuperAdmin->json());
    }

    public function test_3_configuration_partielle_refusee(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $reponse = $this->depuis('chez-awa.localhost')->putJson('http://chez-awa.localhost:8000/api/parametres/paiement', [
            'cinetpay_site_id' => '105900000',
            'cinetpay_cle_api' => 'cle-api-fictive',
            // cinetpay_secret manquant.
        ]);

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['cinetpay_secret']);
        $this->assertFalse($this->chezAwa()->paiementEstConfigure());
    }

    public function test_4_paiement_est_configure_vrai_seulement_si_les_trois_sont_la(): void
    {
        $this->seed();
        $etablissement = $this->chezAwa();

        $this->assertFalse($etablissement->paiementEstConfigure());

        $etablissement->cinetpay_site_id = '1';
        $etablissement->cinetpay_cle_api = '2';
        $this->assertFalse($etablissement->paiementEstConfigure());

        $etablissement->cinetpay_secret = '3';
        $this->assertTrue($etablissement->paiementEstConfigure());
    }

    public function test_5_vitrine_expose_paiement_disponible_sans_identifiants(): void
    {
        // Disponible = configuré ET intégration active (CINETPAY_ACTIF).
        config(['services.cinetpay.actif' => true]);
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $this->depuis('chez-awa.localhost')
            ->putJson('http://chez-awa.localhost:8000/api/parametres/paiement', $this->payloadIdentifiants())
            ->assertStatus(200);

        $reponse = $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/vitrine/etablissement');

        $reponse->assertStatus(200);
        $this->assertTrue($reponse->json('data.paiement_disponible'));
        $this->assertStringNotContainsString('105900000', $reponse->getContent());
        $this->assertStringNotContainsString('secret-fictif-abcdefghij', $reponse->getContent());
    }

    public function test_6_demande_de_paiement_en_ligne_sans_configuration_refusee(): void
    {
        $this->seed();
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create([
            'prix' => 1000,
            'quantite_stock' => 5,
            'quantite_reservee' => 0,
        ]);

        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => ['nom' => 'Fatou Koné', 'telephone' => '0701020304'],
            'cle_idempotence' => 'cle-paiement-non-configure',
            'paiement_en_ligne' => true,
        ]);

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['paiement_en_ligne']);
    }

    public function test_7_operateur_recoit_403_sur_put(): void
    {
        $this->seed();
        $this->connecte('maquis-du-port.localhost', 'yao@maquis-du-port.test');

        $reponse = $this->depuis('maquis-du-port.localhost')
            ->putJson('http://maquis-du-port.localhost:8000/api/parametres/paiement', $this->payloadIdentifiants());

        $reponse->assertStatus(403);
    }

    public function test_8_admin_de_a_ne_peut_ni_lire_ni_ecrire_la_configuration_de_b(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $maquisDuPort = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();

        $hoteSuperAdmin = config('tenancy.hote_super_admin');

        $lecture = $this->depuis($hoteSuperAdmin)
            ->getJson("http://{$hoteSuperAdmin}:8000/api/etablissements/{$maquisDuPort->id}/paiement");
        $lecture->assertStatus(403);

        $ecriture = $this->depuis($hoteSuperAdmin)
            ->putJson("http://{$hoteSuperAdmin}:8000/api/etablissements/{$maquisDuPort->id}/paiement", $this->payloadIdentifiants());
        $ecriture->assertStatus(403);
    }

    public function test_9_suppression_rend_paiement_est_configure_faux(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $this->depuis('chez-awa.localhost')
            ->putJson('http://chez-awa.localhost:8000/api/parametres/paiement', $this->payloadIdentifiants())
            ->assertStatus(200);
        $this->assertTrue($this->chezAwa()->paiementEstConfigure());

        $this->depuis('chez-awa.localhost')
            ->deleteJson('http://chez-awa.localhost:8000/api/parametres/paiement')
            ->assertStatus(204);

        $this->assertFalse($this->chezAwa()->paiementEstConfigure());
    }

    public function test_10_ecriture_journalisee_sans_les_valeurs(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $awa = User::where('email', 'awa@chez-awa.test')->firstOrFail();

        $this->depuis('chez-awa.localhost')
            ->putJson('http://chez-awa.localhost:8000/api/parametres/paiement', $this->payloadIdentifiants())
            ->assertStatus(200);

        $this->assertDatabaseHas('journaux_audit', [
            'utilisateur_id' => $awa->id,
            'action' => 'paiement_configure',
        ]);

        $journal = DB::table('journaux_audit')->where('action', 'paiement_configure')->first();
        $this->assertStringNotContainsString('secret-fictif-abcdefghij', $journal->details);
        $this->assertStringNotContainsString('cle-api-fictive-1234567890', $journal->details);
    }
}
