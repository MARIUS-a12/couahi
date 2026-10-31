<?php

namespace Tests\Feature\Equipe;

use App\Enums\StatutMembre;
use App\Models\Etablissement;
use App\Models\EtablissementUtilisateur;
use App\Models\Produit;
use App\Models\Role;
use App\Models\User;
use App\Services\Equipe\ChangerStatutMembreEquipe;
use App\Services\Equipe\ModifierMembreEquipe;
use App\Services\Utilisateurs\CreerRattachementUtilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Lot F — un membre cumule plusieurs rôles et ses droits s'additionnent.
 * Garde-fous serveur : au moins un rôle, toujours un administrateur actif,
 * un changement appliqué dès la requête suivante, un membre désactivé
 * coupé tout de suite. La course entre deux administrateurs est prouvée à
 * part, sur PostgreSQL (DernierAdministrateurConcurrentTest).
 */
class RolesCumulablesApiTest extends TestCase
{
    use RefreshDatabase;

    private const HOTE = 'http://maquis-du-port.localhost:8000';

    private Etablissement $maquis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->maquis = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
    }

    private function en(string $email): static
    {
        $this->withHeader('Referer', self::HOTE);
        if ($this->app['auth']->guard('web')->check()) {
            $this->postJson(self::HOTE.'/api/deconnexion');
            $this->app['auth']->forgetGuards();
        }
        $this->postJson(self::HOTE.'/api/connexion', ['email' => $email, 'mot_de_passe' => 'motdepasse'])->assertOk();

        return $this;
    }

    /** @return list<int> */
    private function roles(string ...$noms): array
    {
        return Role::whereIn('nom', $noms)->orderBy('id')->pluck('id')->all();
    }

    private function membre(string $email): EtablissementUtilisateur
    {
        return EtablissementUtilisateur::where('etablissement_id', $this->maquis->id)
            ->whereHas('utilisateur', fn ($requete) => $requete->where('email', $email))
            ->firstOrFail();
    }

    private function utilisateur(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function deuxiemeAdministrateur(): EtablissementUtilisateur
    {
        $bintou = User::factory()->create(['name' => 'Bintou', 'email' => 'bintou@maquis-du-port.test', 'password' => 'motdepasse', 'mot_de_passe_defini' => true]);

        return app(CreerRattachementUtilisateur::class)->executer($bintou, $this->maquis, $this->roles('admin_etablissement'));
    }

    public function test_les_roles_proposes_portent_leurs_vraies_permissions(): void
    {
        $roles = collect($this->en('adjoua@maquis-du-port.test')->getJson(self::HOTE.'/api/equipe/roles')->assertOk()->json('data'));

        $this->assertSame(['admin_etablissement', 'operateur', 'caissier', 'gestionnaire_stock'], $roles->pluck('nom')->all());
        $this->assertSame(
            ['creer_commandes', 'gerer_demandes_whatsapp', 'traiter_commandes', 'voir_catalogue', 'voir_commandes'],
            $roles->firstWhere('nom', 'operateur')['permissions'],
        );
        $this->assertSame(['encaisser', 'voir_catalogue', 'voir_commandes'], $roles->firstWhere('nom', 'caissier')['permissions']);
    }

    public function test_un_membre_a_deux_roles_a_la_somme_de_leurs_droits(): void
    {
        $creation = $this->en('adjoua@maquis-du-port.test')->postJson(self::HOTE.'/api/equipe', [
            'nom' => 'Koffi',
            'email' => 'koffi@maquis-du-port.test',
            'role_ids' => $this->roles('operateur', 'caissier'),
        ])->assertCreated();
        $this->assertSame(['operateur', 'caissier'], array_column($creation->json('data.roles'), 'nom'));

        $this->utilisateur('koffi@maquis-du-port.test')->update(['password' => 'motdepasse', 'mot_de_passe_defini' => true]);
        $moi = $this->en('koffi@maquis-du-port.test')->getJson(self::HOTE.'/api/moi')->assertOk();

        $this->assertSame(['operateur', 'caissier'], $moi->json('roles'));
        $this->assertSame('Opérateur · Caisse', $moi->json('role_libelle_espace'));
        $this->assertSame(
            ['creer_commandes', 'encaisser', 'gerer_demandes_whatsapp', 'traiter_commandes', 'voir_catalogue', 'voir_commandes'],
            $moi->json('permissions'),
        );
        // Par le serveur aussi : un droit du premier rôle, puis un droit que
        // seul le second donne (vendre au comptoir, encaisser).
        $this->getJson(self::HOTE.'/api/demandes-whatsapp')->assertOk();
        $produit = Produit::factory()->for($this->maquis)->publie()->create(['prix' => 2000, 'quantite_stock' => 5, 'quantite_reservee' => 0, 'disponible' => true]);
        $this->postJson(self::HOTE.'/api/commandes/manuelles', [
            'canal' => 'comptoir', 'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]], 'cle_idempotence' => (string) Str::uuid(),
        ])->assertCreated();
        $this->getJson(self::HOTE.'/api/parametres')->assertForbidden();
    }

    public function test_administrateur_coche_avec_dautres_roles_reste_seul(): void
    {
        $this->en('adjoua@maquis-du-port.test')->postJson(self::HOTE.'/api/equipe', [
            'nom' => 'Awa bis',
            'email' => 'awabis@maquis-du-port.test',
            'role_ids' => $this->roles('admin_etablissement', 'caissier'),
        ])->assertCreated()->assertJsonPath('data.roles.0.nom', 'admin_etablissement')->assertJsonCount(1, 'data.roles');

        $yao = $this->membre('yao@maquis-du-port.test');
        $this->putJson(self::HOTE."/api/equipe/{$yao->id}", ['role_ids' => $this->roles('operateur', 'admin_etablissement')])
            ->assertOk()->assertJsonCount(1, 'data.roles')->assertJsonPath('data.roles.0.nom', 'admin_etablissement');
    }

    public function test_un_membre_garde_toujours_au_moins_un_role(): void
    {
        $this->en('adjoua@maquis-du-port.test');
        $yao = $this->membre('yao@maquis-du-port.test');

        $this->putJson(self::HOTE."/api/equipe/{$yao->id}", ['role_ids' => []])->assertUnprocessable()->assertJsonValidationErrors(['role_ids']);
        $this->putJson(self::HOTE."/api/equipe/{$yao->id}", ['role_ids' => null])->assertUnprocessable()->assertJsonValidationErrors(['role_ids']);
        $this->assertSame(['operateur'], $yao->refresh()->roles->pluck('nom')->all());

        $nouveau = ['nom' => 'Sans rôle', 'email' => 'sansrole@maquis-du-port.test'];
        $this->postJson(self::HOTE.'/api/equipe', $nouveau)->assertUnprocessable()->assertJsonValidationErrors(['role_ids']);
        $this->postJson(self::HOTE.'/api/equipe', [...$nouveau, 'role_ids' => []])->assertUnprocessable()->assertJsonValidationErrors(['role_ids']);
        $this->postJson(self::HOTE.'/api/equipe', [...$nouveau, 'role_ids' => $this->roles('super_admin')])->assertUnprocessable()->assertJsonValidationErrors(['role_ids.0']);
        $this->assertFalse(User::where('email', 'sansrole@maquis-du-port.test')->exists());
    }

    /**
     * Avec un autre administrateur, le garde du dernier administrateur ne
     * joue pas : c'est bien « pas vos propres rôles » qui refuse.
     */
    public function test_on_ne_modifie_pas_ses_propres_roles_meme_avec_un_autre_administrateur(): void
    {
        $this->deuxiemeAdministrateur();
        $adjoua = $this->membre('adjoua@maquis-du-port.test');

        $this->en('adjoua@maquis-du-port.test')
            ->putJson(self::HOTE."/api/equipe/{$adjoua->id}", ['role_ids' => $this->roles('admin_etablissement', 'caissier')])
            ->assertOk();
        $this->putJson(self::HOTE."/api/equipe/{$adjoua->id}", ['role_ids' => $this->roles('caissier')])
            ->assertUnprocessable()
            ->assertJsonPath('errors.role_ids.0', 'Vous ne pouvez pas modifier vos propres rôles.');
        $this->assertSame(['admin_etablissement'], $adjoua->refresh()->roles->pluck('nom')->all());
    }

    public function test_un_administrateur_peut_retirer_le_role_dun_autre_administrateur(): void
    {
        $bintou = $this->deuxiemeAdministrateur();

        $this->en('adjoua@maquis-du-port.test')
            ->putJson(self::HOTE."/api/equipe/{$bintou->id}", ['role_ids' => $this->roles('caissier')])
            ->assertOk()->assertJsonPath('data.roles.0.nom', 'caissier');
    }

    /**
     * Deux administrateurs dont les droits ont été vérifiés avant que l'un
     * ne retire le rôle à l'autre : le second geste, relu sous le verrou,
     * est refusé. Ici en séquence, sur PostgreSQL en vraie course.
     */
    public function test_la_boutique_garde_toujours_un_administrateur_actif(): void
    {
        $adjoua = $this->membre('adjoua@maquis-du-port.test');
        $bintou = $this->deuxiemeAdministrateur();
        $modifier = app(ModifierMembreEquipe::class);

        $modifier->executer($bintou, null, $this->roles('caissier'), $this->utilisateur('adjoua@maquis-du-port.test'), null);

        try {
            $modifier->executer($adjoua, null, $this->roles('operateur'), $this->utilisateur('bintou@maquis-du-port.test'), null);
            $this->fail('Le dernier administrateur actif a perdu son rôle.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Cet établissement doit conserver au moins un administrateur actif.'], $exception->errors()['role_ids']);
        }

        try {
            app(ChangerStatutMembreEquipe::class)->executer($adjoua, StatutMembre::Suspendu, $this->utilisateur('bintou@maquis-du-port.test'), null);
            $this->fail('Le dernier administrateur actif a été désactivé.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Cet établissement doit conserver au moins un administrateur actif.'], $exception->errors()['statut']);
        }

        $this->assertSame(['admin_etablissement'], $adjoua->refresh()->roles->pluck('nom')->all());
        $this->assertSame(StatutMembre::Actif, $adjoua->statut);
    }

    public function test_un_changement_de_roles_sapplique_des_la_requete_suivante(): void
    {
        $yao = $this->membre('yao@maquis-du-port.test');
        $adjoua = $this->utilisateur('adjoua@maquis-du-port.test');
        $modifier = app(ModifierMembreEquipe::class);

        $this->en('yao@maquis-du-port.test')->getJson(self::HOTE.'/api/demandes-whatsapp')->assertOk();

        $modifier->executer($yao, null, $this->roles('caissier'), $adjoua, null);
        $this->getJson(self::HOTE.'/api/demandes-whatsapp')->assertForbidden();
        $this->assertSame(['encaisser', 'voir_catalogue', 'voir_commandes'], $this->getJson(self::HOTE.'/api/moi')->json('permissions'));

        $modifier->executer($yao, null, $this->roles('operateur', 'caissier'), $adjoua, null);
        $this->getJson(self::HOTE.'/api/demandes-whatsapp')->assertOk();
    }

    /**
     * Aujourd'hui, deux barrières : la désactivation efface ses sessions
     * (en production, sa requête suivante n'est plus authentifiée), et
     * User::peut comme /api/moi relisent le statut à chaque requête. Ici
     * les sessions de test sont en mémoire : c'est la seconde qui répond.
     */
    public function test_un_membre_desactive_perd_lacces_a_la_requete_suivante(): void
    {
        $yao = $this->membre('yao@maquis-du-port.test');
        $this->en('yao@maquis-du-port.test')->getJson(self::HOTE.'/api/commandes')->assertOk();

        app(ChangerStatutMembreEquipe::class)->executer($yao, StatutMembre::Suspendu, $this->utilisateur('adjoua@maquis-du-port.test'), null);

        $this->getJson(self::HOTE.'/api/commandes')->assertForbidden();
        $this->getJson(self::HOTE.'/api/moi')->assertForbidden();
        $this->postJson(self::HOTE.'/api/commandes/manuelles', [
            'canal' => 'telephone', 'lignes' => [['produit_id' => 1, 'quantite' => 1]], 'cle_idempotence' => (string) Str::uuid(),
        ])->assertForbidden();
    }
}
