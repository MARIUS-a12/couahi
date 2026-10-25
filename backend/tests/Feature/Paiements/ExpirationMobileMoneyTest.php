<?php

namespace Tests\Feature\Paiements;

use App\Enums\Canal;
use App\Enums\ModePaiement;
use App\Enums\OperateurMobileMoney;
use App\Enums\SourceCommande;
use App\Enums\StatutCommande;
use App\Enums\StatutReservation;
use App\Jobs\LibererReservationsExpirees;
use App\Models\Commande;
use App\Models\Etablissement;
use App\Models\Produit;
use App\Services\Commandes\CreerCommande;
use App\Services\Commandes\LigneCommandeDemandee;
use App\Services\Stock\LibererReservation;
use App\Support\Tenancy\ContexteEtablissement;
use App\Support\Vitrine\CacheVitrine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

/**
 * Étape 13 (suite) — une commande Mobile Money PAYÉE n'expire jamais et
 * garde sa réservation ; une non payée expire normalement ; CinetPay et
 * espèces ne changent pas. Sur chez-awa (boutique) : ses produits sont en
 * stock compté, donc réellement réservés — un restaurant ne réserve rien.
 */
class ExpirationMobileMoneyTest extends TestCase
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

    private function connecterAwa(): void
    {
        $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
            'email' => 'awa@chez-awa.test',
            'mot_de_passe' => 'motdepasse',
        ])->assertStatus(200);
    }

    private function produitEnStock(): Produit
    {
        return Produit::factory()->for($this->chezAwa())->publie()->create([
            'prix' => 20309,
            'quantite_stock' => 10,
            'quantite_reservee' => 0,
        ]);
    }

    private function commanderEnMobileMoney(Produit $produit, string $cle = 'cle-mm'): Commande
    {
        $etablissement = $this->chezAwa();
        $etablissement->mm_numero = '+2250707080910';
        $etablissement->mm_operateur = OperateurMobileMoney::Wave;
        $etablissement->mm_beneficiaire = 'TRAORE AWA';
        $etablissement->save();
        CacheVitrine::invalider($etablissement->id);

        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 3]],
            'client' => ['nom' => 'Client Test', 'telephone' => '0701020304'],
            'commune' => 'Cocody',
            'quartier' => 'Angré',
            'cle_idempotence' => $cle,
            'mode_paiement' => 'mobile_money',
        ])->assertStatus(201);

        return Commande::pourTousEtablissements()->where('numero', $reponse->json('numero'))->firstOrFail();
    }

    /**
     * Par défaut, le TOTAL de la commande : un paiement complet. (Un
     * montant fixe de 20 309 servait ici pour 3 articles à 20 309 — soit un
     * paiement partiel, qui n'est jamais "payée", voir
     * PaiementPartielEtRemboursementTest.)
     */
    private function confirmerPaiement(Commande $commande, ?int $montant = null): void
    {
        $this->depuis('chez-awa.localhost')
            ->postJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/confirmer-paiement", ['montant_recu' => $montant ?? $commande->total])
            ->assertStatus(200);
    }

    private function depasserLeDelai(Commande $commande): void
    {
        $commande->forceFill(['expire_le' => now()->subMinute()])->save();
        $commande->reservations()->pourTousEtablissements()->update(['expire_le' => now()->subMinute()]);
    }

    private function executerLeJob(): void
    {
        // Le job tourne sans établissement, comme en production.
        app(ContexteEtablissement::class)->definir(null);
        (new LibererReservationsExpirees)->handle(app(LibererReservation::class));
    }

    public function test_1_une_commande_mobile_money_payee_nexpire_pas_meme_apres_le_delai(): void
    {
        $this->seed();
        $produit = $this->produitEnStock();
        $commande = $this->commanderEnMobileMoney($produit);
        $this->connecterAwa();
        $this->confirmerPaiement($commande);

        $this->depasserLeDelai($commande);
        $this->executerLeJob();
        $this->executerLeJob();

        $fraiche = Commande::pourTousEtablissements()->findOrFail($commande->id);
        $this->assertSame(StatutCommande::AttentePaiement, $fraiche->statut);
        $this->assertSame(3, $produit->fresh()->quantite_reservee, 'la réservation tient toujours');
        $this->assertSame(StatutReservation::Active, $fraiche->reservations()->pourTousEtablissements()->first()->statut);

        // Visible comme tel : du stock retenu, sans date de fin.
        $this->depuis('chez-awa.localhost')
            ->getJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}")
            ->assertJsonPath('data.stock_reserve.jusqua', null)
            ->assertJsonPath('data.paiement.confirme', true);
    }

    public function test_2_une_commande_mobile_money_non_payee_expire_et_libere_son_stock(): void
    {
        $this->seed();
        $produit = $this->produitEnStock();
        $commande = $this->commanderEnMobileMoney($produit);
        $this->assertSame(3, $produit->fresh()->quantite_reservee);

        $this->depasserLeDelai($commande);
        $this->executerLeJob();

        $fraiche = Commande::pourTousEtablissements()->findOrFail($commande->id);
        $this->assertSame(StatutCommande::Expiree, $fraiche->statut);
        $this->assertSame(0, $produit->fresh()->quantite_reservee);
        $this->assertSame(10, $produit->fresh()->quantite_stock);
        $this->assertSame(StatutReservation::Liberee, $fraiche->reservations()->pourTousEtablissements()->first()->statut);
    }

    public function test_3_cinetpay_et_especes_gardent_exactement_leur_expiration(): void
    {
        $this->seed();
        $etablissement = $this->chezAwa();
        app(ContexteEtablissement::class)->definir($etablissement);
        $client = $etablissement->clients()->create(['nom' => 'Client Test']);
        $produit = $this->produitEnStock();

        Date::setTestNow(now());
        $commandes = [];
        foreach ([ModePaiement::Cinetpay, ModePaiement::Especes, null] as $index => $mode) {
            $commande = app(CreerCommande::class)->executer(
                $etablissement, $client, [new LigneCommandeDemandee($produit->id, null, 1)],
                Canal::Web, SourceCommande::PanierWeb, "cle-{$index}", null, $mode,
            );
            // Une commande web de boutique : 24 heures, comme le Mobile
            // Money (un commerçant seul ne la voyait pas en 15 minutes).
            $this->assertEqualsWithDelta(24 * 3600, now()->diffInSeconds($commande->expire_le), 2, "délai pour {$mode?->value}");
            $commandes[] = $commande;
        }
        $this->assertSame(3, $produit->fresh()->quantite_reservee);

        // Seize minutes plus tard, l'ancien délai : rien ne bouge.
        Date::setTestNow(now()->addMinutes(16));
        $this->executerLeJob();
        $this->assertSame(3, $produit->fresh()->quantite_reservee);

        Date::setTestNow(now()->addHours(24));
        $this->executerLeJob();
        Date::setTestNow();

        foreach ($commandes as $commande) {
            $this->assertSame(StatutCommande::Expiree, Commande::pourTousEtablissements()->findOrFail($commande->id)->statut);
        }
        $this->assertSame(0, $produit->fresh()->quantite_reservee);
    }

    /**
     * Le restaurant garde 15 minutes : son délai propre est reporté au lot
     * restauration (voir decisions.md), il ne suit pas la boutique.
     */
    public function test_3b_une_commande_web_de_restaurant_garde_15_minutes(): void
    {
        $this->seed();
        $maquis = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
        app(ContexteEtablissement::class)->definir($maquis);
        $plat = Produit::factory()->for($maquis)->publie()->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);

        Date::setTestNow(now());
        $commande = app(CreerCommande::class)->executer(
            $maquis, $maquis->clients()->create(['nom' => 'Client Maquis']), [new LigneCommandeDemandee($plat->id, null, 1)],
            Canal::Web, SourceCommande::PanierWeb, 'cle-maquis',
        );

        $this->assertEqualsWithDelta(15 * 60, now()->diffInSeconds($commande->expire_le), 2);
        Date::setTestNow();
    }

    public function test_4_une_commande_payee_puis_annulee_affiche_lavertissement_de_remboursement(): void
    {
        $this->seed();
        $produit = $this->produitEnStock();
        $commande = $this->commanderEnMobileMoney($produit);
        $this->connecterAwa();
        $this->confirmerPaiement($commande, 20000);

        $this->depuis('chez-awa.localhost')
            ->getJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}")
            ->assertJsonPath('data.paiement.a_rembourser', null);

        $this->depuis('chez-awa.localhost')
            ->postJson("http://chez-awa.localhost:8000/api/commandes/{$commande->id}/annuler", ['motif_type' => 'article_indisponible'])
            ->assertStatus(200)
            ->assertJsonPath('data.statut', 'annulee')
            ->assertJsonPath('data.paiement.a_rembourser', 20000);

        // L'annulation libère toujours le stock, payée ou non.
        $this->assertSame(0, $produit->fresh()->quantite_reservee);
    }

    public function test_une_commande_payee_non_traitee_depuis_2h_est_urgente_et_comptee_a_part(): void
    {
        $this->seed();
        $payee = $this->commanderEnMobileMoney($this->produitEnStock(), 'cle-payee');
        $nonPayee = $this->commanderEnMobileMoney($this->produitEnStock(), 'cle-non-payee');
        $this->connecterAwa();
        $this->confirmerPaiement($payee);

        $statistiques = $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/commandes/statistiques');
        $this->assertSame(1, $statistiques->json('en_attente_payees'));
        $this->assertGreaterThanOrEqual(2, $statistiques->json('en_attente'));

        $this->travel(3)->hours();

        $liste = collect($this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/commandes')->json('data'))->keyBy('id');
        $this->assertTrue($liste[$payee->id]['paiement']['a_traiter_en_urgence']);
        $this->assertFalse($liste[$nonPayee->id]['paiement']['a_traiter_en_urgence']);
        $this->assertTrue($liste[$nonPayee->id]['paiement']['en_retard']);
        $this->assertNotNull($liste[$nonPayee->id]['stock_reserve']['jusqua']);
        $this->assertNull($liste[$payee->id]['stock_reserve']['jusqua']);
        // Le Mobile Money a son propre signal de retard : jamais l'ancien en double.
        $this->assertFalse($liste[$nonPayee->id]['en_retard']);
    }
}
