/**
 * Panier vide : une invitation à parcourir le catalogue, pas une phrase
 * triste (voir EtatVideVitrine pour l'équivalent "aucun résultat de
 * recherche", un cas différent).
 */
import { ShoppingCart } from 'lucide-react'
import { Link } from 'react-router-dom'

export function EtatPanierVide() {
  return (
    <div className="flex flex-col items-center gap-3 border border-bordure bg-surface px-6 py-16 text-center">
      <ShoppingCart aria-hidden="true" size={48} strokeWidth={1.5} className="text-texte-secondaire" />
      <h2 className="text-titre-section font-semibold text-texte">Votre panier est vide</h2>
      <p className="max-w-sm text-corps text-texte-secondaire">
        Parcourez le catalogue et ajoutez des produits pour commencer une commande.
      </p>
      <Link
        to="/"
        className="mt-2 inline-flex min-h-11 py-2 text-center leading-snug cursor-pointer items-center rounded bg-accent px-4 text-corps font-medium text-accent-texte transition-[opacity,transform] hover:opacity-90 active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1"
      >
        Voir les produits
      </Link>
    </div>
  )
}
