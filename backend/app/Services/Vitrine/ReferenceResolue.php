<?php

namespace App\Services\Vitrine;

use App\Models\Produit;
use App\Models\ReferenceWhatsapp;
use App\Models\VarianteProduit;

/** Ce qu'une référence WhatsApp désigne, entièrement lu en base (voir ResoudreReferenceWhatsapp). */
final readonly class ReferenceResolue
{
    public function __construct(
        public ReferenceWhatsapp $reference,
        public Produit $produit,
        public ?VarianteProduit $variante,
        public int $prixUnitaire,
    ) {}
}
