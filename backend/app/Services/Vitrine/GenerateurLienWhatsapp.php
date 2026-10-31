<?php

namespace App\Services\Vitrine;

use App\Models\Produit;
use App\Models\ReferenceWhatsapp;
use App\Models\VarianteProduit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Construit le lien wa.me du bouton "Commander sur WhatsApp" de la fiche
 * produit. Le message est composé ICI, côté serveur, jamais par le
 * frontend : c'est ce qui garantit que la référence opaque [REF:XXXXXXXX]
 * qu'il contient correspond exactement à une ligne de references_whatsapp.
 * Le reste du texte (nom, prix) n'est qu'un confort de lecture pour le
 * client, qui peut le modifier : il n'est jamais relu (voir
 * ResoudreReferenceWhatsapp).
 *
 * Génération idempotente : le même visiteur qui redemande un lien pour le
 * même article dans les 10 minutes reçoit la MÊME référence, sans nouvelle
 * ligne. C'est la vraie protection de cette route publique anonyme (voir
 * AGENTS.md, dérogation à la règle email + IP) : un client hésitant qui
 * clique trois fois ne crée qu'une demande dans la liste du commerçant, et
 * des milliers de clics d'un même script n'en créent pas des milliers.
 *
 * La recherche puis la création passent sous un verrou de cache court, par
 * visiteur et par article : entre le SELECT et l'INSERT il y a un
 * aller-retour vers la base, et un double appui sur mobile (100 à 300 ms
 * d'écart) tombe exactement dans cette fenêtre — sans verrou, deux
 * références, donc deux demandes dans la liste du commerçant. Pas d'index
 * unique : l'unicité ne vaut que 10 minutes, elle n'est pas constante.
 */
class GenerateurLienWhatsapp
{
    public const FENETRE_DEDUPLICATION_MINUTES = 10;

    /** Durée de vie du verrou : bien au-delà d'une génération normale. */
    public const DUREE_VERROU_SECONDES = 10;

    /** Attente maximale d'une seconde requête derrière la première. */
    public const ATTENTE_VERROU_SECONDES = 3;

    /**
     * $cleVisiteur : empreinte qui identifie le visiteur (voir
     * VitrineProduitController::cleVisiteur), jamais un identifiant brut.
     */
    public function generer(Produit $produit, ?VarianteProduit $variante, string $numero, string $cleVisiteur): string
    {
        $cleVerrou = "lien-whatsapp:{$produit->etablissement_id}:{$cleVisiteur}:{$produit->id}:".($variante?->id ?? '-');

        $code = Cache::lock($cleVerrou, self::DUREE_VERROU_SECONDES)
            ->block(self::ATTENTE_VERROU_SECONDES, fn () => $this->referenceExistante($produit, $variante, $cleVisiteur)
                ?? $this->creerReference($produit, $variante, $cleVisiteur));

        $message = $this->message($produit, $variante, $code);

        return 'https://wa.me/'.$this->normaliserNumero($numero).'?text='.rawurlencode($message);
    }

    private function referenceExistante(Produit $produit, ?VarianteProduit $variante, string $cleVisiteur): ?string
    {
        return ReferenceWhatsapp::query()
            ->where('cle_visiteur', $cleVisiteur)
            ->where('produit_id', $produit->id)
            ->where(fn ($requete) => $variante === null
                ? $requete->whereNull('variante_id')
                : $requete->where('variante_id', $variante->id))
            ->where('created_at', '>=', now()->subMinutes(self::FENETRE_DEDUPLICATION_MINUTES))
            ->latest('id')
            ->value('code');
    }

    private function creerReference(Produit $produit, ?VarianteProduit $variante, string $cleVisiteur): string
    {
        $code = $this->codeUnique();

        ReferenceWhatsapp::create([
            'code' => $code,
            'produit_id' => $produit->id,
            'variante_id' => $variante?->id,
            'cle_visiteur' => $cleVisiteur,
            'expire_le' => now()->addDays(7),
        ]);

        return $code;
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
     *
     * pourTousEtablissements() : l'unicité du code est globale (contrainte
     * sur toute la table), et la requête ne renvoie qu'un booléen, aucune
     * ligne d'un autre établissement. Un code pris ailleurs est remplacé
     * en silence : la réponse ne doit jamais révéler qu'il existe
     * (exception nommée dans decisions.md, 31/10 ; LienWhatsappApiTest).
     */
    private function codeUnique(): string
    {
        do {
            $code = Str::random(8);
        } while (ReferenceWhatsapp::pourTousEtablissements()->where('code', $code)->exists());

        return $code;
    }
}
