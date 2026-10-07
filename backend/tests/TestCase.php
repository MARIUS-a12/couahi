<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * Aucun test ne doit jamais toucher un vrai réseau — le seul appel
     * sortant de toute l'application est la vérification pwnedpasswords.com
     * de Password::uncompromised() (voir PwnedPasswordVerifier).
     * preventStrayRequests() transforme tout appel non explicitement simulé
     * (par un Http::fake() posé DANS le test) en échec immédiat
     * (StrayRequestException) plutôt qu'un vrai aller-retour réseau : une
     * suite qui passe est donc la preuve qu'aucun test n'en a fait un, pas
     * une simple absence de chance.
     *
     * Volontairement AUCUN Http::fake() par défaut ici : PwnedPasswordVerifier
     * traite déjà toute exception (StrayRequestException y compris) comme
     * "service indisponible" et laisse passer le mot de passe — exactement
     * le comportement de repli voulu pour tout test qui ne s'intéresse pas
     * spécifiquement à ce service. Un test qui veut un scénario précis
     * (compromis, sain, service indisponible) pose son propre Http::fake().
     *
     * Storage::fake('public') de même ici, pas seulement dans les tests qui
     * envoient eux-mêmes un fichier : EtablissementsDemoSeeder génère un
     * vrai logo et de vraies photos (voir sa docblock) à chaque $this->seed(),
     * donc dans quasiment chaque test de la suite — sans disque simulé, ce
     * sont des fichiers réels qui s'accumuleraient sur le disque à chaque
     * exécution. Un test qui veut vérifier un disque précis repose son
     * propre Storage::fake(), sans effet différent (idempotent).
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('public');

        // EtablissementsDemoSeeder génère un vrai logo et de vraies photos à
        // chaque $this->seed() (voir sa docblock) : la FORME des données
        // (une ligne "medias" réelle, les bonnes relations) est ce qui
        // compte pour les tests, jamais le rendu visuel. GenerateurVariantesImage
        // encode toujours à la taille de SORTIE configurée ici, quelle que
        // soit la taille de la source — sans ce réglage, chaque photo de
        // démo serait quand même ré-encodée à 1200px à chaque test.
        config([
            'medias.formats' => ['vignette' => 20, 'moyenne' => 20, 'grande' => 20],
            'medias.formats_logo' => ['petit' => 20, 'grand' => 20],
        ]);
    }
}
