<?php

namespace Tests\Feature\Commandes;

use App\Enums\OperateurMobileMoney;
use App\Enums\StatutCommande;
use App\Models\Commande;
use App\Models\Etablissement;
use App\Models\Produit;
use App\Models\Role;
use App\Models\User;
use App\Services\Commandes\AnnulerCommande;
use App\Services\Commandes\ConfirmerPaiementCommande;
use App\Services\Stock\LibererReservation;
use App\Services\Stock\RestaurerReservation;
use App\Services\Utilisateurs\CreerRattachementUtilisateur;
use App\Support\Vitrine\CacheVitrine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Lot F — gerer_commandes découpé en traiter_commandes, encaisser et
 * annuler_payees, et le comptoir passé de creer_commandes à encaisser.
 * Chaque permission est prouvée par l'API, avec les vrais rôles du seeder :
 * Yao (opérateur, maquis-du-port), un caissier, un gestionnaire de stock,
 * et Adjoua (administratrice).
 */
class PermissionsCommandesTest extends TestCase
{
    use RefreshDatabase;

    private const HOTE = 'http://maquis-du-port.localhost:8000';

    private Etablissement $maquis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->maquis = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
        $this->maquis->forceFill([
            'mm_numero' => '+2250707080910',
            'mm_operateur' => OperateurMobileMoney::Wave,
            'mm_beneficiaire' => 'KOFFI YAO ARMAND',
        ])->save();
        CacheVitrine::invalider($this->maquis->id);

        foreach (['caissier' => 'caissier', 'stock' => 'gestionnaire_stock'] as $prefixe => $role) {
            $membre = User::factory()->create(['email' => "{$prefixe}@maquis-du-port.test", 'password' => 'motdepasse', 'mot_de_passe_defini' => true]);
            app(CreerRattachementUtilisateur::class)->executer($membre, $this->maquis, [Role::where('nom', $role)->value('id')]);
        }
    }

    /**
     * Change d'utilisateur dans le même test, comme ConsultationsPlateformeTest :
     * déconnexion, puis nouvelle connexion par la vraie route.
     */
    private function en(string $prefixe): static
    {
        $this->withHeader('Referer', self::HOTE);
        if ($this->app['auth']->guard('web')->check()) {
            $this->postJson(self::HOTE.'/api/deconnexion');
            $this->app['auth']->forgetGuards();
        }
        $this->postJson(self::HOTE.'/api/connexion', ['email' => "{$prefixe}@maquis-du-port.test", 'mot_de_passe' => 'motdepasse'])->assertOk();

        return $this;
    }

    private function utilisateur(string $prefixe): User
    {
        return User::where('email', "{$prefixe}@maquis-du-port.test")->firstOrFail();
    }

    /**
     * Une commande de la vitrine payable par Mobile Money, en attente.
     */
    private function commandeVitrine(): Commande
    {
        $produit = Produit::factory()->for($this->maquis)->publie()->create([
            'prix' => 20309, 'quantite_stock' => 10, 'quantite_reservee' => 0, 'disponible' => true,
        ]);

        $reponse = $this->withHeader('Referer', self::HOTE)->postJson(self::HOTE.'/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => ['nom' => 'Client Test', 'telephone' => '0701020304'],
            'commune' => 'Port-Bouët',
            'quartier' => 'Vridi',
            'cle_idempotence' => (string) Str::uuid(),
            'mode_paiement' => 'mobile_money',
        ])->assertCreated();

        return Commande::pourTousEtablissements()->where('numero', $reponse->json('numero'))->firstOrFail();
    }

    private function payer(Commande $commande, int $montant): void
    {
        $this->assertTrue(app(ConfirmerPaiementCommande::class)->executer($commande, $montant, null, $this->utilisateur('adjoua'), null));
    }

    private function action(Commande $commande, string $action, array $corps = []): TestResponse
    {
        return match ($action) {
            'confirmer', 'marquer-prete', 'marquer-livree' => $this->patchJson(self::HOTE."/api/commandes/{$commande->id}/{$action}"),
            'annuler' => $this->postJson(self::HOTE."/api/commandes/{$commande->id}/annuler", ['motif_type' => 'client_annule']),
            default => $this->postJson(self::HOTE."/api/commandes/{$commande->id}/{$action}", $corps),
        };
    }

    /**
     * @param  array<string, mixed>  $surcharge
     */
    private function saisir(string $canal, array $surcharge = []): TestResponse
    {
        $produit = Produit::factory()->for($this->maquis)->publie()->create(['prix' => 2000, 'quantite_stock' => 10, 'quantite_reservee' => 0, 'disponible' => true]);
        $livraison = $canal === 'comptoir' ? [] : ['client' => ['telephone' => '07 01 02 03 04'], 'commune' => 'Port-Bouët', 'confirmer_doublon' => true];

        return $this->postJson(self::HOTE.'/api/commandes/manuelles', array_merge([
            'canal' => $canal,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'cle_idempotence' => (string) Str::uuid(),
        ], $livraison, $surcharge));
    }

    public function test_traiter_commandes_confirmer_preparer_livrer(): void
    {
        $commande = $this->commandeVitrine();

        $this->en('caissier')->action($commande, 'confirmer')->assertForbidden();
        $this->en('stock')->action($commande, 'confirmer')->assertForbidden();
        $this->assertSame(StatutCommande::AttentePaiement, $commande->fresh()->statut);

        $this->en('yao')->action($commande, 'confirmer')->assertOk();
        $this->action($commande, 'marquer-prete')->assertOk();

        $this->en('caissier')->action($commande, 'marquer-livree')->assertForbidden();
        $this->en('stock')->action($commande, 'marquer-livree')->assertForbidden();
        $this->en('yao')->action($commande, 'marquer-livree')->assertOk();
    }

    public function test_encaisser_confirmer_un_paiement_recu(): void
    {
        $commande = $this->commandeVitrine();

        $this->en('yao')->action($commande, 'confirmer-paiement', ['montant_recu' => 20309])->assertForbidden();
        $this->en('stock')->action($commande, 'confirmer-paiement', ['montant_recu' => 20309])->assertForbidden();
        $this->assertNull($commande->fresh()->paiement_confirme_le);

        $this->en('caissier')->action($commande, 'confirmer-paiement', ['montant_recu' => 20309])->assertOk();
        $this->assertSame($this->utilisateur('caissier')->id, $commande->fresh()->paiement_confirme_par);
    }

    public function test_encaisser_vendre_au_comptoir_et_le_canal_suit_les_permissions(): void
    {
        $this->en('yao');
        $this->saisir('comptoir')->assertForbidden();
        $this->saisir('telephone')->assertCreated();
        $this->saisir('whatsapp')->assertCreated();
        $this->getJson(self::HOTE.'/api/commandes/manuelles/zones')->assertOk();

        $this->en('caissier');
        $this->saisir('comptoir')->assertCreated();
        $this->saisir('telephone')->assertForbidden();
        $this->saisir('whatsapp')->assertForbidden();
        // Requête forgée : un canal de livraison sans les champs de
        // livraison est refusé pour le droit, pas pour la forme.
        $this->saisir('telephone', ['client' => null, 'commune' => null])->assertForbidden();
        $this->getJson(self::HOTE.'/api/commandes/manuelles/articles')->assertOk();
        $this->getJson(self::HOTE.'/api/commandes/manuelles/client?telephone=0701020304')->assertOk();
        $this->getJson(self::HOTE.'/api/commandes/manuelles/zones')->assertForbidden();
        $this->getJson(self::HOTE.'/api/commandes/manuelles/reference?reference=AB12CD34')->assertForbidden();

        $this->en('stock');
        $this->saisir('comptoir')->assertForbidden();
        $this->saisir('telephone')->assertForbidden();

        $this->assertSame(
            ['comptoir' => 1, 'telephone' => 1, 'whatsapp' => 1],
            Commande::pourTousEtablissements()->where('source', 'back_office')->get()->countBy(fn (Commande $c) => $c->canal->value)->sortKeys()->all(),
        );
    }

    public function test_annuler_une_commande_non_payee_demande_traiter_commandes(): void
    {
        $commande = $this->commandeVitrine();

        $this->en('caissier')->action($commande, 'annuler')->assertForbidden();
        $this->en('stock')->action($commande, 'annuler')->assertForbidden();
        $this->assertSame(StatutCommande::AttentePaiement, $commande->fresh()->statut);

        $this->en('yao')->action($commande, 'annuler')->assertOk();
        $this->assertSame(StatutCommande::Annulee, $commande->fresh()->statut);
    }

    /**
     * La faille fermée : payée dès le premier paiement, même partiel. Un
     * encaissement inférieur d'1 FCFA au total ne rend pas la commande
     * annulable par un opérateur, ni par le caissier qui a encaissé.
     */
    public function test_annuler_une_commande_payee_meme_partiellement_est_reserve_a_ladministrateur(): void
    {
        $commande = $this->commandeVitrine();
        $this->payer($commande, 20308);

        // Refusé par la policy elle-même, avant la seconde barrière de
        // l'UPDATE (dont le message serait « vient de recevoir un paiement »).
        $this->en('yao')->action($commande, 'annuler')->assertForbidden()->assertJsonPath('message', 'This action is unauthorized.');
        $this->en('caissier')->action($commande, 'annuler')->assertForbidden();
        $this->en('stock')->action($commande, 'annuler')->assertForbidden();
        $this->assertSame(StatutCommande::AttentePaiement, $commande->fresh()->statut);

        $this->en('adjoua')->action($commande, 'annuler')->assertOk();
        $this->assertSame(StatutCommande::Annulee, $commande->fresh()->statut);
    }

    public function test_un_caissier_ne_peut_pas_annuler_une_vente_quil_a_encaissee(): void
    {
        $commande = $this->commandeVitrine();

        $this->en('caissier')->action($commande, 'confirmer-paiement', ['montant_recu' => 20309])->assertOk();
        $this->action($commande, 'annuler')->assertForbidden();

        $this->assertSame(StatutCommande::AttentePaiement, $commande->fresh()->statut);
        $this->assertNotNull($commande->fresh()->paiement_confirme_le);
    }

    public function test_rembourser_est_reserve_a_ladministrateur(): void
    {
        $commande = $this->commandeVitrine();
        $this->payer($commande, 20309);
        app(AnnulerCommande::class)->executer($commande->fresh(), 'Rupture');

        $this->en('yao')->action($commande, 'rembourser', ['montant' => 20309])->assertForbidden();
        $this->en('caissier')->action($commande, 'rembourser', ['montant' => 20309])->assertForbidden();
        $this->en('stock')->action($commande, 'rembourser', ['montant' => 20309])->assertForbidden();
        $this->assertNull($commande->fresh()->rembourse_le);

        $this->en('adjoua')->action($commande, 'rembourser', ['montant' => 20309])->assertOk();
        $this->assertNotNull($commande->fresh()->rembourse_le);
    }

    public function test_le_gestionnaire_de_stock_voit_les_commandes_en_lecture(): void
    {
        $commande = $this->commandeVitrine();

        $this->en('stock');
        $this->getJson(self::HOTE.'/api/commandes')->assertOk()->assertJsonPath('data.0.id', $commande->id);
        $this->getJson(self::HOTE."/api/commandes/{$commande->id}")->assertOk();
    }

    /**
     * Un paiement enregistré entre la lecture de la commande et son
     * annulation : l'annulation sans annuler_payees échoue, au lieu de
     * défaire une vente payée.
     */
    public function test_une_annulation_sans_annuler_payees_perd_contre_un_paiement_simultane(): void
    {
        $commande = $this->commandeVitrine();
        $lueAvantLePaiement = $commande->fresh();
        $this->payer($commande, 20309);

        $annulee = app(AnnulerCommande::class)->executer($lueAvantLePaiement, 'Course', null, seulementNonPayee: true);

        $this->assertFalse($annulee);
        $this->assertSame(StatutCommande::AttentePaiement, $commande->fresh()->statut);
        $this->assertTrue(app(AnnulerCommande::class)->executer($lueAvantLePaiement, 'Administrateur'));
    }

    /**
     * La même course par l'API : le paiement est enregistré après que la
     * requête de Yao a passé l'autorisation, juste avant l'annulation. Il
     * reçoit un refus qui le dit, pas une commande inchangée sans
     * explication.
     */
    public function test_yao_recoit_un_refus_si_un_paiement_arrive_pendant_quil_annule(): void
    {
        $commande = $this->commandeVitrine();
        $paiementSimultane = fn () => $this->payer($commande, 20309);
        $this->app->instance(AnnulerCommande::class, new class(app(LibererReservation::class), app(RestaurerReservation::class), $paiementSimultane) extends AnnulerCommande
        {
            public function __construct(LibererReservation $liberer, RestaurerReservation $restaurer, private readonly \Closure $avant)
            {
                parent::__construct($liberer, $restaurer);
            }

            public function executer(Commande $commande, ?string $motif = null, ?int $utilisateurId = null, bool $seulementNonPayee = false): bool
            {
                ($this->avant)();

                return parent::executer($commande, $motif, $utilisateurId, $seulementNonPayee);
            }
        });

        $this->en('yao')->action($commande, 'annuler')
            ->assertForbidden()
            ->assertJsonPath('message', 'Cette commande vient de recevoir un paiement : seul un administrateur peut maintenant l’annuler.');

        $this->assertSame(StatutCommande::AttentePaiement, $commande->fresh()->statut);
        $this->assertNotNull($commande->fresh()->paiement_confirme_le);
    }
}
