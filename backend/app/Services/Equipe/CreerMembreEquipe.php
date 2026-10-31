<?php

namespace App\Services\Equipe;

use App\Models\Etablissement;
use App\Models\JournalAudit;
use App\Models\User;
use App\Services\Activation\GenererCodeActivation;
use App\Services\Utilisateurs\CreerRattachementUtilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crée TOUJOURS une nouvelle ligne User, jamais réutilisée d'un autre
 * établissement : deux comptes du même email dans deux établissements
 * différents sont deux comptes distincts (voir migration
 * "preparer_utilisateurs_pour_equipe", qui retire l'unicité globale de
 * users.email). L'unicité "au sein de CET établissement" est vérifiée en
 * amont par StoreMembreEquipeRequest.
 *
 * Correctif activation par code — ne pose plus de mot de passe connu de
 * l'administrateur : celui écrit ici est une valeur inutilisable, jamais
 * communiquée à personne, et mot_de_passe_defini reste à faux (valeur par
 * défaut) — la connexion est refusée indépendamment de ce mot de passe tant
 * que l'employé n'a pas lui-même activé son compte (voir
 * SessionController::store() et GenererCodeActivation/ActiverCompte).
 */
class CreerMembreEquipe
{
    public function __construct(
        private readonly CreerRattachementUtilisateur $creerRattachement,
        private readonly GenererCodeActivation $genererCodeActivation,
    ) {}

    public function executer(
        Etablissement $etablissement,
        string $nom,
        string $email,
        array $roleIds,
        User $acteur,
        ?string $adresseIp,
    ): ResultatCreationMembreEquipe {
        return DB::transaction(function () use ($etablissement, $nom, $email, $roleIds, $acteur, $adresseIp) {
            $utilisateur = User::create([
                'name' => $nom,
                'email' => $email,
                'password' => Str::password(32),
                'mot_de_passe_defini' => false,
            ]);

            $membre = $this->creerRattachement->executer($utilisateur, $etablissement, ModifierMembreEquipe::normaliser($roleIds));

            $code = $this->genererCodeActivation->executer($utilisateur, $etablissement, $acteur, $adresseIp);

            JournalAudit::create([
                'utilisateur_id' => $acteur->id,
                'action' => 'equipe_membre_cree',
                'details' => [
                    'membre_id' => $utilisateur->id,
                    'email' => $email,
                    'roles' => $membre->roles()->pluck('nom')->all(),
                ],
                'adresse_ip' => $adresseIp,
            ]);

            return new ResultatCreationMembreEquipe($membre, $code);
        });
    }
}
