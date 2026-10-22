<?php

namespace App\Models;

use App\Models\Concerns\AppartientAEtablissement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jeton opaque glissé dans le message WhatsApp pré-rempli d'une fiche
 * produit (voir GenerateurLienWhatsapp) : quand le client répond sur
 * WhatsApp, c'est ce code — jamais un texte à deviner — qui retrouve le
 * produit et la variante exacts (voir ResoudreReferenceWhatsapp). Expire après 7 jours,
 * voir estValide().
 */
class ReferenceWhatsapp extends Model
{
    use AppartientAEtablissement, HasFactory;

    protected $table = 'references_whatsapp';

    protected $fillable = [
        'code',
        'produit_id',
        'variante_id',
        'expire_le',
    ];

    protected function casts(): array
    {
        return [
            'expire_le' => 'datetime',
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

    public function estValide(): bool
    {
        return $this->expire_le->isFuture();
    }
}
