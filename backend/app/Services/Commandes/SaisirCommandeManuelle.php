<?php

namespace App\Services\Commandes;

use App\Enums\Canal;
use App\Enums\ModePaiement;
use App\Enums\SourceCommande;
use App\Enums\StatutCommande;
use App\Enums\StatutDemandeWhatsapp;
use App\Exceptions\DemandeDejaTransformeeException;
use App\Exceptions\DoublonPossibleException;
use App\Models\Client;
use App\Models\Commande;
use App\Models\Etablissement;
use App\Models\ReferenceWhatsapp;
use App\Models\User;
use App\Services\Clients\TrouverOuCreerClient;
use App\Services\Stock\ConsommerReservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lot C — une commande saisie au back-office, par-dessus le noyau existant,
 * jamais à côté : CreerCommande (réservation, lignes, historique, numéro),
 * ConsommerReservation (la confirmation), TransitionnerStatutCommande et
 * ConfirmerPaiementCommande. Aucun calcul de prix ni de stock ici.
 *
 * - Comptoir : la vente est terminée à la saisie (argent encaissé,
 *   marchandise remise, client parti). Créée, confirmée, livrée et payée en
 *   espèces dans UNE transaction : si une étape échoue, rien n'est écrit.
 *   « Livrée » veut dire marchandise remise au client, ce qui est
 *   littéralement vrai au comptoir.
 * - Téléphone et WhatsApp : la vente est convenue. Confirmée à la création
 *   (le commerçant a parlé au client et vu son stock), non payée.
 *
 * Aucune n'expire (voir CreerCommande, source BackOffice).
 *
 * Lot D : avec une référence WhatsApp, la commande transforme la demande.
 * Dans la même transaction, la demande passe « traitee » ; si la création
 * échoue, elle reste comme elle était. Une demande déjà transformée est
 * refusée (DemandeDejaTransformeeException), jamais transformée deux fois :
 * sa ligne est verrouillée le temps de la transaction, et un index unique
 * sur commandes.reference_whatsapp_id tient même en cas de course.
 */
class SaisirCommandeManuelle
{
    public function __construct(
        private readonly CreerCommande $creerCommande,
        private readonly ConsommerReservation $consommerReservation,
        private readonly TransitionnerStatutCommande $transitionnerStatut,
        private readonly ConfirmerPaiementCommande $confirmerPaiement,
        private readonly TrouverOuCreerClient $trouverOuCreerClient,
    ) {}

    /**
     * @param  LigneCommandeDemandee[]  $lignes
     * @param  array{telephone: string, nom: string}|null  $client  téléphone déjà normalisé
     *
     * @throws DoublonPossibleException tant que $confirmerDoublon est faux
     * @throws DemandeDejaTransformeeException
     */
    public function executer(
        Etablissement $etablissement,
        Canal $canal,
        array $lignes,
        ?array $client,
        ?string $commune,
        bool $autreCommune,
        ?string $quartier,
        ?ReferenceWhatsapp $referenceWhatsapp,
        string $cleIdempotence,
        bool $confirmerDoublon,
        User $auteur,
        ?string $adresseIp,
    ): Commande {
        // Dans cet ordre : la même saisie rejouée (même clé) retrouve sa
        // commande ; une demande déjà transformée est refusée ; seulement
        // ensuite vient l'avertissement de doublon, qui ne doit masquer ni
        // l'un ni l'autre.
        $dejaSaisie = $etablissement->commandes()->pourTousEtablissements()->where('cle_idempotence', $cleIdempotence)->first();

        if ($dejaSaisie !== null) {
            return $dejaSaisie;
        }

        if ($referenceWhatsapp !== null) {
            $this->exigerDemandeLibre($referenceWhatsapp, $cleIdempotence);
        }

        if (! $confirmerDoublon) {
            $doublons = $this->doublonsPossibles($etablissement, $client['telephone'] ?? null);

            if ($doublons->isNotEmpty()) {
                throw new DoublonPossibleException($doublons);
            }
        }

        return DB::transaction(function () use ($etablissement, $canal, $lignes, $client, $commune, $autreCommune, $quartier, $referenceWhatsapp, $cleIdempotence, $auteur, $adresseIp) {
            $comptoir = $canal === Canal::Comptoir;

            if ($referenceWhatsapp !== null) {
                // Verrouillée jusqu'au commit : une seconde transformation
                // simultanée attend ici, puis voit la commande et est refusée.
                $referenceWhatsapp = ReferenceWhatsapp::pourTousEtablissements()->whereKey($referenceWhatsapp->id)->lockForUpdate()->firstOrFail();
                $existante = $this->exigerDemandeLibre($referenceWhatsapp, $cleIdempotence);

                if ($existante !== null) {
                    return $existante;
                }
            }

            $commande = $this->creerCommande->executer(
                etablissement: $etablissement,
                client: $client === null ? null : $this->client($etablissement, $client),
                lignes: $lignes,
                canal: $canal,
                source: SourceCommande::BackOffice,
                cleIdempotence: $cleIdempotence,
                zoneLivraison: $comptoir || $commune === null ? null : $etablissement->zoneLivraisonPour($commune, $autreCommune),
                modePaiement: $comptoir ? ModePaiement::Especes : null,
                auteur: $auteur,
                referenceWhatsapp: $referenceWhatsapp,
            );

            if (! $comptoir) {
                $commande->update(['commune' => $commune, 'quartier' => $quartier]);
            }

            $this->consommerReservation->executer($commande, $auteur->id);

            $referenceWhatsapp?->update(['statut' => StatutDemandeWhatsapp::Traitee, 'statut_change_le' => now()]);

            if ($comptoir) {
                $commande->refresh();
                $this->transitionnerStatut->executer($commande, StatutCommande::Payee, StatutCommande::Livree, $auteur->id);
                $this->confirmerPaiement->executer($commande->refresh(), $commande->total, null, $auteur, $adresseIp);
            }

            return $commande->refresh();
        });
    }

    /**
     * La commande déjà issue de cette demande : la même si c'est la même
     * saisie rejouée (même clé), un refus sinon.
     *
     * @throws DemandeDejaTransformeeException
     */
    private function exigerDemandeLibre(ReferenceWhatsapp $referenceWhatsapp, string $cleIdempotence): ?Commande
    {
        $existante = Commande::pourTousEtablissements()->where('reference_whatsapp_id', $referenceWhatsapp->id)->first();

        if ($existante !== null && $existante->cle_idempotence !== $cleIdempotence) {
            throw new DemandeDejaTransformeeException($existante);
        }

        return $existante;
    }

    /**
     * Une commande encore en cours (ni livrée, ni annulée, ni expirée) de ce
     * même téléphone depuis moins de 24 heures. Un avertissement, jamais un
     * blocage : un client peut commander deux fois dans la journée. (Une
     * référence WhatsApp déjà utilisée n'est plus un avertissement depuis le
     * lot D : c'est un refus, voir executer().)
     *
     * @return Collection<int, Commande>
     */
    public function doublonsPossibles(Etablissement $etablissement, ?string $telephone): Collection
    {
        return $telephone === null ? collect() : $etablissement->commandes()
            ->pourTousEtablissements()
            ->whereHas('client', fn ($requete) => $requete->pourTousEtablissements()->where('telephone', $telephone))
            ->whereIn('statut', [StatutCommande::AttentePaiement, StatutCommande::Payee, StatutCommande::Prete])
            ->where('created_at', '>=', now()->subDay())
            ->latest('id')
            ->get();
    }

    /**
     * Le client retrouvé par son téléphone, ou créé. Le nom saisi par le
     * commerçant fait foi : il vient de parler au client, il corrige un nom
     * mal orthographié ou incomplet.
     *
     * @param  array{telephone: string, nom: string}  $donnees
     */
    private function client(Etablissement $etablissement, array $donnees): Client
    {
        $client = $this->trouverOuCreerClient->executer($etablissement, $donnees['nom'], $donnees['telephone'], null);

        if ($client->nom !== $donnees['nom']) {
            $client->update(['nom' => $donnees['nom']]);
        }

        return $client;
    }
}
