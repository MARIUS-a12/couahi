/**
 * Bandeau d'abonnement en haut de toutes les pages du back-office
 * (Étape 12) — réservé à qui a gerer_parametres : il mène à
 * /admin/abonnement, qu'un opérateur ne peut pas ouvrir (une entrée visible
 * ne doit jamais mener à un 403).
 *
 * "Masquer" ne vaut que pour la session de l'onglet (sessionStorage), jamais
 * définitivement : un impayé ne doit pas pouvoir disparaître pour de bon
 * d'un clic. La clé de masquage inclut le statut, l'échéance et le ton :
 * dès que la situation change (essai → retard, nouvelle échéance...), le
 * bandeau revient même s'il avait été masqué.
 */
import { AlertTriangle, Clock, X } from 'lucide-react'
import { useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import type { Moi } from '../api/auth'
import type { TonBandeauAbonnement } from '../lib/abonnement'
import { messageBandeauAbonnement } from '../lib/abonnement'

const CLE_MASQUAGE = 'bandeau-abonnement-masque'

const CLASSES_TON: Record<TonBandeauAbonnement, string> = {
  discret: 'border-bordure bg-surface text-texte-secondaire',
  alerte: 'border-alerte bg-alerte/10 text-alerte',
  danger: 'border-danger bg-danger/10 text-danger-texte',
}

function lireMasquage(): string | null {
  try {
    return sessionStorage.getItem(CLE_MASQUAGE)
  } catch {
    return null
  }
}

function ecrireMasquage(valeur: string): void {
  try {
    sessionStorage.setItem(CLE_MASQUAGE, valeur)
  } catch {
    // Stockage indisponible (navigation privée stricte) : le bandeau se
    // masque quand même pour cet affichage, il reviendra au rechargement.
  }
}

export function BandeauAbonnement({ moi }: { moi: Moi }) {
  const location = useLocation()
  const [masqueLocalement, setMasqueLocalement] = useState<string | null>(lireMasquage)

  const abonnement = moi.abonnement
  if (!abonnement || !moi.permissions.includes('gerer_parametres')) {
    return null
  }

  const contenu = messageBandeauAbonnement(abonnement)
  if (!contenu) {
    return null
  }

  const empreinte = `${abonnement.statut}|${abonnement.date_echeance}|${contenu.ton}`
  if (masqueLocalement === empreinte) {
    return null
  }

  function masquer() {
    ecrireMasquage(empreinte)
    setMasqueLocalement(empreinte)
  }

  const surPageAbonnement = location.pathname === '/admin/abonnement'
  const Icone = contenu.ton === 'discret' ? Clock : AlertTriangle

  return (
    <div
      role={contenu.ton === 'discret' ? 'status' : 'alert'}
      className={`flex animate-entree-haut items-center gap-3 rounded-lg border px-4 py-2 ${CLASSES_TON[contenu.ton]} ${
        contenu.ton === 'discret' ? 'text-petit' : 'text-corps font-medium'
      }`}
    >
      <Icone aria-hidden="true" size={contenu.ton === 'discret' ? 16 : 20} strokeWidth={1.75} className="shrink-0" />
      <p className="flex-1">{contenu.message}</p>
      {!surPageAbonnement && (
        <Link
          to="/admin/abonnement"
          className="shrink-0 rounded-md px-2 py-1 font-semibold underline underline-offset-2 transition-colors hover:bg-texte/5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1"
        >
          {contenu.ton === 'discret' ? 'Voir mon abonnement' : 'Payer mon abonnement'}
        </Link>
      )}
      <button
        type="button"
        onClick={masquer}
        aria-label="Masquer pour cette session"
        title="Masquer pour cette session"
        className="flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-md transition-[background-color,transform] duration-rapide ease-apparition hover:bg-texte/5 active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1"
      >
        <X aria-hidden="true" size={18} strokeWidth={1.75} />
      </button>
    </div>
  )
}
