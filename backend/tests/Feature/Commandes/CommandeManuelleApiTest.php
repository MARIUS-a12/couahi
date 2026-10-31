<?php

namespace Tests\Feature\Commandes;

use App\Enums\Canal;
use App\Enums\ModePaiement;
use App\Enums\SourceCommande;
use App\Enums\StatutCommande;
use App\Enums\StatutProduit;
use App\Enums\StatutReservation;
use App\Enums\StatutVariante;
use App\Jobs\LibererReservationsExpirees;
use App\Models\Client;
use App\Models\Commande;
use App\Models\Etablissement;
use App\Models\Produit;
use App\Models\ReferenceWhatsapp;
use App\Models\ReservationStock;
use App\Models\Role;
use App\Models\User;
use App\Models\VarianteProduit;
use App\Services\Commandes\ConfirmerPaiementCommande;
use App\Services\Livraison\GererZonesLivraison;
use App\Services\Stock\LibererReservation;
use App\Services\Utilisateurs\CreerRattachementUtilisateur;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Lot C — la saisie manuelle au back-office, sur wax-adjame (stock compté,
 * livraison facturée par commune). Chaque règle du cahier des charges a son
 * test : comptoir terminé en une transaction, téléphone confirmé non payé,
 * prix toujours relus en base, stock insuffisant nommé, doublon averti,
 * auteur et encaisseur gardés, permission dédiée, aucune expiration.
 */
class CommandeManuelleApiTest extends TestCase
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
        app(ContexteEtablissement::class)->definir($this->wax);
        $this->pagne = Produit::factory()->for($this->wax)->publie()->create(['nom' => 'Pagne test', 'prix' => 5000, 'quantite_stock' => 10, 'quantite_reservee' => 0]);
        app(ContexteEtablissement::class)->definir(null);
    }

    private function connecte(string $email = 'fatou@wax-adjame.test'): static
    {
        $this->withHeader('Referer', self::HOTE)
            ->postJson(self::HOTE.'/api/connexion', ['email' => $email, 'mot_de_passe' => 'motdepasse'])
            ->assertOk();

        return $this;
    }

    private function fatou(): User
    {
        return User::where('email', 'fatou@wax-adjame.test')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $surcharge
     */
    private function saisir(array $surcharge = []): TestResponse
    {
        return $this->postJson(self::HOTE.'/api/commandes/manuelles', array_merge([
            'canal' => 'comptoir',
            'lignes' => [['produit_id' => $this->pagne->id, 'quantite' => 2]],
            'cle_idempotence' => (string) Str::uuid(),
        ], $surcharge));
    }

    /**
     * @param  array<string, mixed>  $surcharge
     */
    private function saisirAuTelephone(array $surcharge = []): TestResponse
    {
        return $this->saisir(array_merge([
            'canal' => 'telephone',
            'client' => ['telephone' => '07 01 02 03 04', 'nom' => 'Mariam Koné'],
            'commune' => 'Cocody',
            'quartier' => 'Angré 7e tranche',
        ], $surcharge));
    }

    private function commande(TestResponse $reponse): Commande
    {
        return Commande::pourTousEtablissements()->findOrFail($reponse->json('data.id'));
    }

    public function test_une_vente_au_comptoir_est_livree_payee_sans_client_ni_reservation_active(): void
    {
        $this->connecte();
        $clientsAvant = Client::pourTousEtablissements()->count();

        $commande = $this->commande($this->saisir()->assertCreated());

        $this->assertSame(Canal::Comptoir, $commande->canal);
        $this->assertSame(SourceCommande::BackOffice, $commande->source);
        $this->assertSame(StatutCommande::Livree, $commande->statut);
        $this->assertNull($commande->client_id, 'jamais un faux « Client comptoir »');
        $this->assertSame($clientsAvant, Client::pourTousEtablissements()->count(), 'aucune ligne dans clients');
        $this->assertSame(10000, $commande->total);
        $this->assertSame(0, $commande->frais_livraison);
        $this->assertSame(ModePaiement::Especes, $commande->mode_paiement);
        $this->assertSame(10000, $commande->paiement_montant_recu, 'payée intégralement');
        $this->assertSame($this->fatou()->id, $commande->paiement_confirme_par, 'qui a encaissé');
        $this->assertSame($this->fatou()->id, $commande->cree_par, 'qui a saisi');
        $this->assertNull($commande->expire_le);
        $this->assertSame(0, ReservationStock::pourTousEtablissements()->where('commande_id', $commande->id)->where('statut', StatutReservation::Active)->count());
        $this->assertSame(8, $this->pagne->fresh()->quantite_stock, 'la marchandise est partie');
        $this->assertSame(0, $this->pagne->fresh()->quantite_reservee);

        $historique = $commande->historique()->pourTousEtablissements()->orderBy('id')->get();
        $this->assertSame(['attente_paiement', 'payee', 'livree', 'livree'], $historique->map(fn ($e) => $e->nouveau_statut->value)->all());
        $this->assertSame('paiement_confirme', $historique->last()->evenement);
        $this->assertTrue($historique->every(fn ($e) => $e->utilisateur_id === $this->fatou()->id), 'chaque étape porte son auteur');
    }

    public function test_un_habitue_identifie_au_comptoir_est_retrouve_par_son_telephone(): void
    {
        $this->connecte();

        $commande = $this->commande($this->saisir(['client' => ['telephone' => '0701020304', 'nom' => 'Mariam Koné']])->assertCreated());

        $this->assertSame('+2250701020304', $commande->client->telephone);
        $this->assertSame(StatutCommande::Livree, $commande->statut);
    }

    public function test_une_vente_au_telephone_est_confirmee_non_payee_et_facturee_comme_la_vitrine(): void
    {
        $this->connecte();

        $commande = $this->commande($this->saisirAuTelephone()->assertCreated());

        $this->assertSame(Canal::Telephone, $commande->canal);
        $this->assertSame(StatutCommande::Payee, $commande->statut, 'Confirmée à la création');
        $this->assertNull($commande->paiement_confirme_le, 'Non payée');
        $this->assertNull($commande->mode_paiement);
        $this->assertNull($commande->expire_le);
        $this->assertSame('Mariam Koné', $commande->client->nom);
        $this->assertSame('+2250701020304', $commande->client->telephone);
        $this->assertSame('Cocody', $commande->commune);
        $this->assertSame(1500, $commande->frais_livraison, 'mêmes zones que la vitrine');
        $this->assertSame(11500, $commande->total);
        $this->assertSame(0, ReservationStock::pourTousEtablissements()->where('commande_id', $commande->id)->where('statut', StatutReservation::Active)->count(), 'réservation consommée');
        $this->assertSame(8, $this->pagne->fresh()->quantite_stock);
    }

    public function test_autre_commune_au_telephone_livraison_a_convenir(): void
    {
        $this->connecte();

        $commande = $this->commande($this->saisirAuTelephone(['commune' => 'Bingerville', 'autre_commune' => true])->assertCreated());

        $this->assertNull($commande->zone_livraison_id);
        $this->assertSame(0, $commande->frais_livraison);
        $this->assertSame('Bingerville', $commande->commune);
    }

    public function test_autre_commune_au_telephone_paie_les_frais_fixes_comme_la_vitrine(): void
    {
        app(GererZonesLivraison::class)->definirAutreCommune($this->wax, 3000);
        $this->connecte();

        $this->getJson(self::HOTE.'/api/commandes/manuelles/zones')->assertOk()->assertJsonPath('autre_commune', ['frais' => 3000]);
        $commande = $this->commande($this->saisirAuTelephone(['commune' => 'Bingerville', 'autre_commune' => true])->assertCreated());

        $this->assertSame('Bingerville', $commande->commune);
        $this->assertSame(3000, $commande->frais_livraison);
        $this->assertSame(13000, $commande->total);
    }

    public function test_au_telephone_le_numero_est_obligatoire(): void
    {
        $this->connecte();

        $this->saisirAuTelephone(['client' => ['nom' => 'Mariam Koné']])->assertStatus(422)->assertJsonValidationErrors('client.telephone');
        $this->assertSame(0, Commande::pourTousEtablissements()->where('canal', 'telephone')->count());
    }

    public function test_un_prix_envoye_par_le_navigateur_est_ignore(): void
    {
        $this->connecte();

        $commande = $this->commande($this->saisir([
            'lignes' => [['produit_id' => $this->pagne->id, 'quantite' => 1, 'prix' => 1, 'prix_unitaire' => 1, 'total' => 1]],
            'total' => 1,
            'sous_total' => 1,
        ])->assertCreated());

        $this->assertSame(5000, $commande->total);
        $this->assertSame(5000, $commande->lignes()->pourTousEtablissements()->first()->prix_unitaire);
        $this->assertSame(5000, $commande->paiement_montant_recu, 'encaissé au prix de la base');
    }

    public function test_stock_insuffisant_nomme_article_variante_demande_et_disponible(): void
    {
        $this->connecte();
        $variante = VarianteProduit::pourTousEtablissements()->where('nom', '45 / Noir')->firstOrFail();

        $reponse = $this->saisir(['lignes' => [['produit_id' => $variante->produit_id, 'variante_id' => $variante->id, 'quantite' => 3]]]);

        $reponse->assertStatus(422);
        $this->assertSame('Stock insuffisant pour Sandales en cuir tressé — 45 / Noir : 3 demandé(s), 1 disponible(s).', $reponse->json('message'));
        $this->assertSame(0, Commande::pourTousEtablissements()->where('source', 'back_office')->count());
        $this->assertSame(0, $variante->fresh()->quantite_reservee);
    }

    public function test_le_message_de_stock_de_la_vitrine_ne_change_pas(): void
    {
        $variante = VarianteProduit::pourTousEtablissements()->where('nom', '45 / Noir')->firstOrFail();

        $reponse = $this->postJson(self::HOTE.'/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $variante->produit_id, 'variante_id' => $variante->id, 'quantite' => 3]],
            'client' => ['nom' => 'Koffi', 'telephone' => '0701020304'],
            'commune' => 'Cocody',
            'quartier' => 'Riviera',
            'cle_idempotence' => 'vitrine-stock',
        ]);

        $reponse->assertStatus(422);
        $this->assertSame('Stock insuffisant pour "Sandales en cuir tressé — 45 / Noir" (3 demandé(s)).', $reponse->json('message'));
    }

    public function test_doublon_par_telephone_averti_puis_confirme(): void
    {
        $this->connecte();
        $premiere = $this->commande($this->saisirAuTelephone()->assertCreated());

        $reponse = $this->saisirAuTelephone()->assertStatus(409);
        $this->assertSame($premiere->numero, $reponse->json('doublons.0.numero'));
        $this->assertSame($premiere->id, $reponse->json('doublons.0.id'));

        $this->saisirAuTelephone(['confirmer_doublon' => true])->assertCreated();
        $this->assertSame(2, Commande::pourTousEtablissements()->where('canal', 'telephone')->count(), 'un avertissement, jamais un blocage');
    }

    public function test_une_commande_livree_ou_de_plus_de_24h_nest_pas_un_doublon(): void
    {
        $this->connecte();
        $premiere = $this->commande($this->saisirAuTelephone()->assertCreated());
        $premiere->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->saisirAuTelephone()->assertCreated();

        // Livrée il y a une heure : terminée, ce n'est pas un doublon.
        $livree = $this->commande($this->saisirAuTelephone(['confirmer_doublon' => true])->assertCreated());
        Commande::pourTousEtablissements()->whereKeyNot($livree->id)->get()->each->forceFill(['created_at' => now()->subHours(25)])->each->save();
        $livree->forceFill(['statut' => StatutCommande::Livree])->save();

        $this->saisirAuTelephone()->assertCreated();
    }

    public function test_le_nom_saisi_par_le_commercant_corrige_celui_du_client(): void
    {
        $this->connecte();
        $this->saisirAuTelephone(['client' => ['telephone' => '0701020304', 'nom' => 'Mariam K']])->assertCreated();

        $commande = $this->commande($this->saisirAuTelephone(['confirmer_doublon' => true])->assertCreated());

        $this->assertSame('Mariam Koné', $commande->client->nom);
        $this->assertSame(1, Client::pourTousEtablissements()->where('telephone', '+2250701020304')->count(), 'le même client, pas un second');
    }

    public function test_sans_canal_la_saisie_est_refusee(): void
    {
        $this->connecte();

        $this->saisir(['canal' => null])->assertStatus(422)->assertJsonValidationErrors('canal');
        $this->assertSame(0, Commande::pourTousEtablissements()->count());
    }

    public function test_une_reference_deja_transformee_est_refusee_meme_confirmee(): void
    {
        $this->connecte();
        app(ContexteEtablissement::class)->definir($this->wax);
        $reference = ReferenceWhatsapp::create(['code' => 'AB12CD34', 'produit_id' => $this->pagne->id, 'expire_le' => now()->addDays(7)]);
        app(ContexteEtablissement::class)->definir(null);

        $premiere = $this->commande($this->saisirAuTelephone(['reference' => 'Bonjour, je veux ça [REF:AB12CD34]'])->assertCreated());
        $this->assertSame($reference->id, $premiere->reference_whatsapp_id);

        // Lot D : un autre numéro, le même message — un refus, pas un
        // avertissement qu'on confirme, même en forçant confirmer_doublon.
        foreach ([false, true] as $confirmer) {
            $this->saisirAuTelephone(['client' => ['telephone' => '0505050505', 'nom' => 'Autre'], 'reference' => 'AB12CD34', 'confirmer_doublon' => $confirmer])
                ->assertStatus(409)
                ->assertJsonPath('deja_transformee.numero', $premiere->numero)
                ->assertJsonMissingPath('doublons');
        }
        $this->assertSame(1, Commande::pourTousEtablissements()->where('reference_whatsapp_id', $reference->id)->count());
    }

    public function test_une_reference_inconnue_est_refusee(): void
    {
        $this->connecte();

        $this->saisirAuTelephone(['reference' => 'ZZZZZZZZ'])->assertStatus(422)->assertJsonValidationErrors('reference');
    }

    public function test_au_telephone_seuls_le_numero_et_la_commune_sont_obligatoires(): void
    {
        $this->connecte();

        $commande = $this->commande($this->saisirAuTelephone(['client' => ['telephone' => '0708091011', 'nom' => '  '], 'quartier' => ''])->assertCreated());

        $this->assertNull($commande->client->nom, 'un client sans nom, jamais un nom inventé');
        $this->assertNull($commande->quartier);
        $this->assertSame('Cocody', $commande->commune);
        $this->getJson(self::HOTE.'/api/commandes/'.$commande->id)->assertOk()->assertJsonPath('data.client.nom', null);

        $this->saisirAuTelephone(['commune' => null, 'confirmer_doublon' => true])->assertStatus(422)->assertJsonValidationErrors('commune');
    }

    public function test_un_nom_laisse_vide_nefface_pas_celui_dun_client_connu(): void
    {
        $this->connecte();
        $this->saisirAuTelephone()->assertCreated();

        $commande = $this->commande($this->saisirAuTelephone(['client' => ['telephone' => '07 01 02 03 04'], 'confirmer_doublon' => true])->assertCreated());

        $this->assertSame('Mariam Koné', $commande->client->nom);
    }

    public function test_au_comptoir_un_habitue_identifie_garde_son_nom_obligatoire(): void
    {
        $this->connecte();

        $this->saisir(['client' => ['telephone' => '0701020304', 'nom' => ' ']])->assertStatus(422)->assertJsonValidationErrors('client.nom');
    }

    public function test_une_reference_collee_donne_son_article_et_sa_commande(): void
    {
        $this->connecte();
        $variante = VarianteProduit::pourTousEtablissements()->where('nom', '45 / Noir')->firstOrFail();
        app(ContexteEtablissement::class)->definir($this->wax);
        ReferenceWhatsapp::create(['code' => 'SANSVAR1', 'produit_id' => $this->pagne->id, 'expire_le' => now()->addDays(7)]);
        ReferenceWhatsapp::create(['code' => 'AVECVAR1', 'produit_id' => $variante->produit_id, 'variante_id' => $variante->id, 'expire_le' => now()->addDays(7)]);
        ReferenceWhatsapp::create(['code' => 'SANSCHX1', 'produit_id' => $variante->produit_id, 'expire_le' => now()->addDays(7)]);
        app(ContexteEtablissement::class)->definir(null);
        $url = self::HOTE.'/api/commandes/manuelles/reference?reference=';

        $this->getJson($url.urlencode('Bonjour [REF:SANSVAR1]'))->assertOk()
            ->assertJsonPath('data.article.produit_id', $this->pagne->id)
            ->assertJsonPath('data.article.variante_id', null)
            ->assertJsonPath('data.article.prix', 5000)
            ->assertJsonPath('data.commande', null);
        $this->getJson($url.'AVECVAR1')->assertOk()
            ->assertJsonPath('data.article.variante_id', $variante->id)
            ->assertJsonPath('data.article.variante', '45 / Noir');
        $this->getJson($url.'SANSCHX1')->assertOk()->assertJsonPath('data.article', null);
        $this->getJson($url.'ZZZZZZZZ')->assertNotFound()->assertJsonPath('message', 'Cette référence n’existe pas dans votre boutique.');

        $variante->update(['statut' => StatutVariante::Inactif]);
        $this->getJson($url.'AVECVAR1')->assertOk()->assertJsonPath('data.article', null);

        $commande = $this->commande($this->saisirAuTelephone(['reference' => 'SANSVAR1'])->assertCreated());
        $this->getJson($url.'SANSVAR1')->assertOk()->assertJsonPath('data.commande', ['id' => $commande->id, 'numero' => $commande->numero]);
    }

    public function test_une_reference_dune_autre_boutique_est_inconnue(): void
    {
        $autre = Etablissement::where('slug', '!=', 'wax-adjame')->firstOrFail();
        app(ContexteEtablissement::class)->definir($autre);
        $produit = Produit::factory()->for($autre)->publie()->create();
        ReferenceWhatsapp::create(['code' => 'AILLEURS', 'produit_id' => $produit->id, 'expire_le' => now()->addDays(7)]);
        app(ContexteEtablissement::class)->definir(null);

        $this->connecte();
        $this->getJson(self::HOTE.'/api/commandes/manuelles/reference?reference=AILLEURS')->assertNotFound();
    }

    public function test_sans_la_permission_creer_commandes_tout_est_refuse(): void
    {
        app(ContexteEtablissement::class)->definir($this->wax);
        $stock = User::factory()->create(['email' => 'stock@wax-adjame.test', 'password' => 'motdepasse', 'mot_de_passe_defini' => true]);
        app(CreerRattachementUtilisateur::class)->executer($stock, $this->wax, [Role::where('nom', 'gestionnaire_stock')->value('id')]);
        app(ContexteEtablissement::class)->definir(null);
        $this->connecte('stock@wax-adjame.test');

        $this->saisir()->assertForbidden();
        $this->getJson(self::HOTE.'/api/commandes/manuelles/articles')->assertForbidden();
        $this->getJson(self::HOTE.'/api/commandes/manuelles/client?telephone=0701020304')->assertForbidden();
        $this->getJson(self::HOTE.'/api/commandes/manuelles/zones')->assertForbidden();
        $this->getJson(self::HOTE.'/api/commandes/manuelles/reference?reference=AB12CD34')->assertForbidden();
        $this->assertSame(0, Commande::pourTousEtablissements()->where('source', 'back_office')->count());
    }

    /**
     * Si une étape de la vente au comptoir échoue (ici l'encaissement), rien
     * n'est écrit : ni commande, ni stock consommé.
     */
    public function test_comptoir_tout_ou_rien(): void
    {
        $this->connecte();
        $this->app->instance(ConfirmerPaiementCommande::class, new class extends ConfirmerPaiementCommande
        {
            public function executer($commande, int $montantRecu, ?string $reference, $utilisateur, ?string $adresseIp): bool
            {
                throw new RuntimeException('panne simulée à l’encaissement');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->saisir();
            $this->fail('la panne aurait dû remonter');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Commande::pourTousEtablissements()->where('source', 'back_office')->count());
        $this->assertSame(10, $this->pagne->fresh()->quantite_stock);
        $this->assertSame(0, $this->pagne->fresh()->quantite_reservee);
    }

    /**
     * L'horloge avance de 25 heures, le job d'expiration passe : aucune
     * commande saisie à la main n'expire, quel que soit son canal.
     */
    public function test_aucune_commande_saisie_a_la_main_nexpire_apres_25_heures(): void
    {
        $this->connecte();
        $comptoir = $this->commande($this->saisir()->assertCreated());
        $telephone = $this->commande($this->saisirAuTelephone()->assertCreated());
        $whatsapp = $this->commande($this->saisirAuTelephone(['canal' => 'whatsapp', 'client' => ['telephone' => '0505050505', 'nom' => 'Awa']])->assertCreated());

        $this->travel(25)->hours();
        app(ContexteEtablissement::class)->definir(null);
        (new LibererReservationsExpirees)->handle(app(LibererReservation::class));

        $this->assertSame(StatutCommande::Livree, $comptoir->fresh()->statut);
        $this->assertSame(StatutCommande::Payee, $telephone->fresh()->statut);
        $this->assertSame(StatutCommande::Payee, $whatsapp->fresh()->statut);
        $this->assertSame(4, $this->pagne->fresh()->quantite_stock, 'aucun stock rendu');
    }

    /**
     * Une commande de la vitrine passée exactement comme avant le lot C :
     * même numéro, même total, même stock réservé, même expiration, aucun
     * auteur.
     */
    public function test_la_vitrine_commande_exactement_comme_avant(): void
    {
        $avant = (int) Commande::pourTousEtablissements()->where('etablissement_id', $this->wax->id)->count();

        $reponse = $this->postJson(self::HOTE.'/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $this->pagne->id, 'quantite' => 2]],
            'client' => ['nom' => 'Koffi', 'telephone' => '0701020304'],
            'commune' => 'Cocody',
            'quartier' => 'Riviera',
            'cle_idempotence' => 'vitrine-comme-avant',
        ])->assertCreated();

        $commande = Commande::pourTousEtablissements()->where('numero', $reponse->json('numero'))->where('etablissement_id', $this->wax->id)->firstOrFail();
        $this->assertSame(sprintf('CMD-%06d', $avant + 1), $commande->numero);
        $this->assertSame(Canal::Web, $commande->canal);
        $this->assertSame(SourceCommande::PanierWeb, $commande->source);
        $this->assertSame(StatutCommande::AttentePaiement, $commande->statut);
        $this->assertSame(10000 + 1500, $commande->total);
        $this->assertNull($commande->cree_par);
        $this->assertNotNull($commande->client_id);
        $this->assertEqualsWithDelta(24 * 3600, now()->diffInSeconds($commande->expire_le), 5);
        $this->assertSame(10, $this->pagne->fresh()->quantite_stock, 'réservé, pas consommé');
        $this->assertSame(2, $this->pagne->fresh()->quantite_reservee);
        $this->assertNull($commande->historique()->pourTousEtablissements()->first()->utilisateur_id);
    }

    public function test_les_articles_listent_chaque_variante_avec_prix_et_stock(): void
    {
        $this->connecte();
        app(ContexteEtablissement::class)->definir($this->wax);
        Produit::factory()->for($this->wax)->create(['nom' => 'Sandales archivées', 'statut' => StatutProduit::Archive]);
        VarianteProduit::where('nom', '44 / Marron')->firstOrFail()->update(['statut' => StatutVariante::Inactif]);
        app(ContexteEtablissement::class)->definir(null);

        $articles = collect($this->getJson(self::HOTE.'/api/commandes/manuelles/articles?recherche=sandales')->assertOk()->json('data'));

        $this->assertCount(11, $articles, '12 variantes moins l’inactive ; jamais le produit archivé');
        $noir45 = $articles->firstWhere('variante', '45 / Noir');
        $this->assertSame('Sandales en cuir tressé', $noir45['nom']);
        $this->assertSame(19500, $noir45['prix']);
        $this->assertSame(['mode' => 'compte', 'disponible' => 1], $noir45['stock']);
        $this->assertNull($articles->firstWhere('variante', '44 / Marron'));
    }

    public function test_un_telephone_connu_preremplit_nom_commune_et_quartier(): void
    {
        $this->connecte();
        $this->saisirAuTelephone()->assertCreated();

        $this->getJson(self::HOTE.'/api/commandes/manuelles/client?telephone=0701020304')
            ->assertOk()
            ->assertJsonPath('data.nom', 'Mariam Koné')
            ->assertJsonPath('data.commune', 'Cocody')
            ->assertJsonPath('data.quartier', 'Angré 7e tranche');
        $this->getJson(self::HOTE.'/api/commandes/manuelles/client?telephone=0909090909')->assertOk()->assertJsonPath('data', null);
    }

    public function test_un_produit_dun_autre_etablissement_ne_se_vend_pas(): void
    {
        $this->connecte();
        $autre = Produit::pourTousEtablissements()->where('etablissement_id', Etablissement::where('slug', 'chez-awa')->value('id'))->firstOrFail();

        $this->assertContains($this->saisir(['lignes' => [['produit_id' => $autre->id, 'quantite' => 1]]])->status(), [404, 422]);
        $this->assertSame(0, Commande::pourTousEtablissements()->where('source', 'back_office')->count());
    }

    public function test_la_liste_et_le_detail_disent_client_de_passage_et_auteur(): void
    {
        $this->connecte();
        $commande = $this->commande($this->saisir()->assertCreated());

        $detail = $this->getJson(self::HOTE."/api/commandes/{$commande->id}")->assertOk();
        $detail->assertJsonPath('data.client', null)->assertJsonPath('data.cree_par', 'Fatou Diallo')->assertJsonPath('data.canal_libelle', 'Comptoir');
        $this->getJson(self::HOTE.'/api/commandes')->assertOk();
    }

    public function test_vendre_depuis_un_produit_ne_liste_que_lui_et_les_zones_sont_celles_de_la_vitrine(): void
    {
        $this->connecte();

        $articles = $this->getJson(self::HOTE."/api/commandes/manuelles/articles?produit={$this->pagne->id}")->assertOk()->json('data');
        $this->assertSame([$this->pagne->id], array_column($articles, 'produit_id'));

        $zones = $this->getJson(self::HOTE.'/api/commandes/manuelles/zones')->assertOk()->json('data');
        $this->assertSame($this->getJson(self::HOTE.'/api/vitrine/zones-livraison')->json('data.*.nom'), array_column($zones, 'nom'));
    }
}
