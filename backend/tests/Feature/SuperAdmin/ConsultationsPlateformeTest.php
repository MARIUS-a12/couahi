<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\ConsultationPlateforme;
use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteLaravel;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Journal des consultations, par le super-admin, des données commerciales
 * d'un établissement — lu par le commerçant ("Consultations par l'équipe
 * de la plateforme"). Aucune route super-admin ne renvoie encore de
 * commandes, de clients ou de chiffre d'affaires : le middleware est
 * exercé ici sur une route de test montée exactement comme le serait une
 * vraie (même groupe, même middleware), et test_6 garantit que la
 * prochaine vraie route de ce genre ne pourra pas l'oublier.
 */
class ConsultationsPlateformeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les routes super-admin d'un établissement qui ne montrent PAS ses
     * données commerciales : sa fiche, son identité, sa configuration de
     * paiement, son abonnement. Toute autre route de ce groupe doit être
     * journalisée (test_6). Ajouter une route ici, c'est affirmer qu'elle
     * ne montre ni commande, ni client, ni chiffre d'affaires.
     */
    private const ROUTES_ADMINISTRATIVES = [
        'GET api/etablissements/{etablissement}',
        'POST api/etablissements/{etablissement}/suspendre',
        'POST api/etablissements/{etablissement}/reactiver',
        'PATCH api/etablissements/{etablissement}/type',
        'DELETE api/etablissements/{etablissement}',
        'GET api/etablissements/{etablissement}/identite',
        'PATCH api/etablissements/{etablissement}/identite',
        'POST api/etablissements/{etablissement}/identite/logo',
        'DELETE api/etablissements/{etablissement}/identite/logo',
        'GET api/etablissements/{etablissement}/paiement',
        'PUT api/etablissements/{etablissement}/paiement',
        'DELETE api/etablissements/{etablissement}/paiement',
        'GET api/etablissements/{etablissement}/mobile-money',
        'PUT api/etablissements/{etablissement}/mobile-money',
        'DELETE api/etablissements/{etablissement}/mobile-money',
        'GET api/etablissements/{etablissement}/abonnement',
        'PATCH api/etablissements/{etablissement}/abonnement',
        'POST api/etablissements/{etablissement}/abonnement/paiements',
    ];

    private function depuis(string $hote): static
    {
        return $this->withHeader('Referer', "http://{$hote}:8000");
    }

    private function connecte(string $hote, string $email): static
    {
        if ($this->app['auth']->guard('web')->check()) {
            $this->postJson('/api/deconnexion');
            $this->app['auth']->forgetGuards();
        }
        $this->depuis($hote)->postJson("http://{$hote}:8000/api/connexion", [
            'email' => $email,
            'mot_de_passe' => 'motdepasse',
        ])->assertStatus(200);

        return $this;
    }

    private function hoteSuperAdmin(): string
    {
        return config('tenancy.hote_super_admin');
    }

    private function waxAdjame(): Etablissement
    {
        return Etablissement::where('slug', 'wax-adjame')->firstOrFail();
    }

    /** Une page de données super-admin, montée comme le serait une vraie. */
    private function monterPageDeDonnees(string $page = 'commandes', int $statut = 200): string
    {
        $uri = "api/etablissements/{etablissement}/test-{$page}-{$statut}";

        Route::middleware(['api', 'auth:sanctum', 'definir.etablissement.cible', "journaliser.consultation:{$page}"])
            ->get($uri, fn () => response()->json(['data' => [['numero' => 'CMD-000002', 'total' => 15000]]], $statut));

        return Str::replace('{etablissement}', (string) $this->waxAdjame()->id, $uri);
    }

    private function consulter(string $chemin)
    {
        $hote = $this->hoteSuperAdmin();

        return $this->depuis($hote)->getJson("http://{$hote}:8000/{$chemin}");
    }

    private function historiqueVuParFatou()
    {
        $this->connecte('wax-adjame.localhost', 'fatou@wax-adjame.test');

        return $this->depuis('wax-adjame.localhost')
            ->getJson('http://wax-adjame.localhost:8000/api/parametres/consultations-plateforme');
    }

    public function test_1_une_consultation_est_tracee_qui_quand_quel_etablissement_quelle_page(): void
    {
        $this->seed();
        $chemin = $this->monterPageDeDonnees('commandes');
        $this->travelTo(now()->setTime(14, 30));

        $this->connecte($this->hoteSuperAdmin(), 'super@plateforme.test');
        $this->consulter($chemin)->assertStatus(200);

        $consultation = ConsultationPlateforme::pourTousEtablissements()->sole();
        $this->assertSame($this->waxAdjame()->id, $consultation->etablissement_id);
        $this->assertSame(User::where('email', 'super@plateforme.test')->value('id'), $consultation->utilisateur_id);
        $this->assertSame('Marius Kouame', $consultation->nom_utilisateur);
        $this->assertSame('commandes', $consultation->page->value);
        $this->assertTrue($consultation->consulte_le->equalTo(now()->startOfSecond()));

        // Le commerçant le voit dans son propre back-office.
        $this->historiqueVuParFatou()
            ->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.qui', 'Marius Kouame')
            ->assertJsonPath('data.0.page', 'commandes')
            ->assertJsonPath('data.0.page_libelle', 'Liste des commandes');
    }

    public function test_2_jamais_le_contenu_consulte(): void
    {
        $this->seed();
        $chemin = $this->monterPageDeDonnees('detail_commande');

        $this->connecte($this->hoteSuperAdmin(), 'super@plateforme.test');
        $this->consulter($chemin)->assertStatus(200)->assertJsonPath('data.0.numero', 'CMD-000002');

        $this->assertSame(
            ['id', 'etablissement_id', 'utilisateur_id', 'nom_utilisateur', 'page', 'consulte_le'],
            Schema::getColumnListing('consultations_plateforme'),
        );
        $ligne = json_encode(ConsultationPlateforme::pourTousEtablissements()->sole()->getAttributes());
        $this->assertStringNotContainsString('CMD-000002', $ligne);
        $this->assertStringNotContainsString('15000', $ligne);
        $this->assertStringNotContainsString('test-', $ligne, "ni même l'adresse exacte demandée");
    }

    public function test_3_les_pages_dadministration_ne_sont_pas_journalisees(): void
    {
        $this->seed();
        $id = $this->waxAdjame()->id;
        $this->connecte($this->hoteSuperAdmin(), 'super@plateforme.test');

        foreach (['', '/identite', '/paiement', '/mobile-money', '/abonnement'] as $suffixe) {
            $this->consulter("api/etablissements/{$id}{$suffixe}")->assertStatus(200);
        }

        $this->assertSame(0, ConsultationPlateforme::pourTousEtablissements()->count());
    }

    public function test_4_une_reponse_en_echec_na_rien_montre_donc_rien_nest_trace(): void
    {
        $this->seed();
        $chemin = $this->monterPageDeDonnees('clients', 403);

        $this->connecte($this->hoteSuperAdmin(), 'super@plateforme.test');
        $this->consulter($chemin)->assertStatus(403);

        $this->assertSame(0, ConsultationPlateforme::pourTousEtablissements()->count());
    }

    public function test_5_sans_trace_possible_les_donnees_ne_partent_pas(): void
    {
        $this->seed();
        $chemin = $this->monterPageDeDonnees('chiffre_affaires');
        $this->connecte($this->hoteSuperAdmin(), 'super@plateforme.test');
        Schema::drop('consultations_plateforme');

        $this->withoutExceptionHandling();
        try {
            $this->consulter($chemin);
            $this->fail("La réponse est partie alors que la consultation n'a pas pu être tracée.");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_6_aucune_route_de_donnees_super_admin_ne_peut_echapper_au_journal(): void
    {
        $nonClassees = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteLaravel $route) => in_array('definir.etablissement.cible', $route->gatherMiddleware(), true))
            ->reject(fn (RouteLaravel $route) => collect($route->gatherMiddleware())
                ->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'journaliser.consultation:')))
            ->flatMap(fn (RouteLaravel $route) => collect($route->methods())
                ->reject(fn ($methode) => $methode === 'HEAD')
                ->map(fn ($methode) => "{$methode} {$route->uri()}"))
            ->diff(self::ROUTES_ADMINISTRATIVES)
            ->values()
            ->all();

        $this->assertSame([], $nonClassees,
            "Route super-admin d'un établissement ni journalisée (journaliser.consultation:{page}), ".
            'ni déclarée administrative dans ROUTES_ADMINISTRATIVES.');
    }

    public function test_7_le_super_admin_na_aucun_autre_chemin_vers_les_commandes(): void
    {
        $this->seed();
        $this->connecte($this->hoteSuperAdmin(), 'super@plateforme.test');

        // Les routes commerçant n'ont aucun établissement sur l'hôte du
        // super-admin : refus, jamais de données — donc rien à tracer.
        foreach (['api/commandes', 'api/commandes/statistiques'] as $chemin) {
            $reponse = $this->consulter($chemin);
            $this->assertContains($reponse->status(), [400, 403], $chemin);
            $this->assertNull($reponse->json('data'), $chemin);
        }
    }

    public function test_8_une_page_rechargee_dans_les_10_minutes_nest_tracee_quune_fois(): void
    {
        $this->seed();
        $chemin = $this->monterPageDeDonnees('commandes');
        $this->connecte($this->hoteSuperAdmin(), 'super@plateforme.test');

        $this->consulter($chemin);
        $this->travel(5)->minutes();
        $this->consulter($chemin);
        $this->assertSame(1, ConsultationPlateforme::pourTousEtablissements()->count());

        $this->travel(6)->minutes();
        $this->consulter($chemin);
        $this->assertSame(2, ConsultationPlateforme::pourTousEtablissements()->count());
    }

    public function test_9_chaque_commercant_ne_voit_que_son_historique_et_seulement_le_responsable(): void
    {
        $this->seed();
        $chemin = $this->monterPageDeDonnees('commandes');
        $this->connecte($this->hoteSuperAdmin(), 'super@plateforme.test');
        $this->consulter($chemin)->assertStatus(200);

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $this->depuis('chez-awa.localhost')
            ->getJson('http://chez-awa.localhost:8000/api/parametres/consultations-plateforme')
            ->assertStatus(200)
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data');

        // Un opérateur n'a pas la main sur la relation avec la plateforme.
        $this->connecte('maquis-du-port.localhost', 'yao@maquis-du-port.test');
        $this->depuis('maquis-du-port.localhost')
            ->getJson('http://maquis-du-port.localhost:8000/api/parametres/consultations-plateforme')
            ->assertStatus(403);
    }

    public function test_10_le_commercant_sait_toujours_qui_meme_apres_suppression_du_compte(): void
    {
        $this->seed();
        $chemin = $this->monterPageDeDonnees('commandes');
        $this->connecte($this->hoteSuperAdmin(), 'super@plateforme.test');
        $this->consulter($chemin)->assertStatus(200);

        User::where('email', 'super@plateforme.test')->delete();

        $this->historiqueVuParFatou()->assertJsonPath('data.0.qui', 'Marius Kouame');
    }
}
