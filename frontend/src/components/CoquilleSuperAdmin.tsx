/**
 * Coquille visuelle de l'espace super-admin — l'équivalent de
 * CoquilleApplication, mais pour un utilisateur qui n'appartient à aucun
 * établissement. Montée par RouteProtegee, jamais par une route du
 * routeur directement.
 *
 * Même habillage que le back-office commerçant : la classe ".back-office"
 * (palette, polices Poppins/Inter, voir index.css) et la même barre
 * supérieure avec le menu utilisateur. Sans elle, toutes les pages
 * super-admin retombaient sur les jetons génériques et la police système
 * — d'où des pages "sans style" à côté d'un back-office commerçant habillé.
 */
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import type { Moi } from '../api/auth'
import { chargerPolicesBackOffice } from '../lib/chargerPolicesBackOffice'
import { useTitreDocument } from '../hooks/useTitreDocument'
import { BarreLateraleSuperAdmin } from './BarreLateraleSuperAdmin'
import { BarreSuperieure } from './BarreSuperieure'
import { TransitionPage } from './TransitionPage'

export function CoquilleSuperAdmin({ moi, children }: { moi: Moi; children: ReactNode }) {
  const [menuLateralOuvert, setMenuLateralOuvert] = useState(false)
  useTitreDocument('Super-admin · Plateforme')

  useEffect(() => {
    chargerPolicesBackOffice()
  }, [])

  return (
    <div className="back-office min-h-screen bg-surface-alt font-sans">
      <BarreLateraleSuperAdmin ouvertMobile={menuLateralOuvert} onFermerMobile={() => setMenuLateralOuvert(false)} />

      <div className="lg:pl-[260px]">
        <BarreSuperieure moi={moi} onOuvrirMenuLateral={() => setMenuLateralOuvert(true)} />

        <main className="p-4 sm:p-6">
          <TransitionPage>{children}</TransitionPage>
        </main>
      </div>
    </div>
  )
}
