<?php

namespace App\Models;

use App\Enums\StatutDemandeWhatsapp;
use App\Models\Concerns\AppartientAEtablissement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Jeton opaque glissé dans le message WhatsApp pré-rempli d'une fiche
 * produit (voir GenerateurLienWhatsapp) : quand le client répond sur
 * WhatsApp, c'est ce code — jamais un texte à deviner — qui retrouve le
 * produit et la variante exacts (voir ResoudreReferenceWhatsapp). Expire après 7 jours,
 * voir estValide().
 *
 * Lot D : chaque référence est aussi une DEMANDE que le commerçant voit
 * dans « Demandes WhatsApp » et traite (statut). Une demande n'est pas une
 * commande : elle ne réserve aucun stock, n'a pas de numéro et ne compte
 * dans aucun chiffre. Transformée, elle est reliée à SA commande par
 * commandes.reference_whatsapp_id (unique : une seule commande par demande).
 */
class ReferenceWhatsapp extends Model
{
    use AppartientAEtablissement, HasFactory;

    protected $table = 'references_whatsapp';

    protected $fillable = [
        'code',
        'produit_id',
        'variante_id',
        'cle_visiteur',
        'expire_le',
        'statut',
        'statut_change_le',
    ];

    protected function casts(): array
    {
        return [
            'expire_le' => 'datetime',
            'statut' => StatutDemandeWhatsapp::class,
            'statut_change_le' => 'datetime',
        ];
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(VarianteProduit::class, 'variante_id');
    }

    /** La commande issue de cette demande, s'il y en a une (au plus une). */
    public function commande(): HasOne
    {
        return $this->hasOne(Commande::class, 'reference_whatsapp_id');
    }

    public function estValide(): bool
    {
        return $this->expire_le->isFuture();
    }
}
