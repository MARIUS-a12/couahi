/**
 * Section "Paiement" du détail d'une commande (Étape 13). Le paiement est
 * suivi À CÔTÉ du statut, jamais à sa place : confirmer un paiement reçu ne
 * fait pas avancer la commande, et confirmer la commande ne dit pas
 * qu'elle est payée (voir ConfirmerPaiementCommande côté API).
 *
 * "Confirmer le paiement reçu" n'est proposé qu'en Mobile Money, qu'à qui a
 * encaisser (le serveur revérifie), et ouvre un petit formulaire :
 * montant RÉELLEMENT reçu (pré-rempli avec le total, modifiable — un client
 * qui arrondit n'est pas une erreur) et référence de transaction
 * facultative. Un écart est annoncé avant d'enregistrer, puis affiché.
 *
 * Un montant inférieur au total n'est jamais présenté comme "payé" : bloc
 * orangé, reste dû affiché, et — tant que la commande n'est pas acceptée —
 * la décision à prendre. Une commande annulée ou expirée après paiement
 * propose "Marquer le remboursement effectué", qui solde la mention
 * "À rembourser" en gardant la trace (montant, qui, quand, référence).
 */
import axios from 'axios'
import { useMutation } from '@tanstack/react-query'
import { CheckCircle2, CircleDashed, Clock, CreditCard, Undo2, Wallet } from 'lucide-react'
import type { FormEvent } from 'react'
import { useState } from 'react'
import type { CommandeDetail } from '../api/commandes'
import { LigneStockReserve } from './PastillePaiement'
import { confirmerPaiementCommande, enregistrerRemboursementCommande } from '../api/commandes'
import { extraireErreursChamps } from '../lib/erreursValidation'
import { formaterMontant } from '../lib/formatage'
import { Bouton } from './Bouton'
import { IconeMobileMoney } from './IconeMobileMoney'

const CLASSES_CHAMP =
  'h-11 w-full rounded border bg-surface px-3 text-corps text-texte transition-colors focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1'

const LIBELLES_MODE: Record<string, string> = {
  mobile_money: 'Mobile Money',
  cinetpay: 'Paiement en ligne (CinetPay)',
  especes: 'Espèces',
}

function formaterDateHeure(date: string): string {
  return new Date(date).toLocaleString('fr-FR', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function phraseEcart(ecart: number): string {
  return ecart < 0
    ? `${formaterMontant(-ecart)} de moins que le total`
    : `${formaterMontant(ecart)} de plus que le total`
}

/** Un entier de francs CFA strictement positif, sinon null (jamais NaN envoyé ni affiché). */
function montantSaisi(valeur: string): number | null {
  const nombre = Number(valeur.replace(/\s/g, ''))

  return Number.isInteger(nombre) && nombre > 0 ? nombre : null
}

function messageErreur(erreur: unknown): string {
  const message = axios.isAxiosError(erreur) ? erreur.response?.data?.message : null

  return typeof message === 'string' ? message : 'Impossible de contacter le serveur. Vérifiez votre connexion et réessayez.'
}

export function SectionPaiementCommande({
  commande,
  peutEncaisser,
  peutRembourser,
  onMiseAJour,
}: {
  commande: CommandeDetail
  peutEncaisser: boolean
  peutRembourser: boolean
  onMiseAJour: (commande: CommandeDetail) => void
}) {
  const [formulaireOuvert, setFormulaireOuvert] = useState(false)
  const [montant, setMontant] = useState(String(commande.total))
  const [reference, setReference] = useState('')
  const [erreurs, setErreurs] = useState<Record<string, string>>({})
  const [erreurGenerique, setErreurGenerique] = useState<string | null>(null)

  const confirmation = useMutation({
    mutationFn: (montantRecu: number) =>
      confirmerPaiementCommande(commande.id, {
        montant_recu: montantRecu,
        reference: reference.trim() === '' ? null : reference.trim(),
      }),
    onSuccess: (miseAJour) => {
      setFormulaireOuvert(false)
      onMiseAJour(miseAJour)
    },
    onError: (erreur) => {
      const champs = extraireErreursChamps(erreur)
      setErreurs(champs)
      setErreurGenerique(Object.keys(champs).length > 0 ? null : messageErreur(erreur))
    },
  })

  if (commande.mode_paiement === null) {
    return null
  }

  const paiement = commande.paiement
  const montantValide = montantSaisi(montant)
  const ecartSaisi = montantValide !== null ? montantValide - commande.total : 0

  function soumettre(evenement: FormEvent<HTMLFormElement>) {
    evenement.preventDefault()
    setErreurs({})
    setErreurGenerique(null)

    if (montantValide === null) {
      setErreurs({ montant_recu: 'Indiquez un montant en francs CFA, sans décimales.' })
      return
    }

    if (ecartSaisi !== 0) {
      const confirme = window.confirm(
        `Le montant reçu (${formaterMontant(montantValide)}) diffère du total (${formaterMontant(commande.total)}) : ` +
          `${phraseEcart(ecartSaisi)}.\n\nL'écart sera noté dans l'historique. Confirmer ce paiement ?`,
      )
      if (!confirme) {
        return
      }
    }

    confirmation.mutate(montantValide)
  }

  return (
    <section className="rounded-lg border border-bordure bg-surface p-4 sm:p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-titre-section font-semibold text-texte">Paiement</h2>
        <span className="inline-flex items-center gap-1.5 text-petit text-texte-secondaire">
          {commande.mode_paiement === 'mobile_money' ? (
            <IconeMobileMoney size={16} strokeWidth={1.75} />
          ) : (
            <CreditCard aria-hidden="true" size={16} strokeWidth={1.75} />
          )}
          {LIBELLES_MODE[commande.mode_paiement] ?? commande.mode_paiement}
        </span>
      </div>

      {paiement.a_rembourser != null && (
        <BlocRemboursementDu commande={commande} peutRembourser={peutRembourser} onMiseAJour={onMiseAJour} />
      )}

      {paiement.remboursement != null && (
        <div className="mt-3 rounded-md bg-surface-alt p-3 text-corps text-texte">
          <p className="flex items-center gap-1.5 font-semibold">
            <Undo2 aria-hidden="true" size={18} strokeWidth={2} />
            Remboursement effectué : {formaterMontant(paiement.remboursement.montant)}
          </p>
          <p className="mt-1 text-petit text-texte-secondaire">
            Enregistré par {paiement.remboursement.par ?? 'un membre de l’équipe'}, le{' '}
            {formaterDateHeure(paiement.remboursement.le)}
            {paiement.remboursement.reference && ` — réf. ${paiement.remboursement.reference}`}
          </p>
        </div>
      )}

      {paiement.a_traiter_en_urgence && (
        <p role="alert" className="mt-3 rounded-md bg-danger p-3 text-corps font-semibold text-white">
          Payée depuis plus de 2 heures et toujours pas traitée : le client attend sa commande. Elle retient son
          stock tant que vous ne la confirmez ou ne l'annulez pas.
        </p>
      )}

      {paiement.partielle_a_decider && paiement.reste_du != null && (
        <p role="alert" className="mt-3 rounded-md bg-danger p-3 text-corps font-semibold text-white">
          Le client n'a envoyé qu'une partie du montant : il manque {formaterMontant(paiement.reste_du)}. Relancez-le
          pour le reste, acceptez la commande quand même, ou annulez-la et remboursez-le. Elle retient son stock tant
          que vous n'avez pas décidé.
        </p>
      )}

      {commande.stock_reserve && (
        <p className="mt-2">
          <LigneStockReserve commande={commande} />
        </p>
      )}

      {paiement.confirme ? (
        <div
          className={`mt-3 rounded-md p-3 text-corps text-texte ${paiement.partiel ? 'border border-alerte bg-alerte/10' : 'bg-succes/10'}`}
        >
          {paiement.partiel ? (
            <p className="flex items-center gap-1.5 font-semibold text-alerte">
              <CircleDashed aria-hidden="true" size={18} strokeWidth={2} />
              Paiement partiel reçu : {formaterMontant(paiement.montant_recu)} sur {formaterMontant(commande.total)}
            </p>
          ) : (
            <p className="flex items-center gap-1.5 font-semibold text-succes">
              <CheckCircle2 aria-hidden="true" size={18} strokeWidth={2} />
              Paiement reçu : {formaterMontant(paiement.montant_recu)}
            </p>
          )}
          <p className="mt-1 text-petit text-texte-secondaire">
            Confirmé par {paiement.confirme_par ?? 'un membre de l’équipe'}
            {paiement.confirme_le && `, le ${formaterDateHeure(paiement.confirme_le)}`}
            {paiement.reference && ` — réf. ${paiement.reference}`}
          </p>
          {paiement.reste_du != null ? (
            <p className="mt-2 text-corps font-semibold text-alerte">Reste dû : {formaterMontant(paiement.reste_du)}</p>
          ) : (
            paiement.ecart !== null &&
            paiement.ecart !== 0 && (
              <p className="mt-2 rounded-md border border-alerte bg-alerte/10 px-2.5 py-1.5 text-petit font-medium text-alerte">
                Écart : {phraseEcart(paiement.ecart)} ({formaterMontant(commande.total)}).
              </p>
            )
          )}
        </div>
      ) : commande.mode_paiement === 'mobile_money' && !['annulee', 'expiree'].includes(commande.statut) ? (
        <>
          <p
            className={`mt-3 flex items-center gap-1.5 rounded-md p-3 text-corps font-medium ${
              paiement.en_retard ? 'bg-danger/10 text-danger' : 'bg-alerte/10 text-alerte'
            }`}
          >
            <Clock aria-hidden="true" size={18} strokeWidth={2} className="shrink-0" />
            {paiement.en_retard
              ? 'Transfert attendu depuis plus de 2 heures : vérifiez votre téléphone ou relancez le client.'
              : 'Transfert pas encore confirmé. Vérifiez sur votre téléphone que l’argent est bien arrivé.'}
          </p>

          {peutEncaisser && !formulaireOuvert && (
            <Bouton
              variante="principal"
              className="mt-3"
              icone={<Wallet aria-hidden="true" size={20} strokeWidth={1.75} />}
              onClick={() => setFormulaireOuvert(true)}
            >
              Confirmer le paiement reçu
            </Bouton>
          )}

          {peutEncaisser && formulaireOuvert && (
            <form onSubmit={soumettre} className="mt-4 animate-entree-champ space-y-4 border-t border-bordure pt-4">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label htmlFor="montant_recu" className="mb-1 block text-petit font-medium text-texte">
                    Montant réellement reçu (FCFA)
                  </label>
                  <input
                    id="montant_recu"
                    type="number"
                    inputMode="numeric"
                    min={1}
                    step={1}
                    value={montant}
                    onChange={(evenement) => setMontant(evenement.target.value)}
                    className={`${CLASSES_CHAMP} tabular-nums ${erreurs.montant_recu ? 'border-danger' : 'border-bordure focus:border-primaire'}`}
                  />
                  {erreurs.montant_recu ? (
                    <p className="mt-1 animate-entree-champ text-petit text-danger">{erreurs.montant_recu}</p>
                  ) : ecartSaisi !== 0 ? (
                    <p className="mt-1 animate-entree-champ text-petit font-medium text-alerte">
                      {phraseEcart(ecartSaisi)} — l'écart sera noté dans l'historique.
                    </p>
                  ) : (
                    <p className="mt-1 text-petit text-texte-secondaire">Pré-rempli avec le total de la commande.</p>
                  )}
                </div>
                <div>
                  <label htmlFor="reference_paiement" className="mb-1 block text-petit font-medium text-texte">
                    Référence de la transaction (facultatif)
                  </label>
                  <input
                    id="reference_paiement"
                    type="text"
                    maxLength={100}
                    value={reference}
                    onChange={(evenement) => setReference(evenement.target.value)}
                    className={`${CLASSES_CHAMP} ${erreurs.reference ? 'border-danger' : 'border-bordure focus:border-primaire'}`}
                  />
                  {erreurs.reference && <p className="mt-1 animate-entree-champ text-petit text-danger">{erreurs.reference}</p>}
                </div>
              </div>
              <p className="text-petit text-texte-secondaire">
                Le statut de la commande ne change pas : confirmez-la, préparez-la et livrez-la comme d'habitude.
              </p>
              {erreurGenerique && (
                <p role="alert" className="animate-entree-champ text-petit text-danger">
                  {erreurGenerique}
                </p>
              )}
              <div className="flex flex-wrap gap-3">
                <Bouton variante="principal" type="submit" chargement={confirmation.isPending}>
                  Confirmer le paiement reçu
                </Bouton>
                <Bouton variante="tertiaire" onClick={() => setFormulaireOuvert(false)}>
                  Annuler
                </Bouton>
              </div>
            </form>
          )}
        </>
      ) : commande.mode_paiement === 'mobile_money' ? (
        <p className="mt-3 text-corps text-texte-secondaire">Aucun paiement reçu pour cette commande.</p>
      ) : (
        <p className="mt-3 text-corps text-texte-secondaire">Paiement non suivi depuis le back-office pour ce mode.</p>
      )}
    </section>
  )
}

/**
 * Argent reçu pour une commande annulée ou expirée : l'alerte, et pour qui
 * a annuler_payees, l'action qui la solde. Montant pré-rempli avec ce qui
 * a été reçu (cas courant), modifiable vers le bas seulement — le serveur
 * refuse un remboursement supérieur au montant reçu.
 */
function BlocRemboursementDu({
  commande,
  peutRembourser,
  onMiseAJour,
}: {
  commande: CommandeDetail
  peutRembourser: boolean
  onMiseAJour: (commande: CommandeDetail) => void
}) {
  const aRembourser = commande.paiement.a_rembourser ?? 0
  const [ouvert, setOuvert] = useState(false)
  const [montant, setMontant] = useState(String(aRembourser))
  const [reference, setReference] = useState('')
  const [erreurs, setErreurs] = useState<Record<string, string>>({})
  const [erreurGenerique, setErreurGenerique] = useState<string | null>(null)

  const remboursement = useMutation({
    mutationFn: (montantRembourse: number) =>
      enregistrerRemboursementCommande(commande.id, {
        montant: montantRembourse,
        reference: reference.trim() === '' ? null : reference.trim(),
      }),
    onSuccess: (miseAJour) => {
      setOuvert(false)
      onMiseAJour(miseAJour)
    },
    onError: (erreur) => {
      const champs = extraireErreursChamps(erreur)
      setErreurs(champs)
      setErreurGenerique(Object.keys(champs).length > 0 ? null : messageErreur(erreur))
    },
  })

  function soumettre(evenement: FormEvent<HTMLFormElement>) {
    evenement.preventDefault()
    setErreurs({})
    setErreurGenerique(null)

    const valeur = montantSaisi(montant)
    if (valeur === null) {
      setErreurs({ montant: 'Indiquez un montant en francs CFA, sans décimales.' })
      return
    }

    remboursement.mutate(valeur)
  }

  return (
    <div className="mt-3 rounded-md border border-alerte bg-alerte/10 p-3">
      <p role="alert" className="text-corps font-semibold text-alerte">
        Commande {commande.statut === 'expiree' ? 'expirée' : 'annulée'} après paiement : le client a payé{' '}
        {formaterMontant(aRembourser)}, pensez à le rembourser.
      </p>

      {peutRembourser && !ouvert && (
        <Bouton
          variante="secondaire"
          className="mt-3"
          icone={<Undo2 aria-hidden="true" size={20} strokeWidth={1.75} />}
          onClick={() => setOuvert(true)}
        >
          Marquer le remboursement effectué
        </Bouton>
      )}

      {peutRembourser && ouvert && (
        <form onSubmit={soumettre} className="mt-3 animate-entree-champ space-y-3 border-t border-alerte/40 pt-3">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label htmlFor="montant_rembourse" className="mb-1 block text-petit font-medium text-texte">
                Montant remboursé (FCFA)
              </label>
              <input
                id="montant_rembourse"
                type="number"
                inputMode="numeric"
                min={1}
                max={aRembourser}
                step={1}
                value={montant}
                onChange={(evenement) => setMontant(evenement.target.value)}
                className={`${CLASSES_CHAMP} tabular-nums ${erreurs.montant ? 'border-danger' : 'border-bordure focus:border-primaire'}`}
              />
              {erreurs.montant && <p className="mt-1 animate-entree-champ text-petit text-danger">{erreurs.montant}</p>}
            </div>
            <div>
              <label htmlFor="reference_remboursement" className="mb-1 block text-petit font-medium text-texte">
                Référence du transfert (facultatif)
              </label>
              <input
                id="reference_remboursement"
                type="text"
                maxLength={100}
                value={reference}
                onChange={(evenement) => setReference(evenement.target.value)}
                className={`${CLASSES_CHAMP} ${erreurs.reference ? 'border-danger' : 'border-bordure focus:border-primaire'}`}
              />
              {erreurs.reference && <p className="mt-1 animate-entree-champ text-petit text-danger">{erreurs.reference}</p>}
            </div>
          </div>
          {erreurGenerique && (
            <p role="alert" className="animate-entree-champ text-petit text-danger">
              {erreurGenerique}
            </p>
          )}
          <div className="flex flex-wrap gap-3">
            <Bouton variante="principal" type="submit" chargement={remboursement.isPending}>
              Enregistrer le remboursement
            </Bouton>
            <Bouton variante="tertiaire" onClick={() => setOuvert(false)}>
              Annuler
            </Bouton>
          </div>
        </form>
      )}
    </div>
  )
}
