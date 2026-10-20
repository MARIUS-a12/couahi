/**
 * État du PAIEMENT d'une commande (Étape 13), affiché à côté de son statut
 * et jamais mélangé avec lui : une commande peut être confirmée sans être
 * payée, et inversement. Seul le Mobile Money est suivi ici — c'est le seul
 * mode dont le commerçant constate lui-même la réception.
 */
import { CheckCircle2, Clock, Smartphone } from 'lucide-react'
import type { Commande } from '../api/commandes'

type CommandePastille = Pick<Commande, 'mode_paiement' | 'paiement'>

const BASE = 'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-petit font-semibold'

export function PastillePaiement({ commande }: { commande: CommandePastille }) {
  if (commande.mode_paiement !== 'mobile_money') {
    return <span className="text-petit text-texte-secondaire">—</span>
  }

  const { paiement } = commande

  if (paiement.confirme) {
    return (
      <span className={`${BASE} bg-succes/10 text-succes`}>
        <CheckCircle2 aria-hidden="true" size={14} strokeWidth={2} />
        Payée
      </span>
    )
  }

  if (paiement.en_retard) {
    return (
      <span className={`${BASE} bg-danger/10 text-danger`}>
        <Clock aria-hidden="true" size={14} strokeWidth={2} />
        Attendu depuis +2h
      </span>
    )
  }

  return (
    <span className={`${BASE} bg-alerte/10 text-alerte`}>
      <Smartphone aria-hidden="true" size={14} strokeWidth={2} />
      À vérifier
    </span>
  )
}
