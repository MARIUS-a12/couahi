<?php

namespace App\Http\Controllers\Api\Vitrine;

use App\Enums\StatutProduit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vitrine\VerifierPanierRequest;
use App\Models\Produit;
use Illuminate\Http\JsonResponse;

/**
 * Réconciliation du panier à l'ouverture (Étape 6B) : un panier peut dater de
 * plusieurs jours, cette route relit l'état réel de chaque ligne depuis la
 * base — jamais un prix ou une disponibilité qu'aurait gardés le navigateur.
 */
class VitrinePanierController extends Controller
{
    public function verifier(VerifierPanierRequest $request): JsonResponse
    {
        $sousTotal = 0;
        $lignes = [];

        foreach ($request->validated('lignes') as $ligneBrute) {
            [$ligneReponse, $prixPourTotal] = $this->verifierLigne($ligneBrute);
            $lignes[] = $ligneReponse;
            $sousTotal += $prixPourTotal;
        }

        return response()->json(['lignes' => $lignes, 'sous_total' => $sousTotal]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function verifierLigne(array $ligneBrute): array
    {
        $quantite = $ligneBrute['quantite'];
        $varianteId = $ligneBrute['variante_id'] ?? null;

        $produit = Produit::with('variantes')
            ->where('statut', StatutProduit::Publie)
            ->find($ligneBrute['produit_id']);

        if ($produit === null) {
            return [$this->ligneRetiree($ligneBrute, null), 0];
        }

        $variante = null;

        if ($varianteId !== null) {
            $variante = $produit->variantes->firstWhere('id', $varianteId);

            // Une variante inactive ne se vend plus : retirée du panier comme
            // une variante disparue.
            if ($variante === null || ! $variante->estActive()) {
                return [$this->ligneRetiree($ligneBrute, $produit->nom), 0];
            }
        }

        $prixActuel = $variante?->prixEffectif() ?? $produit->prix;
        $disponible = $variante !== null
            ? $produit->mode_stock->estDisponibleEnQuantite(
                $variante->quantite_stock,
                $variante->quantite_reservee,
                $variante->disponible,
                $quantite,
            )
            : $produit->estDisponibleEnQuantite($quantite);

        $prixVu = $ligneBrute['prix_vu'] ?? null;

        $statut = match (true) {
            ! $disponible => 'epuise',
            $prixVu !== null && $prixVu !== $prixActuel => 'prix_modifie',
            default => 'disponible',
        };

        $nom = $variante !== null ? "{$produit->nom} — {$variante->nom}" : $produit->nom;

        $ligne = [
            'produit_id' => $produit->id,
            'variante_id' => $variante?->id,
            'quantite' => $quantite,
            'nom' => $nom,
            'prix_actuel' => $prixActuel,
            'disponible' => $disponible,
            'statut' => $statut,
        ];

        $prixPourTotal = in_array($statut, ['disponible', 'prix_modifie'], true) ? $prixActuel * $quantite : 0;

        return [$ligne, $prixPourTotal];
    }

    private function ligneRetiree(array $ligneBrute, ?string $nom): array
    {
        return [
            'produit_id' => $ligneBrute['produit_id'],
            'variante_id' => $ligneBrute['variante_id'] ?? null,
            'quantite' => $ligneBrute['quantite'],
            'nom' => $nom,
            'prix_actuel' => null,
            'disponible' => false,
            'statut' => 'retire',
        ];
    }
}
