<?php

namespace App\Services\Vitrine;

use App\Models\Produit;
use App\Models\ReferenceWhatsapp;
use App\Models\VarianteProduit;
use Illuminate\Support\Str;

/**
 * Construit le lien wa.me du bouton "Commander sur WhatsApp" de la fiche
 * produit. Le message est composé ICI, côté serveur, jamais par le
 * frontend : c'est ce qui garantit que la référence opaque [REF:XXXXXXXX]
 * qu'il contient correspond exactement à une ligne de references_whatsapp.
 * Le reste du texte (nom, prix) n'est qu'un confort de lecture pour le
 * client, qui peut le modifier : il n'est jamais relu (voir
 * ResoudreReferenceWhatsapp).
 */
class GenerateurLienWhatsapp
{
    public function generer(Produit $produit, ?VarianteProduit $variante, string $numero): string
    {
        $code = $this->codeUnique();

        ReferenceWhatsapp::create([
            'code' => $code,
            'produit_id' => $produit->id,
            'variante_id' => $variante?->id,
            'expire_le' => now()->addDays(7),
        ]);

        $message = $this->message($produit, $variante, $code);

        return 'https://wa.me/'.$this->normaliserNumero($numero).'?text='.rawurlencode($message);
    }

    private function message(Produit $produit, ?VarianteProduit $variante, string $code): string
    {
        $prix = $variante?->prixEffectif() ?? $produit->prix;

        $lignes = ['Bonjour, je souhaite commander :', $produit->nom];

        if ($variante) {
            $lignes[] = "Variante : {$variante->nom}";
        }

        $lignes[] = 'Prix : '.$this->formaterPrix($prix);
        $lignes[] = "[REF:{$code}]";

        return implode("\n", $lignes);
    }

    private function formaterPrix(int $montant): string
    {
        return number_format($montant, 0, ',', ' ').' FCFA';
    }

    /**
     * wa.me n'accepte que des chiffres (indicatif pays inclus, sans "+" ni
     * espaces) : Etablissement::telephone est saisi librement au back-office,
     * donc potentiellement formaté ("+225 07 00 00 00 00").
     */
    private function normaliserNumero(string $numero): string
    {
        return preg_replace('/\D/', '', $numero) ?? '';
    }

    /**
     * 8 caractères alphanumériques, imprévisibles — jamais dérivés de
     * l'identifiant produit ou de son nom (voir la docblock de la classe).
     * La boucle ne s'exécute plus d'une fois en pratique : l'espace de
     * 62^8 rend une collision quasi impossible, mais la contrainte unique
     * sur la colonne existe et doit être respectée si jamais.
     */
    private function codeUnique(): string
    {
        do {
            $code = Str::random(8);
        } while (ReferenceWhatsapp::pourTousEtablissements()->where('code', $code)->exists());

        return $code;
    }
}
