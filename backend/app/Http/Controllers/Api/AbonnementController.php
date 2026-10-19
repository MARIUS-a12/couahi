<?php

namespace App\Http\Controllers\Api;

use App\Enums\MoyenPaiementAbonnement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Abonnements\EnregistrerPaiementAbonnementRequest;
use App\Http\Requests\Abonnements\ModifierReglagesAbonnementRequest;
use App\Http\Resources\AbonnementResource;
use App\Http\Resources\PaiementAbonnementResource;
use App\Models\Etablissement;
use App\Services\Abonnements\EnregistrerPaiementAbonnement;
use App\Services\Abonnements\ModifierReglagesAbonnement;
use App\Support\Tenancy\ContexteEtablissement;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Étape 12 — même principe à deux chemins que PaiementEtablissementController :
 * - commerçant, sur son propre établissement (résolu par le sous-domaine) :
 *   "/abonnement", LECTURE seule, réservée à "gerer_parametres" ;
 * - super-admin, sur n'importe lequel : "/etablissements/{etablissement}/abonnement*",
 *   lecture, réglages, et enregistrement d'un paiement reçu.
 * Le commerçant ne peut jamais déclarer lui-même un paiement : seul le
 * super-admin (ou, plus tard, CinetPay) le fait.
 */
class AbonnementController extends Controller
{
    public function show(?Etablissement $etablissement = null): JsonResponse
    {
        if ($etablissement !== null) {
            Gate::authorize('view', $etablissement);
        } else {
            abort_unless(auth()->user()->peut('gerer_parametres'), 403);
            $etablissement = app(ContexteEtablissement::class)->obtenir();
        }

        return $this->reponse($etablissement);
    }

    public function update(ModifierReglagesAbonnementRequest $request, Etablissement $etablissement, ModifierReglagesAbonnement $modifier): JsonResponse
    {
        Gate::authorize('update', $etablissement);

        $modifier->executer(
            abonnement: $etablissement->abonnement()->firstOrFail(),
            montantMensuel: (int) $request->validated('montant_mensuel'),
            joursDeGrace: (int) $request->validated('jours_de_grace'),
            utilisateur: $request->user(),
            adresseIp: $request->ip(),
        );

        return $this->reponse($etablissement);
    }

    public function enregistrerPaiement(EnregistrerPaiementAbonnementRequest $request, Etablissement $etablissement, EnregistrerPaiementAbonnement $enregistrer): JsonResponse
    {
        Gate::authorize('update', $etablissement);

        $enregistrer->executer(
            etablissement: $etablissement,
            montant: (int) $request->validated('montant'),
            periodeDebut: CarbonImmutable::parse($request->validated('periode_debut')),
            periodeFin: CarbonImmutable::parse($request->validated('periode_fin')),
            moyen: MoyenPaiementAbonnement::from($request->validated('moyen')),
            reference: $request->validated('reference'),
            enregistrePar: $request->user(),
            adresseIp: $request->ip(),
        );

        return $this->reponse($etablissement)->setStatusCode(201);
    }

    /**
     * Les coordonnées de règlement viennent de la configuration du serveur
     * (voir config/abonnement.php), jamais de la base ni du code.
     */
    private function reponse(Etablissement $etablissement): JsonResponse
    {
        return (new AbonnementResource($etablissement->abonnement()->firstOrFail()))
            ->additional([
                'paiements' => PaiementAbonnementResource::collection(
                    $etablissement->paiementsAbonnement()->latest('paye_le')->latest('id')->get()
                ),
                'reglement' => [
                    'beneficiaire' => config('abonnement.beneficiaire'),
                    'numero_mobile_money' => config('abonnement.numero_mobile_money'),
                ],
            ])
            ->response();
    }
}
