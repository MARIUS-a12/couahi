/**
 * Route "*" de la vitrine publique (voir App.tsx) : toute URL publique qui
 * ne correspond à aucune page connue (accueil, fiche produit) — jamais une
 * redirection silencieuse vers l'accueil, qui masquerait un lien cassé.
 */
import { SearchX } from 'lucide-react'
import { Link } from 'react-router-dom'

export function PageIntrouvableVitrine() {
  return (
    <div className="flex flex-col items-center gap-3 border border-bordure bg-surface px-6 py-16 text-center">
      <SearchX aria-hidden="true" size={48} strokeWidth={1.5} className="text-texte-secondaire" />
      <h1 className="text-titre-section font-semibold text-texte">Page introuvable</h1>
      <p className="max-w-sm text-corps text-texte-secondaire">
        Cette page n'existe pas ou n'est plus disponible.
      </p>
      <Link
        to="/"
        className="mt-2 inline-flex min-h-11 py-2 text-center leading-snug cursor-pointer items-center rounded bg-primaire px-4 text-corps font-medium text-surface transition-[background-color,transform] hover:bg-primaire-fonce active:scale-[0.97] active:bg-primaire-fonce focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1"
      >
        Retour à la boutique
      </Link>
    </div>
  )
}
