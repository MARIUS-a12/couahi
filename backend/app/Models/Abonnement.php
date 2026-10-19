<?php

namespace App\Models;

use App\Enums\StatutAbonnement;
use App\Models\Concerns\AppartientAEtablissement;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abonnement d'un établissement à la plateforme (Étape 12). Le statut est
 * une conséquence des dates, jamais une décision : statutAu() le recalcule
 * entièrement depuis l'échéance et les jours de grâce, ce qui rend la tâche
 * quotidienne (ActualiserStatutsAbonnements) idempotente par construction —
 * la relancer ne fait que retrouver le même résultat, elle ne déplace
 * jamais l'échéance. Seul un paiement enregistré la déplace (voir
 * EnregistrerPaiementAbonnement).
 */
class Abonnement extends Model
{
    use AppartientAEtablissement;

    protected $table = 'abonnements';

    protected $fillable = [
        'montant_mensuel',
        'date_echeance',
        'statut',
        'jours_de_grace',
    ];

    protected function casts(): array
    {
        return [
            'montant_mensuel' => 'integer',
            'date_echeance' => 'immutable_date',
            'statut' => StatutAbonnement::class,
            'jours_de_grace' => 'integer',
        ];
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /**
     * Point unique de création : un commerçant doit pouvoir travailler
     * avant de payer, d'où l'essai plutôt qu'une échéance immédiate.
     */
    public static function ouvrirEssaiPour(Etablissement $etablissement): self
    {
        $abonnement = new self([
            'montant_mensuel' => (int) config('abonnement.montant_mensuel_defaut'),
            'date_echeance' => CarbonImmutable::today()->addDays((int) config('abonnement.jours_essai')),
            'statut' => StatutAbonnement::Essai,
            'jours_de_grace' => (int) config('abonnement.jours_de_grace_defaut'),
        ]);
        $abonnement->pourEtablissement($etablissement->id)->save();

        return $abonnement;
    }

    /**
     * Jusqu'à l'échéance incluse : essai (s'il n'a jamais payé) ou actif.
     * Ensuite, en retard pendant les jours de grâce, puis suspendu.
     */
    public function statutAu(CarbonInterface $jour): StatutAbonnement
    {
        $jour = CarbonImmutable::parse($jour)->startOfDay();

        if ($jour->lte($this->date_echeance)) {
            return $this->statut === StatutAbonnement::Essai ? StatutAbonnement::Essai : StatutAbonnement::Actif;
        }

        if ($jour->lte($this->dateCoupure())) {
            return StatutAbonnement::EnRetard;
        }

        return StatutAbonnement::Suspendu;
    }

    /**
     * Enregistre le statut du jour s'il a changé ; renvoie vrai dans ce
     * seul cas, pour que l'appelant sache s'il y a eu une transition.
     */
    public function actualiserStatut(?CarbonInterface $jour = null): bool
    {
        $nouveau = $this->statutAu($jour ?? CarbonImmutable::today());

        if ($nouveau === $this->statut) {
            return false;
        }

        $this->statut = $nouveau;
        $this->save();

        return true;
    }

    /** Dernier jour où la vitrine reste en ligne malgré le retard. */
    public function dateCoupure(): CarbonImmutable
    {
        return $this->date_echeance->addDays($this->jours_de_grace);
    }

    public function vitrineCoupee(): bool
    {
        return $this->statut === StatutAbonnement::Suspendu;
    }

    /** Jours jusqu'à l'échéance : négatif une fois dépassée. */
    public function joursAvantEcheance(?CarbonInterface $jour = null): int
    {
        $jour = CarbonImmutable::parse($jour ?? CarbonImmutable::today())->startOfDay();

        return (int) $jour->diffInDays($this->date_echeance, false);
    }

    /** Jours jusqu'à la coupure de la vitrine : négatif une fois coupée. */
    public function joursAvantCoupure(?CarbonInterface $jour = null): int
    {
        $jour = CarbonImmutable::parse($jour ?? CarbonImmutable::today())->startOfDay();

        return (int) $jour->diffInDays($this->dateCoupure(), false);
    }

    public function doitAvertir(?CarbonInterface $jour = null): bool
    {
        return $this->joursAvantEcheance($jour) < (int) config('abonnement.jours_avertissement');
    }
}
