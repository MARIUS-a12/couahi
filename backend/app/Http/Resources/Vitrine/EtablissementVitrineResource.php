<?php

namespace App\Http\Resources\Vitrine;

use App\Http\Resources\LogoResource;
use App\Support\Horaires\EtatHoraires;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * En-tête et pied de page de la vitrine (voir GET /api/vitrine/etablissement,
 * Étape 6A ter). Seuls les champs RENSEIGNÉS apparaissent — jamais une ligne
 * vide ni un "non renseigné" : au frontend de n'afficher que ce qui est
 * non-null (voir PiedDePageVitrine côté composants).
 *
 * "numero_whatsapp" retombe sur Etablissement::telephone si
 * telephone_whatsapp n'a pas encore été renseigné (compatibilité avec les
 * établissements créés avant l'Étape 6A ter, qui n'avaient que ce champ).
 *
 * "couleur_accent" retombe sur le vert de marque de HK Studio quand
 * l'établissement n'en a pas choisi — jamais null ici, pour que le
 * frontend n'ait aucun cas particulier à gérer.
 */
class EtablissementVitrineResource extends JsonResource
{
    private const COULEUR_PAR_DEFAUT = '#146c43';

    public function toArray(Request $request): array
    {
        return [
            'nom' => $this->nom,
            'type' => $this->type,
            'description' => $this->description,
            'logo' => $this->logoMedia ? new LogoResource($this->logoMedia) : null,
            'numero_whatsapp' => $this->telephone_whatsapp ?? $this->telephone,
            'telephone_fixe' => $this->telephone_fixe,
            'email_contact' => $this->email_contact,
            'adresse' => $this->adresse,
            'horaires' => $this->horaires,
            'etat_ouverture' => EtatHoraires::calculer($this->horaires),
            'lien_facebook' => $this->lien_facebook,
            'lien_instagram' => $this->lien_instagram,
            'lien_tiktok' => $this->lien_tiktok,
            'lien_site_web' => $this->lien_site_web,
            'couleur_accent' => $this->couleur_accent ?? self::COULEUR_PAR_DEFAUT,
            // Étape 6C-1 : jamais les identifiants, seulement s'ils existent
            // tous les trois — pilote la présence du bouton "Payer
            // maintenant" (voir BoutonsAchatVitrine côté frontend).
            'paiement_disponible' => $this->paiementEstConfigure(),
            // Étape 13 : LE moyen proposé au client — "cinetpay",
            // "mobile_money" ou null — jamais les deux à la fois (voir
            // Etablissement::modePaiementPropose). Le numéro Mobile Money
            // n'est donné qu'avec une commande (CommandeVitrineResource).
            'mode_paiement' => $this->modePaiementPropose()?->value,
        ];
    }
}
