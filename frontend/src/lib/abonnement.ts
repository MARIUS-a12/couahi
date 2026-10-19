/**
 * Textes de l'abonnement (Étape 12), partagés par le bandeau du back-office
 * et la page /admin/abonnement : les deux doivent dire exactement la même
 * chose, avec les mêmes dates. Les dates arrivent de l'API au format
 * AAAA-MM-JJ, sans heure : formatées en UTC pour qu'un fuseau local ne les
 * décale jamais d'un jour.
 */
import type { MoyenPaiementAbonnement, ResumeAbonnement, StatutAbonnement } from '../api/abonnement'
import { formaterMontant } from './formatage'

const formateurDate = new Intl.DateTimeFormat('fr-FR', {
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
})

function versDateUtc(date: string): Date {
  return new Date(`${date.slice(0, 10)}T00:00:00Z`)
}

export function formaterDateLongue(date: string): string {
  return formateurDate.format(versDateUtc(date))
}

/** Décale une date AAAA-MM-JJ d'un nombre de jours, au même format. */
export function decalerDate(date: string, jours: number): string {
  const jour = versDateUtc(date)
  jour.setUTCDate(jour.getUTCDate() + jours)

  return jour.toISOString().slice(0, 10)
}

/**
 * Dernier jour d'une période d'un mois commençant à "debut" : la veille du
 * même quantième le mois suivant (1er → 31, 15 → 14 du mois suivant). Un
 * 31 janvier bute sur la fin de février au lieu de déborder en mars.
 */
export function finDePeriodeMensuelle(debut: string): string {
  const jour = versDateUtc(debut)
  const quantieme = jour.getUTCDate()
  const moisSuivant = new Date(Date.UTC(jour.getUTCFullYear(), jour.getUTCMonth() + 1, 1))
  const dernierJourMoisSuivant = new Date(
    Date.UTC(moisSuivant.getUTCFullYear(), moisSuivant.getUTCMonth() + 1, 0),
  ).getUTCDate()
  moisSuivant.setUTCDate(Math.min(quantieme, dernierJourMoisSuivant))

  return decalerDate(moisSuivant.toISOString().slice(0, 10), -1)
}

export function nombreDeJours(nombre: number): string {
  return `${nombre} jour${Math.abs(nombre) > 1 ? 's' : ''}`
}

export const LIBELLES_STATUT_ABONNEMENT: Record<StatutAbonnement, string> = {
  essai: "Période d'essai",
  actif: 'À jour',
  en_retard: 'En retard',
  suspendu: 'Suspendu',
}

export const LIBELLES_MOYEN_PAIEMENT: Record<MoyenPaiementAbonnement, string> = {
  en_ligne: 'En ligne',
  mobile_money: 'Mobile Money',
  especes: 'Espèces',
  virement: 'Virement',
}

/** La phrase d'état, en mots clairs, en tête de la page d'abonnement. */
export function phraseEtatAbonnement(abonnement: ResumeAbonnement): string {
  const echeance = formaterDateLongue(abonnement.date_echeance)

  switch (abonnement.statut) {
    case 'essai':
      return `Vous êtes en période d'essai jusqu'au ${echeance}.`
    case 'actif':
      return `Votre abonnement est à jour jusqu'au ${echeance}.`
    case 'en_retard':
      return `Votre abonnement est arrivé à échéance le ${echeance}. Votre boutique reste en ligne jusqu'au ${formaterDateLongue(abonnement.date_coupure)}.`
    case 'suspendu':
      return `Votre boutique est hors ligne depuis le ${formaterDateLongue(decalerDate(abonnement.date_coupure, 1))}. Vos données sont intactes : elle revient en ligne dès que votre paiement est enregistré.`
  }
}

export type TonBandeauAbonnement = 'discret' | 'alerte' | 'danger'

/**
 * Le message du bandeau, ou null quand il n'y a rien à dire (abonnement à
 * jour et loin de l'échéance). "discret" : essai encore loin de son terme.
 */
export function messageBandeauAbonnement(
  abonnement: ResumeAbonnement,
): { ton: TonBandeauAbonnement; message: string } | null {
  const montant = formaterMontant(abonnement.montant_mensuel)
  const echeance = formaterDateLongue(abonnement.date_echeance)

  if (abonnement.statut === 'suspendu') {
    return {
      ton: 'danger',
      message: `Votre boutique est hors ligne : l'abonnement de ${montant}, échu le ${echeance}, n'a pas été réglé. Vos données sont intactes, tout revient dès le paiement.`,
    }
  }

  if (abonnement.statut === 'en_retard') {
    const jours = Math.max(abonnement.jours_avant_coupure, 0)
    const delai = jours === 0 ? 'cette nuit' : `dans ${nombreDeJours(jours)}`

    return {
      ton: 'danger',
      message: `Échéance du ${echeance} dépassée : sans paiement de ${montant}, votre boutique sera coupée ${delai}.`,
    }
  }

  if (abonnement.doit_avertir) {
    const quand =
      abonnement.jours_avant_echeance === 0
        ? `aujourd'hui (${echeance})`
        : `le ${echeance}, dans ${nombreDeJours(abonnement.jours_avant_echeance)}`

    return { ton: 'alerte', message: `Votre abonnement arrive à échéance ${quand} : ${montant} à régler.` }
  }

  if (abonnement.statut === 'essai') {
    return {
      ton: 'discret',
      message: `Période d'essai : encore ${nombreDeJours(abonnement.jours_avant_echeance)}, jusqu'au ${echeance}.`,
    }
  }

  return null
}
