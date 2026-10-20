/**
 * Coquille visuelle de l'app une fois connecté : barre latérale fixe
 * (Étape 8, maquette de référence) + barre supérieure + zone de contenu.
 * Rendue par RouteProtegee, jamais montée directement par une route.
 *
 * ".back-office" sur le conteneur racine re-dessine tous les jetons de
 * couleur génériques (surface, bordure, texte, primaire, danger...) aux
 * couleurs de la maquette (voir index.css) — aucun composant partagé
 * (Bouton, badges, champs...) n'a eu besoin de changer une seule classe
 * pour ça, exactement comme ".vitrine" le fait côté boutique publique.
 * "font-sans" réaffirme la police locale (Inter, voir index.css) sur cet
 * élément : la police du <body> ne se retype pas toute seule, elle est
 * fixée à la police système plus haut dans l'arbre, hors de cette portée.
 *
 * Poppins/Inter ne sont chargées qu'ici, au montage (voir
 * lib/chargerPolicesBackOffice.ts) — jamais pour un visiteur de la vitrine.
 */
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import type { Moi } from '../api/auth'
import { BandeauAbonnement } from './BandeauAbonnement'
import { BarreLaterale } from './BarreLaterale'
import { BarreSuperieure } from './BarreSuperieure'
import { chargerPolicesBackOffice } from '../lib/chargerPolicesBackOffice'
import { useTitreDocument } from '../hooks/useTitreDocument'
import { TransitionPage } from './TransitionPage'

export function CoquilleApplication({ moi, children }: { moi: Moi; children: ReactNode }) {
  const [menuLateralOuvert, setMenuLateralOuvert] = useState(false)

  // Distinct de la vitrine (qui porte le seul nom de la boutique) : un
  // commerçant qui a les deux ouverts doit les distinguer d'un coup d'œil.
  useTitreDocument(moi.etablissement ? `${moi.etablissement.nom} · Back-office` : 'Back-office')

  useEffect(() => {
    chargerPolicesBackOffice()
  }, [])

  return (
    <div className="back-office min-h-screen bg-surface-alt font-sans">
      <BarreLaterale moi={moi} ouvertMobile={menuLateralOuvert} onFermerMobile={() => setMenuLateralOuvert(false)} />

      <div className="lg:pl-[260px]">
        <BarreSuperieure moi={moi} onOuvrirMenuLateral={() => setMenuLateralOuvert(true)} />

        <main className="p-4 sm:p-6">
          <div className="mb-4 empty:hidden">
            <BandeauAbonnement moi={moi} />
          </div>
          <TransitionPage>{children}</TransitionPage>
        </main>
      </div>
    </div>
  )
}
