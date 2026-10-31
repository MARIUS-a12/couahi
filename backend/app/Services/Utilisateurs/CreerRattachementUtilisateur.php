<?php

namespace App\Services\Utilisateurs;

use App\Enums\StatutMembre;
use App\Models\Etablissement;
use App\Models\EtablissementUtilisateur;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * SEUL point de création d'un rattachement utilisateur ↔ établissement.
 * Un compte appartient à UN SEUL établissement — quelqu'un travaillant dans
 * deux boutiques a deux comptes distincts, jamais un même compte rattaché
 * deux fois (voir la contrainte UNIQUE sur etablissement_utilisateurs.
 * utilisateur_id). Ce fait est représenté à deux endroits, qui doivent donc
 * TOUJOURS être écrits ensemble, jamais l'un sans l'autre ni par deux
 * services différents :
 * - la ligne etablissement_utilisateurs elle-même et ses rôles, source de
 *   vérité des permissions et du statut (voir User::peut(), SessionController) ;
 * - users.etablissement_id, colonne dénormalisée qui ne sert qu'à
 *   UNIQUE(etablissement_id, email) sur "users" (voir la migration
 *   "ajouter_etablissement_id_a_users_table") — jamais relue pour une
 *   décision de permission ou de rôle.
 *
 * CreerEtablissement, CreerMembreEquipe et UtilisateursDemoSeeder appellent
 * tous ce service plutôt que d'écrire l'un ou l'autre eux-mêmes.
 */
class CreerRattachementUtilisateur
{
    public function executer(
        User $utilisateur,
        Etablissement $etablissement,
        array $roleIds,
        StatutMembre $statut = StatutMembre::Actif,
    ): EtablissementUtilisateur {
        return DB::transaction(function () use ($utilisateur, $etablissement, $roleIds, $statut) {
            $rattachement = EtablissementUtilisateur::create([
                'etablissement_id' => $etablissement->id,
                'utilisateur_id' => $utilisateur->id,
                'statut' => $statut->value,
            ]);
            $rattachement->roles()->attach($roleIds);

            $utilisateur->update(['etablissement_id' => $etablissement->id]);

            return $rattachement;
        });
    }
}
