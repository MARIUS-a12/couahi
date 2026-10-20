/**
 * Disposition commune aux pages publiques de la vitrine (accueil, fiche
 * produit) — aucune authentification, aucun rapport avec CoquilleApplication
 * qui, elle, habille le back-office connecté. En-tête léger avec le logo (ou,
 * à défaut, le nom) de l'établissement, qui ramène à l'accueil, et une zone
 * de contenu. Montée par le routeur (voir App.tsx), jamais directement par
 * une page.
 *
 * La classe "vitrine" (voir index.css) assouplit surface-alt/bordure, et le
 * style inline pose --color-accent/--color-accent-texte à la couleur de CET
 * établissement : tout composant sous ce conteneur peut utiliser
 * bg-accent/text-accent-texte sans jamais savoir de quel établissement il
 * s'agit, ni calculer lui-même le contraste.
 */
import type { CSSProperties } from 'react'
import { Link, Outlet, useMatch } from 'react-router-dom'
import { useEtablissementVitrine } from '../hooks/useEtablissementVitrine'
import { useProduitVitrine } from '../hooks/useProduitVitrine'
import { useTitreDocument } from '../hooks/useTitreDocument'
import { couleurTexteSurAccent } from '../lib/couleurAccent'
import { LienPanier } from './LienPanier'
import { PiedDePageVitrine } from './PiedDePageVitrine'
import { TransitionPage } from './TransitionPage'

export function DispositionVitrine() {
  const { data: etablissement, isPending } = useEtablissementVitrine()

  // Titre de l'onglet décidé ICI, pour toute la vitrine : le nom de la
  // boutique, et sur une fiche produit "Produit — Boutique". La requête du
  // produit est la même (même clé) que celle de la fiche : aucun appel en
  // plus. Un produit introuvable garde le nom de la boutique.
  const routeProduit = useMatch('/produit/:id')
  const idProduit = routeProduit ? Number(routeProduit.params.id) || null : null
  const { data: produit } = useProduitVitrine(idProduit)
  useTitreDocument(
    etablissement ? (idProduit !== null && produit ? `${produit.nom} — ${etablissement.nom}` : etablissement.nom) : null,
  )

  const styleAccent: CSSProperties | undefined = etablissement
    ? ({
        '--color-accent': etablissement.couleur_accent,
        '--color-accent-texte': couleurTexteSurAccent(etablissement.couleur_accent),
      } as CSSProperties)
    : undefined

  return (
    <div className="vitrine min-h-screen bg-surface" style={styleAccent}>
      <header className="sticky top-0 z-10 border-b border-bordure bg-surface px-4">
        <div className="mx-auto flex h-14 max-w-6xl items-center justify-between gap-3">
          {isPending ? (
            <div className="h-5 w-40 animate-pulse rounded bg-surface-alt" />
          ) : (
            <Link
              to="/"
              className="flex min-w-0 items-center gap-2 truncate"
              aria-label={etablissement?.logo ? etablissement.nom : undefined}
            >
              {etablissement?.logo ? (
                <picture>
                  {etablissement.logo.petit.webp && (
                    <source srcSet={etablissement.logo.petit.webp} type="image/webp" />
                  )}
                  <img
                    src={etablissement.logo.petit.jpg ?? etablissement.logo.petit.webp ?? undefined}
                    alt=""
                    width={36}
                    height={36}
                    className="h-9 w-9 shrink-0 rounded border border-accent object-cover"
                  />
                </picture>
              ) : (
                <span className="truncate text-titre-section font-semibold text-texte">
                  {etablissement?.nom ?? 'Boutique'}
                </span>
              )}
            </Link>
          )}
          <LienPanier />
        </div>
      </header>

      <main className="mx-auto max-w-6xl px-4 py-6">
        <TransitionPage>
          <Outlet />
        </TransitionPage>
      </main>

      {etablissement && <PiedDePageVitrine etablissement={etablissement} />}
    </div>
  )
}
