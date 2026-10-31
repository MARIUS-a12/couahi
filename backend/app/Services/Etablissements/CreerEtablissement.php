<?php

namespace App\Services\Etablissements;

use App\Models\Etablissement;
use App\Models\ParametreSite;
use App\Models\Role;
use App\Models\User;
use App\Services\Utilisateurs\CreerRattachementUtilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Les cinq étapes (établissement, domaine, paramètres, administrateur,
 * rattachement) dans une seule transaction : si l'une échoue — l'email de
 * l'administrateur déjà pris en est le cas le plus probable, malgré la
 * validation en amont, en cas de double soumission concurrente — aucune ne
 * doit rester à moitié faite en base.
 */
class CreerEtablissement
{
    public function __construct(
        private readonly CreerRattachementUtilisateur $creerRattachement,
    ) {}

    public function executer(
        string $nom,
        string $type,
        string $sousDomaine,
        ?string $email,
        ?string $telephone,
        ?string $couleurAccent,
        string $nomAdministrateur,
        string $emailAdministrateur,
    ): ResultatCreationEtablissement {
        return DB::transaction(function () use (
            $nom,
            $type,
            $sousDomaine,
            $email,
            $telephone,
            $couleurAccent,
            $nomAdministrateur,
            $emailAdministrateur,
        ) {
            $etablissement = Etablissement::create([
                'nom' => $nom,
                'slug' => $sousDomaine,
                'type' => $type,
                'email' => $email,
                'telephone' => $telephone,
                'couleur_accent' => $couleurAccent,
            ]);

            // Via la relation, pas Domaine::create() : etablissement_id
            // n'est délibérément pas dans son $fillable (Domaine n'est pas
            // tenant-scoped), un create() direct l'ignorerait donc
            // silencieusement et l'INSERT échouerait (colonne NOT NULL).
            $etablissement->domaines()->create([
                'hote' => $sousDomaine.config('tenancy.suffixe_domaine'),
                'type' => 'sous_domaine',
                'est_principal' => true,
                'verifie_le' => now(),
                'statut' => 'actif',
            ]);

            ParametreSite::create([
                'etablissement_id' => $etablissement->id,
            ]);

            $roleAdmin = Role::where('nom', 'admin_etablissement')->value('id');

            // Un mot de passe aléatoire, jamais choisi ni transmis par le
            // super-admin : ni lui ni personne d'autre ne doit pouvoir le
            // deviner ou le réutiliser d'un établissement à l'autre. Flux
            // différent de CreerMembreEquipe (correctif activation par
            // code) : ce mot de passe EST immédiatement utilisable par
            // l'administrateur qui le reçoit, pas d'activation à part.
            $motDePasseGenere = Str::password(14);

            $administrateur = User::create([
                'name' => $nomAdministrateur,
                'email' => $emailAdministrateur,
                'password' => $motDePasseGenere,
                'mot_de_passe_defini' => true,
            ]);

            $this->creerRattachement->executer($administrateur, $etablissement, [$roleAdmin]);

            return new ResultatCreationEtablissement($etablissement, $motDePasseGenere);
        });
    }
}
