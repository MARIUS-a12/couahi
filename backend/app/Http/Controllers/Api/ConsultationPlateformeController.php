<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsultationPlateforme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chemin commerçant uniquement : "Consultations par l'équipe de la
 * plateforme", sur son propre établissement (résolu par le sous-domaine,
 * le scope tenant filtre le reste). Même permission que l'abonnement et le
 * paiement : ce qui concerne la relation avec la plateforme regarde le
 * responsable de la boutique, pas chaque employé.
 */
class ConsultationPlateformeController extends Controller
{
    public const LIMITE = 50;

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->peut('gerer_parametres'), 403);

        $consultations = ConsultationPlateforme::query()
            ->orderByDesc('consulte_le')
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        return response()->json([
            'data' => $consultations->map(fn (ConsultationPlateforme $consultation) => [
                'id' => $consultation->id,
                'qui' => $consultation->nom_utilisateur,
                'page' => $consultation->page->value,
                'page_libelle' => $consultation->page->libelle(),
                'consulte_le' => $consultation->consulte_le,
            ]),
            'total' => ConsultationPlateforme::query()->count(),
        ]);
    }
}
