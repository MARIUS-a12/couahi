/**
 * Écran public "/commander" — une seule page, pas un assistant en plusieurs
 * écrans (voir Étape 6B) : coordonnées, adresse de livraison, et le
 * récapitulatif recalculé par le serveur, tout sur le même écran. La clé
 * d'idempotence est générée une fois à l'ouverture de la page et conservée
 * tant qu'on ne la quitte pas (useState avec initialiseur) — un double clic
 * sur "Valider la commande" envoie deux fois la MÊME clé, jamais deux
 * commandes (voir CreerCommande, côté serveur, qui la rejoue).
 *
 * Correctif livraison : Email et Note (inutiles pour un commerçant ivoirien)
 * ont été retirés, remplacés par Commune et Quartier, tous deux
 * obligatoires — ce dont un livreur a réellement besoin. "Commune" remplace
 * l'ancien sélecteur de zone de livraison (une liste déroulante quand
 * l'établissement en a, du texte libre sinon) : on ne demande pas deux fois
 * la même chose.
 *
 * « Autre commune » : quand la boutique facture par commune, un client dont
 * la commune n'est pas dans la liste la saisit librement. La livraison est
 * alors à convenir, le total n'en inclut pas, et la boutique l'appelle.
 * Perdre un client de Bingerville coûte plus qu'un total sans frais.
 *
 * Étape 13 : quand l'établissement propose le Mobile Money, la commande est
 * passée dans ce mode — le client reçoit le numéro à payer sur la page de
 * confirmation, jamais avant d'avoir une commande et son numéro à citer.
 */
import { useState } from 'react'
import type { FormEvent } from 'react'
import axios from 'axios'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { BoutonRetour } from '../components/BoutonRetour'
import { ChampTexteVitrine } from '../components/ChampTexteVitrine'
import { EtatBouton } from '../components/EtatBouton'
import { EtatPanierVide } from '../components/EtatPanierVide'
import { useEtablissementVitrine } from '../hooks/useEtablissementVitrine'
import { usePanier } from '../hooks/usePanier'
import { useVerifierPanier } from '../hooks/useVerifierPanier'
import { creerCommandeVitrine, recupererZonesLivraison } from '../api/vitrine'
import { allerAuPremierChampEnErreur, extraireErreursChamps } from '../lib/erreursValidation'
import { formaterMontant } from '../lib/formatage'
import { libelleFrais } from '../lib/livraison'
import { IconeMobileMoney } from '../components/IconeMobileMoney'

const ORDRE_CHAMPS = ['client.nom', 'client.telephone', 'commune', 'quartier']

function extraireErreurArticle(erreur: unknown): { message: string; article: string } | null {
  if (!axios.isAxiosError(erreur) || erreur.response?.status !== 422) {
    return null
  }

  const donnees = erreur.response.data as { message?: string; article?: string }

  return donnees.article ? { message: donnees.message ?? '', article: donnees.article } : null
}

/** Valeur de l'option « Autre commune » : jamais un nom de commune réel. */
const AUTRE_COMMUNE = '__autre__'

export function PageCommander() {
  const { lignes, vider } = usePanier()
  const requeteVerification = useVerifierPanier(lignes)
  const requeteZones = useQuery({ queryKey: ['zones-livraison'], queryFn: recupererZonesLivraison })
  const navigate = useNavigate()
  const { data: etablissement } = useEtablissementVitrine()
  const parMobileMoney = etablissement?.mode_paiement === 'mobile_money'

  const [cleIdempotence] = useState(() => crypto.randomUUID())
  const [nom, setNom] = useState('')
  const [telephone, setTelephone] = useState('')
  const [commune, setCommune] = useState('')
  const [autreCommune, setAutreCommune] = useState(false)
  const [quartier, setQuartier] = useState('')
  const [erreurs, setErreurs] = useState<Record<string, string>>({})
  const [erreurGenerique, setErreurGenerique] = useState<string | null>(null)

  const creation = useMutation({
    mutationFn: creerCommandeVitrine,
    onSuccess: ({ numero, jeton }) => {
      vider()
      navigate(`/commande/${numero}?jeton=${jeton}`)
    },
    onError: (erreur) => {
      const article = extraireErreurArticle(erreur)

      if (article) {
        navigate('/panier', { state: { erreurArticle: article } })
        return
      }

      const champs = extraireErreursChamps(erreur)
      setErreurs(champs)
      setErreurGenerique(
        Object.keys(champs).length === 0
          ? 'Impossible de valider la commande. Vérifiez votre connexion et réessayez.'
          : null,
      )
      allerAuPremierChampEnErreur(champs, ORDRE_CHAMPS)
    },
  })

  if (lignes.length === 0) {
    return <EtatPanierVide />
  }

  function soumettre(evenement: FormEvent<HTMLFormElement>) {
    evenement.preventDefault()
    setErreurGenerique(null)
    setErreurs({})

    creation.mutate({
      lignes: lignes.map((ligne) => ({
        produit_id: ligne.produitId,
        variante_id: ligne.varianteId,
        quantite: ligne.quantite,
      })),
      client: { nom, telephone },
      commune,
      autre_commune: autreCommune,
      quartier,
      cle_idempotence: cleIdempotence,
      // Le mode que propose l'établissement, tel quel (le serveur refuse
      // tout autre) : "cinetpay" seulement s'il est réellement disponible.
      mode_paiement: etablissement?.mode_paiement ?? null,
    })
  }

  const zonesExistent = (requeteZones.data?.length ?? 0) > 0
  const zoneChoisie = autreCommune ? null : (requeteZones.data?.find((zone) => zone.nom === commune) ?? null)
  // La boutique ne facture pas, ou le client a pris « Autre commune » : la
  // livraison se convient avec elle, et le total le dit.
  const livraisonAConvenir = !zonesExistent || autreCommune
  const sousTotal = requeteVerification.data?.sous_total ?? 0
  const fraisLivraison = zoneChoisie?.frais ?? 0
  const total = sousTotal + fraisLivraison
  const uneLigneIndisponible = requeteVerification.data?.lignes.some(
    (ligne) => ligne.statut === 'epuise' || ligne.statut === 'retire',
  )

  return (
    <div className="mx-auto max-w-4xl space-y-4 pb-4">
      <BoutonRetour vers="/panier" />
      <h1 className="text-titre-page font-semibold text-texte">Passer la commande</h1>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_320px]">
        <div className="order-2 lg:order-1">
          {uneLigneIndisponible && (
            <p className="animate-entree-haut mb-4 border border-danger bg-surface p-3 text-corps text-danger">
              Un article de votre panier n'est plus disponible. Retournez au panier pour le retirer avant de
              continuer.
            </p>
          )}

          <form onSubmit={soumettre} className="space-y-4">
            {erreurGenerique && (
              <p role="alert" className="animate-entree-haut border border-danger bg-surface p-3 text-corps text-danger">
                {erreurGenerique}
              </p>
            )}

            <ChampTexteVitrine
              id="client.nom"
              label="Nom complet"
              requis
              valeur={nom}
              onChange={setNom}
              erreur={erreurs['client.nom']}
            />
            <ChampTexteVitrine
              id="client.telephone"
              label="Numéro de téléphone"
              type="tel"
              requis
              placeholder="07 01 02 03 04"
              valeur={telephone}
              onChange={setTelephone}
              erreur={erreurs['client.telephone']}
            />

            {zonesExistent ? (
              <div>
                <label htmlFor="commune" className="mb-1 block text-petit font-medium text-texte">
                  Commune <span aria-hidden="true" className="text-danger">*</span>
                </label>
                <select
                  id="commune"
                  required
                  value={autreCommune ? AUTRE_COMMUNE : commune}
                  onChange={(evenement) => {
                    const choix = evenement.target.value
                    setAutreCommune(choix === AUTRE_COMMUNE)
                    setCommune(choix === AUTRE_COMMUNE ? '' : choix)
                  }}
                  className="h-11 w-full rounded border border-bordure bg-surface px-3 text-corps text-texte transition-colors focus:border-texte focus:outline focus:outline-2 focus:outline-texte focus:outline-offset-1"
                >
                  <option value="" disabled>
                    Choisissez votre commune
                  </option>
                  {requeteZones.data?.map((zone) => (
                    <option key={zone.id} value={zone.nom}>
                      {zone.nom} — {libelleFrais(zone.frais)}
                    </option>
                  ))}
                  <option value={AUTRE_COMMUNE}>Autre commune — livraison à convenir</option>
                </select>
                {!autreCommune && erreurs.commune && (
                  <p className="animate-entree-champ mt-1 text-petit text-danger">{erreurs.commune}</p>
                )}
                {autreCommune && (
                  <div className="mt-3">
                    <ChampTexteVitrine
                      id="commune-autre"
                      label="Votre commune"
                      requis
                      maxLength={100}
                      placeholder="Bingerville"
                      valeur={commune}
                      onChange={setCommune}
                      erreur={erreurs.commune}
                    />
                  </div>
                )}
              </div>
            ) : (
              <ChampTexteVitrine
                id="commune"
                label="Commune"
                requis
                maxLength={100}
                valeur={commune}
                onChange={setCommune}
                erreur={erreurs.commune}
              />
            )}

            <ChampTexteVitrine
              id="quartier"
              label="Quartier"
              requis
              maxLength={150}
              placeholder="Angré 7e tranche, près de la pharmacie"
              valeur={quartier}
              onChange={setQuartier}
              erreur={erreurs.quartier}
            />

            {parMobileMoney && (
              <p className="flex gap-2 border border-bordure bg-surface p-3 text-corps text-texte-secondaire">
                <IconeMobileMoney size={20} strokeWidth={1.5} className="mt-0.5 shrink-0 text-texte" />
                <span>
                  Vous paierez par transfert Mobile Money : le numéro du commerçant et le montant exact vous seront
                  donnés juste après avoir validé.
                </span>
              </p>
            )}

            <button
              type="submit"
              disabled={creation.isPending || uneLigneIndisponible}
              className="min-h-11 py-2 text-center leading-snug px-4 w-full cursor-pointer rounded bg-accent text-corps font-medium text-accent-texte transition-[opacity,transform] hover:opacity-90 active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100"
            >
              <EtatBouton chargement={creation.isPending}>
                {parMobileMoney ? 'Valider et payer par Mobile Money' : 'Valider la commande'}
              </EtatBouton>
            </button>
          </form>
        </div>

        <div className="order-1 h-fit space-y-3 border border-bordure bg-surface p-4 lg:order-2">
          <h2 className="text-titre-section font-semibold text-texte">Récapitulatif</h2>
          <ul className="space-y-1.5 text-corps text-texte">
            {lignes.map((ligne) => {
              const verifiee = requeteVerification.data?.lignes.find(
                (l) => l.produit_id === ligne.produitId && l.variante_id === ligne.varianteId,
              )

              return (
                <li key={`${ligne.produitId}:${ligne.varianteId ?? ''}`} className="flex justify-between gap-2">
                  <span className="truncate text-texte-secondaire">
                    {ligne.quantite} × {verifiee?.nom ?? '…'}
                  </span>
                  <span className="shrink-0 tabular-nums">
                    {verifiee?.prix_actuel !== null && verifiee?.prix_actuel !== undefined
                      ? formaterMontant(verifiee.prix_actuel * ligne.quantite)
                      : '—'}
                  </span>
                </li>
              )
            })}
          </ul>
          <div className="space-y-1 border-t border-bordure pt-3 text-corps">
            <div className="flex justify-between text-texte-secondaire">
              <span>Sous-total</span>
              <span className="tabular-nums">{formaterMontant(sousTotal)}</span>
            </div>
            <div className="flex justify-between text-texte-secondaire">
              <span>Livraison</span>
              <span className="tabular-nums">
                {livraisonAConvenir ? 'À convenir avec la boutique' : zoneChoisie ? libelleFrais(zoneChoisie.frais) : 'Selon la commune'}
              </span>
            </div>
            <div className="flex justify-between text-titre-section font-semibold text-texte">
              <span>Total</span>
              <span className="tabular-nums">{formaterMontant(total)}</span>
            </div>
            {livraisonAConvenir && (
              <p className="text-petit text-texte-secondaire">Ce total n’inclut pas la livraison : la boutique vous contactera pour la convenir.</p>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
