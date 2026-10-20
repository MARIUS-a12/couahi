<?php

namespace Database\Seeders;

use App\Models\Etablissement;
use App\Models\Role;
use App\Models\User;
use App\Services\Utilisateurs\CreerRattachementUtilisateur;
use Illuminate\Database\Seeder;

/**
 * Cinq comptes de démo, un mot de passe identique en clair (données de
 * démo uniquement, jamais en production) : voir la table de l'Étape 1.
 * S'appuie sur les établissements déjà créés par EtablissementsDemoSeeder
 * (slugs "chez-awa", "maquis-du-port" et "wax-adjame") — à exécuter après
 * lui.
 */
class UtilisateursDemoSeeder extends Seeder
{
    private const MOT_DE_PASSE = 'motdepasse';

    public function run(): void
    {
        $chezAwa = Etablissement::where('slug', 'chez-awa')->firstOrFail();
        $maquisDuPort = Etablissement::where('slug', 'maquis-du-port')->firstOrFail();
        $waxAdjame = Etablissement::where('slug', 'wax-adjame')->firstOrFail();

        $roleAdminEtablissement = Role::where('nom', 'admin_etablissement')->value('id');
        $roleOperateur = Role::where('nom', 'operateur')->value('id');

        $creerRattachement = app(CreerRattachementUtilisateur::class);

        $marius = User::factory()->create([
            'name' => 'Marius Kouame',
            'email' => 'super@plateforme.test',
            'password' => self::MOT_DE_PASSE,
            'mot_de_passe_defini' => true,
        ]);
        // est_super_admin n'est pas fillable (voir User) : assignation
        // directe, volontairement, pas via une mise à jour de masse.
        $marius->est_super_admin = true;
        $marius->save();

        $awa = User::factory()->create([
            'name' => 'Awa Traoré',
            'email' => 'awa@chez-awa.test',
            'password' => self::MOT_DE_PASSE,
            'mot_de_passe_defini' => true,
        ]);
        $creerRattachement->executer($awa, $chezAwa, $roleAdminEtablissement);

        $yao = User::factory()->create([
            'name' => 'Yao Kouadio',
            'email' => 'yao@maquis-du-port.test',
            'password' => self::MOT_DE_PASSE,
            'mot_de_passe_defini' => true,
        ]);
        $creerRattachement->executer($yao, $maquisDuPort, $roleOperateur);

        // Admin du restaurant : sans elle, impossible de tester le
        // formulaire produit en mode interrupteur avec un compte qui a
        // gerer_catalogue — Awa est sur une boutique, Yao n'est qu'opérateur.
        $adjoua = User::factory()->create([
            'name' => 'Adjoua Kone',
            'email' => 'adjoua@maquis-du-port.test',
            'password' => self::MOT_DE_PASSE,
            'mot_de_passe_defini' => true,
        ]);
        $creerRattachement->executer($adjoua, $maquisDuPort, $roleAdminEtablissement);

        $fatou = User::factory()->create([
            'name' => 'Fatou Diallo',
            'email' => 'fatou@wax-adjame.test',
            'password' => self::MOT_DE_PASSE,
            'mot_de_passe_defini' => true,
        ]);
        $creerRattachement->executer($fatou, $waxAdjame, $roleAdminEtablissement);
    }
}
