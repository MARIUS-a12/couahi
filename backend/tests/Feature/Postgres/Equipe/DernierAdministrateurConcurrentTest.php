<?php

namespace Tests\Feature\Postgres\Equipe;

use App\Enums\StatutMembre;
use App\Models\Etablissement;
use App\Models\EtablissementUtilisateur;
use App\Models\Role;
use App\Models\User;
use App\Services\Utilisateurs\CreerRattachementUtilisateur;
use Database\Seeders\RolesEtPermissionsSeeder;
use Tests\ConcurrenceTestCase;

/**
 * Le verrou de GardeDernierAdministrateurActif sous une VRAIE course : deux
 * administrateurs se retirent le rôle l'un l'autre au même instant, chacun
 * dans son processus. Sans verrou, chacun voit « il reste l'autre » et la
 * boutique n'a plus d'administrateur. Plusieurs manches : une seule ne
 * suffirait pas à prouver que les deux processus se sont bien croisés.
 */
class DernierAdministrateurConcurrentTest extends ConcurrenceTestCase
{
    private const MANCHES = 5;

    public function test_deux_administrateurs_qui_se_retirent_le_role_au_meme_instant_en_laissent_un(): void
    {
        $this->seed(RolesEtPermissionsSeeder::class);
        $admin = Role::where('nom', 'admin_etablissement')->value('id');
        $caissier = Role::where('nom', 'caissier')->value('id');
        $resultats = [];

        for ($manche = 1; $manche <= self::MANCHES; $manche++) {
            $etablissement = $this->nettoyerAvec(Etablissement::factory()->boutique()->create());
            [$a, $b] = array_map(function (string $nom) use ($etablissement, $admin) {
                $utilisateur = User::factory()->create(['name' => $nom]);

                return app(CreerRattachementUtilisateur::class)->executer($utilisateur, $etablissement, [$admin]);
            }, ['A', 'B']);
            $depart = sprintf('%.6F', microtime(true) + 3);

            $sorties = array_map($this->decoderResultat(...), $this->executerEnParallele([
                ['concurrence:modifier-roles-membre', '--membre='.$b->id, '--acteur='.$a->utilisateur_id, '--roles='.$caissier, '--depart='.$depart],
                ['concurrence:modifier-roles-membre', '--membre='.$a->id, '--acteur='.$b->utilisateur_id, '--roles='.$caissier, '--depart='.$depart],
            ]));

            $administrateursActifs = EtablissementUtilisateur::where('etablissement_id', $etablissement->id)
                ->where('statut', StatutMembre::Actif)
                ->whereHas('roles', fn ($requete) => $requete->where('nom', 'admin_etablissement'))
                ->count();
            $resultats[] = ['manche' => $manche, 'administrateurs_actifs' => $administrateursActifs, 'processus' => $sorties];

            $this->assertSame(1, $administrateursActifs, "manche {$manche} : il reste exactement un administrateur");
            $this->assertSame(1, count(array_filter(array_column($sorties, 'ok'))), "manche {$manche} : un seul des deux gestes passe");
        }

        fwrite(STDERR, "\n[dernier-administrateur] ".self::MANCHES." manches, deux retraits simultanés chacune :\n".$this->resume($resultats)."\n");
    }
}
