<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Livraison\ZoneLivraisonRequest;
use App\Http\Resources\LivraisonResource;
use App\Models\Etablissement;
use App\Models\ZoneLivraison;
use App\Services\Livraison\GererZonesLivraison;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Http\Request;

/**
 * Les zones de livraison du commerçant, sur son propre établissement
 * (résolu par le sous-domaine), « gerer_parametres » exigé comme le
 * paiement. Une zone d'un autre établissement est introuvable : la liaison
 * de route passe par le scope d'établissement (404, jamais 403).
 * Toute écriture renvoie l'écran entier, pour que l'interface n'ait jamais
 * deux versions de la liste à réconcilier.
 */
class LivraisonController extends Controller
{
    public function __construct(
        private readonly GererZonesLivraison $service,
    ) {}

    public function show(): LivraisonResource
    {
        return new LivraisonResource($this->etablissement());
    }

    public function choisirFacturation(Request $request): LivraisonResource
    {
        $etablissement = $this->etablissement();
        $donnees = $request->validate(['par_commune' => ['required', 'boolean']]);
        $this->service->choisirFacturation($etablissement, (bool) $donnees['par_commune']);

        return new LivraisonResource($etablissement);
    }

    public function ajouterZone(ZoneLivraisonRequest $request): LivraisonResource
    {
        $etablissement = $this->etablissement();
        $this->service->ajouter($etablissement, $request->validated('nom'), (int) $request->validated('frais'));

        return new LivraisonResource($etablissement);
    }

    public function definirAutreCommune(Request $request): LivraisonResource
    {
        $etablissement = $this->etablissement();
        $donnees = $request->validate(
            ['frais' => ['present', 'nullable', 'integer', 'min:0', 'max:100000']],
            [
                'frais.integer' => 'Les frais sont un nombre entier de francs CFA.',
                'frais.min' => 'Les frais ne peuvent pas être négatifs.',
                'frais.max' => 'Les frais ne peuvent pas dépasser 100 000 FCFA.',
            ],
        );
        $this->service->definirAutreCommune($etablissement, $donnees['frais'] === null ? null : (int) $donnees['frais']);

        return new LivraisonResource($etablissement);
    }

    public function modifierZone(ZoneLivraisonRequest $request, ZoneLivraison $zone): LivraisonResource
    {
        $etablissement = $this->etablissement();
        abort_if($zone->autre_commune, 404);
        $this->service->modifier($zone, $request->validated('nom'), (int) $request->validated('frais'));

        return new LivraisonResource($etablissement);
    }

    public function retirerZone(ZoneLivraison $zone): LivraisonResource
    {
        $etablissement = $this->etablissement();
        abort_if($zone->autre_commune, 404);
        $this->service->retirer($zone);

        return new LivraisonResource($etablissement);
    }

    private function etablissement(): Etablissement
    {
        abort_unless(auth()->user()->peut('gerer_parametres'), 403);

        return app(ContexteEtablissement::class)->obtenir();
    }
}
