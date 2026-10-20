<?php

namespace App\Http\Controllers\Api\Vitrine;

use App\Http\Controllers\Controller;
use App\Http\Resources\Vitrine\EtablissementVitrineResource;
use App\Support\Tenancy\ContexteEtablissement;
use App\Support\Vitrine\CacheVitrine;
use App\Support\Vitrine\MetadonneesPartage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class VitrineEtablissementController extends Controller
{
    /**
     * Met en cache le tableau déjà résolu, pas le modèle Etablissement —
     * voir la docblock de VitrineProduitController pour la raison
     * (cache.serializable_classes = false empêche unserialize() de
     * reconstruire un modèle Eloquent).
     */
    public function show(): JsonResponse
    {
        $contexte = app(ContexteEtablissement::class);
        $cle = CacheVitrine::cle($contexte->id(), 'etablissement');

        $donnees = Cache::remember(
            $cle,
            60,
            fn () => (new EtablissementVitrineResource($contexte->obtenir()))->response()->getData(true),
        );

        return response()->json($donnees);
    }

    /**
     * Titre, description et image de l'aperçu d'un lien de vitrine (voir
     * MetadonneesPartage). Lu par le serveur qui renvoie le HTML de la page
     * — jamais par le navigateur. Pas de cache : une requête par page
     * HTML, et un produit renommé doit se voir au partage suivant.
     */
    public function partage(Request $request): JsonResponse
    {
        $chemin = '/'.ltrim((string) $request->query('chemin', '/'), '/');

        return response()->json(['data' => MetadonneesPartage::pour(app(ContexteEtablissement::class)->obtenir(), $chemin)]);
    }
}
