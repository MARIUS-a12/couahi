<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatutMembre;
use App\Http\Controllers\Controller;
use App\Http\Requests\Equipe\ChangerStatutMembreRequest;
use App\Http\Requests\Equipe\StoreMembreEquipeRequest;
use App\Http\Requests\Equipe\UpdateMembreEquipeRequest;
use App\Http\Resources\MembreEquipeResource;
use App\Models\EtablissementUtilisateur;
use App\Models\Role;
use App\Services\Equipe\ChangerStatutMembreEquipe;
use App\Services\Equipe\CreerMembreEquipe;
use App\Services\Equipe\GenererNouveauCodeAccesMembre;
use App\Services\Equipe\ModifierMembreEquipe;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Étape 10 — équipe de l'établissement courant. EtablissementUtilisateur
 * n'a volontairement pas le scope global AppartientAEtablissement (voir sa
 * docblock) : verifierAppartenance() ci-dessous joue ce rôle ici, et répond
 * 404 plutôt que 403 sur un membre d'un AUTRE établissement, pour ne pas
 * révéler qu'une telle ligne existe.
 */
class MembreEquipeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', EtablissementUtilisateur::class);

        $membres = EtablissementUtilisateur::query()
            ->where('etablissement_id', $this->etablissementCourantId())
            ->with(['utilisateur', 'roles'])
            ->orderBy('created_at')
            ->get();

        return MembreEquipeResource::collection($membres);
    }

    public function roles(): JsonResponse
    {
        Gate::authorize('viewAny', EtablissementUtilisateur::class);

        // Lot F : avec leurs permissions, pour que l'écran dise en direct
        // ce que des rôles cochés ensemble permettent, d'après la base et
        // pas d'une liste recopiée. Dans l'ordre du seeder : Administrateur
        // d'abord.
        $roles = Role::where('nom', '!=', 'super_admin')
            ->with('permissions:id,nom')
            ->orderBy('id')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'nom' => $role->nom,
                'libelle' => $role->libelle,
                'permissions' => $role->permissions->pluck('nom')->sort()->values(),
            ]);

        return response()->json(['data' => $roles]);
    }

    public function store(StoreMembreEquipeRequest $request, CreerMembreEquipe $service): JsonResponse
    {
        $resultat = $service->executer(
            etablissement: app(ContexteEtablissement::class)->obtenir(),
            nom: $request->validated('nom'),
            email: $request->validated('email'),
            roleIds: $request->validated('role_ids'),
            acteur: $request->user(),
            adresseIp: $request->ip(),
        );

        return (new MembreEquipeResource($resultat->membre->load(['utilisateur', 'roles'])))
            ->additional(['code_activation' => $resultat->codeActivation])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateMembreEquipeRequest $request, EtablissementUtilisateur $membre, ModifierMembreEquipe $service): MembreEquipeResource
    {
        $this->verifierAppartenance($membre);

        $membre = $service->executer(
            membre: $membre,
            nom: $request->validated('nom'),
            roleIds: $request->validated('role_ids'),
            acteur: $request->user(),
            adresseIp: $request->ip(),
        );

        return new MembreEquipeResource($membre->load(['utilisateur', 'roles']));
    }

    public function changerStatut(ChangerStatutMembreRequest $request, EtablissementUtilisateur $membre, ChangerStatutMembreEquipe $service): MembreEquipeResource
    {
        $this->verifierAppartenance($membre);

        $membre = $service->executer(
            membre: $membre,
            nouveauStatut: StatutMembre::from($request->validated('statut')),
            acteur: $request->user(),
            adresseIp: $request->ip(),
        );

        return new MembreEquipeResource($membre->load(['utilisateur', 'roles']));
    }

    public function genererCodeActivation(Request $request, EtablissementUtilisateur $membre, GenererNouveauCodeAccesMembre $service): JsonResponse
    {
        $this->verifierAppartenance($membre);
        Gate::authorize('genererCodeActivation', $membre);

        $code = $service->executer($membre, $request->user(), $request->ip());

        return response()->json(['code_activation' => $code]);
    }

    private function verifierAppartenance(EtablissementUtilisateur $membre): void
    {
        abort_unless($membre->etablissement_id === $this->etablissementCourantId(), 404);
    }

    private function etablissementCourantId(): int
    {
        return app(ContexteEtablissement::class)->id();
    }
}
