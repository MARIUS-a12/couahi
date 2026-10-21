<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Montant remboursé en francs CFA entier (voir AGENTS.md), jamais plus que
 * ce que le commerçant a reçu ; il peut être moindre (frais retenus
 * d'un commun accord) — l'écart est alors noté dans l'historique.
 *
 * L'autorisation (gerer_commandes, via la policy "update") est vérifiée
 * AVANT la validation : un utilisateur sans ce droit reçoit 403, jamais
 * un 422 qui lui détaillerait les règles du formulaire.
 */
class EnregistrerRemboursementCommandeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('commande'));
    }

    protected function prepareForValidation(): void
    {
        $reference = $this->input('reference');

        $this->merge([
            'reference' => is_string($reference) && trim($reference) !== '' ? trim($reference) : null,
        ]);
    }

    public function rules(): array
    {
        $recu = (int) $this->route('commande')?->paiement_montant_recu;

        return [
            'montant' => ['required', 'integer', 'min:1', 'max:'.max($recu, 1)],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'montant.required' => 'Indiquez le montant remboursé.',
            'montant.integer' => 'Le montant doit être un nombre entier de francs CFA.',
            'montant.max' => 'Le montant remboursé ne peut pas dépasser le montant reçu.',
        ];
    }
}
