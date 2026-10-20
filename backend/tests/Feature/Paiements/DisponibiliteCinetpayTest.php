<?php

namespace Tests\Feature\Paiements;

use App\Enums\OperateurMobileMoney;
use App\Models\Etablissement;
use App\Models\Produit;
use App\Support\Vitrine\CacheVitrine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Configuré n'est pas disponible : des identifiants CinetPay enregistrés ne
 * suffisent pas à proposer "Payer maintenant" tant que l'intégration n'est
 * pas active (CINETPAY_ACTIF, voir config/services.php). Le Mobile Money,
 * qui fonctionne, prend alors la place.
 */
class DisponibiliteCinetpayTest extends TestCase
{
    use RefreshDatabase;

    private function chezAwa(): Etablissement
    {
        return Etablissement::where('slug', 'chez-awa')->firstOrFail();
    }

    private function configurer(bool $cinetpay, bool $mobileMoney): void
    {
        $etablissement = $this->chezAwa();

        if ($cinetpay) {
            $etablissement->cinetpay_site_id = '105900000';
            $etablissement->cinetpay_cle_api = 'cle-api-fictive-1234567890';
            $etablissement->cinetpay_secret = 'secret-fictif-abcdefghij';
        }

        if ($mobileMoney) {
            $etablissement->mm_numero = '+2250707080910';
            $etablissement->mm_operateur = OperateurMobileMoney::Wave;
            $etablissement->mm_beneficiaire = 'TRAORE AWA';
        }

        $etablissement->save();
        CacheVitrine::invalider($etablissement->id);
    }

    /** @return array{mode: ?string, disponible: bool} */
    private function vitrine(): array
    {
        $donnees = $this->getJson('http://chez-awa.localhost:8000/api/vitrine/etablissement')->assertStatus(200)->json('data');

        return ['mode' => $donnees['mode_paiement'], 'disponible' => $donnees['paiement_disponible']];
    }

    private function commander(string $mode, string $cle)
    {
        $produit = Produit::factory()->for($this->chezAwa())->publie()->create(['quantite_stock' => 5, 'quantite_reservee' => 0]);

        return $this->withHeader('Referer', 'http://chez-awa.localhost:8000')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => ['nom' => 'Client Test', 'telephone' => '0701020304'],
            'commune' => 'Cocody',
            'quartier' => 'Angré',
            'cle_idempotence' => $cle,
            'mode_paiement' => $mode,
        ]);
    }

    public function test_1_cinetpay_configure_mais_inactif_avec_mobile_money_propose_le_mobile_money(): void
    {
        config(['services.cinetpay.actif' => false]);
        $this->seed();
        $this->configurer(cinetpay: true, mobileMoney: true);

        $this->assertTrue($this->chezAwa()->paiementEstConfigure());
        $this->assertSame(['mode' => 'mobile_money', 'disponible' => false], $this->vitrine());

        // Et le serveur suit : une commande Mobile Money passe, une commande
        // CinetPay (bouton qui n'existe plus) est refusée.
        $this->commander('mobile_money', 'cle-mm')->assertStatus(201);
        $this->commander('cinetpay', 'cle-cp')->assertStatus(422)->assertJsonValidationErrors(['mode_paiement']);
    }

    public function test_2_cinetpay_configure_mais_inactif_sans_mobile_money_aucun_paiement(): void
    {
        config(['services.cinetpay.actif' => false]);
        $this->seed();
        $this->configurer(cinetpay: true, mobileMoney: false);

        $this->assertSame(['mode' => null, 'disponible' => false], $this->vitrine());
        $this->commander('cinetpay', 'cle-cp')->assertStatus(422)->assertJsonValidationErrors(['mode_paiement']);
    }

    public function test_3_cinetpay_actif_payer_maintenant_meme_avec_le_mobile_money(): void
    {
        config(['services.cinetpay.actif' => true]);
        $this->seed();
        $this->configurer(cinetpay: true, mobileMoney: true);

        $this->assertSame(['mode' => 'cinetpay', 'disponible' => true], $this->vitrine());
    }

    public function test_4_aucune_configuration_aucun_paiement_meme_indicateur_actif(): void
    {
        $this->seed();

        foreach ([false, true] as $actif) {
            config(['services.cinetpay.actif' => $actif]);
            CacheVitrine::invalider($this->chezAwa()->id);
            $this->assertSame(['mode' => null, 'disponible' => false], $this->vitrine(), 'CINETPAY_ACTIF='.var_export($actif, true));
        }
    }

    public function test_le_commercant_voit_que_ses_identifiants_ne_sont_pas_encore_actifs(): void
    {
        config(['services.cinetpay.actif' => false]);
        $this->seed();
        $this->configurer(cinetpay: true, mobileMoney: true);

        $this->withHeader('Referer', 'http://chez-awa.localhost:8000')->postJson('http://chez-awa.localhost:8000/api/connexion', [
            'email' => 'awa@chez-awa.test',
            'mot_de_passe' => 'motdepasse',
        ])->assertStatus(200);

        $paiement = $this->getJson('http://chez-awa.localhost:8000/api/parametres/paiement')->assertStatus(200)->json('data');
        $this->assertTrue($paiement['configure']);
        $this->assertFalse($paiement['disponible']);

        $mobileMoney = $this->getJson('http://chez-awa.localhost:8000/api/parametres/mobile-money')->assertStatus(200)->json('data');
        $this->assertFalse($mobileMoney['cinetpay_prioritaire']);
    }
}
