<?php

namespace App\Support\Vitrine;

use Illuminate\Support\Facades\Cache;

/**
 * Cache 60 secondes des routes publiques /api/vitrine/*, par établissement.
 * Le disque de cache par défaut du projet ("database") ne supporte pas les
 * tags Laravel (Cache::tags(), réservé à redis/memcached) : l'invalidation
 * passe donc par un numéro de version par établissement, incrémenté à
 * chaque écriture sur un produit (ou ses médias). Chaque clé de cache
 * embarque ce numéro — l'incrémenter revient à vider tout ce qui portait
 * l'ancien, sans avoir à énumérer les clés existantes ni à connaître leurs
 * paramètres (page, recherche, catégorie...).
 */
class CacheVitrine
{
    private const PREFIXE_VERSION = 'vitrine:version:';

    /**
     * La forme des réponses mises en cache. À incrémenter dès qu'une
     * resource de la vitrine gagne ou perd un champ : sans ça, pendant 60 s
     * après une mise en ligne, la nouvelle interface recevrait l'ancienne
     * forme (B3 : une fiche sans « options » faisait tomber la page ;
     * 3 : « prix_barre »).
     */
    private const FORMAT = 3;

    public static function invalider(int $etablissementId): void
    {
        Cache::forever(self::PREFIXE_VERSION.$etablissementId, self::version($etablissementId) + 1);
    }

    public static function cle(int $etablissementId, string $suffixe): string
    {
        return sprintf('vitrine:f%d:%d:v%d:%s', self::FORMAT, $etablissementId, self::version($etablissementId), $suffixe);
    }

    private static function version(int $etablissementId): int
    {
        return (int) Cache::get(self::PREFIXE_VERSION.$etablissementId, 1);
    }
}
