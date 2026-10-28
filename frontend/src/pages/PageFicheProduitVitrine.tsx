/**
 * Écran public "/produit/:id" — fiche complète d'un produit publié, vue
 * par n'importe quel visiteur non connecté. Galerie de photos (trois
 * formats), description entière, un sélecteur par dimension s'il y a des
 * variantes (voir SelecteurVariantes), et les trois actions d'achat (voir
 * BoutonsAchatVitrine). Un produit sans option n'a ni sélecteur ni garde :
 * exactement la fiche d'avant les variantes. Un identifiant qui ne
 * correspond à aucun produit publié — brouillon, archivé, ou d'un autre
 * établissement — affiche l'état "introuvable", jamais un détail sur la
 * raison réelle (voir VitrineProduitController côté API).
 */
import { useEffect, useRef, useState } from 'react'
import { ImageOff } from 'lucide-react'
import { useParams } from 'react-router-dom'
import type { ProduitVitrineDetail } from '../api/vitrine'
import { BoutonRetour } from '../components/BoutonRetour'
import { BoutonsAchatVitrine } from '../components/BoutonsAchatVitrine'
import { EtatErreur } from '../components/EtatErreur'
import { ImageAvecFondu } from '../components/ImageAvecFondu'
import { SelecteurVariantes } from '../components/SelecteurVariantes'
import { useEtablissementVitrine } from '../hooks/useEtablissementVitrine'
import { useProduitVitrine } from '../hooks/useProduitVitrine'
import { formaterMontant } from '../lib/formatage'
import { prixAnnonce, prixBarreAffiche } from '../lib/prixVitrine'
import type { SelectionVariante } from '../lib/selectionVariantes'
import { basculerValeur, photoDeSelection, varianteDeSelection, variantesCompatibles } from '../lib/selectionVariantes'

export function PageFicheProduitVitrine() {
  const { id } = useParams()
  const produitId = id ? Number(id) : null

  const { data: etablissement } = useEtablissementVitrine()
  const { data: produit, isPending, isError, error, refetch } = useProduitVitrine(produitId)
  const [indexPhoto, setIndexPhoto] = useState(0)
  const [selection, setSelection] = useState<SelectionVariante>({})
  // Un bouton d'achat cliqué sans variante : le sélecteur est entouré
  // jusqu'à ce qu'une variante soit choisie (voir BoutonsAchatVitrine).
  const [signalerVariante, setSignalerVariante] = useState(false)
  const selecteurRef = useRef<HTMLDivElement>(null)
  const vignettesRef = useRef<HTMLDivElement>(null)

  // La vignette active reste visible dans la bande : choisir un coloris
  // dont la photo est la 6e la sélectionnait hors de l'écran (vérifié à
  // 390 px). Défilement de la bande seule, jamais scrollIntoView, qui
  // remonterait aussi la page jusqu'à elle alors qu'on est au sélecteur.
  useEffect(() => {
    const bande = vignettesRef.current
    const vignette = bande?.children[indexPhoto] as HTMLElement | undefined
    if (!bande || !vignette) {
      return
    }
    bande.scrollLeft = vignette.offsetLeft - (bande.clientWidth - vignette.clientWidth) / 2
  }, [indexPhoto])

  function guiderVersLesVariantes() {
    setSignalerVariante(true)
    const selecteur = selecteurRef.current
    selecteur?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    // Le focus suit, sans second défilement : au clavier ou au lecteur
    // d'écran, on arrive sur la première valeur choisissable de la
    // première ligne encore vide.
    selecteur
      ?.querySelector<HTMLButtonElement>('[data-incomplete] button:not(:disabled)')
      ?.focus({ preventScroll: true })
  }

  /**
   * La photo suit le choix dès qu'il désigne une seule photo (le coloris
   * seul suffit), dans le gestionnaire et non dans un effet : un clic sur
   * une vignette ensuite reste maître de la photo affichée.
   */
  function basculer(produitCourant: ProduitVitrineDetail, optionId: number, valeurId: number) {
    const suivante = basculerValeur(selection, optionId, valeurId)
    setSelection(suivante)
    setSignalerVariante(false)

    const photoId = photoDeSelection(produitCourant.variantes, suivante)
    const index = produitCourant.photos.findIndex((photo) => photo.id === photoId)
    if (index >= 0) {
      setIndexPhoto(index)
    }
  }

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
  const varianteChoisie = varianteDeSelection(produit.variantes, produit.options.length, selection)
  // Le prix annoncé parmi les variantes encore possibles (« À partir de »
  // si leurs prix diffèrent, voir prixAnnonce). Le choix complet n'en
  // laisse qu'une : la mention tombe d'elle-même, et le prix est le sien.
  const annonce = prixAnnonce(produit.prix, variantesCompatibles(produit.variantes, selection))
  const prixAffiche = annonce.montant
  const aPartirDe = annonce.aPartirDe
  const barre = prixBarreAffiche(produit.prix_barre, annonce)
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
            <div ref={vignettesRef} className="relative flex gap-2 overflow-x-auto scroll-smooth">
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

          <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <span className="text-titre-page font-bold tabular-nums text-texte">
              {aPartirDe && <span className="text-corps font-medium text-texte-secondaire">À partir de </span>}
              {formaterMontant(prixAffiche)}
            </span>
            {barre !== null && (
              <span className="text-corps tabular-nums text-texte-secondaire">
                <span className="sr-only">Au lieu de </span>
                <s>{formaterMontant(barre)}</s>
              </span>
            )}
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
            <div
              ref={selecteurRef}
              className={`-mx-2 -mt-2 scroll-mt-24 rounded-lg p-2 transition-[outline-color] duration-rapide ${
                signalerVariante && varianteChoisie === null ? 'outline outline-2 outline-offset-0 outline-danger' : 'outline-none'
              }`}
            >
              <SelecteurVariantes
                options={produit.options}
                variantes={produit.variantes}
                photos={produit.photos}
                selection={selection}
                onBasculer={(optionId, valeurId) => basculer(produit, optionId, valeurId)}
              />
            </div>
          )}

          <BoutonsAchatVitrine
            produitId={produit.id}
            prix={prixAffiche}
            disponible={produit.disponible}
            variantes={produit.variantes}
            varianteChoisie={varianteChoisie}
            optionsManquantes={produit.options.filter((option) => selection[option.id] === undefined).map((option) => option.nom)}
            modePaiement={etablissement?.mode_paiement ?? null}
            onVarianteManquante={guiderVersLesVariantes}
          />
        </div>
      </div>
    </div>
  )
}
