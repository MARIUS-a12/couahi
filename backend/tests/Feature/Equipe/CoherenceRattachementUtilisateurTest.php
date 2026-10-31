<?php

namespace Tests\Feature\Equipe;

use App\Models\Etablissement;
use App\Models\Role;
use App\Models\User;
use App\Services\Utilisateurs\CreerRattachementUtilisateur;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cohérence entre users.etablissement_id (colonne dénormalisée) et
 * etablissement_utilisateurs (source de vérité du rattachement) — voir
 * CreerRattachementUtilisateur, seul point qui écrit les deux ensemble, et
 * la commande "utilisateurs:verifier-rattachements" qui les compare.
 */
class CoherenceRattachementUtilisateurTest extends TestCase
{
    use RefreshDatabase;

    public function test_1_creer_un_rattachement_ecrit_la_colonne_et_le_pivot_de_facon_coherente(): void
    {
        $this->seed(RolesEtPermissionsSeeder::class);
        $etablissement = Etablissement::factory()->create();
        $utilisateur = User::factory()->create(['etablissement_id' => null]);
        $roleId = Role::where('nom', 'operateur')->value('id');

        $rattachement = app(CreerRattachementUtilisateur::class)->executer($utilisateur, $etablissement, [$roleId]);

        $this->assertSame($etablissement->id, $rattachement->etablissement_id);
        $this->assertSame($etablissement->id, $utilisateur->refresh()->etablissement_id);
        $this->assertDatabaseHas('etablissement_utilisateurs', [
            'utilisateur_id' => $utilisateur->id,
            'etablissement_id' => $etablissement->id,
        ]);
        $this->assertSame([$roleId], $rattachement->roles()->pluck('roles.id')->all());
    }

    public function test_2_un_second_rattachement_pour_le_meme_utilisateur_est_refuse_par_la_base(): void
    {
        $this->seed(RolesEtPermissionsSeeder::class);
        $etablissementA = Etablissement::factory()->create();
        $etablissementB = Etablissement::factory()->create();
        $utilisateur = User::factory()->create(['etablissement_id' => null]);
        $roleId = Role::where('nom', 'operateur')->value('id');
        $service = app(CreerRattachementUtilisateur::class);

        $service->executer($utilisateur, $etablissementA, [$roleId]);

        $this->expectException(QueryException::class);

        $service->executer($utilisateur, $etablissementB, [$roleId]);
    }

    public function test_3_la_commande_de_verification_detecte_une_divergence_introduite_en_sql_brut(): void
    {
        $this->seed(RolesEtPermissionsSeeder::class);
        $etablissementA = Etablissement::factory()->create();
        $etablissementB = Etablissement::factory()->create();
        $utilisateur = User::factory()->create(['etablissement_id' => null]);
        $roleId = Role::where('nom', 'operateur')->value('id');

        app(CreerRattachementUtilisateur::class)->executer($utilisateur, $etablissementA, [$roleId]);

        // Divergence volontaire : seule la colonne change, en contournant
        // CreerRattachementUtilisateur — exactement ce qu'un bug, une
        // migration de données ou une écriture directe pourrait produire.
        DB::table('users')->where('id', $utilisateur->id)->update(['etablissement_id' => $etablissementB->id]);

        $code = Artisan::call('utilisateurs:verifier-rattachements');
        $sortie = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString("#{$utilisateur->id}", $sortie);
        $this->assertStringContainsString($utilisateur->email, $sortie);
        $this->assertStringContainsString((string) $etablissementB->id, $sortie);
        $this->assertStringContainsString((string) $etablissementA->id, $sortie);
    }

    public function test_4_la_commande_de_verification_ne_signale_rien_quand_tout_est_coherent(): void
    {
        $this->seed();

        $code = Artisan::call('utilisateurs:verifier-rattachements');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Aucune divergence', Artisan::output());
    }
}
