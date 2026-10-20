<?php

namespace App\Services\Commandes;

use App\Enums\Canal;
use App\Enums\ModePaiement;
use App\Enums\ModeStock;
use App\Enums\SourceCommande;
use App\Enums\StatutCommande;
use App\Enums\StatutReservation;
use App\Exceptions\ArticleIndisponibleException;
use App\Exceptions\SelectionVarianteInvalideException;
use App\Models\Client;
use App\Models\Commande;
use App\Models\Etablissement;
use App\Models\Produit;
use App\Models\VarianteProduit;
use App\Models\ZoneLivraison;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreerCommande
{
    public function __construct(
        private readonly GenererNumeroCommande $genererNumero,
    ) {}

    /**
     * @param  LigneCommandeDemandee[]  $lignes
     */
    public function executer(
        Etablissement $etablissement,
        Client $client,
        array $lignes,
        Canal $canal,
        SourceCommande $source,
        string $cleIdempotence,
        ?ZoneLivraison $zoneLivraison = null,
        ?ModePaiement $modePaiement = null,
    ): Commande {
        // pourTousEtablissements() partout dans ce service, jamais le
        // contexte ambiant : $etablissement, reçu en paramètre, est déjà la
        // seule source de vérité, et ce service doit rester correct même
        // sans contexte posé — c'est le cas de CHAQUE appel fait par un
        // processus concurrence:creer-commande (voir Tests\ConcurrenceTestCase),
        // qui tourne dans un processus séparé sans contexte du tout.
        $existante = $etablissement->commandes()->pourTousEtablissements()->where('cle_idempotence', $cleIdempotence)->first();

        if ($existante !== null) {
            return $existante;
        }

        try {
            return DB::transaction(fn () => $this->creerDansLaTransaction(
                $etablissement,
                $client,
                $lignes,
                $canal,
                $source,
                $cleIdempotence,
                $zoneLivraison,
                $modePaiement,
            ));
        } catch (UniqueConstraintViolationException) {
            // Une commande portant la même clé a été insérée entre notre
            // lecture initiale et notre propre INSERT (double appel
            // concurrent, ex. double clic client). La nôtre n'a rien réservé
            // — sa transaction est retombée en rollback — donc c'est celle de
            // l'autre appel qui fait foi.
            return $etablissement->commandes()->pourTousEtablissements()->where('cle_idempotence', $cleIdempotence)->firstOrFail();
        }
    }

    /**
     * @param  LigneCommandeDemandee[]  $lignes
     */
    private function creerDansLaTransaction(
        Etablissement $etablissement,
        Client $client,
        array $lignes,
        Canal $canal,
        SourceCommande $source,
        string $cleIdempotence,
        ?ZoneLivraison $zoneLivraison,
        ?ModePaiement $modePaiement,
    ): Commande {
        $lignesResolues = $this->resoudreEtTrierLignes($etablissement, $lignes);

        // Vérifie/réserve chaque ligne AVANT de rien écrire en base : un
        // échec ici doit encore pouvoir tout annuler sans rien avoir créé.
        foreach ($lignesResolues as $ligne) {
            $this->traiterLigne($ligne);
        }

        // Étape 13 : un transfert Mobile Money n'est constaté qu'à la main
        // par le commerçant — 15 minutes ne lui laisseraient pas le temps
        // de voir l'argent arriver avant que la commande n'expire et que
        // son stock ne soit relâché. 24 heures couvrent une soirée et une
        // nuit sans bloquer le stock indéfiniment.
        $expireLe = match (true) {
            $modePaiement === ModePaiement::MobileMoney => now()->addHours(24),
            $canal === Canal::Web => now()->addMinutes(15),
            default => now()->addHours(2),
        };

        $sousTotal = $lignesResolues->sum(fn (LigneResolue $ligne) => $ligne->total());
        $fraisLivraison = $zoneLivraison?->frais ?? 0;

        $commande = $etablissement->commandes()->create([
            'client_id' => $client->id,
            // Pris en DERNIER (après les verrous de stock) : le verrou du
            // compteur, tenu jusqu'au commit, fait attendre toutes les autres
            // commandes de cette boutique. Après lui, rien que des INSERT
            // (commande, historique, lignes, réservations) — jamais d'appel
            // réseau, d'envoi de message ni de traitement lourd ici : à
            // faire après le commit, hors de cette transaction.
            'numero' => $this->genererNumero->executer($etablissement->id),
            'source' => $source,
            'canal' => $canal,
            'zone_livraison_id' => $zoneLivraison?->id,
            'sous_total' => $sousTotal,
            'remise' => 0,
            'frais_livraison' => $fraisLivraison,
            'total' => $sousTotal + $fraisLivraison,
            'cle_idempotence' => $cleIdempotence,
            'expire_le' => $expireLe,
            'mode_paiement' => $modePaiement,
        ]);

        $commande->historique()->make([
            'ancien_statut' => null,
            'nouveau_statut' => StatutCommande::AttentePaiement,
            'utilisateur_id' => null,
            'motif' => null,
        ])->pourEtablissement($etablissement->id)->save();

        foreach ($lignesResolues as $ligne) {
            $commande->lignes()->make([
                'produit_id' => $ligne->produit->id,
                'variante_id' => $ligne->variante?->id,
                'nom_produit_capture' => $ligne->nomCapture(),
                'reference_capture' => $ligne->referenceCapture(),
                'prix_unitaire' => $ligne->prixUnitaire(),
                'quantite' => $ligne->quantite,
                'total' => $ligne->total(),
            ])->pourEtablissement($etablissement->id)->save();

            if ($ligne->produit->mode_stock === ModeStock::Compte) {
                $commande->reservations()->make([
                    'produit_id' => $ligne->produit->id,
                    'variante_id' => $ligne->variante?->id,
                    'quantite' => $ligne->quantite,
                    'statut' => StatutReservation::Active,
                    'expire_le' => $expireLe,
                ])->pourEtablissement($etablissement->id)->save();
            }
        }

        return $commande;
    }

    /**
     * Résout chaque ligne demandée puis les trie dans un ordre déterministe :
     * les lignes en mode interrupteur ne verrouillent jamais rien (simple
     * lecture de `disponible`, voir traiterLigne) et passent en tête, sans
     * incidence sur l'ordre des verrous. Les lignes en mode compte verrouillent
     * soit une variante (produit avec déclinaisons), soit le produit lui-même
     * (produit sans variante — son propre stock fait foi) : on les trie
     * produits d'abord, puis variantes, id croissant dans chaque groupe. Cet
     * ordre global, identique pour toute commande touchant les mêmes articles,
     * empêche deux commandes concurrentes de s'interbloquer en verrouillant
     * en sens inverse.
     *
     * @param  LigneCommandeDemandee[]  $lignes
     * @return Collection<int, LigneResolue>
     */
    private function resoudreEtTrierLignes(Etablissement $etablissement, array $lignes): Collection
    {
        return collect($lignes)
            ->map(fn (LigneCommandeDemandee $ligne) => $this->resoudreLigne($etablissement, $ligne))
            ->sortBy(fn (LigneResolue $ligne) => $this->cleTriVerrou($ligne))
            ->values();
    }

    private function cleTriVerrou(LigneResolue $ligne): string
    {
        if ($ligne->produit->mode_stock === ModeStock::Interrupteur) {
            return '0-'.str_pad('0', 10, '0', STR_PAD_LEFT);
        }

        return $ligne->variante !== null
            ? '2-'.str_pad((string) $ligne->variante->id, 10, '0', STR_PAD_LEFT)
            : '1-'.str_pad((string) $ligne->produit->id, 10, '0', STR_PAD_LEFT);
    }

    private function resoudreLigne(Etablissement $etablissement, LigneCommandeDemandee $demande): LigneResolue
    {
        $produit = $etablissement->produits()
            ->pourTousEtablissements()
            ->with(['variantes' => fn ($requete) => $requete->pourTousEtablissements()])
            ->findOrFail($demande->produitId);
        $variante = $this->resoudreVariante($produit, $demande);

        return new LigneResolue($produit, $variante, $demande->quantite);
    }

    /**
     * Un produit sans variante porte son propre stock (règle du catalogue) :
     * l'absence de variante n'est donc jamais un problème en soi. Une
     * variante explicitement choisie doit appartenir au produit indiqué ; à
     * défaut, elle ne peut être déduite que si le produit n'en a qu'une —
     * plusieurs variantes sans choix explicite est une sélection ambiguë.
     */
    private function resoudreVariante(Produit $produit, LigneCommandeDemandee $demande): ?VarianteProduit
    {
        if ($demande->varianteId !== null) {
            $variante = $produit->variantes->firstWhere('id', $demande->varianteId);

            if ($variante === null) {
                throw new SelectionVarianteInvalideException(
                    "La variante #{$demande->varianteId} n'appartient pas au produit #{$produit->id}."
                );
            }

            return $this->lierProduit($variante, $produit);
        }

        return match ($produit->variantes->count()) {
            0 => null,
            1 => $this->lierProduit($produit->variantes->first(), $produit),
            default => throw new SelectionVarianteInvalideException(
                "Le produit \"{$produit->nom}\" a plusieurs variantes : précisez laquelle."
            ),
        };
    }

    /**
     * L'eager load de `variantes` (resoudreLigne) ne renseigne pas la
     * relation inverse `variante->produit` : un accès non lié (ex.
     * VarianteProduit::prixEffectif()) déclencherait un lazy load qui
     * applique le scope global de Produit — et lève, faute de contexte
     * ambiant, dans CHAQUE processus concurrence:creer-commande (voir
     * Tests\ConcurrenceTestCase). $produit est déjà en main : pas besoin
     * d'une requête, juste de le rattacher explicitement.
     */
    private function lierProduit(VarianteProduit $variante, Produit $produit): VarianteProduit
    {
        return $variante->setRelation('produit', $produit);
    }

    /**
     * Mode interrupteur : simple lecture de `disponible`, jamais de
     * réservation (décision assumée : deux clients peuvent commander le
     * dernier plat). Mode compte : réservation atomique par UPDATE
     * conditionnel — jamais de lecture préalable — sur la variante si la
     * ligne en cible une, sinon sur le produit lui-même.
     */
    private function traiterLigne(LigneResolue $ligne): void
    {
        if ($ligne->produit->mode_stock === ModeStock::Interrupteur) {
            $disponible = ($ligne->variante ?? $ligne->produit)->estDisponibleEnQuantite($ligne->quantite);

            if (! $disponible) {
                throw ArticleIndisponibleException::nonDisponible($ligne->produit, $ligne->variante);
            }

            return;
        }

        $reserve = $ligne->variante !== null
            ? VarianteProduit::reserverAtomiquement($ligne->variante->id, $ligne->quantite)
            : Produit::reserverAtomiquement($ligne->produit->id, $ligne->quantite);

        if (! $reserve) {
            throw ArticleIndisponibleException::stockInsuffisant($ligne->produit, $ligne->variante, $ligne->quantite);
        }
    }
}
