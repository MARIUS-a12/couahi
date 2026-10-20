<?php

namespace App\Http\Controllers\Api;

use App\Enums\OperateurMobileMoney;
use App\Http\Controllers\Controller;
use App\Http\Requests\MobileMoney\ConfigurerMobileMoneyRequest;
use App\Http\Resources\MobileMoneyEtablissementResource;
use App\Models\Etablissement;
use App\Services\MobileMoney\ConfigurerMobileMoney;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Étape 13 — même principe à deux chemins que PaiementEtablissementController :
 * commerçant sur son propre établissement ("/parametres/mobile-money",
 * gerer_parametres exigé), super-admin sur n'importe lequel
 * ("/etablissements/{etablissement}/mobile-money"). Un seul service derrière.
 */
class MobileMoneyEtablissementController extends Controller
{
    public function __construct(
        private readonly ConfigurerMobileMoney $service,
    ) {}

    public function show(?Etablissement $etablissement = null): MobileMoneyEtablissementResource
    {
        return new MobileMoneyEtablissementResource($this->resoudreCible($etablissement, ecriture: false));
    }

    public function update(ConfigurerMobileMoneyRequest $request, ?Etablissement $etablissement = null): MobileMoneyEtablissementResource
    {
        $cible = $this->service->configurer(
            etablissement: $this->resoudreCible($etablissement, ecriture: true),
            numero: $request->validated('mm_numero'),
            operateur: OperateurMobileMoney::from($request->validated('mm_operateur')),
            beneficiaire: $request->validated('mm_beneficiaire'),
            utilisateur: $request->user(),
            adresseIp: $request->ip(),
        );

        return new MobileMoneyEtablissementResource($cible);
    }

    public function destroy(Request $request, ?Etablissement $etablissement = null): JsonResponse
    {
        $this->service->retirer($this->resoudreCible($etablissement, ecriture: true), $request->user(), $request->ip());

        return response()->json(null, 204);
    }

    private function resoudreCible(?Etablissement $etablissement, bool $ecriture): Etablissement
    {
        if ($etablissement !== null) {
            Gate::authorize($ecriture ? 'update' : 'view', $etablissement);

            return $etablissement;
        }

        abort_unless(auth()->user()->peut('gerer_parametres'), 403);

        return app(ContexteEtablissement::class)->obtenir();
    }
}
