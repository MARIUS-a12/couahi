<?php

namespace Tests\Feature\Etablissements;

use App\Models\Categorie;
use App\Models\Domaine;
use App\Models\Etablissement;
use App\Models\EtablissementUtilisateur;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape 7 — suppression définitive d'un établissement (super-admin) : on ne
 * détruit jamais un historique de ventes, donc bloquée dès qu'il reste une
 * commande. Réutilise les comptes de démo (voir UtilisateursDemoSeeder) :
 * Awa est admin de chez-awa, Marius est le super-admin.
 */
class SupprimerEtablissementApiTest extends TestCase
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

    private function hoteSuperAdmin(): string
    {
        return config('tenancy.hote_super_admin');
    }

    public function test_6_suppression_avec_commande_refusee_et_ne_supprime_rien(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();

        $produit = Produit::factory()->for($chezAwa)->publie()->create([
            'prix' => 1500,
            'quantite_stock' => 5,
            'quantite_reservee' => 0,
        ]);

        $this->depuis('chez-awa.localhost')->postJson('http://chez-awa.localhost:8000/api/vitrine/commandes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'client' => ['nom' => 'Fatou Koné', 'telephone' => '0701020304'],
            'commune' => 'Cocody',
            'quartier' => 'Angré 7e tranche',
            'cle_idempotence' => 'cle-suppression-bloquee',
        ])->assertStatus(201);

        $hote = $this->hoteSuperAdmin();
        $this->connecte($hote, 'super@plateforme.test');

        $reponse = $this->depuis($hote)->deleteJson("http://{$hote}:8000/api/etablissements/{$chezAwa->id}", [
            'nom_confirmation' => $chezAwa->nom,
        ]);

        $reponse->assertStatus(422);
        $this->assertSame(1, $reponse->json('nombre_commandes'));
        $this->assertDatabaseHas('etablissements', ['id' => $chezAwa->id]);
        $this->assertDatabaseHas('produits', ['id' => $produit->id]);
    }

    public function test_7_suppression_sans_commande_supprime_tout(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $categorie = Categorie::factory()->for($chezAwa)->create();
        $produit = Produit::factory()->for($chezAwa)->for($categorie)->create();
        $domaineId = Domaine::where('etablissement_id', $chezAwa->id)->value('id');
        $adminId = User::where('email', 'awa@chez-awa.test')->value('id');

        $hote = $this->hoteSuperAdmin();
        $this->connecte($hote, 'super@plateforme.test');

        $reponse = $this->depuis($hote)->deleteJson("http://{$hote}:8000/api/etablissements/{$chezAwa->id}", [
            'nom_confirmation' => $chezAwa->nom,
        ]);

        $reponse->assertStatus(204);
        $this->assertDatabaseMissing('etablissements', ['id' => $chezAwa->id]);
        $this->assertDatabaseMissing('produits', ['id' => $produit->id]);
        $this->assertDatabaseMissing('categories', ['id' => $categorie->id]);
        $this->assertDatabaseMissing('domaines', ['id' => $domaineId]);
        $this->assertDatabaseMissing('users', ['id' => $adminId]);
        $this->assertDatabaseHas('journaux_audit', ['action' => 'etablissement_supprime']);
    }

    /**
     * Remplace l'ancien test_8 ("utilisateur rattaché à deux établissements
     * survit à la suppression de l'un d'eux") : ce scénario est désormais
     * IMPOSSIBLE à construire — un compte appartient à un seul
     * établissement (voir la migration "unicite_rattachement_par_
     * utilisateur"), quelqu'un travaillant dans deux boutiques a deux
     * comptes distincts. La distinction "exclusif à cet établissement" que
     * SupprimerEtablissement calculait avant de choisir qui effacer est
     * donc devenue un cas qui ne se produit plus jamais : tout utilisateur
     * rattaché à un établissement lui est désormais forcément exclusif (voir
     * test_7, qui couvre déjà la suppression de l'administrateur).
     */
    public function test_8_un_second_rattachement_pour_un_utilisateur_deja_rattache_est_refuse(): void
    {
        $this->seed();
        $maquisDuPort = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
        $awa = User::where('email', 'awa@chez-awa.test')->firstOrFail();

        // Awa est déjà admin de chez-awa (voir UtilisateursDemoSeeder) : un
        // second rattachement, même vers un autre établissement, est refusé.
        $this->expectException(QueryException::class);

        EtablissementUtilisateur::create([
            'etablissement_id' => $maquisDuPort->id,
            'utilisateur_id' => $awa->id,
            'statut' => 'actif',
        ]);
    }

    public function test_9_admin_etablissement_recoit_403_sur_suppression(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();

        $this->connecte('chez-awa.localhost', 'awa@chez-awa.test');

        $reponse = $this->depuis('chez-awa.localhost')->deleteJson("http://chez-awa.localhost:8000/api/etablissements/{$chezAwa->id}", [
            'nom_confirmation' => $chezAwa->nom,
        ]);

        $reponse->assertStatus(403);
        $this->assertDatabaseHas('etablissements', ['id' => $chezAwa->id]);
    }

    public function test_nom_de_confirmation_incorrect_refuse(): void
    {
        $this->seed();
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();

        $hote = $this->hoteSuperAdmin();
        $this->connecte($hote, 'super@plateforme.test');

        $reponse = $this->depuis($hote)->deleteJson("http://{$hote}:8000/api/etablissements/{$chezAwa->id}", [
            'nom_confirmation' => 'Un autre nom',
        ]);

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['nom_confirmation']);
        $this->assertDatabaseHas('etablissements', ['id' => $chezAwa->id]);
    }
}
