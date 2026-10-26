<?php

namespace App\Http\Requests;

use App\Enums\Canal;
use App\Rules\CommuneDeLivraison;
use App\Rules\NumeroTelephoneIvoirien;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lot C — une commande saisie au back-office. Comme à la vitrine, les
 * lignes n'arrivent que par identifiants et quantités : un « prix » envoyé
 * n'est pas dans les champs validés, le serveur ne peut pas le lire (voir
 * CreerCommande, qui relit la base).
 *
 * Le canal décide du reste. Comptoir : des articles seulement, un client
 * facultatif (un habitué qu'on identifie). Téléphone et WhatsApp : sans
 * numéro on ne livre pas, puis nom, commune et quartier, avec les mêmes
 * règles de commune que la vitrine (CommuneDeLivraison).
 */
class SaisirCommandeManuelleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->peut('creer_commandes');
    }

    protected function prepareForValidation(): void
    {
        foreach (['commune', 'quartier', 'reference'] as $champ) {
            if (is_string($this->input($champ))) {
                $this->merge([$champ => trim($this->input($champ))]);
            }
        }
    }

    public function rules(): array
    {
        $comptoir = $this->input('canal') === Canal::Comptoir->value;
        $etablissement = app(ContexteEtablissement::class)->obtenir();

        return [
            'canal' => ['required', Rule::in([Canal::Comptoir->value, Canal::Whatsapp->value, Canal::Telephone->value])],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'integer'],
            'lignes.*.variante_id' => ['nullable', 'integer'],
            'lignes.*.quantite' => ['required', 'integer', 'min:1', 'max:999'],
            'client' => ['nullable', 'array'],
            'client.telephone' => [$comptoir ? 'required_with:client' : 'required', 'nullable', 'string', new NumeroTelephoneIvoirien],
            'client.nom' => [$comptoir ? 'required_with:client' : 'required', 'nullable', 'string', 'max:255'],
            'commune' => $comptoir ? ['prohibited'] : ['required', 'string', 'max:100', new CommuneDeLivraison($etablissement, $this->boolean('autre_commune'))],
            'autre_commune' => ['sometimes', 'boolean'],
            'quartier' => $comptoir ? ['prohibited'] : ['required', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:2000'],
            'cle_idempotence' => ['required', 'string', 'max:100'],
            'confirmer_doublon' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'canal.required' => 'Choisissez le canal de la vente.',
            'lignes.required' => 'Ajoutez au moins un article.',
            'client.telephone.required' => 'Le numéro de téléphone est obligatoire : sans lui, on ne livre pas.',
            'client.nom.required' => 'Indiquez le nom du client.',
            'commune.prohibited' => 'Une vente au comptoir n’a pas d’adresse de livraison.',
            'quartier.prohibited' => 'Une vente au comptoir n’a pas d’adresse de livraison.',
        ];
    }
}
