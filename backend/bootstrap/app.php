<?php

use App\Exceptions\EtablissementNonResoluException;
use App\Http\Middleware\DefinirEtablissementCible;
use App\Http\Middleware\JournaliserConsultationPlateforme;
use App\Http\Middleware\ResoudreEtablissement;
use App\Http\Middleware\VerifierAbonnementVitrine;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'resoudre.etablissement' => ResoudreEtablissement::class,
            'definir.etablissement.cible' => DefinirEtablissementCible::class,
            'abonnement.vitrine' => VerifierAbonnementVitrine::class,
            'journaliser.consultation' => JournaliserConsultationPlateforme::class,
        ]);

        // ResoudreEtablissement n'est pas dans la liste de priorité de
        // Laravel : sans ceci, il s'exécute APRÈS SubstituteBindings (qui y
        // est), donc le binding implicite d'un {produit} de route se résout
        // AVANT que le contexte d'établissement soit posé. Le scope global
        // (AppartientAEtablissement) ne filtre alors par rien de définitif —
        // en test/CLI, l'échappatoire "runningInConsole" du scope le laisse
        // même passer sans lever, un produit d'un autre établissement devient
        // lisible par son id. C'est exactement l'isolation vérifiée par
        // Étape 1, test n°5 : ne pas la remettre par erreur en retirant ceci.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResoudreEtablissement::class,
        );

        // Sanctum en mode SPA (cookies de session), pas en mode jetons Bearer :
        // ceci ajoute EnsureFrontendRequestsAreStateful au groupe "api", qui
        // fait passer les requêtes venant d'un domaine listé dans
        // SANCTUM_STATEFUL_DOMAINS par le middleware de session/cookies du
        // groupe "web" au lieu de l'authentification par jeton. Ne PAS ajouter
        // HasApiTokens à User : ce trait ouvrirait un second chemin
        // d'authentification (jetons pour clients tiers) qu'on ne veut pas.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Une requête sur un modèle tenant-scopé sans établissement résolu
        // (le super-admin, dont l'hôte dédié n'en a jamais un) est une
        // situation prévue, pas un plantage : 400 avec un message explicite
        // plutôt qu'un 500 qui masque la cause réelle et pollue les
        // journaux d'erreurs de faux positifs.
        $exceptions->render(fn (EtablissementNonResoluException $e) => response()->json(['message' => $e->getMessage()], 400));
    })->create();
