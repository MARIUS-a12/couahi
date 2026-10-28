<?php

namespace Tests\Feature\Auth;

use App\Models\Etablissement;
use App\Models\EtablissementUtilisateur;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Étape 1 — Authentification et permissions, tests 1, 2, 3, 6, 7.
 *
 * Un test = un hôte = un parcours : le client de test HTTP de Laravel
 * rejoue tous les cookies de tous les hôtes précédents sur chaque nouvelle
 * requête (aucun cloisonnement par domaine, contrairement à un vrai
 * navigateur) — mélanger plusieurs hôtes dans un seul test produirait de
 * fausses authentifications croisées qui n'existent pas en réalité.
 */
class ConnexionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un vrai navigateur envoie toujours Origin/Referer en cross-origin :
     * EnsureFrontendRequestsAreStateful (donc la session) en dépend. Le port
     * :8000 correspond à l'entrée `*.localhost:8000` de
     * SANCTUM_STATEFUL_DOMAINS (voir .env) — sans lui, aucune entrée ne
     * correspond à un hôte de test sans port explicite.
     */
    private function depuis(string $hote): static
    {
        return $this->withHeader('Referer', "http://{$hote}:8000");
    }

    // Étape 10 : admin_etablissement a reçu gerer_stock et gerer_equipe en
    // plus de ses 8 permissions d'origine (voir RolesEtPermissionsSeeder),
    // puis creer_commandes au lot C et gerer_demandes_whatsapp au lot D —
    // 12 désormais.
    public function test_1_connexion_reussie_et_moi_renvoie_role_et_ses_12_permissions(): void
    {
        $this->seed();

        $connexion = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
            'email' => 'awa@chez-awa.test',
            'mot_de_passe' => 'motdepasse',
        ]);
        $connexion->assertStatus(200);

        $moi = $this->depuis('chez-awa.localhost')->getJson('http://chez-awa.localhost:8000/api/moi');
        $moi->assertStatus(200);
        $moi->assertJsonPath('role', 'admin_etablissement');
        $this->assertCount(12, $moi->json('permissions'));
        $this->assertContains('gerer_demandes_whatsapp', $moi->json('permissions'));
        $this->assertContains('creer_commandes', $moi->json('permissions'));
    }

    public function test_2_mauvais_sous_domaine_est_refuse_meme_avec_le_bon_mot_de_passe(): void
    {
        $this->seed();

        // Awa a un compte valide sur chez-awa, mais aucune appartenance sur
        // maquis-du-port : son mot de passe, pourtant correct, ne suffit pas.
        $reponse = $this->depuis('maquis-du-port.localhost')->postJson('http://maquis-du-port.localhost:8000/api/connexion', [
            'email' => 'awa@chez-awa.test',
            'mot_de_passe' => 'motdepasse',
        ]);

        $reponse->assertStatus(422);
        $reponse->assertJsonPath('errors.email.0', 'Identifiants invalides.');
    }

    public function test_3_appartenance_suspendue_est_refusee(): void
    {
        $this->seed();

        $awa = User::where('email', 'awa@chez-awa.test')->firstOrFail();
        EtablissementUtilisateur::where('utilisateur_id', $awa->id)->update(['statut' => 'suspendu']);

        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
            'email' => 'awa@chez-awa.test',
            'mot_de_passe' => 'motdepasse',
        ]);

        $reponse->assertStatus(422);
        $reponse->assertJsonPath('errors.email.0', 'Identifiants invalides.');
    }

    public function test_3_etablissement_inactif_est_refuse(): void
    {
        $this->seed();

        Etablissement::where('slug', 'chez-awa')->update(['statut' => 'inactif']);

        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
            'email' => 'awa@chez-awa.test',
            'mot_de_passe' => 'motdepasse',
        ]);

        $reponse->assertStatus(422);
        $reponse->assertJsonPath('errors.email.0', 'Identifiants invalides.');
    }

    /**
     * Correctif limitation de débit : remplace l'ancienne limite "5 par IP
     * et par minute" (inadaptée au contexte ivoirien, où une IP partagée
     * par un opérateur mobile dessert des abonnés sans aucun lien entre
     * eux) par le même limiteur combiné que /activation (voir
     * LimiteurEmailEtIp) — ici, par email : 5 par heure.
     */
    public function test_6_la_sixieme_tentative_de_connexion_sur_le_meme_email_en_une_heure_est_limitee(): void
    {
        $this->seed();

        for ($i = 0; $i < 5; $i++) {
            $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
                'email' => 'awa@chez-awa.test',
                'mot_de_passe' => 'faux',
            ])->assertStatus(422);
        }

        $sixieme = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
            'email' => 'awa@chez-awa.test',
            'mot_de_passe' => 'faux',
        ]);

        $sixieme->assertStatus(429);
        $this->assertStringContainsString('minute', $sixieme->json('message'));
    }

    /**
     * Même raisonnement que ActivationApiTest::test_12 : la limite par IP
     * (30/heure, large) ne doit jamais bloquer un compte qui n'a pas
     * lui-même dépassé sa propre limite (5/heure).
     */
    public function test_8_une_tentative_sur_un_autre_email_depuis_la_meme_ip_passe_encore(): void
    {
        $this->seed();

        for ($i = 0; $i < 5; $i++) {
            $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
                'email' => 'awa@chez-awa.test',
                'mot_de_passe' => 'faux',
            ])->assertStatus(422);
        }
        $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
            'email' => 'awa@chez-awa.test',
            'mot_de_passe' => 'faux',
        ])->assertStatus(429);

        // Même IP, email différent (compte réel, mais sur un autre
        // établissement, pour obtenir un 422 "Identifiants invalides." et
        // non un 404) : la limite d'awa ne doit rien lui opposer.
        $reponse = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
            'email' => 'yao@maquis-du-port.test',
            'mot_de_passe' => 'faux',
        ]);

        $reponse->assertStatus(422);
    }

    public function test_9_la_trente_et_unieme_tentative_de_connexion_depuis_la_meme_ip_est_bloquee_quels_que_soient_les_emails(): void
    {
        $this->seed();

        // 30 emails distincts (inexistants : seule l'adresse IP compte ici),
        // une seule tentative chacun : aucun n'atteint sa propre limite (5).
        for ($i = 0; $i < 30; $i++) {
            $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
                'email' => "balayage-{$i}@chez-awa.test",
                'mot_de_passe' => 'faux',
            ])->assertStatus(422);
        }

        $trenteEtUnieme = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
            'email' => 'balayage-31@chez-awa.test',
            'mot_de_passe' => 'faux',
        ]);

        $trenteEtUnieme->assertStatus(429);
        $this->assertStringContainsString('minute', $trenteEtUnieme->json('message'));
    }

    public function test_7_les_4_causes_dechec_renvoient_exactement_le_meme_corps_et_le_meme_code(): void
    {
        $this->seed();

        $awa = User::where('email', 'awa@chez-awa.test')->firstOrFail();

        // Cause 1 : mauvais mot de passe.
        $mauvaisMdp = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
            'email' => 'awa@chez-awa.test',
            'mot_de_passe' => 'faux',
        ]);

        // Cause 2 : mauvais sous-domaine (mot de passe correct).
        $mauvaisSousDomaine = $this->depuis('maquis-du-port.localhost')->postJson('http://maquis-du-port.localhost:8000/api/connexion', [
            'email' => 'awa@chez-awa.test',
            'mot_de_passe' => 'motdepasse',
        ]);

        // Cause 3 : appartenance suspendue.
        EtablissementUtilisateur::where('utilisateur_id', $awa->id)->update(['statut' => 'suspendu']);
        $suspendue = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
            'email' => 'awa@chez-awa.test',
            'mot_de_passe' => 'motdepasse',
        ]);
        EtablissementUtilisateur::where('utilisateur_id', $awa->id)->update(['statut' => 'actif']);

        // Cause 4 : établissement inactif.
        Etablissement::where('slug', 'chez-awa')->update(['statut' => 'inactif']);
        $etablissementInactif = $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
            'email' => 'awa@chez-awa.test',
            'mot_de_passe' => 'motdepasse',
        ]);

        $reponses = [$mauvaisMdp, $mauvaisSousDomaine, $suspendue, $etablissementInactif];

        foreach ($reponses as $reponse) {
            $reponse->assertStatus(422);
            $reponse->assertJsonPath('errors.email.0', 'Identifiants invalides.');
        }

        $corps = array_map(fn ($r) => $r->getContent(), $reponses);
        $this->assertCount(1, array_unique($corps), 'les 4 causes doivent produire exactement le même corps de réponse');
    }

    private function tenter(string $email, string $motDePasse): TestResponse
    {
        return $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/connexion', [
            'email' => $email,
            'mot_de_passe' => $motDePasse,
        ]);
    }

    /**
     * Le cas qui a révélé le bug : avec le middleware "throttle" de Laravel,
     * chaque connexion RÉUSSIE consommait une des 5 tentatives de l'heure.
     * Quatre connexions dans la journée (son téléphone, celui de l'employé…)
     * puis deux fautes de frappe suffisaient à l'enfermer dehors une heure.
     */
    public function test_10_une_connexion_reussie_ne_consomme_pas_le_quota_de_tentatives(): void
    {
        $this->seed();

        for ($i = 0; $i < 4; $i++) {
            $this->tenter('awa@chez-awa.test', 'motdepasse')->assertStatus(200);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->tenter('awa@chez-awa.test', 'faux')->assertStatus(422);
        }

        $this->tenter('awa@chez-awa.test', 'faux')->assertStatus(429);
    }

    public function test_11_une_connexion_reussie_remet_le_compteur_de_lemail_a_zero(): void
    {
        $this->seed();

        for ($i = 0; $i < 4; $i++) {
            $this->tenter('awa@chez-awa.test', 'faux')->assertStatus(422);
        }
        $this->tenter('awa@chez-awa.test', 'motdepasse')->assertStatus(200);

        for ($i = 0; $i < 5; $i++) {
            $this->tenter('awa@chez-awa.test', 'faux')->assertStatus(422);
        }
        $this->tenter('awa@chez-awa.test', 'faux')->assertStatus(429);
    }

    /**
     * Le blocage tient même contre le BON mot de passe : sinon un
     * attaquant continuerait à essayer, et saurait qu'il a trouvé au
     * premier 200 au milieu des 429.
     */
    public function test_12_une_fois_bloque_meme_le_bon_mot_de_passe_est_refuse(): void
    {
        $this->seed();

        for ($i = 0; $i < 5; $i++) {
            $this->tenter('awa@chez-awa.test', 'faux')->assertStatus(422);
        }

        $this->tenter('awa@chez-awa.test', 'motdepasse')->assertStatus(429);
        $this->assertGuest('web');
    }

    /**
     * Une réussite rend à l'IP la seule unité qu'elle avait prise, jamais
     * davantage : un compte valide ne doit pas effacer les échecs que
     * d'autres ont accumulés depuis la même adresse (balayage en cours).
     */
    public function test_13_une_connexion_reussie_ne_remet_pas_a_zero_les_echecs_des_autres_sur_la_meme_ip(): void
    {
        $this->seed();

        for ($i = 0; $i < 29; $i++) {
            $this->tenter("balayage-{$i}@chez-awa.test", 'faux')->assertStatus(422);
        }
        $this->tenter('awa@chez-awa.test', 'motdepasse')->assertStatus(200);

        $this->tenter('balayage-29@chez-awa.test', 'faux')->assertStatus(422);
        $this->tenter('balayage-30@chez-awa.test', 'faux')->assertStatus(429);
    }
}
