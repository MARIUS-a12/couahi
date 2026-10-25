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
        $zones = app(ContexteEtablissement::class)->obtenir()->zonesLivraisonProposees();

        return response()->json(['data' => ZoneLivraisonVitrineResource::collection($zones)]);
    }
}
