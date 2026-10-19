/**
 * Barre latérale de l'espace super-admin — même habillage que BarreLaterale
 * (260px, fond marine, panneau coulissant sous 1024px), mais une navigation
 * propre à la plateforme. Quand une page d'établissement est ouverte
 * (/etablissements/:id/...), ses sous-pages apparaissent en dessous :
 * Paiement, Identité et Abonnement étaient auparavant de simples boutons
 * en bas de la fiche, invisibles sans faire défiler toute la page.
 *
 * La pastille de "Établissements" compte ceux à relancer (abonnement en
 * retard ou suspendu) — la même donnée que l'indicateur de la liste, via
 * la même requête, jamais recalculée à part.
 */
import { Building2, CreditCard, LayoutDashboard, Palette, Plus, ShieldCheck, Wallet } from 'lucide-react'
import { useEffect } from 'react'
import type { LucideIcon } from 'lucide-react'
import { NavLink, useMatch } from 'react-router-dom'
import { useEtablissement } from '../hooks/useEtablissement'
import { useEtablissements } from '../hooks/useEtablissements'

const CLASSES_LIEN = ({ isActive }: { isActive: boolean }) =>
  `flex h-11 items-center gap-3 rounded-lg px-3 text-corps font-medium transition-colors ${
    isActive ? 'bg-orange text-white' : 'text-white/70 hover:bg-white/10 hover:text-white'
  }`

function Entree({
  vers,
  libelle,
  icone: Icone,
  fin = false,
  pastille,
  onClick,
}: {
  vers: string
  libelle: string
  icone: LucideIcon
  fin?: boolean
  pastille?: number
  onClick: () => void
}) {
  return (
    <NavLink to={vers} end={fin} onClick={onClick} className={CLASSES_LIEN}>
      <Icone aria-hidden="true" size={20} strokeWidth={1.75} />
      <span className="flex-1 truncate">{libelle}</span>
      {!!pastille && (
        <span
          title={`${pastille} à relancer`}
          className="flex h-5 min-w-5 items-center justify-center rounded-full bg-danger px-1.5 text-[11px] font-semibold tabular-nums text-white"
        >
          {pastille}
        </span>
      )}
    </NavLink>
  )
}

function SousMenuEtablissement({ etablissementId, onFermer }: { etablissementId: number; onFermer: () => void }) {
  const { data: etablissement } = useEtablissement(etablissementId)
  const base = `/etablissements/${etablissementId}`

  return (
    <div className="mt-4 border-t border-white/10 pt-4">
      <p className="truncate px-3 pb-2 text-petit font-semibold uppercase tracking-wide text-white/50">
        {etablissement?.nom ?? 'Établissement'}
      </p>
      <div className="space-y-1">
        <Entree vers={base} fin libelle="Vue d'ensemble" icone={LayoutDashboard} onClick={onFermer} />
        <Entree vers={`${base}/abonnement`} libelle="Abonnement" icone={Wallet} onClick={onFermer} />
        <Entree vers={`${base}/paiement`} libelle="Paiement" icone={CreditCard} onClick={onFermer} />
        <Entree vers={`${base}/identite`} libelle="Identité" icone={Palette} onClick={onFermer} />
      </div>
    </div>
  )
}

export function BarreLateraleSuperAdmin({
  ouvertMobile,
  onFermerMobile,
}: {
  ouvertMobile: boolean
  onFermerMobile: () => void
}) {
  const { data: etablissements } = useEtablissements()
  const nombreARelancer = (etablissements ?? []).filter(
    (etablissement) =>
      etablissement.abonnement?.statut === 'en_retard' || etablissement.abonnement?.statut === 'suspendu',
  ).length

  // "/etablissements/nouveau" est une page de la liste, pas un
  // établissement : seul un identifiant numérique ouvre le sous-menu.
  const correspondance = useMatch('/etablissements/:id/*')
  const idBrut = correspondance?.params.id
  const etablissementId = idBrut && /^\d+$/.test(idBrut) ? Number(idBrut) : null

  useEffect(() => {
    if (!ouvertMobile) {
      return
    }

    function surEchap(evenement: KeyboardEvent) {
      if (evenement.key === 'Escape') {
        onFermerMobile()
      }
    }

    document.addEventListener('keydown', surEchap)
    return () => document.removeEventListener('keydown', surEchap)
  }, [ouvertMobile, onFermerMobile])

  return (
    <>
      {ouvertMobile && (
        <div
          role="presentation"
          onClick={onFermerMobile}
          className="fixed inset-0 z-30 animate-entree-fondu bg-texte/40 lg:hidden"
        />
      )}

      <aside
        className={`fixed inset-y-0 left-0 z-40 flex w-[260px] flex-col bg-marine transition-transform duration-normale ease-apparition lg:translate-x-0 ${
          ouvertMobile ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        <div className="flex items-center gap-3 border-b border-white/10 p-4">
          <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-orange">
            <ShieldCheck aria-hidden="true" size={22} strokeWidth={1.75} className="text-white" />
          </span>
          <div className="min-w-0">
            <p className="truncate font-titre text-corps font-semibold text-white">Plateforme</p>
            <p className="truncate text-petit text-white/60">Espace Administration</p>
          </div>
        </div>

        <nav className="flex-1 overflow-y-auto p-3">
          <div className="space-y-1">
            <Entree
              vers="/etablissements"
              fin
              libelle="Établissements"
              icone={Building2}
              pastille={nombreARelancer}
              onClick={onFermerMobile}
            />
            <Entree vers="/etablissements/nouveau" libelle="Nouvel établissement" icone={Plus} onClick={onFermerMobile} />
          </div>

          {etablissementId !== null && (
            <SousMenuEtablissement etablissementId={etablissementId} onFermer={onFermerMobile} />
          )}
        </nav>
      </aside>
    </>
  )
}
