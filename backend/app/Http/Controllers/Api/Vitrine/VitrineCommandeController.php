<?php

namespace App\Http\Controllers\Api\Vitrine;

use App\Enums\Canal;
use App\Enums\ModePaiement;
use App\Enums\SourceCommande;
use App\Enums\StatutProduit;
use App\Exceptions\ArticleIndisponibleException;
use App\Exceptions\SelectionVarianteInvalideException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vitrine\CreerCommandeVitrineRequest;
use App\Http\Resources\Vitrine\CommandeVitrineResource;
use App\Models\Commande;
use App\Models\Produit;
use App\Models\ZoneLivraison;
use App\Services\Clients\TrouverOuCreerClient;
use App\Services\Commandes\CreerCommande;
use App\Services\Commandes\LigneCommandeDemandee;
use App\Support\Telephone\NormaliseurTelephone;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Branche la vitrine sur le noyau de commande existant (CreerCommande,
 * réservation atomique, idempotence, numérotation — voir Étape 6B, rien de
 * tout ça n'est réécrit ici). Ce que ce contrôleur ajoute, que ce noyau ne
 * fait pas lui-même parce qu'il sert aussi d'autres canaux (back-office, IA
 * WhatsApp) :
 * - vérifier que chaque produit est bien publié (CreerCommande ne vérifie que
 *   son existence et son stock, jamais son statut de publication) ;
 * - trouver ou créer le client par téléphone ;
 * - résoudre la commune envoyée en ZoneLivraison (correctif livraison) :
 *   quand l'établissement a des zones actives, "commune" EST le nom d'une
 *   d'entre elles (déjà vérifié par CreerCommandeVitrineRequest) — on la
 *   retrouve ici pour en tirer le frais et l'identifiant à enregistrer,
 *   jamais un zone_livraison_id envoyé par le navigateur.
 */
class VitrineCommandeController extends Controller
{
    public function __construct(
        private readonly CreerCommande $creerCommande,
        private readonly TrouverOuCreerClient $trouverOuCreerClient,
    ) {}

    public function store(CreerCommandeVitrineRequest $request): JsonResponse
    {
        $etablissement = app(ContexteEtablissement::class)->obtenir();

        try {
            foreach ($request->validated('lignes') as $ligneBrute) {
                $produit = Produit::find($ligneBrute['produit_id']);

                if ($produit === null) {
                    return response()->json(['message' => "Un article de votre commande n'existe plus."], 422);
                }

                if ($produit->statut !== StatutProduit::Publie) {
                    throw ArticleIndisponibleException::nonDisponible($produit);
                }
            }

            $donneesClient = $request->validated('client');
            $client = $this->trouverOuCreerClient->executer(
                etablissement: $etablissement,
                nom: $donneesClient['nom'],
                telephone: NormaliseurTelephone::normaliser($donneesClient['telephone']),
                email: $donneesClient['email'] ?? null,
            );

            $commune = $request->validated('commune');
            $zoneLivraison = $etablissement->zoneLivraisonPour($commune, $request->boolean('autre_commune'));

            $lignes = array_map(
                fn (array $ligne) => new LigneCommandeDemandee(
                    $ligne['produit_id'],
                    $ligne['variante_id'] ?? null,
                    $ligne['quantite'],
                ),
                $request->validated('lignes'),
            );

            $commande = $this->creerCommande->executer(
                etablissement: $etablissement,
                client: $client,
                lignes: $lignes,
                canal: Canal::Web,
                source: SourceCommande::PanierWeb,
                cleIdempotence: $request->validated('cle_idempotence'),
                zoneLivraison: $zoneLivraison,
                modePaiement: ModePaiement::tryFrom((string) $request->validated('mode_paiement')),
            );
        } catch (ArticleIndisponibleException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'article' => $exception->nomArticle()], 422);
        } catch (SelectionVarianteInvalideException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        // REPLI À CONSTRUIRE avec l'intégration CinetPay (CINETPAY_ACTIF,
        // voir config/services.php) : c'est ICI, la commande créée en mode
        // "cinetpay", qu'on initialisera la transaction CinetPay. Si cet appel
        // échoue (service injoignable, refus, délai dépassé) et que
        // $etablissement->mobileMoneyEstConfigure(), on basculera la commande
        // en ModePaiement::MobileMoney (avec son expiration de 24 h, voir
        // CreerCommande) et on renverra le client vers les instructions de
        // transfert de sa page de confirmation — jamais une erreur sèche,
        // jamais un cul-de-sac. Sans Mobile Money : la commande reste, le
        // client est invité à la régler sur WhatsApp.

        // Rejouer la même clé d'idempotence redonne la commande déjà créée,
        // avec son adresse déjà posée : cette ré-écriture est sans effet,
        // jamais incorrecte (même soumission, même page, même adresse).
        $commande->update(['commune' => $commune, 'quartier' => $request->validated('quartier')]);

        return response()->json([
            'numero' => $commande->numero,
            'jeton' => $commande->jeton_acces,
        ], 201);
    }

    public function show(Request $request, string $numero): JsonResponse
    {
        $jeton = (string) $request->query('jeton', '');
        $commande = Commande::where('numero', $numero)->first();

        abort_if(
            $commande === null || $jeton === '' || ! hash_equals((string) $commande->jeton_acces, $jeton),
            404,
        );

        $commande->load(['lignes', 'zoneLivraison', 'etablissement']);

        return response()->json(['data' => new CommandeVitrineResource($commande)]);
    }
}
