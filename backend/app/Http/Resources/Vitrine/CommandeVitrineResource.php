<?php

namespace App\Http\Resources\Vitrine;

use App\Enums\ModePaiement;
use App\Support\Telephone\NormaliseurTelephone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Page de confirmation publique (voir GET /api/vitrine/commandes/{numero}) :
 * suppose "lignes" et "zoneLivraison" déjà chargées par le contrôleur.
 * Jamais le jeton d'accès lui-même dans cette réponse — il est déjà dans
 * l'URL que le client a en main, pas besoin de le lui renvoyer.
 */
class CommandeVitrineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'numero' => $this->numero,
            'statut' => $this->statut->value,
            'sous_total' => $this->sous_total,
            'frais_livraison' => $this->frais_livraison,
            'total' => $this->total,
            'note' => $this->note,
            'cree_le' => $this->created_at,
            'zone_livraison' => $this->zoneLivraison ? ['nom' => $this->zoneLivraison->nom] : null,
            // Étape 13 — "paiement_confirme" ne devient vrai QUE lorsque le
            // commerçant a constaté l'argent reçu : jamais déduit du statut
            // de la commande, qui est une autre machine d'état.
            'mode_paiement' => $this->mode_paiement?->value,
            'paiement_confirme' => $this->paiementEstConfirme(),
            // Ce que la cliente doit savoir de SON argent, sans jamais
            // déduire quoi que ce soit du statut : montant constaté par le
            // commerçant, reste à envoyer (paiement partiel sur une commande
            // vivante), remboursement dû ou effectué (commande annulée ou
            // expirée après paiement).
            'montant_recu' => $this->paiementEstConfirme() ? $this->paiement_montant_recu : null,
            'reste_du' => $this->resteDu(),
            'a_rembourser' => $this->montantARembourser(),
            'remboursement' => $this->rembourse_le === null ? null : [
                'montant' => $this->remboursement_montant,
                'le' => $this->rembourse_le,
            ],
            // Une commande annulée ou expirée ne se paie plus : aucune
            // instruction de transfert, jamais un numéro où envoyer de
            // l'argent pour rien.
            'mobile_money' => $this->estAbandonnee() ? null : $this->instructionsMobileMoney(),
            'lignes' => $this->lignes->map(fn ($ligne) => [
                'nom' => $ligne->nom_produit_capture,
                'quantite' => $ligne->quantite,
                'prix_unitaire' => $ligne->prix_unitaire,
                'total' => $ligne->total,
            ]),
        ];
    }

    /**
     * Les coordonnées ACTUELLES de l'établissement, pas une copie figée à
     * la commande : si le commerçant a changé de numéro entre-temps,
     * l'ancien ne doit plus recevoir d'argent. Null si le mode n'est pas le
     * Mobile Money, ou si le commerçant l'a retiré depuis (la page invite
     * alors à le contacter, jamais à envoyer de l'argent à l'aveugle).
     *
     * @return array{numero: string, numero_affiche: string, operateur: string, operateur_libelle: string, beneficiaire: string}|null
     */
    private function instructionsMobileMoney(): ?array
    {
        $etablissement = $this->etablissement;

        if ($this->mode_paiement !== ModePaiement::MobileMoney || ! $etablissement?->mobileMoneyEstConfigure()) {
            return null;
        }

        return [
            'numero' => $etablissement->mm_numero,
            'numero_affiche' => NormaliseurTelephone::formaterLocal($etablissement->mm_numero),
            'operateur' => $etablissement->mm_operateur->value,
            'operateur_libelle' => $etablissement->mm_operateur->libelle(),
            'beneficiaire' => $etablissement->mm_beneficiaire,
        ];
    }
}
