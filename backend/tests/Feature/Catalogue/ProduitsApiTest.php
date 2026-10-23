<?php

namespace Tests\Feature\Catalogue;

use App\Enums\Canal;
use App\Enums\SourceCommande;
use App\Enums\StatutVariante;
use App\Enums\TypeOffre;
use App\Models\Categorie;
use App\Models\Etablissement;
use App\Models\EtablissementUtilisateur;
use App\Models\Produit;
use App\Models\Role;
use App\Models\User;
use App\Models\VarianteProduit;
use App\Services\Commandes\CreerCommande;
use App\Services\Commandes\LigneCommandeDemandee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape 2 — API catalogue (produits), tests 1 à 10 de la spec, plus un 11e
 * ajouté après la correction sur la stabilité du slug.
 *
 * Réutilise les établissements et comptes de démo de l'Étape 1 (voir
 * EtablissementsDemoSeeder / UtilisateursDemoSeeder) : chez-awa est une
 * boutique administrée par Awa (admin_etablissement, gerer_catalogue),
 * maquis-du-port est un restaurant où Yao n'est qu'opérateur (pas
 * gerer_catalogue).
 */
class ProduitsApiTest extends TestCase
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

    /**
     * Crée un second compte admin_etablissement, cette fois sur
     * maquis-du-port (qui, dans la démo, n'a qu'un opérateur) : nécessaire au
     * test 9, qui doit prouver qu'un slug est réutilisable dans un AUTRE
     * établissement — ce qui exige d'y créer un produit via l'API, donc d'y
     * être authentifié avec gerer_catalogue.
     */
    private function creerAdminPour(Etablissement $etablissement): User
    {
        $roleAdmin = Role::where('nom', 'admin_etablissement')->value('id');

        $utilisateur = User::factory()->create(['password' => 'motdepasse', 'mot_de_passe_defini' => true]);

        EtablissementUtilisateur::create([
            'etablissement_id' => $etablissement->id,
            'utilisateur_id' => $utilisateur->id,
            'role_id' => $roleAdmin,
            'statut' => 'actif',
        ]);

        return $utilisateur;
    }

    public function test_1_crud_complet_en_tant_quadmin(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $creation = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/produits', [
            'nom' => 'Jus de Bissap',
            'prix' => 1000,
        ]);
        $creation->assertStatus(201);
        $creation->assertJsonPath('data.nom', 'Jus de Bissap');
        $creation->assertJsonPath('data.slug', 'jus-de-bissap');
        $creation->assertJsonPath('data.statut', 'brouillon');
        $creation->assertJsonMissingPath('data.etablissement_id');
        $id = $creation->json('data.id');

        $lecture = $this->depuis('chez-awa.localhost')->getJson("http://chez-awa.localhost:8000/api/produits/{$id}");
        $lecture->assertStatus(200);
        $lecture->assertJsonPath('data.nom', 'Jus de Bissap');

        $modification = $this->depuis('chez-awa.localhost')->putJson("http://chez-awa.localhost:8000/api/produits/{$id}", [
            'prix' => 1200,
            'description' => 'Rafraîchissant et local.',
        ]);
        $modification->assertStatus(200);
        $modification->assertJsonPath('data.prix', 1200);
        $modification->assertJsonPath('data.description', 'Rafraîchissant et local.');
        $modification->assertJsonPath('data.nom', 'Jus de Bissap');
    }

    /**
     * L'opérateur n'a que voir_catalogue (lecture) : il traite les
     * commandes et doit voir produits/catégories et leurs prix, sans
     * pouvoir les modifier — gerer_catalogue reste réservé à l'admin.
     */
    public function test_2_operateur_lecture_catalogue_autorisee_ecriture_refusee(): void
    {
        $this->seed();

        $maquisDuPort = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
        $produitMaquis = Produit::pourTousEtablissements()->where('etablissement_id', $maquisDuPort->id)->firstOrFail();
        $categorieMaquis = Categorie::pourTousEtablissements()->where('etablissement_id', $maquisDuPort->id)->firstOrFail();

        $this->connecte('maquis-du-port.localhost', 'yao@maquis-du-port.test');

        $this->depuis('maquis-du-port.localhost')
            ->getJson('http://maquis-du-port.localhost:8000/api/produits')
            ->assertStatus(200);

        $this->depuis('maquis-du-port.localhost')
            ->getJson("http://maquis-du-port.localhost:8000/api/produits/{$produitMaquis->id}")
            ->assertStatus(200);

        $this->depuis('maquis-du-port.localhost')
            ->getJson('http://maquis-du-port.localhost:8000/api/categories')
            ->assertStatus(200);

        $this->depuis('maquis-du-port.localhost')
            ->postJson('http://maquis-du-port.localhost:8000/api/produits', ['nom' => 'Tentative', 'prix' => 500])
            ->assertStatus(403);

        $this->depuis('maquis-du-port.localhost')
            ->putJson("http://maquis-du-port.localhost:8000/api/produits/{$produitMaquis->id}", ['prix' => 999])
            ->assertStatus(403);

        $this->depuis('maquis-du-port.localhost')
            ->deleteJson("http://maquis-du-port.localhost:8000/api/produits/{$produitMaquis->id}")
            ->assertStatus(403);

        $this->depuis('maquis-du-port.localhost')
            ->postJson('http://maquis-du-port.localhost:8000/api/categories', ['nom' => 'Tentative'])
            ->assertStatus(403);

        $this->depuis('maquis-du-port.localhost')
            ->putJson("http://maquis-du-port.localhost:8000/api/categories/{$categorieMaquis->id}", ['nom' => 'Tentative'])
            ->assertStatus(403);

        $this->depuis('maquis-du-port.localhost')
            ->deleteJson("http://maquis-du-port.localhost:8000/api/categories/{$categorieMaquis->id}")
            ->assertStatus(403);
    }

    public function test_3_isolation_awa_404_sur_produit_de_maquis_en_get_put_delete(): void
    {
        $this->seed();

        $maquisDuPort = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
        $produitMaquis = Produit::pourTousEtablissements()->where('etablissement_id', $maquisDuPort->id)->firstOrFail();

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $this->depuis('chez-awa.localhost')
            ->getJson("http://chez-awa.localhost:8000/api/produits/{$produitMaquis->id}")
            ->assertStatus(404);

        $this->depuis('chez-awa.localhost')
            ->putJson("http://chez-awa.localhost:8000/api/produits/{$produitMaquis->id}", ['nom' => 'Modifié par erreur'])
            ->assertStatus(404);

        $this->depuis('chez-awa.localhost')
            ->deleteJson("http://chez-awa.localhost:8000/api/produits/{$produitMaquis->id}")
            ->assertStatus(404);

        $produitMaquis->refresh();
        $this->assertNotSame('Modifié par erreur', $produitMaquis->nom);
    }

    public function test_4_injection_categorie_id_dun_autre_etablissement_refusee(): void
    {
        $this->seed();

        $maquisDuPort = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
        $categorieMaquis = Categorie::pourTousEtablissements()->where('etablissement_id', $maquisDuPort->id)->firstOrFail();

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/produits', [
            'nom' => 'Injection',
            'prix' => 500,
            'categorie_id' => $categorieMaquis->id,
        ]);

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['categorie_id']);
    }

    public function test_5_injection_etablissement_id_dans_le_corps_ignoree(): void
    {
        $this->seed();

        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $maquisDuPort = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/produits', [
            'nom' => 'Toujours chez Awa',
            'prix' => 500,
            'etablissement_id' => $maquisDuPort->id,
        ]);

        $reponse->assertStatus(201);
        $id = $reponse->json('data.id');

        $produit = Produit::pourTousEtablissements()->findOrFail($id);
        $this->assertSame($chezAwa->id, $produit->etablissement_id);
    }

    public function test_6_delete_archive_au_lieu_de_supprimer(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $creation = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/produits', [
            'nom' => 'Produit à archiver',
            'prix' => 750,
        ]);
        $id = $creation->json('data.id');

        $this->depuis('chez-awa.localhost')
            ->deleteJson("http://chez-awa.localhost:8000/api/produits/{$id}")
            ->assertStatus(204);

        $produit = Produit::pourTousEtablissements()->find($id);
        $this->assertNotNull($produit, 'la ligne doit toujours exister en base après archivage');
        $this->assertSame('archive', $produit->statut->value);
    }

    /**
     * Lot A : le mode de stock n'est plus figé à la création. Il reste
     * refusé tant qu'une commande en cours en dépend — ici une réservation
     * active ; les autres cas sont dans ChangementModeStockTest.
     */
    public function test_7_mode_stock_modifie_refuse_tant_quune_reservation_est_active(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $creation = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/produits', [
            'nom' => 'Produit stock compte',
            'prix' => 500,
            'quantite_stock' => 10,
        ]);
        $id = $creation->json('data.id');
        $this->assertSame('compte', Produit::pourTousEtablissements()->find($id)->mode_stock->value);

        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        app(CreerCommande::class)->executer(
            $chezAwa, $chezAwa->clients()->create(['nom' => 'Client Test']), [new LigneCommandeDemandee($id, null, 2)],
            Canal::Web, SourceCommande::PanierWeb, 'cle-test-7',
        );

        $reponse = $this->depuis('chez-awa.localhost')->putJson("http://chez-awa.localhost:8000/api/produits/{$id}", [
            'mode_stock' => 'interrupteur',
        ]);

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['mode_stock']);
        $this->assertSame('compte', Produit::pourTousEtablissements()->find($id)->mode_stock->value);
    }

    public function test_8_prix_decimal_refuse(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/produits', [
            'nom' => 'Produit à prix décimal',
            'prix' => 12.50,
        ]);

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['prix']);
    }

    public function test_9a_slug_duplique_dans_le_meme_etablissement_refuse(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $premiere = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/produits', [
            'nom' => 'Poulet Braisé',
            'prix' => 2500,
        ]);
        $premiere->assertStatus(201);

        $doublon = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/produits', [
            'nom' => 'Autre nom',
            'slug' => 'poulet-braise',
            'prix' => 3000,
        ]);
        $doublon->assertStatus(422);
        $doublon->assertJsonValidationErrors(['slug']);
    }

    /**
     * Dans un test séparé, sur un seul hôte : le client de test HTTP de
     * Laravel rejoue tous les cookies de tous les hôtes précédents sur
     * chaque requête (voir le docblock de ConnexionTest) — mélanger
     * chez-awa et maquis-du-port dans UN SEUL test produirait une session
     * ambiguë entre les deux comptes, pas le scénario cross-tenant voulu.
     */
    public function test_9b_meme_slug_accepte_dans_un_autre_etablissement(): void
    {
        $this->seed();

        Produit::factory()->for(Etablissement::where('slug', 'chez-awa')->firstOrFail())->create([
            'nom' => 'Brochettes de bœuf',
            'slug' => 'brochettes-de-boeuf',
        ]);

        $maquisDuPort = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
        $adminMaquis = $this->creerAdminPour($maquisDuPort);

        $this->connecte('maquis-du-port.localhost', $adminMaquis->email);

        $ailleurs = $this->depuis('maquis-du-port.localhost')->postJson('http://maquis-du-port.localhost:8000/api/produits', [
            'nom' => 'Brochettes de bœuf',
            'slug' => 'brochettes-de-boeuf',
            'prix' => 2800,
        ]);
        $ailleurs->assertStatus(201);
    }

    public function test_10_pagination_et_filtres(): void
    {
        $this->seed();

        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();

        Produit::factory()->for($chezAwa)->count(10)->publie()->create(['nom' => 'Article commun']);
        Produit::factory()->for($chezAwa)->count(10)->brouillon()->create();

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $total = Produit::pourTousEtablissements()->where('etablissement_id', $chezAwa->id)->count();

        $parDefaut = $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/produits');
        $parDefaut->assertStatus(200);
        $this->assertCount(20, $parDefaut->json('data'));
        $this->assertSame($total, $parDefaut->json('meta.total'));

        $filtreParStatut = $this->depuis('chez-awa.localhost')
            ->getJson('http://chez-awa.localhost:8000/api/produits?statut=brouillon&par_page=50');
        $filtreParStatut->assertStatus(200);
        $this->assertGreaterThanOrEqual(10, count($filtreParStatut->json('data')));
        foreach ($filtreParStatut->json('data') as $produit) {
            $this->assertSame('brouillon', $produit['statut']);
        }

        $recherche = $this->depuis('chez-awa.localhost')
            ->getJson('http://chez-awa.localhost:8000/api/produits?recherche=Article+commun&par_page=50');
        $recherche->assertStatus(200);
        $this->assertSame(10, count($recherche->json('data')));

        $petitePage = $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/produits?par_page=5');
        $petitePage->assertStatus(200);
        $this->assertCount(5, $petitePage->json('data'));

        $triNomAsc = $this->depuis('chez-awa.localhost')
            ->getJson('http://chez-awa.localhost:8000/api/produits?tri=nom&direction=asc&par_page=50');
        $triNomAsc->assertStatus(200);
        $noms = array_column($triNomAsc->json('data'), 'nom');
        $nomsTries = $noms;
        sort($nomsTries, SORT_STRING | SORT_FLAG_CASE);
        $this->assertSame($nomsTries, $noms);
    }

    /**
     * Correction post-revue : le slug est dans l'URL publique et dans des
     * liens déjà partagés (wa.me...) — le régénérer au passage d'un
     * renommage casserait ces liens en silence. Un renommage ne doit donc
     * jamais toucher au slug.
     */
    public function test_11_renommer_un_produit_ne_change_pas_son_slug(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $creation = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/produits', [
            'nom' => 'Attiéké Poisson',
            'prix' => 2000,
        ]);
        $id = $creation->json('data.id');
        $this->assertSame('attieke-poisson', $creation->json('data.slug'));

        $renommage = $this->depuis('chez-awa.localhost')->putJson("http://chez-awa.localhost:8000/api/produits/{$id}", [
            'nom' => 'Attiéké Poisson Fumé',
        ]);

        $renommage->assertStatus(200);
        $renommage->assertJsonPath('data.nom', 'Attiéké Poisson Fumé');
        $renommage->assertJsonPath('data.slug', 'attieke-poisson');
    }

    /**
     * Étape 6A, test n°9 : type_offre n'est ni validé ni accepté par
     * StoreProduitRequest (aucun formulaire ne l'expose) — un produit créé
     * sans le préciser doit donc porter la valeur par défaut du modèle.
     */
    public function test_12_type_offre_par_defaut_vaut_bien(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $creation = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/produits', [
            'nom' => 'Sans type_offre précisé',
            'prix' => 1500,
        ]);
        $creation->assertStatus(201);

        $produit = Produit::pourTousEtablissements()->findOrFail($creation->json('data.id'));
        $this->assertSame(TypeOffre::Bien, $produit->type_offre);
    }

    /**
     * Lot A — la pastille du back-office lisait le stock du produit, qui
     * vaut 0 quand le stock vit sur les variantes : le riz s'affichait
     * « Épuisé » avec trois sacs en stock. L'API résume désormais le
     * stock des seules variantes actives.
     */
    public function test_13a_le_stock_dun_produit_a_variantes_est_celui_de_ses_variantes_actives(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $stockDe = fn (string $hote, string $slug) => collect(
            $this->depuis($hote)->getJson("http://{$hote}:8000/api/produits?par_page=100")->assertStatus(200)->json('data')
        )->firstWhere('slug', $slug)['stock_variantes'];

        $this->assertSame(['nombre' => 3, 'quantite_disponible' => 55, 'disponible' => true], $stockDe('chez-awa.localhost', 'riz-parfume-long-grain'));
        $this->assertNull($stockDe('chez-awa.localhost', 'huile-de-palme-rouge-non-raffinee-bouteille-1-litre'));

        $riz = Produit::pourTousEtablissements()->where('slug', 'riz-parfume-long-grain')->firstOrFail();
        VarianteProduit::pourTousEtablissements()->where('produit_id', $riz->id)->where('nom', '1 kg')->update(['statut' => StatutVariante::Inactif]);
        $this->assertSame(['nombre' => 2, 'quantite_disponible' => 15, 'disponible' => true], $stockDe('chez-awa.localhost', 'riz-parfume-long-grain'));
    }

    public function test_13b_en_disponibilite_manuelle_le_stock_suit_les_variantes_et_non_le_produit(): void
    {
        $this->seed();
        $admin = $this->creerAdminPour(Etablissement::where('slug', 'maquis-du-port')->firstOrFail());
        $this->connecte('maquis-du-port.localhost', $admin->email);

        $stockDe = fn (string $hote, string $slug) => collect(
            $this->depuis($hote)->getJson("http://{$hote}:8000/api/produits?par_page=100")->assertStatus(200)->json('data')
        )->firstWhere('slug', $slug)['stock_variantes'];

        $this->assertSame(['nombre' => 2, 'quantite_disponible' => null, 'disponible' => true], $stockDe('maquis-du-port.localhost', 'poulet-braise'));

        $poulet = Produit::pourTousEtablissements()->where('slug', 'poulet-braise')->firstOrFail();
        VarianteProduit::pourTousEtablissements()->where('produit_id', $poulet->id)->update(['disponible' => false]);
        $this->assertTrue($poulet->fresh()->disponible, 'le drapeau du produit, lui, reste à disponible');
        $this->assertSame(['nombre' => 2, 'quantite_disponible' => null, 'disponible' => false], $stockDe('maquis-du-port.localhost', 'poulet-braise'));
    }
}
