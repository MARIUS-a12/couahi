/**
 * Pastille de statut d'abonnement (Étape 12), la même partout : page du
 * commerçant, liste et fiche super-admin. En retard et suspendu sont
 * volontairement colorés : c'est exactement ce que le super-admin cherche
 * des yeux pour savoir qui relancer.
 */
import type { StatutAbonnement } from '../api/abonnement'
import { LIBELLES_STATUT_ABONNEMENT } from '../lib/abonnement'

const CLASSES: Record<StatutAbonnement, string> = {
  essai: 'bg-bleu-info/10 text-bleu-info-texte',
  actif: 'bg-succes/10 text-succes',
  en_retard: 'bg-alerte/10 text-alerte',
  suspendu: 'bg-danger/10 text-danger-texte',
}

export function BadgeStatutAbonnement({ statut }: { statut: StatutAbonnement }) {
  return (
    <span
      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-petit font-semibold whitespace-nowrap ${CLASSES[statut]}`}
    >
      {LIBELLES_STATUT_ABONNEMENT[statut]}
    </span>
  )
}
