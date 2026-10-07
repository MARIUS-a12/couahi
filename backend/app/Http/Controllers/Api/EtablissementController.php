<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatutEtablissement;
use App\Exceptions\EtablissementAvecCommandesException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEtablissementRequest;
use App\Http\Requests\SupprimerEtablissementRequest;
use App\Http\Resources\EtablissementDetailResource;
use App\Http\Resources\EtablissementResource;
use App\Models\Domaine;
use App\Models\Etablissement;
use App\Services\Etablissements\CreerEtablissement;
use App\Services\Etablissements\SupprimerEtablissement;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Réservé au super-admin (voir EtablissementPolicy). Volontairement hors du
 * groupe resoudre.etablissement — ces routes n'ont justement pas
 * d'établissement courant, et Etablissement n'est pas un modèle
 * tenant-scopé.
 */
class EtablissementController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Etablissement::class);

        // withCount sur une relation vers un modèle tenant-scopé (Produit)
        // appliquerait sinon son scope global à la sous-requête de comptage
        // — et lèverait, faute de contexte ambiant sur l'hôte du
        // super-admin. pourTousEtablissements() dans la closure : ce
        // comptage porte volontairement sur TOUS les établissements à la
        // fois, un par ligne — SEULE exception restante à "jamais
        // pourTousEtablissements() depuis une route web" (voir
        // DefinirEtablissementCible) : cette liste n'a justement PAS un
        // établissement "cible" unique sur lequel poser le contexte, et la
        // sous-requête de comptage reste de toute façon bornée par la
        // jointure sur chaque etablissements.id — ce n'est pas la même
        // classe de risque qu'un contournement sur UN établissement précis.
        $etablissements = Etablissement::query()
            ->with('domaines')
            ->withCount(['produits' => fn ($requete) => $requete->pourTousEtablissements()])
            ->orderBy('nom')
            ->get();

        return EtablissementResource::collection($etablissements);
    }

    public function store(StoreEtablissementRequest $request, CreerEtablissement $creerEtablissement, ContexteEtablissement $contexte): JsonResponse
    {
        $resultat = $creerEtablissement->executer(
            nom: $request->validated('nom'),
            type: $request->validated('type'),
            sousDomaine: Str::lower($request->validated('sous_domaine')),
            email: $request->validated('email'),
            telephone: $request->validated('telephone'),
            couleurAccent: $request->validated('couleur_accent'),
            nomAdministrateur: $request->validated('nom_administrateur'),
            emailAdministrateur: $request->validated('email_administrateur'),
        );

        // Aucun {etablissement} dans l'URL de cette route (l'id n'existe pas
        // encore avant la création) : DefinirEtablissementCible ne s'applique
        // donc pas ici. On pose le contexte nous-mêmes sur l'établissement
        // qu'on vient de créer, pour que EtablissementDetailResource (qui
        // compte ses commandes, forcément 0 ici) se filtre normalement au
        // lieu de devoir contourner le scope — puis on le relâche, comme le
        // ferait ce middleware pour toute autre route super-admin.
        $contexte->definir($resultat->etablissement);

        try {
            return (new EtablissementDetailResource($resultat->etablissement->load(['domaines', 'appartenances.utilisateur', 'appartenances.role'])))
                ->additional(['mot_de_passe_genere' => $resultat->motDePasseGenere])
                ->response()
                ->setStatusCode(201);
        } finally {
            $contexte->definir(null);
        }
    }

    public function show(Etablissement $etablissement): EtablissementDetailResource
    {
        Gate::authorize('view', $etablissement);

        $etablissement->load(['domaines', 'appartenances.utilisateur', 'appartenances.role']);

        return new EtablissementDetailResource($etablissement);
    }

    /**
     * Rend le site public inaccessible (ResoudreEtablissement, voir sa
     * docblock) et bloque toute nouvelle connexion (SessionController) —
     * sans rien supprimer. Jamais de suppression d'établissement dans
     * cette interface.
     */
    public function suspendre(Etablissement $etablissement): EtablissementDetailResource
    {
        Gate::authorize('update', $etablissement);

        $etablissement->update(['statut' => StatutEtablissement::Inactif]);

        return new EtablissementDetailResource($etablissement->load(['domaines', 'appartenances.utilisateur', 'appartenances.role']));
    }

    public function reactiver(Etablissement $etablissement): EtablissementDetailResource
    {
        Gate::authorize('update', $etablissement);

        $etablissement->update(['statut' => StatutEtablissement::Actif]);

        return new EtablissementDetailResource($etablissement->load(['domaines', 'appartenances.utilisateur', 'appartenances.role']));
    }

    /**
     * Destruction définitive — voir SupprimerEtablissement. Bloquée (422)
     * tant qu'il reste la moindre commande ; le bouton correspondant est de
     * toute façon absent côté interface dans ce cas, cette vérification est
     * la véritable barrière, pas un simple confort d'affichage.
     */
    public function destroy(SupprimerEtablissementRequest $request, Etablissement $etablissement, SupprimerEtablissement $supprimerEtablissement): JsonResponse
    {
        Gate::authorize('delete', $etablissement);

        try {
            $supprimerEtablissement->executer($etablissement);
        } catch (EtablissementAvecCommandesException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'nombre_commandes' => $exception->nombreCommandes,
            ], 422);
        }

        return response()->json(null, 204);
    }

    /**
     * Vérification en direct depuis le formulaire de création, avant même
     * de soumettre : reprend exactement les mêmes règles que
     * StoreEtablissementRequest (format, mots réservés, unicité), pour ne
     * jamais dire "disponible" à un sous-domaine que la soumission
     * refuserait ensuite.
     */
    public function verifierSousDomaine(Request $request): JsonResponse
    {
        Gate::authorize('create', Etablissement::class);

        $valeur = Str::lower(trim((string) $request->query('valeur', '')));

        $disponible = $valeur !== ''
            && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $valeur) === 1
            && ! in_array($valeur, StoreEtablissementRequest::SOUS_DOMAINES_RESERVES, true)
            && ! Etablissement::where('slug', $valeur)->exists()
            && ! Domaine::where('hote', $valeur.config('tenancy.suffixe_domaine'))->exists();

        return response()->json(['disponible' => $disponible]);
    }
}
