<?php

namespace App\Support\RateLimiting;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limiteur combiné, partagé par /connexion et /activation (middleware
 * "limiter.echecs:<action>") : une IP seule est un mauvais verrou dans ce
 * contexte, les opérateurs mobiles ivoiriens partagent une même adresse
 * entre un grand nombre d'abonnés sans aucun lien entre eux, qui se
 * bloqueraient mutuellement. La vraie protection contre le bourrage d'un
 * compte précis est la limite par email (5/heure, stricte) ; la limite par
 * IP (30/heure, large) ne reste là que pour arrêter un balayage automatisé.
 *
 * Il ne compte que les ÉCHECS — c'est pourquoi ce n'est plus le middleware
 * "throttle" de Laravel, qui compte chaque requête, réussie ou non : une
 * commerçante qui se connecte le matin sur son téléphone, à midi sur celui
 * de son employé, puis se trompe deux fois, était enfermée dehors une heure
 * sans avoir rien fait de suspect. Désormais :
 *  - un échec (toute réponse non 2xx) incrémente l'email et l'IP ;
 *  - une réussite remet le compteur de l'email à zéro, et rend à l'IP la
 *    seule unité que cette requête avait prise — jamais davantage : un
 *    compte valide ne doit pas pouvoir effacer les échecs que d'autres ont
 *    accumulés depuis la même adresse.
 *
 * Le compteur est incrémenté AVANT le traitement, puis rendu en cas de
 * réussite : une rafale de requêtes parallèles ne peut donc pas passer
 * toutes la vérification avant qu'aucune ne soit comptée.
 */
class LimiteurEmailEtIp
{
    public const TENTATIVES_PAR_EMAIL = 5;

    public const TENTATIVES_PAR_IP = 30;

    private const FENETRE_SECONDES = 3600;

    public function __construct(private readonly RateLimiter $limiteur) {}

    /**
     * $action identifie le limiteur dans les clés de cache et le journal
     * ("connexion", "activation") — jamais exposé au client.
     */
    public function handle(Request $request, Closure $next, string $action): Response
    {
        $email = Str::lower(trim((string) $request->input('email')));
        $cleEmail = self::cleEmail($action, $email);
        $cleIp = "{$action}:ip:{$request->ip()}";

        foreach ([$cleEmail => self::TENTATIVES_PAR_EMAIL, $cleIp => self::TENTATIVES_PAR_IP] as $cle => $maximum) {
            if ($this->limiteur->tooManyAttempts($cle, $maximum)) {
                return $this->reponseBloquee($request, $action, $email, $cle);
            }
        }

        $parEmail = $this->limiteur->hit($cleEmail, self::FENETRE_SECONDES);
        $parIp = $this->limiteur->hit($cleIp, self::FENETRE_SECONDES);

        if ($parEmail > self::TENTATIVES_PAR_EMAIL || $parIp > self::TENTATIVES_PAR_IP) {
            return $this->reponseBloquee($request, $action, $email, $parEmail > self::TENTATIVES_PAR_EMAIL ? $cleEmail : $cleIp);
        }

        $reponse = $next($request);

        if ($reponse->isSuccessful()) {
            $this->limiteur->clear($cleEmail);
            $this->limiteur->decrement($cleIp, self::FENETRE_SECONDES);
        }

        return $reponse;
    }

    /**
     * Exposée pour remettre à zéro à la main le compteur d'un compte précis
     * (RateLimiter::clear(LimiteurEmailEtIp::cleEmail('connexion', $email))).
     */
    public static function cleEmail(string $action, string $email): string
    {
        return "{$action}:email:".Str::lower(trim($email));
    }

    private function reponseBloquee(Request $request, string $action, string $email, string $cle): JsonResponse
    {
        $secondes = $this->limiteur->availableIn($cle);
        $minutes = max(1, (int) ceil($secondes / 60));

        // Jamais le mot de passe tenté, jamais le code d'activation tenté —
        // seulement de quoi distinguer un employé qui se trompe d'une
        // attaque : quel compte est visé, depuis quelle adresse.
        Log::warning("Limite de tentatives atteinte : {$action}.", [
            'email' => $email,
            'adresse_ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => "Trop de tentatives. Réessayez dans {$minutes} minute".($minutes > 1 ? 's' : '').'.',
        ], 429, ['Retry-After' => $secondes]);
    }
}
