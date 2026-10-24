<?php

namespace App\Http\Requests;

use App\Enums\StatutCategorie;
use App\Models\Categorie;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreCategorieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Categorie::class);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('slug') && $this->filled('nom')) {
            $this->merge(['slug' => Str::slug($this->input('nom'))]);
        }
    }

    /**
     * "Sacs", "sacs" et "Sacs  " doivent être vus comme la même catégorie :
     * comparaison en PHP (Str::lower, pas LOWER() en SQL) pour un résultat
     * fiable sur les caractères accentués quelle que soit la collation de la
     * base ; sans conséquence sur les perfs, une établissement n'a jamais
     * qu'une poignée de catégories.
     */
    public function categorieDejaExistante(): ?Categorie
    {
        $nom = trim((string) $this->input('nom', ''));

        if ($nom === '') {
            return null;
        }

        return Categorie::all()->first(
            fn (Categorie $categorie) => Str::lower(trim($categorie->nom)) === Str::lower($nom)
        );
    }

    public function rules(): array
    {
        $categorieExistante = $this->categorieDejaExistante();

        return [
            'nom' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug')
                    ->where('etablissement_id', app(ContexteEtablissement::class)->id())
                    ->ignore($categorieExistante),
            ],
            'description' => ['nullable', 'string'],
            'ordre' => ['nullable', 'integer'],
            'statut' => ['nullable', Rule::enum(StatutCategorie::class)],
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
        return null;
    }
}
