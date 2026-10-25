/**
 * Photos d'un produit déjà enregistré (l'API exige un produit existant pour
 * lui attacher une image, donc ce composant ne s'affiche qu'en modification
 * — voir PageFormulaireProduit). Clic ou glisser-déposer pour envoyer,
 * aperçu immédiat avec barre de progression, glisser une vignette pour
 * réordonner (la première devient la principale), suppression avec
 * confirmation. Maximum 15 photos, comme le serveur (config medias.max_par_produit).
 *
 * Chaque photo est réduite sur le téléphone avant de partir (voir
 * lib/reductionPhoto) : la limite de 8 Mo s'applique donc au fichier
 * réduit, et une photo de 12 Mo prise au téléphone passe. Un refus du
 * serveur (photo trop grande) s'affiche tel quel : son message dit quoi
 * faire, « Échec de l'envoi » ne le disait pas.
 *
 * Toute mise à jour (envoi, suppression, réordonnancement) écrit directement
 * dans le cache react-query de la requête produit plutôt que de garder son
 * propre état des photos : PageFormulaireProduit et cette zone lisent alors
 * toujours la même liste, sans étape de synchronisation.
 */
import { useRef, useState } from 'react'
import type { DragEvent } from 'react'
import { Loader2, Trash2, Upload } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import type { Media } from '../api/medias'
import { envoyerMediaProduit, reordonnerMediasProduit, supprimerMediaProduit } from '../api/medias'
import type { Produit } from '../api/produits'
import { confirmerSuppressionPhoto } from '../lib/confirmations'
import { extraireErreursChamps } from '../lib/erreursValidation'
import { reduirePhoto } from '../lib/reductionPhoto'

const MAX_PHOTOS = 15
const POIDS_MAX_OCTETS = 8 * 1024 * 1024

type EnvoiEnCours = {
  id: string
  previewUrl: string
  progression: number
  erreur: string | null
}

export function ZoneEnvoiPhotos({ produitId, medias }: { produitId: number; medias: Media[] }) {
  const queryClient = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const [survole, setSurvole] = useState(false)
  const [envoisEnCours, setEnvoisEnCours] = useState<EnvoiEnCours[]>([])
  const [indexGlisse, setIndexGlisse] = useState<number | null>(null)
  const [erreurGenerale, setErreurGenerale] = useState<string | null>(null)

  const placesRestantes = MAX_PHOTOS - medias.length - envoisEnCours.length
  const peutEnvoyer = placesRestantes > 0

  function definirMedias(nouveauxMedias: Media[]) {
    queryClient.setQueryData(['produit', produitId], (actuel: unknown) => {
      if (!actuel || typeof actuel !== 'object') {
        return actuel
      }

      return { ...(actuel as Produit), medias: nouveauxMedias }
    })
  }

  function validerFichier(fichier: File): string | null {
    if (!fichier.type.startsWith('image/')) {
      return "Ce fichier n'est pas une image."
    }
    if (fichier.size > POIDS_MAX_OCTETS) {
      return 'La photo dépasse la taille maximale autorisée (8 Mo).'
    }
    return null
  }

  async function envoyerFichiers(fichiers: File[]) {
    setErreurGenerale(null)
    const aTraiter = fichiers.slice(0, Math.max(0, placesRestantes))

    if (fichiers.length > aTraiter.length) {
      setErreurGenerale(`Seules ${MAX_PHOTOS} photos maximum sont autorisées par produit.`)
    }

    let mediasActuels = medias

    for (const choisi of aTraiter) {
      const id = crypto.randomUUID()
      const previewUrl = URL.createObjectURL(choisi)
      const fichier = choisi.type.startsWith('image/') ? await reduirePhoto(choisi) : choisi
      const erreurValidation = validerFichier(fichier)

      setEnvoisEnCours((actuels) => [...actuels, { id, previewUrl, progression: 0, erreur: erreurValidation }])

      if (erreurValidation) {
        continue
      }

      try {
        const media = await envoyerMediaProduit(produitId, fichier, (pourcentage) => {
          setEnvoisEnCours((actuels) =>
            actuels.map((envoi) => (envoi.id === id ? { ...envoi, progression: pourcentage } : envoi)),
          )
        })

        mediasActuels = [...mediasActuels, media]
        definirMedias(mediasActuels)
        setEnvoisEnCours((actuels) => actuels.filter((envoi) => envoi.id !== id))
        URL.revokeObjectURL(previewUrl)
      } catch (erreur) {
        // Le refus du serveur est trop long pour une vignette de 100 px :
        // il va dans le message général, la vignette dit seulement « Refusée ».
        const refus = extraireErreursChamps(erreur).photo
        if (refus) {
          setErreurGenerale(refus)
        }
        setEnvoisEnCours((actuels) =>
          actuels.map((envoi) => (envoi.id === id ? { ...envoi, erreur: refus ? 'Refusée.' : "Échec de l'envoi. Réessayez." } : envoi)),
        )
      }
    }
  }

  function retirerEnvoiEchoue(id: string) {
    setEnvoisEnCours((actuels) => {
      const envoi = actuels.find((e) => e.id === id)
      if (envoi) {
        URL.revokeObjectURL(envoi.previewUrl)
      }
      return actuels.filter((e) => e.id !== id)
    })
  }

  async function supprimer(media: Media) {
    if (!confirmerSuppressionPhoto()) {
      return
    }

    const precedents = medias
    const restants = medias
      .filter((m) => m.id !== media.id)
      .map((m, index) => ({ ...m, ordre: index, est_principal: index === 0 }))

    definirMedias(restants)

    try {
      await supprimerMediaProduit(produitId, media.id)
    } catch {
      definirMedias(precedents)
      setErreurGenerale('Impossible de supprimer cette photo. Réessayez.')
    }
  }

  async function deplacer(depuisIndex: number, versIndex: number) {
    if (depuisIndex === versIndex) {
      return
    }

    const precedents = medias
    const copie = [...medias]
    const [deplace] = copie.splice(depuisIndex, 1)
    copie.splice(versIndex, 0, deplace)
    const reordonnes = copie.map((m, index) => ({ ...m, ordre: index, est_principal: index === 0 }))

    definirMedias(reordonnes)

    try {
      await reordonnerMediasProduit(produitId, reordonnes.map((m) => m.id))
    } catch {
      definirMedias(precedents)
      setErreurGenerale('Impossible de réordonner les photos. Réessayez.')
    }
  }

  function gererDepot(evenement: DragEvent<HTMLDivElement>) {
    evenement.preventDefault()
    setSurvole(false)
    envoyerFichiers(Array.from(evenement.dataTransfer.files))
  }

  return (
    <div>
      <span className="mb-1 block text-petit font-medium text-texte">
        Photos <span className="text-texte-secondaire">({medias.length}/{MAX_PHOTOS})</span>
      </span>

      {erreurGenerale && <p className="animate-entree-champ mb-2 text-petit text-danger">{erreurGenerale}</p>}

      {(medias.length > 0 || envoisEnCours.length > 0) && (
        <div className="mb-3 grid grid-cols-3 gap-3 sm:grid-cols-5">
          {medias.map((media, index) => (
            <div
              key={media.id}
              draggable
              onDragStart={() => setIndexGlisse(index)}
              onDragOver={(evenement) => evenement.preventDefault()}
              onDrop={(evenement) => {
                evenement.preventDefault()
                if (indexGlisse !== null) {
                  deplacer(indexGlisse, index)
                }
                setIndexGlisse(null)
              }}
              onDragEnd={() => setIndexGlisse(null)}
              className="relative aspect-square cursor-move overflow-hidden rounded border border-bordure bg-surface-alt"
            >
              <picture>
                {media.vignette.webp && <source srcSet={media.vignette.webp} type="image/webp" />}
                <img
                  src={media.vignette.jpg ?? media.vignette.webp ?? undefined}
                  alt=""
                  className="h-full w-full object-cover"
                />
              </picture>
              {media.est_principal && (
                <span className="absolute left-1 top-1 rounded bg-primaire px-1.5 py-0.5 text-petit font-medium text-surface">
                  Principale
                </span>
              )}
              <button
                type="button"
                title="Supprimer cette photo"
                aria-label="Supprimer cette photo"
                onClick={() => supprimer(media)}
                className="absolute right-1 top-1 flex h-11 w-11 cursor-pointer items-center justify-center rounded-full bg-surface/90 text-danger transition-[background-color,transform] hover:bg-surface active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1"
              >
                <Trash2 aria-hidden="true" size={18} strokeWidth={1.5} />
              </button>
            </div>
          ))}

          {envoisEnCours.map((envoi) => (
            <div key={envoi.id} className="relative aspect-square overflow-hidden rounded border border-bordure">
              <img src={envoi.previewUrl} alt="" className="h-full w-full object-cover opacity-50" />
              {envoi.erreur ? (
                <button
                  type="button"
                  onClick={() => retirerEnvoiEchoue(envoi.id)}
                  className="absolute inset-0 flex items-center justify-center bg-surface/90 p-1 text-center text-petit text-danger"
                >
                  {envoi.erreur}
                  <br />
                  (toucher pour retirer)
                </button>
              ) : (
                <div className="absolute inset-0 flex flex-col items-center justify-center gap-1.5 bg-surface/70">
                  <Loader2 aria-hidden="true" size={20} strokeWidth={1.5} className="animate-spin text-primaire" />
                  <div className="h-1 w-4/5 overflow-hidden rounded bg-bordure">
                    {/* scaleX plutôt que width : une largeur qui change
                        recalcule la mise en page à chaque pourcentage reçu,
                        un transform non. origin-left pour que la barre
                        grandisse vers la droite comme une largeur l'aurait
                        fait. */}
                    <div
                      className="h-full w-full origin-left rounded bg-primaire transition-transform"
                      style={{ transform: `scaleX(${envoi.progression / 100})` }}
                    />
                  </div>
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      {peutEnvoyer && (
        <div
          role="button"
          tabIndex={0}
          onClick={() => inputRef.current?.click()}
          onKeyDown={(evenement) => {
            if (evenement.key === 'Enter' || evenement.key === ' ') {
              evenement.preventDefault()
              inputRef.current?.click()
            }
          }}
          onDragOver={(evenement) => {
            evenement.preventDefault()
            setSurvole(true)
          }}
          onDragLeave={() => setSurvole(false)}
          onDrop={gererDepot}
          className={`flex h-11 cursor-pointer items-center justify-center gap-2 rounded border-2 border-dashed px-4 text-corps text-texte-secondaire transition-[background-color,border-color,color,transform] active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 ${
            survole ? 'border-primaire bg-primaire/5 text-primaire' : 'border-bordure hover:border-primaire'
          }`}
        >
          <Upload aria-hidden="true" size={20} strokeWidth={1.5} />
          Ajouter une photo
          <input
            ref={inputRef}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="hidden"
            onChange={(evenement) => {
              if (evenement.target.files) {
                envoyerFichiers(Array.from(evenement.target.files))
              }
              evenement.target.value = ''
            }}
          />
        </div>
      )}
    </div>
  )
}
