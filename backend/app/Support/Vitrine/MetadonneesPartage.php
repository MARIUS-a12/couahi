<?php

namespace App\Support\Vitrine;

use App\Enums\StatutProduit;
use App\Models\Etablissement;
use App\Models\Produit;

/**
 * Ce qu'affiche l'aperçu d'un lien de vitrine partagé (WhatsApp, Facebook) :
 * titre, description, image. WhatsApp ne lit QUE le HTML renvoyé par le
 * serveur, jamais le résultat du JavaScript — ces valeurs doivent donc être
 * posées dans la page avant tout rendu React (voir le greffon Vite
 * "metadonneesPartage", et decisions.md pour la production).
 *
 * - Accueil, panier, etc. : nom de la boutique, sa description, son logo.
 * - Fiche produit : "Produit — Boutique", la description du produit (à
 *   défaut, celle de la boutique), sa photo principale (à défaut, le logo).
 *   Un produit non publié, ou d'un autre établissement, retombe sur la
 *   boutique : jamais un nom de produit qui ne se vend pas ici.
 */
class MetadonneesPartage
{
    private const LONGUEUR_DESCRIPTION = 200;

    /** @return array{titre: string, description: string, image: ?string} */
    public static function pour(Etablissement $etablissement, string $chemin): array
    {
        $boutique = [
            'titre' => $etablissement->nom,
            'description' => self::abreger($etablissement->description ?? "Commandez en ligne chez {$etablissement->nom}."),
            'image' => $etablissement->logoMedia?->urlVariante('grand', 'jpg'),
        ];

        if (preg_match('#^/produit/(\d+)/?$#', $chemin, $correspondance) !== 1) {
            return $boutique;
        }

        // Scope global actif : la requête ne voit que les produits de
        // l'établissement résolu par le sous-domaine.
        $produit = Produit::where('statut', StatutProduit::Publie)->with('medias')->find((int) $correspondance[1]);

        if ($produit === null) {
            return $boutique;
        }

        $photo = $produit->medias->sortBy('pivot.ordre')->first();

        return [
            'titre' => "{$produit->nom} — {$etablissement->nom}",
            'description' => $produit->description ? self::abreger($produit->description) : $boutique['description'],
            'image' => $photo?->urlVariante('moyenne', 'jpg') ?? $boutique['image'],
        ];
    }

    private static function abreger(string $texte): string
    {
        $texte = trim((string) preg_replace('/\s+/u', ' ', $texte));

        return mb_strlen($texte) <= self::LONGUEUR_DESCRIPTION
            ? $texte
            : rtrim(mb_substr($texte, 0, self::LONGUEUR_DESCRIPTION - 1)).'…';
    }
}
