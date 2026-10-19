/**
 * Section "Abonnement" de la fiche super-admin d'un établissement
 * (Étape 12) : état et échéance, réglages (montant mensuel, jours de
 * grâce), enregistrement d'un paiement reçu hors ligne, historique. Chaque
 * enregistrement et chaque changement d'échéance est journalisé côté API —
 * cet écran n'a rien à faire pour ça.
 *
 * La période proposée enchaîne sur l'échéance actuelle (jamais de trou ni
 * de chevauchement) ; pour un établissement suspendu, elle part
 * d'aujourd'hui, pour qu'un paiement remette réellement la boutique en
 * ligne au lieu de couvrir un passé déjà écoulé. Le super-admin peut
 * toujours la modifier avant d'enregistrer.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import axios from 'axios'
import type { FormEvent, ReactNode } from 'react'
import { useState } from 'react'
import type { DetailAbonnement, PaiementAbonnementPayload, ResumeAbonnement } from '../api/abonnement'
import {
  enregistrerPaiementAbonnement,
  modifierReglagesAbonnement,
  recupererAbonnementEtablissement,
} from '../api/abonnement'
import { decalerDate, finDePeriodeMensuelle, formaterDateLongue, LIBELLES_MOYEN_PAIEMENT } from '../lib/abonnement'
import { extraireErreursChamps } from '../lib/erreursValidation'
import { formaterMontant } from '../lib/formatage'
import { BadgeStatutAbonnement } from './BadgeStatutAbonnement'
import { BandeauSucces } from './BandeauSucces'
import { Bouton } from './Bouton'
import { EtatChargement } from './EtatChargement'
import { EtatErreur } from './EtatErreur'
import { HistoriquePaiementsAbonnement } from './HistoriquePaiementsAbonnement'

const CLASSES_CHAMP =
  'h-11 w-full rounded border bg-surface px-3 text-corps text-texte transition-colors focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1'

const MOYENS_MANUELS: PaiementAbonnementPayload['moyen'][] = ['mobile_money', 'especes', 'virement']

function aujourdhui(): string {
  const maintenant = new Date()
  const mois = String(maintenant.getMonth() + 1).padStart(2, '0')
  const jour = String(maintenant.getDate()).padStart(2, '0')

  return `${maintenant.getFullYear()}-${mois}-${jour}`
}

function periodeProposee(abonnement: ResumeAbonnement): { debut: string; fin: string } {
  const debut = abonnement.statut === 'suspendu' ? aujourdhui() : decalerDate(abonnement.date_echeance, 1)

  return { debut, fin: finDePeriodeMensuelle(debut) }
}

function messageErreurGenerique(erreur: unknown): string | null {
  if (axios.isAxiosError(erreur) && erreur.response?.status === 422) {
    return null
  }

  return "L'enregistrement a échoué. Vérifiez votre connexion et réessayez."
}

export function SectionAbonnementSuperAdmin({ etablissementId }: { etablissementId: number }) {
  const cle = ['abonnement-etablissement', etablissementId]
  const queryClient = useQueryClient()
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: cle,
    queryFn: () => recupererAbonnementEtablissement(etablissementId),
  })
  const [messageSucces, setMessageSucces] = useState<string | null>(null)

  function appliquer(detail: DetailAbonnement, message: string) {
    queryClient.setQueryData(cle, detail)
    queryClient.invalidateQueries({ queryKey: ['etablissements'] })
    setMessageSucces(message)
  }

  return (
    <section className="rounded-lg border border-bordure bg-surface">
      <h2 className="border-b border-bordure px-4 py-2 text-titre-section font-semibold text-texte">Abonnement</h2>

      {isPending && <EtatChargement />}
      {isError && <EtatErreur erreur={error} onReessayer={() => refetch()} />}

      {data && (
        <div className="divide-y divide-bordure">
          {messageSucces && (
            <div className="p-4">
              <BandeauSucces message={messageSucces} onFermer={() => setMessageSucces(null)} />
            </div>
          )}

          <dl className="grid grid-cols-1 gap-4 p-4 sm:grid-cols-3">
            <div>
              <dt className="text-petit text-texte-secondaire">État</dt>
              <dd className="mt-1">
                <BadgeStatutAbonnement statut={data.abonnement.statut} />
              </dd>
            </div>
            <div>
              <dt className="text-petit text-texte-secondaire">Échéance</dt>
              <dd className="text-corps text-texte">{formaterDateLongue(data.abonnement.date_echeance)}</dd>
            </div>
            <div>
              <dt className="text-petit text-texte-secondaire">Boutique coupée après le</dt>
              <dd className="text-corps text-texte">{formaterDateLongue(data.abonnement.date_coupure)}</dd>
            </div>
          </dl>

          <FormulaireReglages
            key={`${data.abonnement.montant_mensuel}-${data.abonnement.jours_de_grace}`}
            abonnement={data.abonnement}
            onEnregistrer={(payload) => modifierReglagesAbonnement(etablissementId, payload)}
            onSucces={(detail) => appliquer(detail, 'Réglages de l’abonnement enregistrés.')}
          />

          <FormulairePaiementRecu
            key={data.abonnement.date_echeance}
            abonnement={data.abonnement}
            onEnregistrer={(payload) => enregistrerPaiementAbonnement(etablissementId, payload)}
            onSucces={(detail) =>
              appliquer(
                detail,
                `Paiement enregistré. Nouvelle échéance : ${formaterDateLongue(detail.abonnement.date_echeance)}.`,
              )
            }
          />

          <div>
            <h3 className="px-4 pt-4 text-corps font-semibold text-texte">Historique des paiements</h3>
            <HistoriquePaiementsAbonnement paiements={data.paiements} />
          </div>
        </div>
      )}
    </section>
  )
}

function Champ({
  id,
  label,
  erreur,
  aide,
  children,
}: {
  id: string
  label: string
  erreur?: string
  aide?: string
  children: ReactNode
}) {
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-petit font-medium text-texte">
        {label}
      </label>
      {children}
      {aide && !erreur && <p className="mt-1 text-petit text-texte-secondaire">{aide}</p>}
      {erreur && <p className="mt-1 animate-entree-champ text-petit text-danger">{erreur}</p>}
    </div>
  )
}

function classesChamp(erreur: string | undefined): string {
  return `${CLASSES_CHAMP} ${erreur ? 'border-danger' : 'border-bordure focus:border-primaire'}`
}

function FormulaireReglages({
  abonnement,
  onEnregistrer,
  onSucces,
}: {
  abonnement: ResumeAbonnement
  onEnregistrer: (payload: { montant_mensuel: number; jours_de_grace: number }) => Promise<DetailAbonnement>
  onSucces: (detail: DetailAbonnement) => void
}) {
  const [montant, setMontant] = useState(String(abonnement.montant_mensuel))
  const [joursDeGrace, setJoursDeGrace] = useState(String(abonnement.jours_de_grace))
  const [erreurs, setErreurs] = useState<Record<string, string>>({})

  const mutation = useMutation({
    mutationFn: () => onEnregistrer({ montant_mensuel: Number(montant), jours_de_grace: Number(joursDeGrace) }),
    onSuccess: (detail) => {
      setErreurs({})
      onSucces(detail)
    },
    onError: (erreur) => setErreurs(extraireErreursChamps(erreur)),
  })

  function soumettre(evenement: FormEvent<HTMLFormElement>) {
    evenement.preventDefault()
    mutation.mutate()
  }

  const inchange =
    Number(montant) === abonnement.montant_mensuel && Number(joursDeGrace) === abonnement.jours_de_grace
  const messageGenerique = mutation.isError ? messageErreurGenerique(mutation.error) : null

  return (
    <form onSubmit={soumettre} className="space-y-4 p-4">
      <h3 className="text-corps font-semibold text-texte">Réglages</h3>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Champ id="montant_mensuel" label="Montant mensuel (FCFA)" erreur={erreurs.montant_mensuel}>
          <input
            id="montant_mensuel"
            type="number"
            inputMode="numeric"
            min={1}
            step={1}
            value={montant}
            onChange={(evenement) => setMontant(evenement.target.value)}
            className={`${classesChamp(erreurs.montant_mensuel)} tabular-nums`}
          />
        </Champ>
        <Champ
          id="jours_de_grace"
          label="Jours de grâce"
          erreur={erreurs.jours_de_grace}
          aide="Délai après l'échéance avant que la boutique soit coupée."
        >
          <input
            id="jours_de_grace"
            type="number"
            inputMode="numeric"
            min={0}
            max={90}
            step={1}
            value={joursDeGrace}
            onChange={(evenement) => setJoursDeGrace(evenement.target.value)}
            className={`${classesChamp(erreurs.jours_de_grace)} tabular-nums`}
          />
        </Champ>
      </div>
      {messageGenerique && <p className="text-petit text-danger">{messageGenerique}</p>}
      <Bouton type="submit" variante="secondaire" chargement={mutation.isPending} disabled={inchange}>
        Enregistrer les réglages
      </Bouton>
    </form>
  )
}

function FormulairePaiementRecu({
  abonnement,
  onEnregistrer,
  onSucces,
}: {
  abonnement: ResumeAbonnement
  onEnregistrer: (payload: PaiementAbonnementPayload) => Promise<DetailAbonnement>
  onSucces: (detail: DetailAbonnement) => void
}) {
  const periode = periodeProposee(abonnement)
  const [montant, setMontant] = useState(String(abonnement.montant_mensuel))
  const [debut, setDebut] = useState(periode.debut)
  const [fin, setFin] = useState(periode.fin)
  const [moyen, setMoyen] = useState<PaiementAbonnementPayload['moyen']>('mobile_money')
  const [reference, setReference] = useState('')
  const [erreurs, setErreurs] = useState<Record<string, string>>({})

  const mutation = useMutation({
    mutationFn: () =>
      onEnregistrer({
        montant: Number(montant),
        periode_debut: debut,
        periode_fin: fin,
        moyen,
        reference: reference.trim() === '' ? null : reference.trim(),
      }),
    onSuccess: (detail) => {
      setErreurs({})
      onSucces(detail)
    },
    onError: (erreur) => setErreurs(extraireErreursChamps(erreur)),
  })

  function changerDebut(valeur: string) {
    setDebut(valeur)
    if (valeur) {
      setFin(finDePeriodeMensuelle(valeur))
    }
  }

  function soumettre(evenement: FormEvent<HTMLFormElement>) {
    evenement.preventDefault()
    const confirme = window.confirm(
      `Enregistrer un paiement de ${formaterMontant(Number(montant) || 0)} (${LIBELLES_MOYEN_PAIEMENT[moyen]}) ` +
        `couvrant la période du ${debut ? formaterDateLongue(debut) : '?'} au ${fin ? formaterDateLongue(fin) : '?'} ?\n\n` +
        "Un paiement enregistré ne se modifie plus : il reste dans l'historique.",
    )

    if (confirme) {
      mutation.mutate()
    }
  }

  const messageGenerique = mutation.isError ? messageErreurGenerique(mutation.error) : null

  return (
    <form onSubmit={soumettre} className="space-y-4 p-4">
      <div>
        <h3 className="text-corps font-semibold text-texte">Enregistrer un paiement reçu</h3>
        <p className="mt-1 text-petit text-texte-secondaire">
          L'échéance passe à la fin de la période couverte, et la boutique est remise en ligne aussitôt si elle était
          coupée.
        </p>
      </div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Champ id="montant" label="Montant reçu (FCFA)" erreur={erreurs.montant}>
          <input
            id="montant"
            type="number"
            inputMode="numeric"
            min={1}
            step={1}
            value={montant}
            onChange={(evenement) => setMontant(evenement.target.value)}
            className={`${classesChamp(erreurs.montant)} tabular-nums`}
          />
        </Champ>
        <Champ id="moyen" label="Moyen" erreur={erreurs.moyen}>
          <select
            id="moyen"
            value={moyen}
            onChange={(evenement) => setMoyen(evenement.target.value as PaiementAbonnementPayload['moyen'])}
            className={classesChamp(erreurs.moyen)}
          >
            {MOYENS_MANUELS.map((valeur) => (
              <option key={valeur} value={valeur}>
                {LIBELLES_MOYEN_PAIEMENT[valeur]}
              </option>
            ))}
          </select>
        </Champ>
        <Champ id="periode_debut" label="Période couverte : du" erreur={erreurs.periode_debut}>
          <input
            id="periode_debut"
            type="date"
            value={debut}
            onChange={(evenement) => changerDebut(evenement.target.value)}
            className={classesChamp(erreurs.periode_debut)}
          />
        </Champ>
        <Champ id="periode_fin" label="au" erreur={erreurs.periode_fin}>
          <input
            id="periode_fin"
            type="date"
            value={fin}
            min={debut}
            onChange={(evenement) => setFin(evenement.target.value)}
            className={classesChamp(erreurs.periode_fin)}
          />
        </Champ>
        <div className="sm:col-span-2">
          <Champ
            id="reference"
            label="Référence (facultatif)"
            erreur={erreurs.reference}
            aide="Numéro de transaction Mobile Money, de virement, ou de reçu."
          >
            <input
              id="reference"
              type="text"
              maxLength={100}
              value={reference}
              onChange={(evenement) => setReference(evenement.target.value)}
              className={classesChamp(erreurs.reference)}
            />
          </Champ>
        </div>
      </div>
      {messageGenerique && <p className="text-petit text-danger">{messageGenerique}</p>}
      <Bouton type="submit" variante="principal" chargement={mutation.isPending}>
        Enregistrer le paiement
      </Bouton>
    </form>
  )
}
