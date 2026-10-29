<?php

namespace App\Http\Controllers\Api\Vitrine;

use App\Http\Controllers\Controller;
use App\Http\Resources\Vitrine\ZoneLivraisonVitrineResource;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Http\JsonResponse;

class VitrineZoneLivraisonController extends Controller
{
    public function index(): JsonResponse
    {
        $etablissement = app(ContexteEtablissement::class)->obtenir();

        return response()->json([
            'data' => ZoneLivraisonVitrineResource::collection($etablissement->zonesLivraisonProposees()),
            // Frais fixes de toute commune hors liste ; null : à convenir.
            'autre_commune' => $etablissement->zoneAutreCommune()?->only('frais'),
        ]);
    }
}
