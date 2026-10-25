<?php

namespace Tests\Feature\Postgres\Medias;

use App\Models\Media;
use App\Models\Produit;
use App\Services\Etablissements\CreerEtablissement;
use Database\Seeders\RolesEtPermissionsSeeder;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\ConcurrenceTestCase;

/**
 * Une photo de téléphone (12 mégapixels) faisait mourir la requête d'envoi
 * sur une erreur fatale de mémoire : une 500 muette. Ce test le prouve pour
 * de vrai, avec la limite de PRODUCTION : un serveur PHP neuf lancé avec
 * memory_limit=128M, comme une requête php-fpm, et non le processus PHPUnit
 * qui porte la mémoire de tous les tests précédents (et dont la limite ne
 * dirait rien). Connexion réelle, envoi multipart réel, base Postgres de
 * test validée (voir ConcurrenceTestCase).
 *
 * Les images sont fabriquées par un processus à part, à la bonne taille en
 * pixels : la mémoire d'un décodage ne dépend que des dimensions, pas du
 * contenu ni du poids du fichier.
 */
class EnvoiPhotoSousLimiteMemoireTest extends ConcurrenceTestCase
{
    private const LIMITE_PRODUCTION = '128M';

    /**
     * 24 Mo sous la limite de production : le socle d'une requête varie
     * d'un serveur à l'autre (extensions, opcache), une image au plafond
     * doit donc passer avec de la marge, pas de justesse. Mesuré : à
     * 20 mégapixels, l'envoi échouait déjà à 112 Mo.
     */
    private const LIMITE_AVEC_MARGE = '104M';

    private ?Process $serveur = null;

    private string $slug;

    private string $motDePasse;

    private string $hote;

    private int $port;

    private Produit $produit;

    private CookieJar $cookies;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesEtPermissionsSeeder::class);
        $this->slug = 'photos-'.Str::lower(Str::random(8));
        $resultat = app(CreerEtablissement::class)->executer(
            'Photos '.$this->slug, 'boutique', $this->slug, null, null, null, 'Admin Photos', "{$this->slug}@photos.test",
        );
        $this->motDePasse = $resultat->motDePasseGenere;
        $etablissement = $this->nettoyerAvec($resultat->etablissement);
        $this->produit = Produit::factory()->for($etablissement)->create();

        // Le projet interdit toute requête HTTP sortante en test ; celle-ci
        // ne va qu'au serveur local que le test vient de lancer.
        Http::allowStrayRequests();
    }

    /** Un serveur PHP neuf à cette limite de mémoire, et une commerçante connectée. */
    private function serveurA(string $limiteMemoire): void
    {
        $this->demarrerServeur($limiteMemoire);
        $this->hote = "{$this->slug}.localhost:{$this->port}";
        $this->connecter("{$this->slug}@photos.test", $this->motDePasse);
    }

    protected function tearDown(): void
    {
        $this->serveur?->stop(1);

        // Les fichiers écrits par le serveur sont réels : on les efface
        // avant que la cascade ne supprime les lignes qui les désignent.
        Media::pourTousEtablissements()
            ->whereHas('produits', fn ($requete) => $requete->pourTousEtablissements()->whereKey($this->produit->id))
            ->get()
            ->each(fn (Media $media) => $this->disqueDuServeur()->delete(collect($media->metadonnees_json['variantes'])->flatMap(fn ($format) => [$format['webp'], $format['jpg']])->all()));

        parent::tearDown();
    }

    public function test_une_photo_de_telephone_de_12_megapixels_passe_sous_128_mo(): void
    {
        $this->serveurA(self::LIMITE_PRODUCTION);
        $reponse = $this->envoyer($this->image(4000, 3000));

        $this->assertSame(201, $reponse->status(), $this->diagnostic($reponse));
        $this->assertSame(1200, $this->largeurGrande());
        $this->assertSixFichiers();
    }

    /**
     * Le plafond n'est pas une promesse en l'air : une image qui l'atteint
     * exactement passe, et même avec 24 Mo de moins que la production.
     */
    public function test_une_photo_au_plafond_de_pixels_passe_avec_24_mo_de_marge(): void
    {
        $this->serveurA(self::LIMITE_AVEC_MARGE);
        [$largeur, $hauteur] = $this->dimensionsAuPlafond();

        $reponse = $this->envoyer($this->image($largeur, $hauteur));

        $this->assertSame(201, $reponse->status(), $this->diagnostic($reponse));
        $this->assertSixFichiers();
    }

    public function test_au_dela_du_plafond_un_refus_clair_jamais_une_500(): void
    {
        $this->serveurA(self::LIMITE_PRODUCTION);
        $reponse = $this->envoyer($this->image(6000, 4000));

        $this->assertSame(422, $reponse->status(), $this->diagnostic($reponse));
        $message = $reponse->json('errors.photo.0');
        $this->assertStringContainsString('trop grande (24 mégapixels', $message);
        $this->assertStringContainsString('depuis votre téléphone', $message);
        $this->assertSame(0, $this->mediasDuProduit(), 'aucune photo enregistrée');
    }

    private function demarrerServeur(string $limiteMemoire): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $this->serveur = new Process(
            [PHP_BINARY, '-d', 'memory_limit='.$limiteMemoire, '-S', "127.0.0.1:{$this->port}",
                base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
            public_path(),
            [
                'DB_CONNECTION' => 'pgsql_testing',
                // Les valeurs de phpunit.xml (session et cache "array")
                // ne survivraient pas d'une requête à l'autre.
                'SESSION_DRIVER' => 'file',
                'CACHE_STORE' => 'file',
                'SANCTUM_STATEFUL_DOMAINS' => "localhost:{$this->port},*.localhost:{$this->port}",
                'MEDIAS_DISQUE' => 'public',
            ],
            null,
            120,
        );
        $this->serveur->start();

        for ($essai = 0; $essai < 100; $essai++) {
            if (@fsockopen('127.0.0.1', $this->port) !== false) {
                return;
            }
            usleep(100_000);
        }
        $this->fail("Le serveur PHP n'a pas démarré :\n".$this->serveur->getErrorOutput());
    }

    private function connecter(string $email, string $motDePasse): void
    {
        $this->cookies = new CookieJar;

        $this->client()->get('/sanctum/csrf-cookie')->throw();
        $reponse = $this->client()->post('/api/connexion', ['email' => $email, 'mot_de_passe' => $motDePasse]);

        $this->assertSame(200, $reponse->status(), $this->diagnostic($reponse));
    }

    private function envoyer(string $chemin): Response
    {
        return $this->client()
            ->attach('photo', fopen($chemin, 'r'), 'photo.jpg')
            ->post("/api/produits/{$this->produit->id}/medias");
    }

    /**
     * Une requête neuve à chaque appel : PendingRequest est mutable, et
     * withHeaders() fusionne — l'ancien jeton XSRF (renouvelé à la
     * connexion) restait collé à côté du nouveau.
     */
    private function client(): PendingRequest
    {
        return Http::baseUrl("http://127.0.0.1:{$this->port}")
            ->withOptions(['cookies' => $this->cookies])
            ->withHeaders([
                'Host' => $this->hote,
                'Referer' => "http://{$this->hote}/",
                'Accept' => 'application/json',
                'X-XSRF-TOKEN' => $this->jetonXsrf(),
            ])
            ->timeout(60);
    }

    private function jetonXsrf(): string
    {
        return urldecode((string) $this->cookies->getCookieByName('XSRF-TOKEN')?->getValue());
    }

    /**
     * Fabriquée hors du processus PHPUnit (une image de 12 mégapixels pèse
     * 48 Mo décodée), une fois par taille, gardée dans le dossier temporaire.
     */
    private function image(int $largeur, int $hauteur): string
    {
        $chemin = sys_get_temp_dir()."/couahi-photo-{$largeur}x{$hauteur}.jpg";
        if (is_file($chemin)) {
            return $chemin;
        }

        $script = <<<'PHP'
            [, $l, $h, $sortie] = $argv;
            $image = imagecreatetruecolor((int) $l, (int) $h);
            for ($y = 0; $y < $h; $y += 8) {
                imagefilledrectangle($image, 0, $y, $l, $y + 7, imagecolorallocate($image, $y % 256, (int) ($y / 3) % 256, 140));
            }
            imagejpeg($image, $sortie, 85);
            PHP;
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', '-r', $script, (string) $largeur, (string) $hauteur, $chemin]);
        $process->mustRun();

        return $chemin;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimensionsAuPlafond(): array
    {
        $pixelsMax = (int) config('medias.pixels_max');
        $largeur = (int) floor(sqrt($pixelsMax * 4 / 3));

        return [$largeur, intdiv($pixelsMax, $largeur)];
    }

    private function largeurGrande(): int
    {
        return $this->derniereMedia()->metadonnees_json['variantes']['grande']['largeur'];
    }

    private function assertSixFichiers(): void
    {
        $media = $this->derniereMedia();
        $disque = $this->disqueDuServeur();

        foreach ($media->metadonnees_json['variantes'] as $nom => $format) {
            $this->assertTrue($disque->exists($format['webp']), "{$nom} webp absent");
            $this->assertTrue($disque->exists($format['jpg']), "{$nom} jpg absent");
        }
        $this->assertCount(3, $media->metadonnees_json['variantes']);
    }

    /**
     * Le vrai disque "public" sur lequel le serveur a écrit : celui du
     * processus PHPUnit est simulé en mémoire (voir TestCase).
     */
    private function disqueDuServeur(): Filesystem
    {
        return Storage::build(['driver' => 'local', 'root' => storage_path('app/public')]);
    }

    private function derniereMedia(): Media
    {
        return Media::pourTousEtablissements()
            ->whereHas('produits', fn ($requete) => $requete->pourTousEtablissements()->whereKey($this->produit->id))
            ->latest('id')
            ->firstOrFail();
    }

    private function mediasDuProduit(): int
    {
        return Media::pourTousEtablissements()
            ->whereHas('produits', fn ($requete) => $requete->pourTousEtablissements()->whereKey($this->produit->id))
            ->count();
    }

    private function diagnostic(Response $reponse): string
    {
        return "HTTP {$reponse->status()} : ".Str::limit($reponse->body(), 600)
            ."\nServeur : ".Str::limit($this->serveur->getErrorOutput(), 1500, '…');
    }
}
