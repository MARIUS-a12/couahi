/**
 * État du PAIEMENT d'une commande (Étape 13), affiché à côté de son statut
 * et jamais mélangé avec lui : une commande peut être confirmée sans être
 * payée, et inversement. Seul le Mobile Money est suivi ici — c'est le seul
 * mode dont le commerçant constate lui-même la réception.
 *
 * Ordre de priorité, du plus urgent au moins urgent :
 * 1. payée mais pas acceptée depuis plus de 2 h — le commerçant doit de la
 *    marchandise (ou un remboursement) à quelqu'un, et la commande retient
 *    son stock sans limite de temps ;
 * 2. argent reçu pour une commande annulée ou expirée — à rembourser ;
 * 3. payée ;
 * 4. annulée ou expirée sans paiement — plus rien à attendre ;
 * 5. transfert attendu depuis plus de 2 h ;
 * 6. à vérifier.
 *
 * Sous la pastille, le stock encore retenu (voir LigneStockReserve).
 */
import { AlertOctagon, CheckCircle2, Clock, RotateCcw, Smartphone, XCircle } from 'lucide-react'
import type { Commande } from '../api/commandes'
import { formaterMontant } from '../lib/formatage'

type CommandePastille = Pick<Commande, 'mode_paiement' | 'paiement' | 'statut' | 'stock_reserve'>

const BASE = 'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-petit font-semibold'

export function PastillePaiement({ commande, avecStock = false }: { commande: CommandePastille; avecStock?: boolean }) {
  if (commande.mode_paiement !== 'mobile_money') {
    return <span className="text-petit text-texte-secondaire">—</span>
  }

  return (
    <span className="inline-flex flex-col items-start gap-1">
      <Pastille commande={commande} />
      {avecStock && <LigneStockReserve commande={commande} />}
    </span>
  )
}

function Pastille({ commande }: { commande: CommandePastille }) {
  const { paiement, statut } = commande

  if (paiement.a_traiter_en_urgence) {
    return (
      <span className={`${BASE} bg-danger text-white`}>
        <AlertOctagon aria-hidden="true" size={14} strokeWidth={2} />
        Payée — à traiter
      </span>
    )
  }

  if (paiement.a_rembourser !== null) {
    return (
      <span className={`${BASE} bg-alerte/10 text-alerte`}>
        <RotateCcw aria-hidden="true" size={14} strokeWidth={2} />
        À rembourser : {formaterMontant(paiement.a_rembourser)}
      </span>
    )
  }

  if (paiement.confirme) {
    return (
      <span className={`${BASE} bg-succes/10 text-succes`}>
        <CheckCircle2 aria-hidden="true" size={14} strokeWidth={2} />
        Payée
      </span>
    )
  }

  if (statut === 'annulee' || statut === 'expiree') {
    return (
      <span className={`${BASE} bg-texte-secondaire/10 text-texte-secondaire`}>
        <XCircle aria-hidden="true" size={14} strokeWidth={2} />
        Non reçu
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

function formaterEcheance(date: string): string {
  return new Date(date).toLocaleString('fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

/** Le stock que la commande retient encore, et jusqu'à quand. */
export function LigneStockReserve({ commande }: { commande: Pick<Commande, 'stock_reserve'> }) {
  if (!commande.stock_reserve) {
    return null
  }

  return (
    <span className="text-[11px] font-medium text-texte-secondaire">
      {commande.stock_reserve.jusqua
        ? `Stock réservé jusqu'au ${formaterEcheance(commande.stock_reserve.jusqua)}`
        : 'Stock réservé sans limite tant que non traitée'}
    </span>
  )
}
