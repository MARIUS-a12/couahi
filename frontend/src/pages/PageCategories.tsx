/**
 * Écran Catégories (/admin/categories) : liste avec le nombre de produits par
 * catégorie, création en ligne (un champ + un bouton, pas une page à part —
 * ça n'a pas besoin d'être plus lourd que ça), et archivage/republication.
 * Accessible depuis la liste de produits et depuis le champ Catégorie du
 * formulaire produit. Création et actions absentes pour qui n'a pas
 * gerer_catalogue — un opérateur ne fait que consulter.
 *
 * Deux niveaux (« Chaussures › Sandales ») : la liste s'affiche en arbre,
 * une catégorie se crée directement dans une catégorie principale, et une
 * catégorie sans sous-catégorie peut être déplacée dans une autre. Le
 * serveur refuse un troisième niveau avec un message lisible.
 */
import { Archive, ArchiveRestore, FolderTree } from 'lucide-react'
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Categorie } from '../api/categories'
import { archiverCategorie, changerParentCategorie, creerCategorie, reactiverCategorie } from '../api/categories'
import { BandeauSucces } from '../components/BandeauSucces'
import { EnteteDePage } from '../components/EnteteDePage'
import { EtatBouton } from '../components/EtatBouton'
import { EtatChargement } from '../components/EtatChargement'
import { EtatErreur } from '../components/EtatErreur'
import { useCategories } from '../hooks/useCategories'
import { useMoi } from '../hooks/useMoi'
import { extraireErreursChamps } from '../lib/erreursValidation'

export function PageCategories() {
  const { data: categories, isPending, isError, error, refetch } = useCategories()
  const { data: moi } = useMoi()
  const peutGererCatalogue = moi?.permissions.includes('gerer_catalogue') ?? false
  const queryClient = useQueryClient()

  const [nomNouvelleCategorie, setNomNouvelleCategorie] = useState('')
  const [parentNouvelleCategorie, setParentNouvelleCategorie] = useState<number | null>(null)
  const [erreurDeplacement, setErreurDeplacement] = useState<{ id: number; message: string } | null>(null)
  const [erreurCreation, setErreurCreation] = useState<string | null>(null)
  const [messageSucces, setMessageSucces] = useState<string | null>(null)

  function invaliderApresAction() {
    queryClient.invalidateQueries({ queryKey: ['categories'] })
  }

  const creation = useMutation({
    mutationFn: () => creerCategorie(nomNouvelleCategorie.trim(), parentNouvelleCategorie),
    onSuccess: () => {
      setNomNouvelleCategorie('')
      // La suivante redevient principale par défaut : sans ça, chaque
      // création se rangerait sans le dire dans la dernière choisie.
      setParentNouvelleCategorie(null)
      setErreurCreation(null)
      invaliderApresAction()
    },
    onError: (erreur) => {
      const champs = extraireErreursChamps(erreur)
      setErreurCreation(
        champs.nom ?? "Impossible de créer la catégorie. Vérifiez votre connexion et réessayez.",
      )
    },
  })

  const deplacement = useMutation({
    mutationFn: ({ id, parentId }: { id: number; parentId: number | null }) => changerParentCategorie(id, parentId),
    onSuccess: () => {
      setErreurDeplacement(null)
      invaliderApresAction()
    },
    onError: (erreur, { id }) => {
      const champs = extraireErreursChamps(erreur)
      setErreurDeplacement({ id, message: champs.parent_id ?? 'Impossible de déplacer la catégorie. Réessayez.' })
    },
  })

  const archivage = useMutation({ mutationFn: (id: number) => archiverCategorie(id) })
  const principales = (categories ?? []).filter((categorie) => categorie.parent_id === null && categorie.statut === 'actif')
  // Chaque catégorie principale, suivie de ses sous-catégories : l'arbre à plat.
  const enArbre = (categories ?? [])
    .filter((categorie) => categorie.parent_id === null)
    .flatMap((racine) => [racine, ...(categories ?? []).filter((enfant) => enfant.parent_id === racine.id)])
  const reactivation = useMutation({ mutationFn: (id: number) => reactiverCategorie(id) })

  function soumettreCreation(evenement: FormEvent<HTMLFormElement>) {
    evenement.preventDefault()
    if (!nomNouvelleCategorie.trim()) {
      return
    }
    creation.mutate()
  }

  function demanderArchivage(categorie: Categorie) {
    const confirme = window.confirm(
      `Les produits de « ${categorie.nom} » restent intacts, seulement détachés ` +
        `visuellement de cette catégorie.\n\nArchiver « ${categorie.nom} » ?`,
    )

    if (confirme) {
      archivage.mutate(categorie.id, {
        onSuccess: () => {
          invaliderApresAction()
          setMessageSucces(`« ${categorie.nom} » a été archivée.`)
        },
      })
    }
  }

  function reactiver(categorie: Categorie) {
    reactivation.mutate(categorie.id, {
      onSuccess: () => {
        invaliderApresAction()
        setMessageSucces(`« ${categorie.nom} » a été republiée.`)
      },
    })
  }

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <EnteteDePage
        icone={<FolderTree aria-hidden="true" size={22} strokeWidth={1.75} />}
        couleur="violet"
        titre="Catégories"
        sousTitre="Organisez votre catalogue pour que vos clients s'y retrouvent."
      />

      {messageSucces && <BandeauSucces message={messageSucces} onFermer={() => setMessageSucces(null)} />}

      {peutGererCatalogue && (
        <form onSubmit={soumettreCreation} className="flex flex-wrap gap-3">
          <div className="min-w-48 flex-1">
            <input
              type="text"
              placeholder="Nom de la nouvelle catégorie"
              value={nomNouvelleCategorie}
              onChange={(evenement) => {
                setNomNouvelleCategorie(evenement.target.value)
                setErreurCreation(null)
              }}
              className={`h-11 w-full rounded border bg-surface px-3 text-corps text-texte transition-colors focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1 ${
                erreurCreation ? 'border-danger' : 'border-bordure focus:border-primaire'
              }`}
            />
            {erreurCreation && <p className="animate-entree-champ mt-1 text-petit text-danger">{erreurCreation}</p>}
          </div>
          {principales.length > 0 && (
            <select
              aria-label="Ranger la nouvelle catégorie dans"
              value={parentNouvelleCategorie ?? ''}
              onChange={(evenement) => setParentNouvelleCategorie(evenement.target.value === '' ? null : Number(evenement.target.value))}
              className="h-11 rounded border border-bordure bg-surface px-2 text-corps text-texte"
            >
              <option value="">Catégorie principale</option>
              {principales.map((categorie) => (
                <option key={categorie.id} value={categorie.id}>
                  Dans « {categorie.nom} »
                </option>
              ))}
            </select>
          )}
          <button
            type="submit"
            disabled={creation.isPending || !nomNouvelleCategorie.trim()}
            className="h-11 shrink-0 cursor-pointer rounded bg-primaire px-4 text-corps font-medium text-surface transition-[background-color,transform] hover:bg-primaire-fonce active:scale-[0.97] active:bg-primaire-fonce focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100"
          >
            <EtatBouton chargement={creation.isPending}>Ajouter</EtatBouton>
          </button>
        </form>
      )}

      {isPending && <EtatChargement />}

      {isError && <EtatErreur erreur={error} onReessayer={() => refetch()} />}

      {!isPending && !isError && categories && categories.length === 0 && (
        <p className="text-corps text-texte-secondaire">Aucune catégorie pour le moment.</p>
      )}

      {!isPending && !isError && categories && categories.length > 0 && (
        <ul className="divide-y divide-bordure border border-bordure">
          {enArbre.map((categorie) => {
            const estArchivee = categorie.statut === 'inactif'
            const estEnfant = categorie.parent_id !== null
            const aDesEnfants = categories.some((autre) => autre.parent_id === categorie.id)

            return (
              <li key={categorie.id} className={`flex flex-wrap items-center justify-between gap-3 p-4 ${estEnfant ? 'pl-10' : ''}`}>
                <div>
                  {estEnfant && <span aria-hidden="true" className="mr-1 text-texte-secondaire">└</span>}
                  <span
                    className={`text-corps font-medium ${estArchivee ? 'text-texte-secondaire' : 'text-texte'}`}
                  >
                    {categorie.nom}
                  </span>
                  {estArchivee && <span className="ml-2 text-petit text-texte-secondaire">Archivée</span>}
                  <div className="tabular-nums text-petit text-texte-secondaire">
                    {categorie.produits_count ?? 0} produit
                    {(categorie.produits_count ?? 0) === 1 ? '' : 's'}
                  </div>
                  {erreurDeplacement?.id === categorie.id && <p className="mt-1 text-petit text-danger">{erreurDeplacement.message}</p>}
                </div>


                {peutGererCatalogue &&
                  (estArchivee ? (
                    <button
                      type="button"
                      title="Republier"
                      aria-label={`Republier ${categorie.nom}`}
                      onClick={() => reactiver(categorie)}
                      disabled={reactivation.isPending}
                      className="flex h-11 w-11 cursor-pointer items-center justify-center rounded text-texte-secondaire transition-[background-color,color,transform] hover:bg-surface-alt hover:text-primaire active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100"
                    >
                      <ArchiveRestore aria-hidden="true" size={20} strokeWidth={1.5} />
                    </button>
                  ) : (
                    <button
                      type="button"
                      title="Archiver"
                      aria-label={`Archiver ${categorie.nom}`}
                      onClick={() => demanderArchivage(categorie)}
                      disabled={archivage.isPending}
                      className="flex h-11 w-11 cursor-pointer items-center justify-center rounded text-texte-secondaire transition-[background-color,color,transform] hover:bg-surface-alt hover:text-danger active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100"
                    >
                      <Archive aria-hidden="true" size={20} strokeWidth={1.5} />
                    </button>
                  ))}
                {peutGererCatalogue && !estArchivee && !aDesEnfants && principales.some((principale) => principale.id !== categorie.id) && (
                  <div className="w-full">
                  <select
                    aria-label={`Ranger ${categorie.nom} dans`}
                    value={categorie.parent_id ?? ''}
                    disabled={deplacement.isPending}
                    onChange={(evenement) =>
                      deplacement.mutate({ id: categorie.id, parentId: evenement.target.value === '' ? null : Number(evenement.target.value) })
                    }
                    className="h-11 w-full rounded border border-bordure bg-surface px-2 text-petit text-texte"
                  >
                    <option value="">Catégorie principale</option>
                    {principales
                      .filter((principale) => principale.id !== categorie.id)
                      .map((principale) => (
                        <option key={principale.id} value={principale.id}>
                          Dans « {principale.nom} »
                        </option>
                      ))}
                  </select>
                  </div>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
