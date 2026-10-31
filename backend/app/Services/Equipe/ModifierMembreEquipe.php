<?php

namespace App\Services\Equipe;

use App\Models\EtablissementUtilisateur;
use App\Models\JournalAudit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change le nom et/ou les rôles d'un membre — jamais son email (voir
 * StoreMembreEquipeRequest/UpdateMembreEquipeRequest : ce formulaire ne le
 * propose pas). Porte les garde-fous serveur qui dépendent de la CIBLE
 * et du changement demandé, pas seulement de qui demande (ça, c'est
 * EtablissementUtilisateurPolicy::update) :
 * - un administrateur ne peut pas changer ses propres rôles ;
 * - retirer admin_etablissement au dernier administrateur actif de
 *   l'établissement est refusé, même en cas de course (voir
 *   GardeDernierAdministrateurActif).
 *
 * Lot F : Administrateur a déjà toutes les permissions. Coché avec
 * d'autres rôles (l'écran les grise, une requête forgée peut les envoyer),
 * il reste seul : les autres n'ajouteraient rien et brouilleraient la
 * lecture de l'équipe.
 */
class ModifierMembreEquipe
{
    use GardeDernierAdministrateurActif;

    /**
     * @param  list<int>|null  $roleIds  null : les rôles ne changent pas
     */
    public function executer(
        EtablissementUtilisateur $membre,
        ?string $nom,
        ?array $roleIds,
        User $acteur,
        ?string $adresseIp,
    ): EtablissementUtilisateur {
        return DB::transaction(function () use ($membre, $nom, $roleIds, $acteur, $adresseIp) {
            $this->verrouillerEquipe($membre);
            $membre->load(['roles', 'utilisateur']);

            $rolesActuels = $membre->roles->pluck('id')->sort()->values()->all();
            $nouveauxRoles = $roleIds === null ? null : self::normaliser($roleIds);
            $rolesChangent = $nouveauxRoles !== null && $nouveauxRoles !== $rolesActuels;

            if ($rolesChangent && $membre->utilisateur_id === $acteur->id) {
                throw ValidationException::withMessages([
                    'role_ids' => ['Vous ne pouvez pas modifier vos propres rôles.'],
                ]);
            }

            $resteAdministrateur = $rolesChangent
                && Role::whereKey($nouveauxRoles)->where('nom', 'admin_etablissement')->exists();

            if (
                $rolesChangent
                && $membre->estAdministrateur()
                && ! $resteAdministrateur
                && ! $this->existeAutreAdministrateurActif($membre)
            ) {
                throw ValidationException::withMessages([
                    'role_ids' => ['Cet établissement doit conserver au moins un administrateur actif.'],
                ]);
            }

            if ($nom !== null) {
                $membre->utilisateur->update(['name' => $nom]);
            }

            if ($rolesChangent) {
                $membre->roles()->sync($nouveauxRoles);
            }

            JournalAudit::create([
                'utilisateur_id' => $acteur->id,
                'action' => 'equipe_membre_modifie',
                'details' => array_filter([
                    'membre_id' => $membre->utilisateur_id,
                    'nom' => $nom,
                    'roles_precedents' => $rolesChangent ? $membre->roles->pluck('nom')->all() : null,
                    'roles_nouveaux' => $rolesChangent ? Role::whereKey($nouveauxRoles)->orderBy('id')->pluck('nom')->all() : null,
                ], fn ($valeur) => $valeur !== null),
                'adresse_ip' => $adresseIp,
            ]);

            return $membre->refresh();
        });
    }

    /**
     * Sans doublon, dans l'ordre, et Administrateur seul s'il est coché.
     *
     * @param  list<int>  $roleIds
     * @return list<int>
     */
    public static function normaliser(array $roleIds): array
    {
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        $administrateur = Role::whereKey($roleIds)->where('nom', 'admin_etablissement')->value('id');

        if ($administrateur !== null) {
            return [$administrateur];
        }

        sort($roleIds);

        return $roleIds;
    }
}
