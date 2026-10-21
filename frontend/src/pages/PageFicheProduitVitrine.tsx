/**
 * Écran public "/produit/:id" — fiche complète d'un produit publié, vue
 * par n'importe quel visiteur non connecté. Galerie de photos (trois
 * formats), description entière, sélection de variante s'il y en a, et les
 * trois actions d'achat (voir BoutonsAchatVitrine). Un identifiant qui ne
 * correspond à aucun produit publié — brouillon, archivé, ou d'un autre
 * établissement — affiche l'état "introuvable", jamais un détail sur la
 * raison réelle (voir VitrineProduitController côté API).
 */
import { useState } from 'react'
import { ImageOff } from 'lucide-react'
import { useParams } from 'react-router-dom'
import type { VarianteVitrine } from '../api/vitrine'
import { BoutonRetour } from '../components/BoutonRetour'
import { BoutonsAchatVitrine } from '../components/BoutonsAchatVitrine'
import { EtatErreur } from '../components/EtatErreur'
import { ImageAvecFondu } from '../components/ImageAvecFondu'
import { useEtablissementVitrine } from '../hooks/useEtablissementVitrine'
import { useProduitVitrine } from '../hooks/useProduitVitrine'
import { formaterMontant } from '../lib/formatage'

export function PageFicheProduitVitrine() {
  const { id } = useParams()
  const produitId = id ? Number(id) : null

  const { data: etablissement } = useEtablissementVitrine()
  const { data: produit, isPending, isError, error, refetch } = useProduitVitrine(produitId)
  const [indexPhoto, setIndexPhoto] = useState(0)
  const [varianteChoisie, setVarianteChoisie] = useState<VarianteVitrine | null>(null)

  if (isPending) {
    return (
      <div className="mx-auto max-w-4xl space-y-4">
        <div className="aspect-square w-full animate-pulse rounded bg-surface-alt" />
        <div className="h-6 w-2/3 animate-pulse rounded bg-surface-alt" />
        <div className="h-5 w-1/3 animate-pulse rounded bg-surface-alt" />
      </div>
    )
  }

  if (isError || !produit) {
    return (
      <div className="mx-auto max-w-4xl">
        <BoutonRetour vers="/" />
        <EtatErreur erreur={error} onReessayer={() => refetch()} />
      </div>
    )
  }

  const photoActive = produit.photos[indexPhoto] ?? produit.photos[0] ?? null
  const prixAffiche = varianteChoisie?.prix ?? produit.prix
  const disponibleAffiche = varianteChoisie ? varianteChoisie.disponible : produit.disponible

  return (
    <div className="mx-auto max-w-5xl space-y-8 pb-6">
      <BoutonRetour vers="/" />

      <div className="grid grid-cols-1 gap-8 md:grid-cols-2 md:gap-12">
        <div className="space-y-3">
          <div className="aspect-square w-full overflow-hidden rounded-lg border border-bordure bg-surface-alt">
            {photoActive ? (
              <ImageAvecFondu
                srcJpg={photoActive.grande.jpg ?? photoActive.moyenne.jpg ?? undefined}
                srcSetWebp={[
                  photoActive.moyenne.webp ? `${photoActive.moyenne.webp} 600w` : null,
                  photoActive.grande.webp ? `${photoActive.grande.webp} 1200w` : null,
                ]
                  .filter(Boolean)
                  .join(', ')}
                sizes="(max-width: 768px) 100vw, 50vw"
                alt={produit.nom}
                loading="eager"
                width={1200}
                height={1200}
                className="h-full w-full object-cover"
              />
            ) : (
              <div className="flex h-full w-full items-center justify-center">
                <ImageOff aria-hidden="true" size={48} strokeWidth={1.5} className="text-texte-secondaire" />
              </div>
            )}
          </div>

          {produit.photos.length > 1 && (
            <div className="flex gap-2 overflow-x-auto">
              {produit.photos.map((photo, index) => (
                <button
                  key={photo.id}
                  type="button"
                  onClick={() => setIndexPhoto(index)}
                  aria-label={`Photo ${index + 1}`}
                  aria-pressed={index === indexPhoto}
                  className={`h-16 w-16 shrink-0 cursor-pointer overflow-hidden rounded-md border-2 transition-[border-color,transform] active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1 ${
                    index === indexPhoto ? 'border-texte' : 'border-transparent'
                  }`}
                >
                  <ImageAvecFondu
                    srcJpg={photo.vignette.jpg ?? photo.vignette.webp ?? undefined}
                    alt=""
                    loading="lazy"
                    width={64}
                    height={64}
                    className="h-full w-full object-cover"
                  />
                </button>
              ))}
            </div>
          )}
        </div>

        <div className="space-y-6">
          <div className="space-y-1">
            {produit.categorie && (
              <p className="text-petit font-medium text-texte-secondaire">{produit.categorie.nom}</p>
            )}
            <h1 className="text-titre-page font-bold text-texte">{produit.nom}</h1>
          </div>

          <div className="flex items-center gap-3">
            <span className="text-titre-page font-bold tabular-nums text-texte">
              {formaterMontant(prixAffiche)}
            </span>
            {!disponibleAffiche && (
              <span className="rounded-md bg-texte px-2 py-1 text-petit font-medium text-surface">Épuisé</span>
            )}
          </div>

          {produit.description && (
            <p className="whitespace-pre-line text-corps leading-relaxed text-texte-secondaire">
              {produit.description}
            </p>
          )}

          {produit.variantes.length > 0 && (
            <div>
              <span className="mb-1.5 block text-petit font-medium text-texte">Choisissez une variante</span>
              <div className="flex flex-wrap gap-2">
                {produit.variantes.map((variante) => {
                  const estChoisie = varianteChoisie?.id === variante.id

                  return (
                    <button
                      key={variante.id}
                      type="button"
                      onClick={() => setVarianteChoisie(variante)}
                      aria-pressed={estChoisie}
                      className={`flex min-h-11 py-2 text-center leading-snug cursor-pointer items-center gap-1.5 rounded-md border px-3 text-corps font-medium transition-[background-color,transform] active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1 ${
                        estChoisie
                          ? 'border-texte bg-texte text-surface'
                          : 'border-bordure text-texte hover:bg-surface-alt'
                      } ${variante.disponible ? '' : 'opacity-50'}`}
                    >
                      {variante.nom}
                      {!variante.disponible && (
                        <span className="text-petit font-normal">(épuisé)</span>
                      )}
                    </button>
                  )
                })}
              </div>
            </div>
          )}

          <BoutonsAchatVitrine
            produitId={produit.id}
            prix={prixAffiche}
            disponible={produit.disponible}
            variantes={produit.variantes}
            varianteChoisie={varianteChoisie}
            modePaiement={etablissement?.mode_paiement ?? null}
          />
        </div>
      </div>
    </div>
  )
}
