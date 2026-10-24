<?php

namespace App\Http\Requests;

use App\Enums\StatutCategorie;
use App\Models\Categorie;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategorieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('categorie'));
    }

    /**
     * nom ne régénère jamais slug en modification, voir UpdateProduitRequest.
     */
    public function rules(): array
    {
        $categorie = $this->route('categorie');

        return [
            'nom' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug')
                    ->where('etablissement_id', $categorie->etablissement_id)
                    ->ignore($categorie),
            ],
            'description' => ['nullable', 'string'],
            'ordre' => ['nullable', 'integer'],
            'statut' => ['sometimes', Rule::enum(StatutCategorie::class)],
            'parent_id' => [
                'nullable',
                'integer',
                // La base refuse aussi un troisième niveau (clé composée,
                // voir la migration ajouter_parent_aux_categories) ; ici,
                // avec un message lisible.
                function (string $attribut, mixed $valeur, \Closure $echec): void {
                    if ($valeur === null) {
                        return;
                    }

                    $parent = Categorie::find($valeur);

                    if ($parent === null) {
                        $echec("Cette catégorie parente n'existe pas.");
                    } elseif ($parent->parent_id !== null) {
                        $echec("« {$parent->nom} » est déjà une sous-catégorie : deux niveaux au plus.");
                    } elseif ($this->categorieModifiee() !== null && $parent->id === $this->categorieModifiee()->id) {
                        $echec('Une catégorie ne peut pas être sa propre catégorie parente.');
                    } elseif ($this->categorieModifiee()?->enfants()->exists()) {
                        $echec('Cette catégorie a des sous-catégories : elle ne peut pas devenir elle-même une sous-catégorie.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Le slug doit être en kebab-case (lettres minuscules, chiffres, tirets).',
            'slug.unique' => 'Ce slug est déjà utilisé par une autre catégorie de cet établissement.',
        ];
    }

    private function categorieModifiee(): ?Categorie
    {
        return $this->route('categorie');
    }
}
