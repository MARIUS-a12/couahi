<?php

namespace App\Http\Middleware;

use App\Models\Etablissement;
use App\Support\Tenancy\ContexteEtablissement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pose le contexte de tenancy sur les routes super-admin qui ciblent UN
 * établissement précis par son id dans l'URL (/etablissements/{etablissement}/...)
 * — seul moyen légitime de lire ses données scopées (médias, commandes...)
 * sans contourner ScopeEtablissement avec pourTousEtablissements(), réservé
 * aux traitements hors contexte (jobs, commandes artisan, seeders). Sur
 * l'hôte du super-admin, ResoudreEtablissement ne pose jamais de contexte
 * (il n'y a pas de sous-domaine à résoudre) : sans ce middleware, toute
 * requête sur un modèle tenant-scopé lève EtablissementNonResoluException.
 *
 * Etablissement lui-même n'est PAS tenant-scopé (c'est le tenant), donc le
 * charger ici ne nécessite aucun contournement — seul le prochain modèle
 * scopé qu'un contrôleur interrogera (Media, Commande...) en a besoin.
 *
 * Relâche le contexte après la requête (plutôt que de compter sur la fin du
 * processus) : en test comme sous Octane, l'application reste la même
 * instance entre deux requêtes, et ContexteEtablissement est un singleton —
 * une requête suivante sur l'hôte du super-admin qui ne passerait PAS par ce
 * middleware ne doit jamais hériter silencieusement du contexte laissé par
 * celle-ci.
 */
class DefinirEtablissementCible
{
    public function __construct(
        protected ContexteEtablissement $contexte,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $parametre = $request->route('etablissement');

        $etablissement = $parametre instanceof Etablissement
            ? $parametre
            : Etablissement::find($parametre);

        if ($etablissement === null) {
            throw new NotFoundHttpException("Aucun établissement pour l'identifiant [{$parametre}].");
        }

        $this->contexte->definir($etablissement);

        try {
            return $next($request);
        } finally {
            $this->contexte->definir(null);
        }
    }
}
