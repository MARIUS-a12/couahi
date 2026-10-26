<?php

namespace App\Services\Commandes;

use App\Enums\Canal;
use App\Enums\ModePaiement;
use App\Enums\SourceCommande;
use App\Enums\StatutCommande;
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
        if (! $confirmerDoublon) {
            $doublons = $this->doublonsPossibles($etablissement, $client['telephone'] ?? null, $referenceWhatsapp);

            if ($doublons->isNotEmpty()) {
                throw new DoublonPossibleException($doublons);
            }
        }

        return DB::transaction(function () use ($etablissement, $canal, $lignes, $client, $commune, $autreCommune, $quartier, $referenceWhatsapp, $cleIdempotence, $auteur, $adresseIp) {
            $comptoir = $canal === Canal::Comptoir;

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

            if ($comptoir) {
                $commande->refresh();
                $this->transitionnerStatut->executer($commande, StatutCommande::Payee, StatutCommande::Livree, $auteur->id);
                $this->confirmerPaiement->executer($commande->refresh(), $commande->total, null, $auteur, $adresseIp);
            }

            return $commande->refresh();
        });
    }

    /**
     * Une commande encore en cours (ni livrée, ni annulée, ni expirée) de ce
     * même téléphone depuis moins de 24 heures, ou déjà créée depuis cette
     * même référence WhatsApp. Un avertissement, jamais un blocage : un
     * client peut commander deux fois dans la journée.
     *
     * @return Collection<int, Commande>
     */
    public function doublonsPossibles(Etablissement $etablissement, ?string $telephone, ?ReferenceWhatsapp $referenceWhatsapp): Collection
    {
        $parTelephone = $telephone === null ? collect() : $etablissement->commandes()
            ->pourTousEtablissements()
            ->whereHas('client', fn ($requete) => $requete->pourTousEtablissements()->where('telephone', $telephone))
            ->whereIn('statut', [StatutCommande::AttentePaiement, StatutCommande::Payee, StatutCommande::Prete])
            ->where('created_at', '>=', now()->subDay())
            ->get();

        $parReference = $referenceWhatsapp === null ? collect() : $etablissement->commandes()
            ->pourTousEtablissements()
            ->where('reference_whatsapp_id', $referenceWhatsapp->id)
            ->get();

        return $parTelephone->merge($parReference)->unique('id')->sortByDesc('id')->values();
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
