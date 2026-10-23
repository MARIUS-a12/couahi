/**
 * Une carte de la grille publique (voir PageAccueilVitrine) : photo,
 * nom (deux lignes au plus), prix (« À partir de » si ses variantes n'ont
 * pas toutes le même, voir prixAnnonce), pastille "Épuisé" si le produit
 * n'est pas disponible. Mène à la fiche produit. Sans photo, un repli neutre (icône) plutôt qu'une image
 * cassée. Légère élévation au survol sur écran large (hover: de Tailwind ne
 * s'applique déjà qu'aux appareils qui supportent réellement le survol —
 * rien ne se déclenche au doigt sur mobile), léger enfoncement à la
 * pression, quel que soit l'appareil.
 */
import { ImageOff } from 'lucide-react'
import { Link } from 'react-router-dom'
import type { ProduitVitrine } from '../api/vitrine'
import { ImageAvecFondu } from './ImageAvecFondu'
import { formaterMontant } from '../lib/formatage'
import { prixAnnonce } from '../lib/prixVitrine'

export function CarteProduitVitrine({ produit }: { produit: ProduitVitrine }) {
  const { photo } = produit
  const prix = prixAnnonce(produit.prix, produit.variantes)

  return (
    <Link
      to={`/produit/${produit.id}`}
      className="group block overflow-hidden rounded-lg border border-bordure bg-surface transition-[transform,box-shadow,border-color] duration-rapide ease-apparition hover:-translate-y-0.5 hover:border-bordure-forte hover:shadow-carte active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1"
    >
      <div className="relative aspect-square w-full overflow-hidden bg-surface-alt">
        {photo ? (
          <ImageAvecFondu
            srcJpg={photo.moyenne.jpg ?? photo.vignette.jpg ?? undefined}
            srcSetWebp={[
              photo.vignette.webp ? `${photo.vignette.webp} 150w` : null,
              photo.moyenne.webp ? `${photo.moyenne.webp} 600w` : null,
            ]
              .filter(Boolean)
              .join(', ')}
            sizes="(max-width: 640px) 45vw, 300px"
            alt=""
            width={300}
            height={300}
            className="h-full w-full object-cover transition-transform duration-normale ease-apparition group-hover:scale-105"
          />
        ) : (
          <div className="flex h-full w-full items-center justify-center">
            <ImageOff aria-hidden="true" size={32} strokeWidth={1.5} className="text-texte-secondaire" />
          </div>
        )}

        {!produit.disponible && (
          <span className="absolute left-2 top-2 rounded-md bg-texte px-2 py-1 text-petit font-medium text-surface">
            Épuisé
          </span>
        )}
      </div>

      <div className={`p-3 ${produit.disponible ? '' : 'opacity-60'}`}>
        {/* Deux lignes au plus, et TOUJOURS la hauteur de deux (2lh) : un
            nom court garde la place du second rang, sinon les prix d'une
            même rangée de la grille ne s'alignent plus. */}
        <p className="line-clamp-2 min-h-[2lh] break-words text-corps text-texte-secondaire">{produit.nom}</p>
        {/* « À partir de » sur sa propre petite ligne, réservée sur TOUTES
            les cartes : à côté du montant, elle ne tiendrait pas dans une
            carte de 390 px, et une carte plus haute que ses voisines
            désalignerait les prix de la rangée. */}
        <p className="min-h-[1lh] text-petit text-texte-secondaire">{prix.aPartirDe ? 'À partir de' : ''}</p>
        <p className="tabular-nums text-titre-section font-bold text-texte">{formaterMontant(prix.montant)}</p>
      </div>
    </Link>
  )
}
