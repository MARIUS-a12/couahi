<?php

namespace App\Http\Requests;

use App\Models\Etablissement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Seul le format est vérifié ici. La condition (aucun produit) l'est sous
 * verrou dans EtablissementController::modifierType, et l'autorisation
 * (super-admin) dans le contrôleur, comme pour les autres actions de ce
 * contrôleur.
 */
class ModifierTypeEtablissementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(Etablissement::TYPES)],
        ];
    }
}
