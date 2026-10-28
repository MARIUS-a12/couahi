<?php

namespace Tests\Feature\Commandes;

use App\Enums\Canal;
use App\Enums\SourceCommande;
use App\Enums\StatutCommande;
use App\Enums\StatutDemandeWhatsapp;
use App\Enums\StatutVariante;
use App\Models\Commande;
use App\Models\Etablissement;
use App\Models\LigneCommande;
use App\Models\Media;
use App\Models\Produit;
use App\Models\ReferenceWhatsapp;
use App\Models\ReservationStock;
use App\Models\Role;
use App\Models\User;
use App\Models\VarianteProduit;
use App\Services\Commandes\ConfirmerPaiementCommande;
use App\Services\Commandes\CreerCommande;
use App\Services\Commandes\LigneCommandeDemandee;
use App\Services\Utilisateurs\CreerRattachementUtilisateur;
use App\Support\Tenancy\ContexteEtablissement;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Lot D — les demandes WhatsApp. Une demande n'est PAS une commande :
 * aucun stock réservé, aucun numéro, aucune ligne dans commandes, aucun
 * chiffre qui bouge. Le commerçant la voit, la marque traitée ou ignorée,
 * ou la transforme en commande par la saisie manuelle du lot C — une seule
 * fois, même par requête forgée.
 */
class DemandesWhatsappApiTest extends TestCase
{
    use RefreshDatabase;

    private const HOTE = 'http://wax-adjame.localhost:8000';

    private Etablissement $wax;

    private Produit $pagne;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->wax = Etablissement::where('slug', 'wax-adjame')->firstOrFail();
        $this->pagne = Produit::pourTousEtablissements()->where('etablissement_id', $this->wax->id)->where('slug', 'pagne-wax-hollandais-6-yards')->firstOrFail();
    }

    private function connecte(string $email = 'fatou@wax-adjame.test', string $hote = self::HOTE): static
    {
        $this->withHeader('Referer', $hote)->postJson($hote.'/api/connexion', ['email' => $email, 'mot_de_passe' => 'motdepasse'])->assertOk();

        return $this;
    }

    /** Un clic sur « Commander sur WhatsApp » de la vitrine, par un visiteur neuf. */
    private function clicWhatsapp(Produit $produit, ?VarianteProduit $variante = null): string
    {
        $url = $this->withHeader('Referer', 'http://wax-adjame.localhost:5173')
            ->postJson(self::HOTE."/api/vitrine/produits/{$produit->id}/lien-whatsapp", $variante ? ['variante_id' => $variante->id] : [])
            ->assertOk()
            ->json('url');
        preg_match('/\[REF:([A-Za-z0-9]{8})\]/', rawurldecode($url), $m);

        return $m[1];
    }

    private function demande(string $code): ReferenceWhatsapp
    {
        return ReferenceWhatsapp::pourTousEtablissements()->where('code', $code)->firstOrFail();
    }

    /** Une demande créée directement, à la date voulue. */
    private function demandeDu(CarbonInterface $date, StatutDemandeWhatsapp $statut = StatutDemandeWhatsapp::Nouvelle, ?Etablissement $etablissement = null, ?Produit $produit = null): ReferenceWhatsapp
    {
        $etablissement ??= $this->wax;
        app(ContexteEtablissement::class)->definir($etablissement);
        $demande = ReferenceWhatsapp::create([
            'code' => Str::random(8),
            'produit_id' => ($produit ?? $this->pagne)->id,
            'expire_le' => now()->addDays(7),
            'statut' => $statut,
        ]);
        $demande->forceFill(['created_at' => $date, 'expire_le' => $date->copy()->addDays(7)])->save();
        app(ContexteEtablissement::class)->definir(null);

        return $demande;
    }

    private function transformer(string $code, array $surcharge = []): TestResponse
    {
        return $this->postJson(self::HOTE.'/api/commandes/manuelles', array_merge([
            'canal' => 'whatsapp',
            'lignes' => [['produit_id' => $this->pagne->id, 'quantite' => 1]],
            'client' => ['telephone' => '07 01 02 03 04', 'nom' => 'Mariam Koné'],
            'commune' => 'Cocody',
            'quartier' => 'Angré',
            'reference' => "[REF:{$code}]",
            'cle_idempotence' => (string) Str::uuid(),
        ], $surcharge));
    }

    /** @return array<string, mixed> */
    private function chiffres(): array
    {
        return [
            'statistiques_commandes' => $this->getJson(self::HOTE.'/api/commandes/statistiques')->assertOk()->json(),
            'statistiques' => $this->getJson(self::HOTE.'/api/statistiques')->assertOk()->json(),
            'commandes' => Commande::pourTousEtablissements()->count(),
            'lignes' => LigneCommande::pourTousEtablissements()->count(),
            'reservations' => ReservationStock::pourTousEtablissements()->count(),
            'stock_pagne' => [$this->pagne->fresh()->quantite_stock, $this->pagne->fresh()->quantite_reservee],
        ];
    }

    public function test_une_demande_nest_pas_une_commande_aucun_chiffre_ne_bouge(): void
    {
        $this->connecte();
        $avant = $this->chiffres();

        $codes = [$this->clicWhatsapp($this->pagne), $this->clicWhatsapp($this->pagne), $this->clicWhatsapp(Produit::pourTousEtablissements()->where('slug', 'bazin-riche-brode')->firstOrFail())];
        $this->patchJson(self::HOTE.'/api/demandes-whatsapp/'.$this->demande($codes[0])->id, ['statut' => 'traitee'])->assertOk();
        $this->patchJson(self::HOTE.'/api/demandes-whatsapp/'.$this->demande($codes[1])->id, ['statut' => 'ignoree'])->assertOk();

        $this->assertCount(3, array_unique($codes));
        $this->assertSame($avant, $this->chiffres(), 'aucun stock, aucune commande, aucune statistique');
    }

    public function test_le_bouton_de_la_vitrine_necrit_quune_reference_nouvelle(): void
    {
        $avantCommandes = Commande::pourTousEtablissements()->count();
        $avantReferences = ReferenceWhatsapp::pourTousEtablissements()->count();

        $demande = $this->demande($this->clicWhatsapp($this->pagne));

        $this->assertSame($avantReferences + 1, ReferenceWhatsapp::pourTousEtablissements()->count());
        $this->assertSame($avantCommandes, Commande::pourTousEtablissements()->count());
        $this->assertSame(StatutDemandeWhatsapp::Nouvelle, $demande->statut);
        $this->assertNull($demande->statut_change_le);
        $this->assertNull($demande->commande);
    }

    public function test_la_liste_25_par_page_plus_recentes_dabord_nouvelles_par_defaut(): void
    {
        foreach (range(1, 27) as $minutes) {
            $this->demandeDu(now()->subMinutes($minutes));
        }
        $ignoree = $this->demandeDu(now(), StatutDemandeWhatsapp::Ignoree);
        $this->connecte();

        $page = $this->getJson(self::HOTE.'/api/demandes-whatsapp')->assertOk();
        $this->assertCount(25, $page->json('data'));
        $this->assertSame(27, $page->json('meta.total'));
        $dates = array_column($page->json('data'), 'cree_le');
        $this->assertSame($dates, collect($dates)->sortDesc()->values()->all(), 'plus récentes d\'abord');
        $this->assertNotContains($ignoree->id, array_column($page->json('data'), 'id'));
        $this->assertCount(2, $this->getJson(self::HOTE.'/api/demandes-whatsapp?page=2')->json('data'));

        $this->assertSame([$ignoree->id], array_column($this->getJson(self::HOTE.'/api/demandes-whatsapp?statut=ignoree')->json('data'), 'id'));
        $this->getJson(self::HOTE.'/api/demandes-whatsapp?statut=nimporte')->assertStatus(422);
        $this->assertSame(27, $this->getJson(self::HOTE.'/api/demandes-whatsapp/compte')->json('data.nouvelles'));
    }

    public function test_une_ligne_ne_montre_que_ce_quon_sait_produit_variante_reference_statut(): void
    {
        // Une variante dont la photo n'est PAS la première du produit : sinon
        // le test ne distinguerait pas la photo de la variante de celle du produit.
        app(ContexteEtablissement::class)->definir($this->wax);
        $variante = VarianteProduit::where('etablissement_id', $this->wax->id)->whereNotNull('media_id')->get()
            ->first(fn (VarianteProduit $candidate) => Produit::findOrFail($candidate->produit_id)->medias()->orderBy('media_produit.ordre')->value('medias.id') !== $candidate->media_id);
        app(ContexteEtablissement::class)->definir(null);
        $this->assertNotNull($variante, 'les données de démo ont une variante à photo propre');
        $produit = Produit::pourTousEtablissements()->findOrFail($variante->produit_id);
        $demande = $this->demande($this->clicWhatsapp($produit, $variante));
        $vieille = $this->demandeDu(now()->subDays(8));
        $this->connecte();

        $lignes = collect($this->getJson(self::HOTE.'/api/demandes-whatsapp')->assertOk()->json('data'))->keyBy('id');
        $ligne = $lignes[$demande->id];

        $this->assertSame($produit->nom, $ligne['produit']['nom']);
        $this->assertSame($variante->nom, $ligne['variante']['nom']);
        $photoVariante = Media::pourTousEtablissements()->findOrFail($variante->media_id)->urlVariante('vignette', 'jpg');
        $this->assertSame($photoVariante, $ligne['photo'], 'la photo de la variante, pas celle du produit');
        $this->assertSame("[REF:{$demande->code}]", $ligne['reference']);
        $this->assertSame('nouvelle', $ligne['statut']);
        $this->assertFalse($ligne['expiree']);
        $this->assertTrue($lignes[$vieille->id]['expiree'], 'plus de 7 jours : référence expirée');
        foreach (['nom', 'telephone', 'email', 'client'] as $inconnu) {
            $this->assertArrayNotHasKey($inconnu, $ligne, "wa.me ne dit rien du client : pas de champ « {$inconnu} »");
        }
    }

    public function test_deux_boutiques_ne_voient_jamais_les_demandes_lune_de_lautre(): void
    {
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $produitAwa = Produit::factory()->for($chezAwa)->publie()->create();
        $demandeAwa = $this->demandeDu(now(), etablissement: $chezAwa, produit: $produitAwa);
        $demandeWax = $this->demandeDu(now());
        $this->connecte();

        $this->assertSame([$demandeWax->id], array_column($this->getJson(self::HOTE.'/api/demandes-whatsapp')->json('data'), 'id'));
        $this->assertSame(1, $this->getJson(self::HOTE.'/api/demandes-whatsapp/compte')->json('data.nouvelles'));
        $this->patchJson(self::HOTE."/api/demandes-whatsapp/{$demandeAwa->id}", ['statut' => 'ignoree'])->assertNotFound();
        $this->assertSame(StatutDemandeWhatsapp::Nouvelle, $demandeAwa->fresh()->statut);
    }

    public function test_marquer_traitee_ou_ignoree_seulement(): void
    {
        $demande = $this->demandeDu(now());
        $this->connecte();
        $url = self::HOTE."/api/demandes-whatsapp/{$demande->id}";

        $this->patchJson($url, ['statut' => 'nouvelle'])->assertStatus(422)->assertJsonValidationErrors('statut');
        $this->patchJson($url, ['statut' => 'livree'])->assertStatus(422);
        $this->assertSame(StatutDemandeWhatsapp::Nouvelle, $demande->fresh()->statut);

        $this->patchJson($url, ['statut' => 'ignoree'])->assertOk()->assertJsonPath('data.statut', 'ignoree');
        $this->assertNotNull($demande->fresh()->statut_change_le);
        $this->patchJson($url, ['statut' => 'traitee'])->assertOk()->assertJsonPath('data.statut', 'traitee');
        $this->assertSame(0, $this->getJson(self::HOTE.'/api/demandes-whatsapp/compte')->json('data.nouvelles'));
    }

    public function test_sans_gerer_demandes_whatsapp_tout_est_refuse(): void
    {
        $demande = $this->demandeDu(now());
        app(ContexteEtablissement::class)->definir($this->wax);
        $stock = User::factory()->create(['email' => 'stock@wax-adjame.test', 'password' => 'motdepasse', 'mot_de_passe_defini' => true]);
        app(CreerRattachementUtilisateur::class)->executer($stock, $this->wax, Role::where('nom', 'gestionnaire_stock')->value('id'));
        app(ContexteEtablissement::class)->definir(null);
        $this->connecte('stock@wax-adjame.test');

        $this->getJson(self::HOTE.'/api/demandes-whatsapp')->assertForbidden();
        $this->getJson(self::HOTE.'/api/demandes-whatsapp/compte')->assertForbidden();
        $this->patchJson(self::HOTE."/api/demandes-whatsapp/{$demande->id}", ['statut' => 'ignoree'])->assertForbidden();
        $this->assertSame(StatutDemandeWhatsapp::Nouvelle, $demande->fresh()->statut);
    }

    public function test_transformer_en_commande_traite_la_demande_et_la_relie(): void
    {
        $demande = $this->demande($this->clicWhatsapp($this->pagne));
        $this->connecte();

        $reponse = $this->transformer($demande->code)->assertCreated();

        $commande = Commande::pourTousEtablissements()->findOrFail($reponse->json('data.id'));
        $demande->refresh();
        $this->assertSame(StatutDemandeWhatsapp::Traitee, $demande->statut);
        $this->assertNotNull($demande->statut_change_le);
        $this->assertSame($demande->id, $commande->reference_whatsapp_id);
        $this->assertSame(StatutCommande::Payee, $commande->statut, 'confirmée, comme toute saisie WhatsApp');
        $ligne = collect($this->getJson(self::HOTE.'/api/demandes-whatsapp?statut=traitee')->json('data'))->firstWhere('id', $demande->id);
        $this->assertSame($commande->numero, $ligne['commande']['numero']);
    }

    public function test_une_demande_ne_devient_quune_commande_meme_par_requete_forgee(): void
    {
        $demande = $this->demande($this->clicWhatsapp($this->pagne));
        $this->connecte();
        $premiere = $this->transformer($demande->code)->assertCreated();

        foreach ([[], ['confirmer_doublon' => true], ['canal' => 'telephone'], ['client' => ['telephone' => '0505050505', 'nom' => 'Autre']]] as $forgee) {
            $this->transformer($demande->code, $forgee)->assertStatus(409)->assertJsonPath('deja_transformee.numero', $premiere->json('data.numero'));
        }
        $this->assertSame(1, Commande::pourTousEtablissements()->where('reference_whatsapp_id', $demande->id)->count());

        // Ignorer une demande devenue commande contredirait la commande.
        $this->patchJson(self::HOTE."/api/demandes-whatsapp/{$demande->id}", ['statut' => 'ignoree'])->assertStatus(422);
        $this->assertSame(StatutDemandeWhatsapp::Traitee, $demande->fresh()->statut);
    }

    public function test_la_meme_saisie_rejouee_retrouve_sa_commande(): void
    {
        $demande = $this->demande($this->clicWhatsapp($this->pagne));
        $this->connecte();
        $cle = (string) Str::uuid();

        $premiere = $this->transformer($demande->code, ['cle_idempotence' => $cle])->assertCreated();
        $rejouee = $this->transformer($demande->code, ['cle_idempotence' => $cle])->assertCreated();

        $this->assertSame($premiere->json('data.id'), $rejouee->json('data.id'));
    }

    public function test_si_la_creation_echoue_la_demande_reste_nouvelle(): void
    {
        $demande = $this->demande($this->clicWhatsapp($this->pagne));
        $this->connecte();
        $disponible = $this->pagne->quantite_stock - $this->pagne->quantite_reservee;

        $this->transformer($demande->code, ['lignes' => [['produit_id' => $this->pagne->id, 'quantite' => $disponible + 1]]])
            ->assertStatus(422)
            ->assertJsonPath('message', "Stock insuffisant pour {$this->pagne->nom} : ".($disponible + 1).' demandé(s), '.$disponible.' disponible(s).');

        $this->assertSame(StatutDemandeWhatsapp::Nouvelle, $demande->fresh()->statut);
        $this->assertNull($demande->fresh()->statut_change_le);
        $this->assertNull($demande->fresh()->commande);
    }

    public function test_une_variante_archivee_entre_temps_est_refusee_par_le_message_habituel(): void
    {
        $variante = VarianteProduit::pourTousEtablissements()->where('etablissement_id', $this->wax->id)->where('statut', StatutVariante::Actif)->firstOrFail();
        $demande = $this->demande($this->clicWhatsapp(Produit::pourTousEtablissements()->findOrFail($variante->produit_id), $variante));
        $variante->update(['statut' => StatutVariante::Inactif]);
        $this->connecte();

        $this->transformer($demande->code, ['lignes' => [['produit_id' => $variante->produit_id, 'variante_id' => $variante->id, 'quantite' => 1]]])->assertStatus(422);

        $this->assertSame(StatutDemandeWhatsapp::Nouvelle, $demande->fresh()->statut);
    }

    public function test_la_demande_change_dans_la_meme_transaction_que_la_commande(): void
    {
        $demande = $this->demande($this->clicWhatsapp($this->pagne));
        $this->connecte();
        // Au comptoir, l'encaissement vient APRÈS le passage en « traitee » :
        // une panne là doit tout défaire, demande comprise.
        $this->app->instance(ConfirmerPaiementCommande::class, new class extends ConfirmerPaiementCommande
        {
            public function executer($commande, int $montantRecu, ?string $reference, $utilisateur, ?string $adresseIp): bool
            {
                throw new RuntimeException('panne simulée à l’encaissement');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->postJson(self::HOTE.'/api/commandes/manuelles', [
                'canal' => 'comptoir',
                'lignes' => [['produit_id' => $this->pagne->id, 'quantite' => 1]],
                'reference' => $demande->code,
                'cle_idempotence' => (string) Str::uuid(),
            ]);
            $this->fail('la panne aurait dû remonter');
        } catch (RuntimeException) {
        }

        $this->assertSame(StatutDemandeWhatsapp::Nouvelle, $demande->fresh()->statut);
        $this->assertNull($demande->fresh()->statut_change_le);
        $this->assertSame(0, Commande::pourTousEtablissements()->where('reference_whatsapp_id', $demande->id)->count());
    }

    public function test_la_base_refuse_une_seconde_commande_sur_la_meme_demande(): void
    {
        $demande = $this->demande($this->clicWhatsapp($this->pagne));
        $this->connecte();
        $commande = Commande::pourTousEtablissements()->findOrFail($this->transformer($demande->code)->assertCreated()->json('data.id'));

        $this->expectException(UniqueConstraintViolationException::class);
        $copie = $commande->replicate();
        $copie->forceFill(['numero' => 'CMD-999999', 'cle_idempotence' => (string) Str::uuid()])->saveQuietly();
    }

    /**
     * Si une course passait le verrou, l'index unique tient, et CreerCommande
     * ne prend pas cette violation pour un double clic sur sa clé.
     */
    public function test_creer_commande_laisse_remonter_la_violation_sur_la_demande(): void
    {
        $demande = $this->demande($this->clicWhatsapp($this->pagne));
        $this->connecte();
        $this->transformer($demande->code)->assertCreated();

        $this->expectException(UniqueConstraintViolationException::class);
        app(CreerCommande::class)->executer(
            etablissement: $this->wax,
            client: null,
            lignes: [new LigneCommandeDemandee($this->pagne->id, null, 1)],
            canal: Canal::Whatsapp,
            source: SourceCommande::BackOffice,
            cleIdempotence: (string) Str::uuid(),
            referenceWhatsapp: $demande,
        );
    }

    public function test_une_reference_expiree_se_transforme_toujours(): void
    {
        $demande = $this->demandeDu(now()->subDays(9));
        $this->connecte();

        $this->transformer($demande->code)->assertCreated();

        $this->assertSame(StatutDemandeWhatsapp::Traitee, $demande->fresh()->statut);
    }
}
