<?php

namespace Database\Factories;

use App\Models\Etablissement;
use App\Models\OptionProduit;
use App\Models\Produit;
use App\Models\ValeurOption;
use App\Models\VarianteProduit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Une variante est une combinaison de valeurs (voir la migration
 * creer_options_valeurs_et_combinaisons) : la factory n'en crée jamais une
 * sans valeur. Par défaut, la variante reçoit une valeur à son nom dans une
 * option « Variante » du produit ; dansOption() choisit le nom de cette
 * option, avecValeurs() fixe une combinaison de plusieurs options.
 *
 * @extends Factory<VarianteProduit>
 */
class VarianteProduitFactory extends Factory
{
    protected $model = VarianteProduit::class;

    public function definition(): array
    {
        return [
            'etablissement_id' => Etablissement::factory(),
            'produit_id' => Produit::factory(),
            'nom' => fake()->randomElement(['S', 'M', 'L', 'XL']).'-'.fake()->unique()->numberBetween(1, 999999),
            'reference' => null,
            'prix' => null,
            'quantite_stock' => fake()->numberBetween(1, 50),
            'quantite_reservee' => 0,
            'disponible' => true,
            'statut' => 'actif',
            // Évalué après produit_id et nom (attributs déjà résolus).
            'signature' => fn (array $attributs) => self::valeurAuNomDeLaVariante($attributs, 'Variante'),
        ];
    }

    /**
     * Rattache chaque valeur de la signature à la variante créée : le lien
     * (variante_valeur) a besoin de l'identifiant de la variante, connu
     * seulement après l'insertion.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (VarianteProduit $variante): void {
            $identifiants = array_map('intval', explode('-', $variante->signature));
            $valeurs = ValeurOption::pourTousEtablissements()->whereIn('id', $identifiants)->get();

            foreach ($valeurs as $valeur) {
                $variante->valeurs()->attach($valeur->id, ['option_id' => $valeur->option_id, 'produit_id' => $variante->produit_id]);
            }
        });
    }

    /**
     * Rattache la variante à un produit réel, en reprenant explicitement
     * son etablissement_id : le simple `for($produit)` ne fixerait que
     * produit_id (relation belongsTo), pas etablissement_id, qui n'a pas de
     * lien de relation avec `produit`.
     */
    public function pourProduit(Produit $produit): static
    {
        return $this->state(fn (array $attributes) => [
            'produit_id' => $produit->id,
            'etablissement_id' => $produit->etablissement_id,
        ]);
    }

    /**
     * Une seule option, nommée comme un commerçant la nommerait
     * (« Conditionnement », « Portion ») ; la valeur porte le nom de la
     * variante, à la suite des valeurs déjà créées dans cette option.
     */
    public function dansOption(string $nomOption): static
    {
        return $this->state([
            'signature' => fn (array $attributs) => self::valeurAuNomDeLaVariante($attributs, $nomOption),
        ]);
    }

    /**
     * Une combinaison de valeurs déjà créées (« 39 » et « Noir »), une par
     * option du produit.
     */
    public function avecValeurs(ValeurOption ...$valeurs): static
    {
        return $this->state([
            'signature' => VarianteProduit::signaturePour(array_map(fn (ValeurOption $valeur) => $valeur->id, $valeurs)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private static function valeurAuNomDeLaVariante(array $attributs, string $nomOption): string
    {
        $option = OptionProduit::pourTousEtablissements()
            ->where('produit_id', $attributs['produit_id'])
            ->where('nom', $nomOption)
            ->first();

        if ($option === null) {
            $option = (new OptionProduit([
                'produit_id' => $attributs['produit_id'],
                'nom' => $nomOption,
                'ordre' => OptionProduit::pourTousEtablissements()->where('produit_id', $attributs['produit_id'])->count() + 1,
            ]))->pourEtablissement((int) $attributs['etablissement_id']);
            $option->save();
        }

        $valeur = (new ValeurOption([
            'option_id' => $option->id,
            'libelle' => $attributs['nom'],
            'ordre' => ValeurOption::pourTousEtablissements()->where('option_id', $option->id)->count() + 1,
        ]))->pourEtablissement((int) $attributs['etablissement_id']);
        $valeur->save();

        return VarianteProduit::signaturePour([$valeur->id]);
    }
}
