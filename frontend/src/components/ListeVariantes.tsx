/**
 * Stock, prix et photo de chaque variante, regroupées par la valeur de la
 * dernière option (le coloris) quand il y en a plusieurs : la photo se
 * choisit une fois pour tout le groupe — les 7 sandales noires ont la
 * même —, puis se corrige ligne par ligne si besoin.
 *
 * Chaque champ s'enregistre en quittant le champ (ou à Entrée), seulement
 * s'il a changé. Le prix vide veut dire « prix du produit ».
 *
 * En disponibilité manuelle (restaurant), un interrupteur remplace le
 * stock.
 */
import { useState } from 'react'
import type { Media } from '../api/medias'
import { attribuerImage, modifierVariante } from '../api/variantes'
import type { ModificationVariante, StructureVariantes, Variante } from '../api/variantes'
import type { useVariantes } from '../hooks/useVariantes'
import { Bascule } from './Bascule'
import { sansEntree } from '../lib/clavier'

type Ecrire = ReturnType<typeof useVariantes>['ecrire']

export function ListeVariantes({
  produitId,
  structure,
  photos,
  ecrire,
  enCours,
}: {
  produitId: number
  structure: StructureVariantes
  photos: Media[]
  ecrire: Ecrire
  enCours: boolean
}) {
  const derniere = structure.options.length > 1 ? structure.options[structure.options.length - 1] : null
  const actives = structure.variantes.filter((variante) => variante.active)
  const inactives = structure.variantes.filter((variante) => !variante.active)
  const rang = new Map(structure.options.flatMap((option) => option.valeurs.map((valeur) => [valeur.id, valeur.ordre] as const)))
  const ordreOptions = structure.options.map((option) => new Set(option.valeurs.map((valeur) => valeur.id)))
  // Dans l'ordre des options, puis des valeurs : jamais par libellé.
  const trier = (variantes: Variante[]) =>
    [...variantes].sort((a, b) => {
      for (const valeursOption of ordreOptions) {
        const va = a.valeur_ids.find((id) => valeursOption.has(id)) ?? 0
        const vb = b.valeur_ids.find((id) => valeursOption.has(id)) ?? 0
        if (va !== vb) {
          return (rang.get(va) ?? 0) - (rang.get(vb) ?? 0)
        }
      }

      return 0
    })

  const groupes = derniere
    ? derniere.valeurs
        .map((valeur) => ({ valeur, variantes: trier(actives.filter((variante) => variante.valeur_ids.includes(valeur.id))) }))
        .filter((groupe) => groupe.variantes.length > 0)
    : [{ valeur: null, variantes: trier(actives) }]

  const modifier = (variante: Variante, modification: ModificationVariante) => ecrire(() => modifierVariante(produitId, variante.id, modification))

  // Une seule requête pour tout le groupe (voir GererVariantes::attribuerImage).
  function photoDuGroupe(variantes: Variante[], mediaId: number | null) {
    return ecrire(() => attribuerImage(produitId, variantes.map((variante) => variante.id), mediaId))
  }

  return (
    <div className="space-y-4">
      <h3 className="text-corps font-semibold text-texte">Stock, prix et photo</h3>

      {groupes.map(({ valeur, variantes }) => {
        const mediaCommun = variantes.every((variante) => variante.media_id === variantes[0].media_id) ? variantes[0].media_id : undefined

        return (
          <div key={valeur?.id ?? 'toutes'} className="space-y-2 rounded border border-bordure bg-surface-haute p-3">
            {valeur && (
              <div>
                <h4 className="text-corps font-semibold text-texte">
                  {derniere?.nom} : {valeur.libelle}
                </h4>
                {photos.length > 0 && (
                  <ChoixPhoto
                    legende={`Photo des variantes ${valeur.libelle}`}
                    photos={photos}
                    selection={mediaCommun}
                    desactive={enCours}
                    onChoisir={(mediaId) => photoDuGroupe(variantes, mediaId)}
                  />
                )}
              </div>
            )}
            <ul className="divide-y divide-bordure">
              {variantes.map((variante) => (
                <LigneVariante
                  key={variante.id}
                  variante={variante}
                  libelle={valeur ? libelleSans(variante, valeur.libelle) : variante.nom}
                  structure={structure}
                  photos={photos}
                  avecPhotoParLigne={!valeur}
                  onModifier={(modification) => modifier(variante, modification)}
                />
              ))}
            </ul>
          </div>
        )
      })}

      {inactives.length > 0 && (
        <details className="rounded border border-bordure p-3 text-petit text-texte-secondaire">
          <summary className="cursor-pointer">Variantes archivées ({inactives.length})</summary>
          <ul className="mt-2 space-y-1">
            {inactives.map((variante) => (
              <li key={variante.id}>
                {variante.nom} — {variante.quantite_stock} en stock
                {variante.nombre_commandes > 0 && `, ${variante.nombre_commandes} commande(s)`}. Cochez-la dans la grille pour la réactiver.
              </li>
            ))}
          </ul>
        </details>
      )}
    </div>
  )
}

/** « 39 / Noir » dans le groupe Noir s'affiche « 39 ». */
function libelleSans(variante: Variante, libelleGroupe: string): string {
  return variante.nom
    .split(' / ')
    .filter((partie) => partie !== libelleGroupe)
    .join(' / ') || variante.nom
}

function LigneVariante({
  variante,
  libelle,
  structure,
  photos,
  avecPhotoParLigne,
  onModifier,
}: {
  variante: Variante
  libelle: string
  structure: StructureVariantes
  photos: Media[]
  avecPhotoParLigne: boolean
  onModifier: (modification: ModificationVariante) => Promise<unknown>
}) {
  const [stock, setStock] = useState(String(variante.quantite_stock))
  const [prix, setPrix] = useState(variante.prix === null ? '' : String(variante.prix))

  function enregistrerStock() {
    const valeur = Math.max(0, Number.parseInt(stock, 10) || 0)
    if (valeur !== variante.quantite_stock) {
      onModifier({ quantite_stock: valeur })
    }
    setStock(String(valeur))
  }

  function enregistrerPrix() {
    const valeur = prix.trim() === '' ? null : Math.max(0, Number.parseInt(prix.replace(/\s/g, ''), 10) || 0)
    if (valeur !== variante.prix) {
      onModifier({ prix: valeur })
    }
  }

  return (
    <li className="flex flex-wrap items-center gap-x-3 gap-y-2 py-2">
      <span className="min-w-12 flex-1 text-corps font-medium text-texte">{libelle}</span>
      {structure.mode_stock === 'compte' ? (
        <label className="flex items-center gap-1 text-petit text-texte-secondaire">
          Stock
          <input
            type="number"
            inputMode="numeric"
            min={0}
            value={stock}
            aria-label={`Stock de ${variante.nom}`}
            onChange={(evenement) => setStock(evenement.target.value)}
            onBlur={enregistrerStock}
            onKeyDown={(evenement) => sansEntree(evenement, () => (evenement.target as HTMLInputElement).blur())}
            className="h-11 w-16 rounded border border-bordure bg-surface px-2 text-corps text-texte"
          />
        </label>
      ) : (
        <Bascule actif={variante.disponible} onChange={(disponible) => onModifier({ disponible })} libelleActif="Disponible" libelleInactif="Épuisé" />
      )}
      <label className="flex items-center gap-1 text-petit text-texte-secondaire">
        Prix
        <input
          type="text"
          inputMode="numeric"
          value={prix}
          placeholder={String(structure.prix_produit)}
          aria-label={`Prix de ${variante.nom} (vide : prix du produit)`}
          onChange={(evenement) => setPrix(evenement.target.value)}
          onBlur={enregistrerPrix}
          onKeyDown={(evenement) => sansEntree(evenement, () => (evenement.target as HTMLInputElement).blur())}
          className="h-11 w-24 rounded border border-bordure bg-surface px-2 text-corps text-texte"
        />
      </label>
      {avecPhotoParLigne && photos.length > 0 && (
        <ChoixPhoto legende={`Photo de ${variante.nom}`} photos={photos} selection={variante.media_id} onChoisir={(mediaId) => onModifier({ media_id: mediaId })} compact />
      )}
    </li>
  )
}

/**
 * Les photos du produit en vignettes à toucher, plus « Aucune » (la
 * variante montre alors les photos du produit). `selection` undefined :
 * le groupe a des photos différentes, rien n'est mis en avant.
 */
function ChoixPhoto({
  legende,
  photos,
  selection,
  onChoisir,
  desactive = false,
  compact = false,
}: {
  legende: string
  photos: Media[]
  selection: number | null | undefined
  onChoisir: (mediaId: number | null) => void
  desactive?: boolean
  compact?: boolean
}) {
  const taille = compact ? 'h-11 w-11' : 'h-14 w-14'
  const classes = (choisie: boolean) =>
    `${taille} shrink-0 cursor-pointer overflow-hidden rounded border-2 disabled:opacity-50 ${choisie ? 'border-primaire ring-2 ring-primaire' : 'border-bordure'}`

  return (
    <fieldset className="mt-1">
      <legend className={compact ? 'sr-only' : 'mb-1 text-petit text-texte-secondaire'}>{legende}</legend>
      <div className="flex flex-wrap gap-2">
        {photos.map((photo, index) => (
          <button
            key={photo.id}
            type="button"
            disabled={desactive}
            aria-pressed={selection === photo.id}
            aria-label={`Photo ${index + 1}`}
            onClick={() => onChoisir(photo.id)}
            className={classes(selection === photo.id)}
          >
            <img src={photo.vignette.webp ?? photo.vignette.jpg ?? ''} alt="" className="h-full w-full object-cover" />
          </button>
        ))}
        <button
          type="button"
          disabled={desactive}
          aria-pressed={selection === null}
          onClick={() => onChoisir(null)}
          className={`${classes(selection === null)} bg-surface-alt text-petit text-texte-secondaire`}
        >
          Aucune
        </button>
      </div>
    </fieldset>
  )
}
