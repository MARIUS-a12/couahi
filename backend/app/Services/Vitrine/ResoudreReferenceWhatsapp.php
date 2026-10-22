<?php

namespace App\Services\Vitrine;

use App\Models\ReferenceWhatsapp;

/**
 * Retrouve l'article désigné par un message WhatsApp reçu. SEULE la
 * référence [REF:XXXXXXXX] fait foi : le reste du message est un texte que
 * le client a pu modifier librement avant l'envoi (prix, nom, variante,
 * quantité). Produit, variante et prix viennent donc de la base, jamais du
 * texte — le prix est celui d'aujourd'hui, pas celui affiché dans le
 * message. La référence ne porte aucune quantité : elle n'est pas résolue
 * ici, et ne doit jamais être lue dans le message non plus (voir
 * decisions.md, « Seule la référence fait foi »).
 *
 * Renvoie null quand la référence est absente, inconnue (dans
 * l'établissement courant : le scope tenant écarte celles des autres),
 * expirée, ou ambiguë (plusieurs références différentes dans un même
 * message : on ne choisit pas à la place du commerçant).
 */
class ResoudreReferenceWhatsapp
{
    public function depuisMessage(string $message): ?ReferenceResolue
    {
        preg_match_all('/\[REF:([A-Za-z0-9]{8})\]/', $message, $correspondances);
        $codes = array_values(array_unique($correspondances[1]));

        if (count($codes) !== 1) {
            return null;
        }

        $reference = ReferenceWhatsapp::query()
            ->where('code', $codes[0])
            ->with(['produit', 'variante'])
            ->first();

        if ($reference === null || ! $reference->estValide() || $reference->produit === null) {
            return null;
        }

        return new ReferenceResolue(
            reference: $reference,
            produit: $reference->produit,
            variante: $reference->variante,
            prixUnitaire: $reference->variante?->prixEffectif() ?? $reference->produit->prix,
        );
    }
}
