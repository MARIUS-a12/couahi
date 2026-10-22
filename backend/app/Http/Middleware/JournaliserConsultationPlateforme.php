<?php

namespace App\Http\Middleware;

use App\Enums\PageConsultee;
use App\Models\ConsultationPlateforme;
use App\Support\Tenancy\ContexteEtablissement;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * "journaliser.consultation:{page}" — à poser sur toute route super-admin
 * qui renvoie des données commerciales d'UN établissement (voir
 * ConsultationsPlateformeTest, qui refuse une telle route sans lui).
 *
 * La trace est écrite APRÈS la réponse construite mais AVANT son envoi : si
 * l'écriture échoue, l'exception remplace la réponse, et les données ne
 * partent pas. Jamais de consultation sans trace. Une réponse en échec (404,
 * 403...) n'a rien montré : rien n'est écrit.
 *
 * Une même page rechargée par le même super-admin dans les 10 minutes
 * n'ajoute pas de ligne : un écran qui se rafraîchit seul noierait
 * l'historique du commerçant sous des doublons sans rien lui apprendre.
 */
class JournaliserConsultationPlateforme
{
    public const FENETRE_MINUTES = 10;

    public function __construct(
        protected ContexteEtablissement $contexte,
    ) {}

    public function handle(Request $request, Closure $next, string $page): Response
    {
        $pageConsultee = PageConsultee::from($page);

        if (! $this->contexte->estDefini()) {
            throw new LogicException('journaliser.consultation exige un établissement cible (definir.etablissement.cible).');
        }

        $etablissementId = $this->contexte->id();
        $response = $next($request);
        $utilisateur = $request->user();

        if ($utilisateur?->estSuperAdmin() && $response->isSuccessful()) {
            $dejaTrace = ConsultationPlateforme::pourTousEtablissements()
                ->where('etablissement_id', $etablissementId)
                ->where('utilisateur_id', $utilisateur->id)
                ->where('page', $pageConsultee)
                ->where('consulte_le', '>=', now()->subMinutes(self::FENETRE_MINUTES))
                ->exists();

            if (! $dejaTrace) {
                (new ConsultationPlateforme([
                    'utilisateur_id' => $utilisateur->id,
                    'nom_utilisateur' => $utilisateur->name,
                    'page' => $pageConsultee,
                    'consulte_le' => now(),
                ]))->pourEtablissement($etablissementId)->save();
            }
        }

        return $response;
    }
}
