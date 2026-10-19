<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\ContexteEtablissement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Étape 12 — un abonnement suspendu coupe la VITRINE, et elle seule : posé
 * uniquement sur le groupe de routes "vitrine", jamais sur le back-office,
 * pour qu'un commerçant en défaut puisse toujours se connecter, consulter
 * ses données et régulariser. Volontairement distinct du statut
 * "inactif" de l'établissement (suspension décidée par le super-admin), qui
 * bloque aussi la connexion — le confondre enfermerait dehors le commerçant
 * qui veut payer. Même réponse que ResoudreEtablissement pour un
 * établissement inactif : un visiteur ne doit pas pouvoir distinguer les
 * deux causes.
 *
 * Doit s'exécuter APRÈS "resoudre.etablissement", qui pose le contexte.
 */
class VerifierAbonnementVitrine
{
    public function __construct(
        protected ContexteEtablissement $contexte,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $etablissement = $this->contexte->obtenir();

        if ($etablissement?->abonnement?->vitrineCoupee()) {
            throw new NotFoundHttpException("Aucun établissement actif pour le domaine [{$request->getHost()}].");
        }

        return $next($request);
    }
}
