<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatutMembre;
use App\Http\Controllers\Controller;
use App\Http\Resources\AbonnementResource;
use App\Models\Domaine;
use App\Models\Etablissement;
use App\Models\EtablissementUtilisateur;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    /**
     * Connexion établissement-scopée : plusieurs vérifications distinctes
     * (identifiants, puis soit l'appartenance à l'établissement du domaine
     * courant soit le statut super-admin sur l'hôte dédié) mais UN SEUL
     * message d'échec, toujours le même, pour ne jamais révéler laquelle a
     * échoué — voir echouer().
     */
    public function store(Request $request): JsonResponse
    {
        $identifiants = $request->validate([
            'email' => ['required', 'string'],
            'mot_de_passe' => ['required', 'string'],
        ]);

        $etablissement = $this->resoudreEtablissementConnexion($request);

        // Étape 10 : l'email n'est plus unique globalement (voir migration
        // "preparer_utilisateurs_pour_equipe") — le même email peut exister
        // pour deux comptes distincts dans deux établissements différents.
        // Sur un sous-domaine d'établissement, on résout donc TOUJOURS le
        // compte via l'appartenance à CET établissement, jamais un simple
        // where('email', ...) global qui serait ambigu entre les deux.
        $utilisateur = $etablissement === null
            ? User::where('email', $identifiants['email'])->first()
            : User::whereHas('appartenances', fn ($requete) => $requete->where('etablissement_id', $etablissement->id))
                ->where('email', $identifiants['email'])
                ->first();

        // Correctif activation par code (Étape 10) : un compte pas encore
        // activé ne peut PAS se connecter, même avec un mot de passe
        // correct (il n'y en a de toute façon jamais eu de communiqué à
        // personne, voir CreerMembreEquipe) — vérifié AVANT le mot de
        // passe, et avec un message DIFFÉRENT des autres échecs : contrairement
        // à eux, celui-ci n'a rien à cacher, il doit au contraire orienter
        // vers /admin/activation.
        if ($utilisateur !== null && ! $utilisateur->mot_de_passe_defini) {
            throw ValidationException::withMessages([
                'email' => ["Ce compte n'est pas encore activé. Utilisez le code d'activation qui vous a été transmis."],
            ]);
        }

        if ($utilisateur === null || ! Hash::check($identifiants['mot_de_passe'], $utilisateur->password)) {
            $this->echouer();
        }

        if ($etablissement === null) {
            // Hôte du super-admin : aucun établissement à vérifier, seul son
            // propre statut compte (voir User::estSuperAdmin()).
            if (! $utilisateur->estSuperAdmin()) {
                $this->echouer();
            }
        } else {
            $membre = $this->trouverAppartenance($utilisateur, $etablissement);

            if ($membre === null || $membre->statut !== StatutMembre::Actif || ! $etablissement->estActif()) {
                $this->echouer();
            }
        }

        // Auth::login() résout le guard par défaut, qui peut être "sanctum"
        // (RequestGuard, sans session) selon config/auth.php : le guard "web"
        // est explicite ici pour être sûr de créer une vraie session, quel
        // que soit le guard par défaut de l'application.
        Auth::guard('web')->login($utilisateur);
        $request->session()->regenerate();
        $utilisateur->update(['derniere_connexion_a' => now()]);

        return response()->json([
            'utilisateur' => [
                'id' => $utilisateur->id,
                'nom' => $utilisateur->name,
                'email' => $utilisateur->email,
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    /**
     * Rôle et permissions DANS l'établissement courant — jamais globalement.
     * Sur l'hôte du super-admin, il n'y a pas de contexte d'établissement :
     * son rôle et ses permissions viennent directement du rôle global
     * `super_admin`, pas d'une appartenance (il n'en a aucune).
     */
    public function moi(Request $request): JsonResponse
    {
        $utilisateur = $request->user();
        $etablissement = app(ContexteEtablissement::class)->obtenir();

        if ($etablissement === null) {
            abort_unless($utilisateur->estSuperAdmin(), 403, "Aucun établissement courant, et cet utilisateur n'est pas super-admin.");

            $role = Role::where('nom', 'super_admin')->with('permissions')->firstOrFail();

            return $this->reponseMoi($utilisateur, $role, null);
        }

        $membre = $this->trouverAppartenance($utilisateur, $etablissement)?->loadMissing('role.permissions');

        if ($membre === null || $membre->statut !== StatutMembre::Actif) {
            abort(403, 'Aucune appartenance active pour cet utilisateur ici.');
        }

        return $this->reponseMoi($utilisateur, $membre->role, $etablissement);
    }

    /**
     * etablissement est transmis explicitement plutôt que relu depuis le
     * contexte : sur l'hôte du super-admin, ce dernier n'en a aucun (voir
     * moi() ci-dessus), et cette méthode doit refléter cette absence sans
     * avoir à connaître elle-même ce cas particulier.
     */
    private function reponseMoi(User $utilisateur, Role $role, ?Etablissement $etablissement): JsonResponse
    {
        return response()->json([
            'utilisateur' => [
                'id' => $utilisateur->id,
                'nom' => $utilisateur->name,
                'email' => $utilisateur->email,
            ],
            'etablissement' => $etablissement === null ? null : [
                'id' => $etablissement->id,
                'nom' => $etablissement->nom,
                // Le frontend en a besoin pour savoir quel bloc stock
                // afficher au formulaire produit (quantité vs interrupteur),
                // avant même qu'un seul produit existe pour le déduire.
                'type' => $etablissement->type,
                // Étape 8 : la carte de statut de la barre latérale ("Tout
                // fonctionne correctement") reflète CET état réel, jamais
                // une valeur codée en dur.
                'statut' => $etablissement->statut,
            ],
            'role' => $role->nom,
            // Étape 10 : le libellé affiché sous le nom de la boutique
            // ("Espace Administrateur"...) vient de la base — voir
            // RolesEtPermissionsSeeder::LIBELLES_ESPACE — jamais d'une liste
            // codée en dur côté frontend (voir BarreLaterale).
            'role_libelle_espace' => $role->libelle_espace,
            'permissions' => $role->permissions->pluck('nom')->values(),
            // Étape 12 : pour TOUT membre (la carte "Boutique en ligne" de
            // la barre latérale doit refléter une vitrine coupée pour
            // impayé) ; c'est le frontend qui réserve le bandeau
            // d'avertissement à "gerer_parametres", seuls à pouvoir ouvrir
            // la page vers laquelle il mène.
            'abonnement' => $etablissement?->abonnement === null ? null : new AbonnementResource($etablissement->abonnement),
        ]);
    }

    /**
     * Résout l'établissement du domaine courant SANS vérifier son statut :
     * un établissement inactif doit échouer par la même voie (echouer())
     * que les autres causes, pas par un 404 distinct qui la révélerait.
     * Le super-admin (hôte dédié) n'a pas d'établissement.
     */
    private function resoudreEtablissementConnexion(Request $request): ?Etablissement
    {
        $hote = $request->getHost();

        if ($hote === config('tenancy.hote_super_admin')) {
            return null;
        }

        $etablissement = Domaine::pourHote($hote);

        if ($etablissement === null) {
            abort(404);
        }

        return $etablissement;
    }

    private function trouverAppartenance(User $utilisateur, Etablissement $etablissement): ?EtablissementUtilisateur
    {
        return EtablissementUtilisateur::query()
            ->where('etablissement_id', $etablissement->id)
            ->where('utilisateur_id', $utilisateur->id)
            ->first();
    }

    private function echouer(): never
    {
        throw ValidationException::withMessages([
            'email' => ['Identifiants invalides.'],
        ]);
    }
}
