<?php

namespace Tests;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

/**
 * Disque simulé tenu EN MÉMOIRE, à la place de Storage::fake() (un vrai
 * dossier sous storage/framework/testing). Mesuré le 2026-10-23 sur cette
 * machine : environ 3 ms par écriture de fichier et jusqu'à 400 ms pour
 * vider le dossier entre deux tests — or chaque seed() écrit ~140 fichiers
 * (logos et photos de démo, trois formats, WebP et JPEG). C'était l'essentiel
 * de la durée de la suite ; les requêtes SQL d'un seed(), elles, prennent
 * 12 ms au total.
 *
 * Même contrat qu'un faux disque local pour le code testé : écriture,
 * lecture, suppression, assertExists/assertMissing, et url() construite
 * comme celle du disque "public" réel (APP_URL/storage/...), sans quoi
 * toute Resource qui expose une URL de média lèverait.
 */
class DisqueEnMemoire
{
    public static function installer(string $disque): FilesystemAdapter
    {
        $adaptateur = new InMemoryFilesystemAdapter;
        $config = config("filesystems.disks.{$disque}", []);

        $faux = new class(new Filesystem($adaptateur), $adaptateur, $config) extends FilesystemAdapter
        {
            public function url($path)
            {
                return isset($this->config['url'])
                    ? $this->concatPathToUrl($this->config['url'], $path)
                    : '/storage/'.ltrim($path, '/');
            }
        };

        Storage::set($disque, $faux);

        return $faux;
    }
}
