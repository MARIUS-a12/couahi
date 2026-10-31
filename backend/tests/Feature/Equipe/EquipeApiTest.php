<?php

namespace Tests\Feature\Equipe;

use App\Enums\StatutMembre;
use App\Models\Etablissement;
use App\Models\EtablissementUtilisateur;
use App\Models\Role;
use App\Models\User;
use App\Services\Activation\ActiverCompte;
use App\Services\Equipe\ChangerStatutMembreEquipe;
use App\Services\Equipe\CreerMembreEquipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Étape 10 — page /admin/equipe, tests 4 à 12. Les tests 1 à 3 (gerer_stock
 * vs gerer_catalogue) sont dans StockEtRolesCatalogueApiTest.
 */
class EquipeApiTest extends TestCase
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

    public function test_4_un_administrateur_ne_peut_pas_changer_son_propre_role(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $awa = User::where('email', 'awa@chez-awa.test')->firstOrFail();
        $membreAwa = EtablissementUtilisateur::where('utilisateur_id', $awa->id)
            ->where('etablissement_id', $chezAwa->id)->firstOrFail();

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $reponse = $this->depuis('chez-awa.localhost')->putJson(
            "http://chez-awa.localhost:8000/api/equipe/{$membreAwa->id}",
            ['role_ids' => [Role::where('nom', 'operateur')->value('id')]],
        );

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['role_ids']);
        $this->assertSame(['admin_etablissement'], $membreAwa->refresh()->roles->pluck('nom')->all());
    }

    public function test_5_un_administrateur_ne_peut_pas_se_desactiver_lui_meme(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $awa = User::where('email', 'awa@chez-awa.test')->firstOrFail();
        $membreAwa = EtablissementUtilisateur::where('utilisateur_id', $awa->id)
            ->where('etablissement_id', $chezAwa->id)->firstOrFail();

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $reponse = $this->depuis('chez-awa.localhost')->patchJson(
            "http://chez-awa.localhost:8000/api/equipe/{$membreAwa->id}/statut",
            ['statut' => 'suspendu'],
        );

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['statut']);
        $this->assertSame(StatutMembre::Actif, $membreAwa->refresh()->statut);
    }

    /**
     * Testé directement au niveau du service : avec gerer_equipe réservé à
     * admin_etablissement et un acteur toujours actif pour pouvoir appeler
     * cette route, le seul administrateur actif d'un établissement est
     * TOUJOURS celui qui agit — ce cas précis est donc déjà couvert par le
     * test 5 (auto-désactivation). Ce test vérifie que la règle elle-même
     * ("au moins un administrateur actif") tient, indépendamment de qui agit.
     */
    public function test_6_desactiver_le_dernier_administrateur_actif_est_refuse(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $awa = User::where('email', 'awa@chez-awa.test')->firstOrFail();
        $membreAwa = EtablissementUtilisateur::where('utilisateur_id', $awa->id)
            ->where('etablissement_id', $chezAwa->id)->firstOrFail();
        $tiers = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(ChangerStatutMembreEquipe::class)->executer($membreAwa, StatutMembre::Suspendu, $tiers, null);
    }

    /**
     * SESSION_DRIVER=array en test (voir phpunit.xml) ne persiste jamais la
     * session d'une requête HTTP réelle dans la table "sessions" — même
     * procédé que CompteApiTest::test_3 : une ligne posée directement en
     * base simule la session déjà ouverte du membre avant sa désactivation.
     * L'activation passe par le SERVICE directement, pas par
     * POST /api/activation : ce dernier connecte l'employé (voir
     * ActivationController), ce qui remplacerait la session d'Awa dans le
     * client de test et casserait l'authentification des requêtes
     * suivantes — artefact du harnais de test (cookies non cloisonnés par
     * identité au sein d'un même test, voir ConnexionTest), pas de
     * l'application ; ActivationApiTest couvre le VRAI parcours HTTP.
     */
    public function test_7_un_membre_desactive_ne_peut_plus_se_connecter_et_ses_sessions_sont_invalidees(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $creation = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/equipe', [
            'nom' => 'Caissier Test',
            'email' => 'caissier7@chez-awa.test',
            'role_ids' => [Role::where('nom', 'caissier')->value('id')],
        ])->assertStatus(201);
        $membreId = $creation->json('data.id');
        $utilisateurId = $creation->json('data.utilisateur_id');
        $code = $creation->json('code_activation');

        app(ActiverCompte::class)->executer(
            etablissement: $chezAwa,
            email: 'caissier7@chez-awa.test',
            code: $code,
            nouveauMotDePasse: 'un-mot-de-passe-choisi-123',
        );

        DB::table('sessions')->insert([
            'id' => 'session-caissier7',
            'user_id' => $utilisateurId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('donnees'),
            'last_activity' => time(),
        ]);

        $this->depuis('chez-awa.localhost')
            ->patchJson("http://chez-awa.localhost:8000/api/equipe/{$membreId}/statut", ['statut' => 'suspendu'])
            ->assertStatus(200);

        $this->assertDatabaseMissing('sessions', ['id' => 'session-caissier7']);

        $reconnexion = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
            'email' => 'caissier7@chez-awa.test',
            'mot_de_passe' => 'un-mot-de-passe-choisi-123',
        ]);
        $reconnexion->assertStatus(422);
    }

    public function test_8_un_operateur_recoit_403_sur_toute_route_de_equipe(): void
    {
        $this->seed();
        $yao = User::where('email', 'yao@maquis-du-port.test')->firstOrFail();
        $membreYao = EtablissementUtilisateur::where('utilisateur_id', $yao->id)->firstOrFail();

        $this->connecte('maquis-du-port.localhost', 'yao@maquis-du-port.test');

        $this->depuis('maquis-du-port.localhost')
            ->getJson('http://maquis-du-port.localhost:8000/api/equipe')->assertStatus(403);
        $this->depuis('maquis-du-port.localhost')
            ->getJson('http://maquis-du-port.localhost:8000/api/equipe/roles')->assertStatus(403);
        $this->depuis('maquis-du-port.localhost')->postJson('http://maquis-du-port.localhost:8000/api/equipe', [
            'nom' => 'X',
            'email' => 'x@maquis-du-port.test',
            'role_ids' => [Role::where('nom', 'caissier')->value('id')],
        ])->assertStatus(403);
        $this->depuis('maquis-du-port.localhost')
            ->putJson("http://maquis-du-port.localhost:8000/api/equipe/{$membreYao->id}", ['nom' => 'Y'])
            ->assertStatus(403);
        $this->depuis('maquis-du-port.localhost')
            ->patchJson("http://maquis-du-port.localhost:8000/api/equipe/{$membreYao->id}/statut", ['statut' => 'suspendu'])
            ->assertStatus(403);
        $this->depuis('maquis-du-port.localhost')
            ->postJson("http://maquis-du-port.localhost:8000/api/equipe/{$membreYao->id}/code-activation")
            ->assertStatus(403);
    }

    public function test_9_un_administrateur_de_a_ne_peut_pas_gerer_lequipe_de_b(): void
    {
        $this->seed();
        $maquisDuPort = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
        $yao = User::where('email', 'yao@maquis-du-port.test')->firstOrFail();
        $membreYaoSurB = EtablissementUtilisateur::where('utilisateur_id', $yao->id)
            ->where('etablissement_id', $maquisDuPort->id)->firstOrFail();

        // Awa est admin de chez-awa (A), elle agit depuis SON sous-domaine
        // mais vise un membre de maquis-du-port (B).
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $this->depuis('chez-awa.localhost')
            ->putJson("http://chez-awa.localhost:8000/api/equipe/{$membreYaoSurB->id}", ['nom' => 'Hack'])
            ->assertStatus(404);
    }

    /**
     * Le client de test HTTP de Laravel ne cloisonne pas les cookies par
     * domaine comme un vrai navigateur (voir ConnexionTest) : enchaîner
     * DEUX connexions sur deux hôtes différents dans un même test casse
     * l'authentification de la seconde, un artefact du harnais, pas de
     * l'application. La création sur maquis-du-port passe donc directement
     * par le service, exactement comme l'aurait fait le contrôleur après
     * une connexion réelle d'Adjoua sur son propre sous-domaine.
     */
    public function test_10_le_meme_email_peut_exister_dans_deux_etablissements_differents(): void
    {
        $this->seed();

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/equipe', [
            'nom' => 'Personne Partagée',
            'email' => 'partage@exemple.test',
            'role_ids' => [Role::where('nom', 'caissier')->value('id')],
        ])->assertStatus(201);

        $maquisDuPort = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
        $adjoua = User::where('email', 'adjoua@maquis-du-port.test')->firstOrFail();

        app(CreerMembreEquipe::class)->executer(
            etablissement: $maquisDuPort,
            nom: 'Autre Personne',
            email: 'partage@exemple.test',
            roleIds: [Role::where('nom', 'caissier')->value('id')],
            acteur: $adjoua,
            adresseIp: null,
        );

        $this->assertSame(2, User::where('email', 'partage@exemple.test')->count());
    }

    public function test_11_un_role_ajoute_en_base_apparait_dans_la_liste_des_roles(): void
    {
        $this->seed();
        Role::create(['nom' => 'livreur_test', 'libelle' => 'Livreur']);

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $reponse = $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/equipe/roles');

        $reponse->assertStatus(200);
        $this->assertTrue(collect($reponse->json('data'))->pluck('nom')->contains('livreur_test'));
    }

    public function test_12_chaque_action_sur_un_membre_cree_une_entree_de_journal(): void
    {
        $this->seed();
        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $creation = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/equipe', [
            'nom' => 'Nouveau Membre',
            'email' => 'membre12@chez-awa.test',
            'role_ids' => [Role::where('nom', 'caissier')->value('id')],
        ]);
        $creation->assertStatus(201);
        $this->assertDatabaseHas('journaux_audit', ['action' => 'equipe_membre_cree']);
        // La création génère déjà un premier code (voir CreerMembreEquipe) :
        // une seule entrée à ce stade, pour pouvoir distinguer plus bas
        // celle, DISTINCTE, que "générer un nouveau code d'accès" ajoute.
        $this->assertSame(1, DB::table('journaux_audit')->where('action', 'code_activation_genere')->count());
        $membreId = $creation->json('data.id');

        $this->depuis('chez-awa.localhost')
            ->putJson("http://chez-awa.localhost:8000/api/equipe/{$membreId}", ['nom' => 'Renommé'])
            ->assertStatus(200);
        $this->assertDatabaseHas('journaux_audit', ['action' => 'equipe_membre_modifie']);

        $this->depuis('chez-awa.localhost')
            ->patchJson("http://chez-awa.localhost:8000/api/equipe/{$membreId}/statut", ['statut' => 'suspendu'])
            ->assertStatus(200);
        $this->assertDatabaseHas('journaux_audit', ['action' => 'equipe_membre_suspendu']);

        $this->depuis('chez-awa.localhost')
            ->patchJson("http://chez-awa.localhost:8000/api/equipe/{$membreId}/statut", ['statut' => 'actif'])
            ->assertStatus(200);
        $this->assertDatabaseHas('journaux_audit', ['action' => 'equipe_membre_reactive']);

        $this->depuis('chez-awa.localhost')
            ->postJson("http://chez-awa.localhost:8000/api/equipe/{$membreId}/code-activation")
            ->assertStatus(200);
        $this->assertSame(2, DB::table('journaux_audit')->where('action', 'code_activation_genere')->count());
    }
}
