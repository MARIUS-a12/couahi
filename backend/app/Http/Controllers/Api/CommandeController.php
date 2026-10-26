<?php

namespace App\Http\Controllers\Api;

use App\Enums\ModePaiement;
use App\Enums\StatutCommande;
use App\Enums\StatutReservation;
use App\Exceptions\EtablissementNonResoluException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnnulerCommandeRequest;
use App\Http\Requests\ConfirmerPaiementCommandeRequest;
use App\Http\Requests\EnregistrerRemboursementCommandeRequest;
use App\Http\Resources\CommandeResource;
use App\Models\Commande;
use App\Services\Commandes\AnnulerCommande;
use App\Services\Commandes\ConfirmerPaiementCommande;
use App\Services\Commandes\EnregistrerRemboursementCommande;
use App\Services\Commandes\TransitionnerStatutCommande;
use App\Services\Stock\ConsommerReservation;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Étape 9 — gestion des commandes du back-office. Entièrement bâti sur le
 * noyau de l'Étape 6B (CreerCommande, ConsommerReservation, AnnulerCommande,
 * TransitionnerStatutCommande) : ce contrôleur ne fait QUE résoudre la
 * commande, vérifier la transition demandée, et déléguer — aucune logique de
 * stock ni d'historique ne vit ici.
 *
 * "Confirmer"/"Annuler" ont des effets de stock, donc leurs propres
 * services dédiés (ConsommerReservation, AnnulerCommande). "Marquer prête"
 * et "Marquer livrée" n'en ont aucun, d'où TransitionnerStatutCommande,
 * générique aux deux.
 */
class CommandeController extends Controller
{
    public function __construct(
        private readonly ConsommerReservation $consommerReservation,
        private readonly TransitionnerStatutCommande $transitionnerStatut,
        private readonly AnnulerCommande $annulerCommande,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Commande::class);

        $requete = Commande::query()
            ->with(['client', 'lignes'])
            ->withExists(['reservations as a_reservation_active' => fn ($q) => $q->where('statut', StatutReservation::Active)])
            ->latest('created_at');

        $recherche = trim((string) $request->query('recherche', ''));
        if ($recherche !== '') {
            $requete->where(function ($sousRequete) use ($recherche) {
                $sousRequete->where('numero', 'like', "%{$recherche}%")
                    ->orWhereHas('client', fn ($q) => $q->where('telephone', 'like', "%{$recherche}%"));
            });
        }

        $statut = $request->query('statut');
        if (is_string($statut) && $statut !== '' && StatutCommande::tryFrom($statut) !== null) {
            $requete->where('statut', $statut);
        }

        return CommandeResource::collection($requete->paginate(25)->withQueryString());
    }

    /**
     * Avant "{commande}" dans routes/api.php : sinon "statistiques" serait
     * capturé comme un identifiant de commande (même précaution que
     * "sous-domaine-disponible" avant "{etablissement}").
     */
    public function statistiques(): JsonResponse
    {
        Gate::authorize('viewAny', Commande::class);

        // Sur l'hôte du super-admin, aucun établissement : le même 400 que
        // toute autre lecture scopée (voir ScopeEtablissement), pas un 500
        // sur un appel de méthode sur null.
        $etablissement = app(ContexteEtablissement::class)->obtenir()
            ?? throw new EtablissementNonResoluException('Aucun établissement résolu pour ce domaine : accès refusé aux statistiques de commandes.');
        $statutsConfirmes = [StatutCommande::Payee->value, StatutCommande::Prete->value, StatutCommande::Livree->value];

        $commandesDuJour = $etablissement->commandes()->whereDate('created_at', today())->count();

        $enAttente = $etablissement->commandes()->where('statut', StatutCommande::AttentePaiement->value)->count();

        // Étape 13 : parmi elles, celles déjà PAYÉES — le commerçant doit
        // de la marchandise, à lire sans ouvrir la liste.
        $enAttentePayees = $etablissement->commandes()
            ->where('statut', StatutCommande::AttentePaiement->value)
            ->whereNotNull('paiement_confirme_le')
            ->count();

        $chiffreAffairesJour = (int) $etablissement->commandes()
            ->whereDate('created_at', today())
            ->whereIn('statut', $statutsConfirmes)
            ->sum('total');

        $commandesDuMois = $etablissement->commandes()
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->whereIn('statut', $statutsConfirmes);

        $panierMoyenMois = (int) round((clone $commandesDuMois)->avg('total') ?? 0);

        return response()->json([
            'commandes_du_jour' => $commandesDuJour,
            'en_attente' => $enAttente,
            'en_attente_payees' => $enAttentePayees,
            'chiffre_affaires_jour' => $chiffreAffairesJour,
            'panier_moyen_mois' => $panierMoyenMois,
        ]);
    }

    public function show(Commande $commande): CommandeResource
    {
        Gate::authorize('view', $commande);

        $commande->load([
            'client',
            'zoneLivraison',
            'lignes.variante',
            'lignes.produit.medias' => fn ($requete) => $requete->orderByPivot('ordre'),
            'historique' => fn ($requete) => $requete->orderBy('id'),
            'historique.utilisateur',
            'paiementConfirmePar',
            'remboursePar',
            'creePar',
        ]);

        return new CommandeResource($commande);
    }

    public function confirmer(Commande $commande): JsonResponse
    {
        Gate::authorize('update', $commande);

        if ($commande->statut !== StatutCommande::AttentePaiement) {
            return $this->reponseTransitionRefusee($commande, 'confirmée');
        }

        $this->consommerReservation->executer($commande);

        return $this->reponseCommandeFraiche($commande);
    }

    /**
     * Étape 13 — réservé à gerer_commandes (policy "update") : jamais
     * accessible au client, qui n'a d'ailleurs aucune session sur ces
     * routes. Ne change pas le statut de la commande, voir
     * ConfirmerPaiementCommande.
     */
    public function confirmerPaiement(ConfirmerPaiementCommandeRequest $request, Commande $commande, ConfirmerPaiementCommande $confirmer): JsonResponse
    {
        Gate::authorize('update', $commande);

        if ($commande->mode_paiement !== ModePaiement::MobileMoney) {
            return response()->json(['message' => "Cette commande n'est pas réglée par Mobile Money."], 422);
        }

        $confirme = $confirmer->executer(
            commande: $commande,
            montantRecu: (int) $request->validated('montant_recu'),
            reference: $request->validated('reference'),
            utilisateur: $request->user(),
            adresseIp: $request->ip(),
        );

        if (! $confirme) {
            return response()->json(['message' => 'Le paiement de cette commande a déjà été confirmé.'], 422);
        }

        return $this->reponseCommandeFraiche($commande);
    }

    /**
     * Le commerçant a rendu l'argent d'une commande annulée ou expirée après
     * paiement (voir EnregistrerRemboursementCommande). gerer_commandes est
     * vérifié par la requête, avant toute validation. Ne change pas le
     * statut de la commande.
     */
    public function rembourser(EnregistrerRemboursementCommandeRequest $request, Commande $commande, EnregistrerRemboursementCommande $rembourser): JsonResponse
    {
        if ($commande->montantARembourser() === null) {
            return response()->json(['message' => "Cette commande n'a aucun remboursement en attente."], 422);
        }

        $enregistre = $rembourser->executer(
            commande: $commande,
            montant: (int) $request->validated('montant'),
            reference: $request->validated('reference'),
            utilisateur: $request->user(),
            adresseIp: $request->ip(),
        );

        if (! $enregistre) {
            return response()->json(['message' => 'Le remboursement de cette commande a déjà été enregistré.'], 422);
        }

        return $this->reponseCommandeFraiche($commande);
    }

    public function marquerPrete(Commande $commande): JsonResponse
    {
        return $this->transitionnerSimple($commande, StatutCommande::Payee, StatutCommande::Prete, 'marquée prête');
    }

    public function marquerLivree(Commande $commande): JsonResponse
    {
        return $this->transitionnerSimple($commande, StatutCommande::Prete, StatutCommande::Livree, 'marquée livrée');
    }

    private function transitionnerSimple(Commande $commande, StatutCommande $attendu, StatutCommande $cible, string $libelleAction): JsonResponse
    {
        Gate::authorize('update', $commande);

        if ($commande->statut !== $attendu) {
            return $this->reponseTransitionRefusee($commande, $libelleAction);
        }

        $this->transitionnerStatut->executer($commande, $attendu, $cible, auth()->id());

        return $this->reponseCommandeFraiche($commande);
    }

    public function annuler(AnnulerCommandeRequest $request, Commande $commande): JsonResponse
    {
        Gate::authorize('update', $commande);

        $statutsAnnulables = [StatutCommande::AttentePaiement, StatutCommande::Payee, StatutCommande::Prete];

        if (! in_array($commande->statut, $statutsAnnulables, true)) {
            return $this->reponseTransitionRefusee($commande, 'annulée');
        }

        $this->annulerCommande->executer($commande, $request->motifFinal(), auth()->id());

        return $this->reponseCommandeFraiche($commande);
    }

    private function reponseTransitionRefusee(Commande $commande, string $libelleAction): JsonResponse
    {
        return response()->json([
            'message' => "Cette commande est actuellement « {$commande->statut->libelle()} », elle ne peut pas être {$libelleAction}.",
        ], 422);
    }

    private function reponseCommandeFraiche(Commande $commande): JsonResponse
    {
        $fraiche = $commande->fresh([
            'client',
            'zoneLivraison',
            'lignes.variante',
            'lignes.produit.medias' => fn ($requete) => $requete->orderByPivot('ordre'),
            'historique' => fn ($requete) => $requete->orderBy('id'),
            'historique.utilisateur',
            'paiementConfirmePar',
            'remboursePar',
        ]);

        return (new CommandeResource($fraiche))->response();
    }
}
