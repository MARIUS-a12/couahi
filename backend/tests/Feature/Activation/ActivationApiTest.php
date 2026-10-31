<?php

namespace Tests\Feature\Activation;

use App\Models\CodeActivation;
use App\Models\Etablissement;
use App\Models\Role;
use App\Models\User;
use App\Services\Activation\ActiverCompte;
use App\Services\Equipe\CreerMembreEquipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Correctif Étape 10 — activation par code : l'employé choisit lui-même son
 * mot de passe, personne d'autre ne le connaît jamais (voir
 * GenererCodeActivation / ActiverCompte).
 */
class ActivationApiTest extends TestCase
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

    /**
     * Crée un membre directement via le service (jamais par une connexion
     * HTTP d'Awa suivie d'un appel HTTP à /admin/activation dans le MÊME
     * test : ce dernier connecterait l'employé, remplaçant la session
     * d'Awa dans ce client de test — voir EquipeApiTest::test_7).
     */
    private function creerMembre(Etablissement $etablissement, string $email): array
    {
        $awa = User::where('etablissement_id', $etablissement->id)
            ->whereHas('appartenances.roles', fn ($q) => $q->where('nom', 'admin_etablissement'))
            ->firstOrFail();

        $resultat = app(CreerMembreEquipe::class)->executer(
            etablissement: $etablissement,
            nom: 'Membre Test',
            email: $email,
            roleIds: [Role::where('nom', 'caissier')->value('id')],
            acteur: $awa,
            adresseIp: null,
        );

        return ['membre' => $resultat->membre, 'code' => $resultat->codeActivation];
    }

    public function test_1_un_compte_cree_ne_peut_pas_se_connecter_tant_quil_nest_pas_active(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $this->creerMembre($chezAwa, 'nonactive@chez-awa.test');

        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
            'email' => 'nonactive@chez-awa.test',
            'mot_de_passe' => 'nimporte-quoi',
        ]);

        $reponse->assertStatus(422);
        $this->assertStringContainsString('pas encore activé', $reponse->json('errors.email.0'));
    }

    public function test_2_un_code_expire_est_refuse(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        ['code' => $code] = $this->creerMembre($chezAwa, 'expire@chez-awa.test');

        CodeActivation::query()->update(['expire_le' => now()->subMinute()]);

        $reponse = $this->activer('chez-awa.localhost', 'expire@chez-awa.test', $code);

        $reponse->assertStatus(422);
        $this->assertSame("Ce code n'est pas valide ou a expiré.", $reponse->json('errors.code.0'));
    }

    public function test_3_un_code_deja_utilise_est_refuse(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        ['code' => $code] = $this->creerMembre($chezAwa, 'reutilise@chez-awa.test');

        $this->activer('chez-awa.localhost', 'reutilise@chez-awa.test', $code, 'premier-mot-de-passe-123')
            ->assertStatus(200);

        $reponse = $this->activer('chez-awa.localhost', 'reutilise@chez-awa.test', $code, 'second-mot-de-passe-123');

        $reponse->assertStatus(422);
        $this->assertSame("Ce code n'est pas valide ou a expiré.", $reponse->json('errors.code.0'));
    }

    public function test_4_un_code_dun_autre_etablissement_est_refuse(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        ['code' => $code] = $this->creerMembre($chezAwa, 'partage-cross@exemple.test');

        // Même code, même email, mais tenté sur le sous-domaine de
        // maquis-du-port : le code n'existe que pour chez-awa.
        $reponse = $this->activer('maquis-du-port.localhost', 'partage-cross@exemple.test', $code);

        $reponse->assertStatus(422);
        $this->assertSame("Ce code n'est pas valide ou a expiré.", $reponse->json('errors.code.0'));
    }

    public function test_5_les_trois_refus_precedents_renvoient_le_meme_message(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();

        ['code' => $codeExpire] = $this->creerMembre($chezAwa, 'msg-expire@chez-awa.test');
        CodeActivation::where('utilisateur_id', User::where('email', 'msg-expire@chez-awa.test')->value('id'))
            ->update(['expire_le' => now()->subMinute()]);
        $reponseExpire = $this->activer('chez-awa.localhost', 'msg-expire@chez-awa.test', $codeExpire);

        ['code' => $codeUtilise] = $this->creerMembre($chezAwa, 'msg-utilise@chez-awa.test');
        $this->activer('chez-awa.localhost', 'msg-utilise@chez-awa.test', $codeUtilise, 'un-mot-de-passe-123')
            ->assertStatus(200);
        $reponseUtilise = $this->activer('chez-awa.localhost', 'msg-utilise@chez-awa.test', $codeUtilise);

        $reponseInconnu = $this->activer('chez-awa.localhost', 'personne@chez-awa.test', 'ABCDEFGH');

        $messages = [
            $reponseExpire->json('errors.code.0'),
            $reponseUtilise->json('errors.code.0'),
            $reponseInconnu->json('errors.code.0'),
        ];

        $this->assertCount(1, array_unique($messages));
        $this->assertSame("Ce code n'est pas valide ou a expiré.", $messages[0]);
    }

    public function test_6_generer_un_nouveau_code_invalide_le_precedent(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $membreCree = $this->creerMembre($chezAwa, 'regenere@chez-awa.test');
        $ancienCode = $membreCree['code'];

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $nouvelleReponse = $this->depuis('chez-awa.localhost')
            ->postJson("http://chez-awa.localhost:8000/api/equipe/{$membreCree['membre']->id}/code-activation");
        $nouvelleReponse->assertStatus(200);
        $nouveauCode = $nouvelleReponse->json('code_activation');

        $echecAncien = $this->activer('chez-awa.localhost', 'regenere@chez-awa.test', $ancienCode);
        $echecAncien->assertStatus(422);

        $succesNouveau = $this->activer('chez-awa.localhost', 'regenere@chez-awa.test', $nouveauCode);
        $succesNouveau->assertStatus(200);
    }

    public function test_7_lactivation_reussie_permet_la_connexion_et_marque_le_code_utilise(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        ['code' => $code] = $this->creerMembre($chezAwa, 'reussie@chez-awa.test');

        $activation = $this->activer('chez-awa.localhost', 'reussie@chez-awa.test', $code, 'mon-mot-de-passe-choisi');
        $activation->assertStatus(200);
        $activation->assertJsonPath('utilisateur.email', 'reussie@chez-awa.test');

        $this->assertNotNull(CodeActivation::first()->utilise_le);

        // "Connecté directement" : la réponse d'activation équivaut déjà à
        // une connexion (voir ActivationController), une requête
        // authentifiée immédiatement après le prouve.
        $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/moi')->assertStatus(200);
    }

    public function test_8_generer_un_code_pour_un_membre_actif_invalide_ses_sessions_en_cours(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        ['membre' => $membre, 'code' => $code] = $this->creerMembre($chezAwa, 'actif@chez-awa.test');

        app(ActiverCompte::class)->executer($chezAwa, 'actif@chez-awa.test', $code, 'mot-de-passe-initial-123');

        DB::table('sessions')->insert([
            'id' => 'session-membre-actif',
            'user_id' => $membre->utilisateur_id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('donnees'),
            'last_activity' => time(),
        ]);

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');
        $this->depuis('chez-awa.localhost')
            ->postJson("http://chez-awa.localhost:8000/api/equipe/{$membre->id}/code-activation")
            ->assertStatus(200);

        $this->assertDatabaseMissing('sessions', ['id' => 'session-membre-actif']);
        $this->assertFalse($membre->utilisateur->refresh()->mot_de_passe_defini);
    }

    public function test_9_la_sixieme_tentative_dactivation_sur_le_meme_email_en_une_heure_est_bloquee(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $this->creerMembre($chezAwa, 'throttle@chez-awa.test');

        for ($i = 0; $i < 5; $i++) {
            $this->activer('chez-awa.localhost', 'throttle@chez-awa.test', 'MAUVAIS1')->assertStatus(422);
        }

        $sixieme = $this->activer('chez-awa.localhost', 'throttle@chez-awa.test', 'MAUVAIS1');

        $sixieme->assertStatus(429);
        $this->assertStringContainsString('minute', $sixieme->json('message'));
    }

    public function test_10_le_code_nest_jamais_stocke_en_clair(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        ['code' => $code] = $this->creerMembre($chezAwa, 'clair@chez-awa.test');

        $ligneBrute = DB::table('codes_activation')->first();

        $this->assertNotSame($code, $ligneBrute->code);
        $this->assertStringStartsWith('$2y$', $ligneBrute->code);
    }

    public function test_11_aucune_reponse_dapi_ne_renvoie_un_code_existant(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $membreCree = $this->creerMembre($chezAwa, 'jamais-expose@chez-awa.test');
        $code = $membreCree['code'];

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $liste = $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/equipe');
        $this->assertStringNotContainsString($code, $liste->content());

        $nouveauCode = $this->depuis('chez-awa.localhost')
            ->postJson("http://chez-awa.localhost:8000/api/equipe/{$membreCree['membre']->id}/code-activation")
            ->json('code_activation');

        $listeApres = $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/equipe');
        $this->assertStringNotContainsString($code, $listeApres->content());
        $this->assertStringNotContainsString($nouveauCode, $listeApres->content());
    }

    /**
     * Correctif limitation de débit : la limite par IP (30/heure, large) ne
     * doit JAMAIS bloquer un email qui n'a pas lui-même dépassé sa propre
     * limite (5/heure) — des employés sans lien entre eux partagent souvent
     * la même IP (opérateurs mobiles ivoiriens).
     */
    public function test_12_une_tentative_sur_un_autre_email_depuis_la_meme_ip_passe_encore(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $this->creerMembre($chezAwa, 'voisin-bloque@chez-awa.test');

        for ($i = 0; $i < 5; $i++) {
            $this->activer('chez-awa.localhost', 'voisin-bloque@chez-awa.test', 'MAUVAIS1')->assertStatus(422);
        }
        $this->activer('chez-awa.localhost', 'voisin-bloque@chez-awa.test', 'MAUVAIS1')->assertStatus(429);

        // Même IP (celle du client de test), email différent : la limite de
        // "voisin-bloque@..." ne doit rien lui opposer.
        $this->creerMembre($chezAwa, 'voisin-libre@chez-awa.test');
        $reponse = $this->activer('chez-awa.localhost', 'voisin-libre@chez-awa.test', 'MAUVAIS1');

        $reponse->assertStatus(422);
    }

    public function test_13_la_trente_et_unieme_tentative_depuis_la_meme_ip_est_bloquee_quels_que_soient_les_emails(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();

        // 30 emails distincts, une seule tentative chacun : aucun n'atteint
        // sa propre limite (5), seule l'IP (30) les regroupe toutes.
        for ($i = 0; $i < 30; $i++) {
            $this->activer('chez-awa.localhost', "balayage-{$i}@chez-awa.test", 'MAUVAIS1')->assertStatus(422);
        }

        $trenteEtUnieme = $this->activer('chez-awa.localhost', 'balayage-31@chez-awa.test', 'MAUVAIS1');

        $trenteEtUnieme->assertStatus(429);
        $this->assertStringContainsString('minute', $trenteEtUnieme->json('message'));
    }

    private function activer(string $hote, string $email, string $code, ?string $motDePasse = null): TestResponse
    {
        $motDePasse ??= 'un-mot-de-passe-par-defaut-123';

        return $this->depuis($hote)->postJson("http://{$hote}:8000/api/activation", [
            'email' => $email,
            'code' => $code,
            'nouveau_mot_de_passe' => $motDePasse,
            'nouveau_mot_de_passe_confirmation' => $motDePasse,
        ]);
    }
}
