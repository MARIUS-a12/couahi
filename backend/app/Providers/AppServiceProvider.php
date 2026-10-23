<?php

namespace App\Providers;

use App\Models\User;
use App\Support\RateLimiting\LimiteurEmailEtIp;
use App\Support\Tenancy\ContexteEtablissement;
use App\Validation\PwnedPasswordVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ContexteEtablissement::class);

        // Remplace le vérificateur par défaut de Password::uncompromised()
        // (délai de 30s, échec silencieux) par PwnedPasswordVerifier (2s,
        // échec journalisé) — voir sa docblock. extend(), PAS bind() : Laravel
        // enregistre ce contrat dans un ServiceProvider DIFFÉRÉ (voir
        // Illuminate\Validation\ValidationServiceProvider::provides()), chargé
        // paresseusement à la première résolution — souvent celle du service
        // "validator" lui-même, qui réenregistre ce contrat au passage et
        // écraserait silencieusement un simple bind() posé ici, à l'enregistrement
        // de CE provider, donc nécessairement avant que celui différé ne charge.
        // Un extend() s'applique lui APRÈS coup, à la résolution, quel que soit
        // ce que ce binding différé a entre-temps réenregistré dessous.
        $this->app->extend(
            UncompromisedVerifier::class,
            fn () => new PwnedPasswordVerifier($this->app->make(HttpFactory::class)),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Le super-admin contourne toutes les policies. Explicite ici, dans
        // un seul endroit à auditer — jamais par un `return true` dissimulé
        // dans telle ou telle policy.
        Gate::before(fn (User $user, string $ability) => $user->estSuperAdmin() ? true : null);

        // Correctif limitation de débit : /connexion et /activation
        // partagent le même schéma combiné (voir LimiteurEmailEtIp) — un
        // seul endroit à auditer pour les deux.
        RateLimiter::for('connexion', LimiteurEmailEtIp::pour('connexion'));
        RateLimiter::for('activation', LimiteurEmailEtIp::pour('activation'));

        // Génération de lien WhatsApp (route publique anonyme) : la vraie
        // protection est l'idempotence de la génération (voir
        // GenerateurLienWhatsapp). Ceci n'est qu'un plafond de secours, large,
        // par IP ET par établissement (l'hôte : ce limiteur passe avant
        // ResoudreEtablissement, voir la liste de priorité de bootstrap/app.php).
        RateLimiter::for('lien-whatsapp', fn (Request $request) => Limit::perHour(60)->by($request->ip().'|'.$request->getHost()));
    }
}
