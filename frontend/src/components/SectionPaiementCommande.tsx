/**
 * Section "Paiement" du détail d'une commande (Étape 13). Le paiement est
 * suivi À CÔTÉ du statut, jamais à sa place : confirmer un paiement reçu ne
 * fait pas avancer la commande, et confirmer la commande ne dit pas
 * qu'elle est payée (voir ConfirmerPaiementCommande côté API).
 *
 * "Confirmer le paiement reçu" n'est proposé qu'en Mobile Money, qu'à qui a
 * gerer_commandes (le serveur revérifie), et ouvre un petit formulaire :
 * montant RÉELLEMENT reçu (pré-rempli avec le total, modifiable — un client
 * qui arrondit n'est pas une erreur) et référence de transaction
 * facultative. Un écart est annoncé avant d'enregistrer, puis affiché.
 */
import axios from 'axios'
import { useMutation } from '@tanstack/react-query'
import { CheckCircle2, Clock, Smartphone, Wallet } from 'lucide-react'
import type { FormEvent } from 'react'
import { useState } from 'react'
import type { CommandeDetail } from '../api/commandes'
import { confirmerPaiementCommande } from '../api/commandes'
import { extraireErreursChamps } from '../lib/erreursValidation'
import { formaterMontant } from '../lib/formatage'
import { Bouton } from './Bouton'

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

export function SectionPaiementCommande({
  commande,
  peutGerer,
  onMiseAJour,
}: {
  commande: CommandeDetail
  peutGerer: boolean
  onMiseAJour: (commande: CommandeDetail) => void
}) {
  const [formulaireOuvert, setFormulaireOuvert] = useState(false)
  const [montant, setMontant] = useState(String(commande.total))
  const [reference, setReference] = useState('')
  const [erreurs, setErreurs] = useState<Record<string, string>>({})
  const [erreurGenerique, setErreurGenerique] = useState<string | null>(null)

  const confirmation = useMutation({
    mutationFn: () =>
      confirmerPaiementCommande(commande.id, {
        montant_recu: Number(montant),
        reference: reference.trim() === '' ? null : reference.trim(),
      }),
    onSuccess: (miseAJour) => {
      setFormulaireOuvert(false)
      onMiseAJour(miseAJour)
    },
    onError: (erreur) => {
      const champs = extraireErreursChamps(erreur)
      setErreurs(champs)
      const message = axios.isAxiosError(erreur) ? erreur.response?.data?.message : null
      setErreurGenerique(
        Object.keys(champs).length > 0
          ? null
          : typeof message === 'string'
            ? message
            : 'Impossible de contacter le serveur. Vérifiez votre connexion et réessayez.',
      )
    },
  })

  if (commande.mode_paiement === null) {
    return null
  }

  const paiement = commande.paiement
  const ecartSaisi = Number(montant) > 0 ? Number(montant) - commande.total : 0

  function soumettre(evenement: FormEvent<HTMLFormElement>) {
    evenement.preventDefault()
    setErreurs({})
    setErreurGenerique(null)

    if (ecartSaisi !== 0) {
      const confirme = window.confirm(
        `Le montant reçu (${formaterMontant(Number(montant))}) diffère du total (${formaterMontant(commande.total)}) : ` +
          `${phraseEcart(ecartSaisi)}.\n\nL'écart sera noté dans l'historique. Confirmer ce paiement ?`,
      )
      if (!confirme) {
        return
      }
    }

    confirmation.mutate()
  }

  return (
    <section className="rounded-lg border border-bordure bg-surface p-4 sm:p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-titre-section font-semibold text-texte">Paiement</h2>
        <span className="inline-flex items-center gap-1.5 text-petit text-texte-secondaire">
          <Smartphone aria-hidden="true" size={16} strokeWidth={1.75} />
          {LIBELLES_MODE[commande.mode_paiement] ?? commande.mode_paiement}
        </span>
      </div>

      {paiement.confirme ? (
        <div className="mt-3 rounded-md bg-succes/10 p-3 text-corps text-texte">
          <p className="flex items-center gap-1.5 font-semibold text-succes">
            <CheckCircle2 aria-hidden="true" size={18} strokeWidth={2} />
            Paiement reçu : {formaterMontant(paiement.montant_recu ?? 0)}
          </p>
          <p className="mt-1 text-petit text-texte-secondaire">
            Confirmé par {paiement.confirme_par ?? 'un membre de l’équipe'}
            {paiement.confirme_le && `, le ${formaterDateHeure(paiement.confirme_le)}`}
            {paiement.reference && ` — réf. ${paiement.reference}`}
          </p>
          {paiement.ecart !== null && paiement.ecart !== 0 && (
            <p className="mt-2 rounded-md border border-alerte bg-alerte/10 px-2.5 py-1.5 text-petit font-medium text-alerte">
              Écart : {phraseEcart(paiement.ecart)} ({formaterMontant(commande.total)}).
            </p>
          )}
        </div>
      ) : commande.mode_paiement === 'mobile_money' ? (
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

          {peutGerer && !formulaireOuvert && (
            <Bouton
              variante="principal"
              className="mt-3"
              icone={<Wallet aria-hidden="true" size={20} strokeWidth={1.75} />}
              onClick={() => setFormulaireOuvert(true)}
            >
              Confirmer le paiement reçu
            </Bouton>
          )}

          {peutGerer && formulaireOuvert && (
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
      ) : (
        <p className="mt-3 text-corps text-texte-secondaire">Paiement non suivi depuis le back-office pour ce mode.</p>
      )}
    </section>
  )
}
