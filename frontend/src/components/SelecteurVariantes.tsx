/**
 * Le sélecteur de la fiche produit : une ligne par dimension (« Pointure »,
 * « Coloris »), dans l'ordre du commerçant, valeurs comprises — jamais
 * alphabétique, voir OptionProduit côté API. Toute la décision (ce qui
 * existe, ce qui est épuisé) vient de lib/selectionVariantes.
 *
 * Trois apparences, parce que ce sont trois réponses différentes au client :
 * - disponible : un bouton ordinaire ;
 * - épuisée : choisissable (le client voit le prix et sait que ça existe),
 *   libellé barré tant qu'elle n'est pas choisie — une fois choisie, le
 *   badge « Épuisé » et les boutons d'achat le disent ; une valeur choisie
 *   ET barrée se lisait comme une erreur (vérifié à 390 px) ;
 * - inexistante avec le choix en cours (le 39 en marron) : désactivée,
 *   pointillée et pâlie, mais JAMAIS masquée — le client doit voir que le
 *   39 existe, seulement pas dans ce coloris. Une ligne sous les valeurs le
 *   dit en toutes lettres : la grisaille seule ne s'explique pas.
 *
 * À côté du nom d'une ligne qui pilote la photo (le coloris), la vignette
 * de la valeur choisie : voir photoDeLaLigne pour la règle et le pourquoi.
 *
 * Recliquer une valeur choisie la retire : c'est le chemin pour atteindre
 * le 39 quand le marron est choisi (retirer le marron, ou choisir le noir).
 */
import type { OptionVitrine, PhotoVitrine, VarianteVitrineDetail } from '../api/vitrine'
import type { SelectionVariante } from '../lib/selectionVariantes'
import { etatValeur, photoDeLaLigne } from '../lib/selectionVariantes'
import { ImageAvecFondu } from './ImageAvecFondu'

export function SelecteurVariantes({
  options,
  variantes,
  photos,
  selection,
  onBasculer,
}: {
  options: OptionVitrine[]
  variantes: VarianteVitrineDetail[]
  photos: PhotoVitrine[]
  selection: SelectionVariante
  onBasculer: (optionId: number, valeurId: number) => void
}) {
  return (
    <div className="space-y-4">
      {options.map((option) => {
        const choisie = option.valeurs.find((valeur) => valeur.id === selection[option.id])
        const etats = new Map(option.valeurs.map((valeur) => [valeur.id, etatValeur(variantes, selection, option.id, valeur.id)]))
        const inexistantes = option.valeurs.filter((valeur) => etats.get(valeur.id) === 'inexistante')
        const autresChoix = options
          .filter((autre) => autre.id !== option.id && selection[autre.id] !== undefined)
          .map((autre) => autre.valeurs.find((valeur) => valeur.id === selection[autre.id])?.libelle)
          .filter(Boolean)
        const idTitre = `option-${option.id}`
        const idPhoto = photoDeLaLigne(variantes, option, selection)
        const vignette = photos.find((photo) => photo.id === idPhoto)

        return (
          <div key={option.id} role="group" aria-labelledby={idTitre} data-incomplete={choisie ? undefined : ''}>
            <div className="mb-1.5 flex min-h-5 items-center gap-2">
              <span id={idTitre} className="text-petit font-medium text-texte">
                {option.nom}
                {choisie && <span className="font-normal text-texte-secondaire"> : {choisie.libelle}</span>}
              </span>
              {/* Décorative : le libellé dit déjà « Coloris : Marron ». */}
              {vignette && (
                <ImageAvecFondu
                  key={vignette.id}
                  srcJpg={vignette.vignette.jpg ?? vignette.vignette.webp ?? undefined}
                  alt=""
                  loading="eager"
                  width={40}
                  height={40}
                  className="h-10 w-10 shrink-0 rounded-md border border-bordure object-cover"
                />
              )}
            </div>
            <div className="flex flex-wrap gap-2">
              {option.valeurs.map((valeur) => {
                const etat = etats.get(valeur.id)
                const estChoisie = choisie?.id === valeur.id
                const inexistante = etat === 'inexistante'
                const epuisee = etat === 'epuisee'

                return (
                  <button
                    key={valeur.id}
                    type="button"
                    disabled={inexistante}
                    onClick={() => onBasculer(option.id, valeur.id)}
                    aria-pressed={estChoisie}
                    aria-label={epuisee ? `${valeur.libelle}, épuisé` : undefined}
                    className={`flex min-h-11 min-w-11 cursor-pointer items-center justify-center rounded-md border px-3 py-2 text-center text-corps font-medium leading-snug transition-[background-color,transform] active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-texte disabled:cursor-not-allowed disabled:active:scale-100 ${
                      estChoisie
                        ? 'border-texte bg-texte text-surface'
                        : inexistante
                          ? 'border-dashed border-bordure text-texte-secondaire opacity-60'
                          : 'border-bordure text-texte hover:bg-surface-alt'
                    } ${epuisee && !estChoisie ? 'line-through decoration-1' : ''}`}
                  >
                    {valeur.libelle}
                  </button>
                )
              })}
            </div>
            {inexistantes.length > 0 && autresChoix.length > 0 && (
              <p className="mt-1.5 text-petit text-texte-secondaire">
                {inexistantes.map((valeur) => valeur.libelle).join(', ')} : {inexistantes.length > 1 ? 'n’existent' : 'n’existe'} pas
                en {autresChoix.join(' / ')}.
              </p>
            )}
          </div>
        )
      })}
    </div>
  )
}
