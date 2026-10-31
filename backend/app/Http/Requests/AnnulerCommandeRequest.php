<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Motif choisi dans une liste courte, avec un champ libre réservé à
 * "autre" — jamais un motif entièrement libre pour les cas fréquents, pour
 * que l'historique reste lisible et comparable d'une commande à l'autre.
 */
class AnnulerCommandeRequest extends FormRequest
{
    public const LIBELLES_MOTIFS = [
        'article_indisponible' => 'Article indisponible',
        'client_injoignable' => 'Client injoignable',
        'client_annule' => 'Le client a annulé',
    ];

    /**
     * Avant la validation : sans le droit, un 403, jamais un 422 qui
     * détaillerait le formulaire (voir CommandePolicy::annuler).
     */
    public function authorize(): bool
    {
        return Gate::allows('annuler', $this->route('commande'));
    }

    public function rules(): array
    {
        return [
            'motif_type' => ['required', Rule::in([...array_keys(self::LIBELLES_MOTIFS), 'autre'])],
            'motif_autre' => ['required_if:motif_type,autre', 'string', 'max:255'],
        ];
    }

    /**
     * La seule chaîne effectivement écrite dans historique_commande.motif —
     * un motif structuré (type + libre) côté API, un texte lisible côté
     * historique.
     */
    public function motifFinal(): string
    {
        $type = $this->validated('motif_type');

        return $type === 'autre' ? $this->validated('motif_autre') : self::LIBELLES_MOTIFS[$type];
    }
}
