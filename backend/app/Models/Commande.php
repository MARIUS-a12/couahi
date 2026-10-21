<?php

namespace App\Models;

use App\Enums\Canal;
use App\Enums\ModePaiement;
use App\Enums\SourceCommande;
use App\Enums\StatutCommande;
use App\Models\Concerns\AppartientAEtablissement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Commande extends Model
{
    use AppartientAEtablissement, HasFactory;

    protected $table = 'commandes';

    protected $attributes = [
        'statut' => 'attente_paiement',
        'sous_total' => 0,
        'remise' => 0,
        'frais_livraison' => 0,
        'total' => 0,
    ];

    protected $fillable = [
        'client_id',
        'numero',
        'statut',
        'source',
        'canal',
        'zone_livraison_id',
        'sous_total',
        'remise',
        'frais_livraison',
        'total',
        'cle_idempotence',
        'expire_le',
        'payee_le',
        'note',
        'commune',
        'quartier',
        'mode_paiement',
    ];

    /**
     * jeton_acces n'est délibérément pas fillable (comme etablissement_id,
     * voir AppartientAEtablissement) : un numéro de commande seul se devine
     * (CMD-000847), ce jeton est ce qui protège la page de confirmation
     * publique — il ne doit jamais pouvoir être posé par un payload entrant,
     * seulement généré ici, côté serveur.
     */
    protected static function booted(): void
    {
        static::creating(function (self $commande): void {
            if ($commande->jeton_acces === null) {
                $commande->jeton_acces = Str::random(48);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'statut' => StatutCommande::class,
            'source' => SourceCommande::class,
            'canal' => Canal::class,
            'sous_total' => 'integer',
            'remise' => 'integer',
            'frais_livraison' => 'integer',
            'total' => 'integer',
            'expire_le' => 'datetime',
            'payee_le' => 'datetime',
            'mode_paiement' => ModePaiement::class,
            'paiement_confirme_le' => 'datetime',
            'paiement_montant_recu' => 'integer',
            'rembourse_le' => 'datetime',
            'remboursement_montant' => 'integer',
        ];
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function zoneLivraison(): BelongsTo
    {
        return $this->belongsTo(ZoneLivraison::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneCommande::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(ReservationStock::class);
    }

    public function paiementConfirmePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paiement_confirme_par');
    }

    public function paiementEstConfirme(): bool
    {
        return $this->paiement_confirme_le !== null;
    }

    public function remboursePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rembourse_par');
    }

    /** Annulée ou expirée : plus rien à livrer, plus rien à encaisser. */
    public function estAbandonnee(): bool
    {
        return in_array($this->statut, [StatutCommande::Annulee, StatutCommande::Expiree], true);
    }

    /**
     * Le client a envoyé MOINS que le total. Jamais "payée" : il manque de
     * l'argent (voir resteDu). Un montant supérieur au total n'est pas un
     * paiement partiel — l'écart reste simplement noté.
     */
    public function paiementPartiel(): bool
    {
        return $this->paiementEstConfirme() && $this->paiement_montant_recu < $this->total;
    }

    /** Ce que le client doit encore envoyer, tant que la commande vit ; sinon null. */
    public function resteDu(): ?int
    {
        return $this->paiementPartiel() && ! $this->estAbandonnee()
            ? $this->total - $this->paiement_montant_recu
            : null;
    }

    /**
     * Paiement partiel sur une commande pas encore acceptée : le commerçant
     * doit DÉCIDER (relancer le client pour le reste, accepter quand même,
     * ou annuler et rembourser). Signalée en priorité dans sa liste, comme
     * une commande payée non traitée — sans attendre 2 heures : le client a
     * versé de l'argent, et la commande n'expire plus.
     */
    public function partielleADecider(): bool
    {
        return $this->paiementPartiel() && $this->statut === StatutCommande::AttentePaiement;
    }

    /**
     * Étape 13 — un transfert attendu depuis plus de 2 heures : le
     * commerçant doit vérifier son téléphone ou relancer le client. Hors
     * commandes annulées ou expirées, qu'on n'attend plus.
     */
    public function paiementEnRetard(): bool
    {
        return $this->mode_paiement === ModePaiement::MobileMoney
            && ! $this->paiementEstConfirme()
            && ! in_array($this->statut, [StatutCommande::Annulee, StatutCommande::Expiree], true)
            && $this->created_at->lt(now()->subHours(2));
    }

    /**
     * Le cas le plus urgent de toute la liste du commerçant : il a reçu
     * l'argent mais n'a toujours pas accepté la commande, 2 heures après —
     * il doit de la marchandise (ou un remboursement) à quelqu'un. Une telle
     * commande n'expire jamais (voir LibererReservationsExpirees).
     */
    public function payeeATraiterEnUrgence(): bool
    {
        // Un paiement PARTIEL a son propre signal, immédiat (voir
        // partielleADecider) : jamais présenté comme "payée".
        return $this->paiementEstConfirme()
            && ! $this->paiementPartiel()
            && $this->statut === StatutCommande::AttentePaiement
            && $this->paiement_confirme_le->lt(now()->subHours(2));
    }

    /**
     * Argent reçu pour une commande qui ne sera pas honorée (annulée, ou
     * expirée avant que le paiement soit constaté) et pas encore rendu : le
     * montant à rembourser au client, sinon null. Un remboursement
     * enregistré (voir EnregistrerRemboursementCommande) solde la dette.
     */
    public function montantARembourser(): ?int
    {
        return $this->paiementEstConfirme() && $this->estAbandonnee() && $this->rembourse_le === null
            ? $this->paiement_montant_recu
            : null;
    }

    public function historique(): HasMany
    {
        return $this->hasMany(HistoriqueCommande::class);
    }
}
