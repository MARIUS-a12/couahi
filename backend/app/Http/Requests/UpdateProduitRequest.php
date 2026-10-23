<?php

namespace App\Http\Requests;

use App\Enums\ModeStock;
use App\Enums\StatutProduit;
use App\Models\Categorie;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('produit'));
    }

    /**
     * Contrairement à la création, nom ne régénère JAMAIS slug ici : le slug
     * est dans l'URL publique et dans des liens déjà partagés (wa.me...). Le
     * changer au passage d'un renommage casserait ces liens en silence. Seul
     * un slug explicitement fourni dans le payload est pris en compte.
     */
    public function rules(): array
    {
        $produit = $this->route('produit');

        return [
            'nom' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('produits', 'slug')
                    ->where('etablissement_id', $produit->etablissement_id)
                    ->ignore($produit),
            ],
            'description' => ['nullable', 'string'],
            'reference' => ['nullable', 'string', 'max:255'],
            'prix' => ['sometimes', 'integer', 'min:0'],
            'prix_barre' => [
                'nullable',
                'integer',
                'min:0',
                // gt:prix ne suffit pas ici : si prix n'est pas dans CETTE
                // requête (mise à jour partielle), il faut comparer au prix
                // déjà en base, pas à un champ absent du payload.
                function (string $attribute, mixed $value, callable $fail) use ($produit): void {
                    if ($value === null) {
                        return;
                    }

                    $prix = $this->input('prix', $produit->prix);

                    if ($value <= $prix) {
                        $fail('Le prix barré doit être supérieur au prix.');
                    }
                },
            ],
            'categorie_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, callable $fail): void {
                    if ($value !== null && ! Categorie::whereKey($value)->exists()) {
                        $fail('La catégorie sélectionnée n\'appartient pas à cet établissement.');
                    }
                },
            ],
            'quantite_stock' => ['sometimes', 'integer', 'min:0'],
            'disponible' => ['sometimes', 'boolean'],
            'statut' => ['sometimes', Rule::enum(StatutProduit::class)],
            // Modifiable sous condition, vérifiée sous verrou par
            // ChangerModeStock (ProduitController::update) : la validation
            // tourne avant toute transaction et ne peut pas la garantir.
            'mode_stock' => ['sometimes', Rule::enum(ModeStock::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'prix.integer' => 'Le prix doit être un nombre entier (XOF), sans décimale.',
            'prix_barre.integer' => 'Le prix barré doit être un nombre entier (XOF), sans décimale.',
            'slug.regex' => 'Le slug doit être en kebab-case (lettres minuscules, chiffres, tirets).',
            'slug.unique' => 'Ce slug est déjà utilisé par un autre produit de cet établissement.',
        ];
    }
}
