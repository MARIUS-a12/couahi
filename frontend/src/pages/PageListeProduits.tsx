/**
 * Écran principal du back-office — route protégée "/admin/produits"
 * ("/admin" bare redirige ici, voir App.tsx). Bande de
 * statistiques (publiés/en rupture/brouillons), barre de recherche et de
 * filtres, puis la liste des produits elle-même : cartes empilées sous
 * 768px, tableau triable au-dessus. Chaque ligne porte ses propres actions
 * (modifier, archiver/republier) ; la création passe par le bouton
 * "Ajouter un produit".
 */
import { Archive, ArchiveRestore, ArrowDown, ArrowUp, Package, Pencil, Plus, ShoppingBag } from 'lucide-react'
import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { archiverProduit, republierProduit } from '../api/produits'
import { BadgeStatut } from '../components/BadgeStatut'
import { CLASSE_BORDURE_STATUT } from '../lib/statutProduit'
import { BandeauSucces } from '../components/BandeauSucces'
import { BandeStatistiques } from '../components/BandeStatistiques'
import { BarreFiltres } from '../components/BarreFiltres'
import { Bouton } from '../components/Bouton'
import { CarreInitiale } from '../components/CarreInitiale'
import { EnteteDePage } from '../components/EnteteDePage'
import { EtatChargement } from '../components/EtatChargement'
import { EtatErreur } from '../components/EtatErreur'
import { EtatVide } from '../components/EtatVide'
import { PastilleStock } from '../components/PastilleStock'
import { useCategories } from '../hooks/useCategories'
import { useMoi } from '../hooks/useMoi'
import { useProduits } from '../hooks/useProduits'
import { useValeurDifferee } from '../hooks/useValeurDifferee'
import { confirmerArchivageProduit } from '../lib/confirmations'
import { formaterMontant } from '../lib/formatage'
import type { ColonneTri, Produit, StatutProduit } from '../api/produits'

export function PageListeProduits() {
  const [recherche, setRecherche] = useState('')
  const [statut, setStatut] = useState<StatutProduit | ''>('')
  const [tri, setTri] = useState<ColonneTri>('date')
  const [direction, setDirection] = useState<'asc' | 'desc'>('desc')
  const [page, setPage] = useState(1)

  const rechercheDifferee = useValeurDifferee(recherche)
  const { data: categories } = useCategories()
  const { data: moi } = useMoi()
  const peutGererCatalogue = moi?.permissions.includes('gerer_catalogue') ?? false
  // Lot C : « Vendre » ouvre la saisie manuelle avec ce produit déjà choisi.
  const peutVendre = moi?.permissions.includes('creer_commandes') ?? false
  const vendre = (produitId: number) => navigate(`/admin/commandes/nouvelle?produit=${produitId}`)
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()

  // Capturé une seule fois, à l'arrivée sur cette page (création,
  // modification ou archivage réussis redirigent ici avec ce message dans
  // l'état de navigation) — voir onFermer plus bas pour le nettoyage.
  const [messageSucces, setMessageSucces] = useState<string | null>(
    () => (location.state as { messageSucces?: string } | null)?.messageSucces ?? null,
  )

  function fermerBandeauSucces() {
    setMessageSucces(null)
    // Retire messageSucces de l'historique : sans ça, un rechargement de
    // page réafficherait le même bandeau.
    navigate(location.pathname, { replace: true, state: null })
  }

  const { data, isPending, isError, error, refetch, isFetching } = useProduits({
    recherche: rechercheDifferee || undefined,
    statut: statut || undefined,
    tri,
    direction,
    page,
  })

  function invaliderApresAction() {
    queryClient.invalidateQueries({ queryKey: ['produits'] })
    queryClient.invalidateQueries({ queryKey: ['produits-stats'] })
    queryClient.invalidateQueries({ queryKey: ['categories'] })
  }

  const archivage = useMutation({ mutationFn: (id: number) => archiverProduit(id) })
  const republication = useMutation({ mutationFn: (id: number) => republierProduit(id) })

  function demanderArchivage(produit: Produit) {
    if (!confirmerArchivageProduit()) {
      return
    }

    archivage.mutate(produit.id, {
      onSuccess: () => {
        invaliderApresAction()
        setMessageSucces(`« ${produit.nom} » a été archivé.`)
      },
    })
  }

  function republier(produit: Produit) {
    republication.mutate(produit.id, {
      onSuccess: () => {
        invaliderApresAction()
        setMessageSucces(`« ${produit.nom} » a été republié.`)
      },
    })
  }

  function nomCategorie(produit: Produit): string | null {
    return categories?.find((c) => c.id === produit.categorie_id)?.nom ?? null
  }

  function trierPar(colonne: ColonneTri) {
    if (tri === colonne) {
      setDirection((actuelle) => (actuelle === 'asc' ? 'desc' : 'asc'))
    } else {
      setTri(colonne)
      setDirection('asc')
    }
    setPage(1)
  }

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      {messageSucces && <BandeauSucces message={messageSucces} onFermer={fermerBandeauSucces} />}

      <EnteteDePage
        icone={<Package aria-hidden="true" size={22} strokeWidth={1.75} />}
        couleur="vert"
        titre="Produits"
        sousTitre="Le catalogue visible par vos clients sur la boutique."
        action={
          peutGererCatalogue ? (
            <Bouton
              variante="principal"
              icone={<Plus aria-hidden="true" size={20} strokeWidth={1.5} />}
              onClick={() => navigate('/admin/produits/nouveau')}
            >
              Ajouter un produit
            </Bouton>
          ) : undefined
        }
      />

      <BandeStatistiques />

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <BarreFiltres
          recherche={recherche}
          onRechercheChange={(valeur) => {
            setRecherche(valeur)
            setPage(1)
          }}
          statut={statut}
          onStatutChange={(valeur) => {
            setStatut(valeur)
            setPage(1)
          }}
        />
        <Link to="/admin/categories" className="inline-flex h-11 items-center text-petit text-primaire underline">
          Gérer les catégories
        </Link>
      </div>

      {isPending && <EtatChargement />}

      {isError && <EtatErreur erreur={error} onReessayer={() => refetch()} />}

      {!isPending && !isError && data && data.data.length === 0 && (
        <EtatVide peutCreer={peutGererCatalogue} />
      )}

      {!isPending && !isError && data && data.data.length > 0 && (
        <>
          {/* Mobile : une carte par produit, actions en bas. */}
          <ul className="space-y-3 md:hidden">
            {data.data.map((produit) => {
              const categorie = nomCategorie(produit)
              const estArchive = produit.statut === 'archive'

              return (
                <li
                  key={produit.id}
                  className={`rounded-lg border border-bordure border-l-[3px] bg-surface p-4 ${CLASSE_BORDURE_STATUT[produit.statut]}`}
                >
                  <div className="flex items-start gap-3">
                    <CarreInitiale nom={produit.nom} taille={48} photo={produit.medias[0]?.vignette} />
                    <div className="min-w-0 flex-1">
                      <span className="block truncate text-corps font-semibold text-texte">
                        {produit.nom}
                      </span>
                      <span className="tabular-nums text-corps font-medium text-texte">
                        {formaterMontant(produit.prix)}
                      </span>
                      <div className="mt-2 flex items-center justify-between gap-3">
                        <PastilleStock produit={produit} />
                        <span className="flex items-center gap-2.5 text-petit text-texte-secondaire">
                          <BadgeStatut statut={produit.statut} />
                          {categorie && <span>{categorie}</span>}
                        </span>
                      </div>
                    </div>
                  </div>

                  {peutVendre && !estArchive && (
                    <div className="mt-3 border-t border-bordure pt-3">
                      <Bouton
                        variante="principal"
                        className="w-full"
                        icone={<ShoppingBag aria-hidden="true" size={20} strokeWidth={1.5} />}
                        onClick={() => vendre(produit.id)}
                      >
                        Vendre
                      </Bouton>
                    </div>
                  )}

                  {peutGererCatalogue && (
                    <div className="mt-3 flex gap-2 border-t border-bordure pt-3">
                      <Bouton
                        variante="secondaire"
                        className="flex-1"
                        icone={<Pencil aria-hidden="true" size={20} strokeWidth={1.5} />}
                        onClick={() => navigate(`/admin/produits/${produit.id}/modifier`)}
                      >
                        Modifier
                      </Bouton>
                      {estArchive ? (
                        <Bouton
                          variante="secondaire"
                          className="flex-1 border-primaire text-primaire hover:bg-primaire/10 active:bg-primaire/10"
                          icone={<ArchiveRestore aria-hidden="true" size={20} strokeWidth={1.5} />}
                          onClick={() => republier(produit)}
                          chargement={republication.isPending}
                        >
                          Republier
                        </Bouton>
                      ) : (
                        <Bouton
                          variante="danger"
                          className="flex-1"
                          icone={<Archive aria-hidden="true" size={20} strokeWidth={1.5} />}
                          onClick={() => demanderArchivage(produit)}
                          chargement={archivage.isPending}
                        >
                          Archiver
                        </Bouton>
                      )}
                    </div>
                  )}
                </li>
              )
            })}
          </ul>

          {/* Écran large : tableau, en-têtes cliquables pour le tri. */}
          <div className="hidden overflow-hidden rounded-lg border border-bordure bg-surface md:block">
          <table className="w-full border-separate border-spacing-0">
            <thead>
              <tr className="bg-surface-alt text-left text-petit text-texte-secondaire">
                <EnteteTriable
                  libelle="Nom"
                  actif={tri === 'nom'}
                  direction={direction}
                  onClick={() => trierPar('nom')}
                />
                <th className="border-b border-bordure px-4 py-2 font-medium">Prix</th>
                <th className="border-b border-bordure px-4 py-2 font-medium">Stock</th>
                <th className="border-b border-bordure px-4 py-2 font-medium">Statut</th>
                <EnteteTriable
                  libelle="Ajouté le"
                  actif={tri === 'date'}
                  direction={direction}
                  onClick={() => trierPar('date')}
                />
                {(peutGererCatalogue || peutVendre) && (
                  <th className="border-b border-bordure px-4 py-2 font-medium">Actions</th>
                )}
              </tr>
            </thead>
            <tbody>
              {data.data.map((produit) => {
                const categorie = nomCategorie(produit)
                const estArchive = produit.statut === 'archive'

                return (
                  <tr key={produit.id} className="transition-colors hover:bg-surface-alt">
                    <td
                      className={`border-b border-bordure border-l-[3px] px-4 py-3 ${CLASSE_BORDURE_STATUT[produit.statut]}`}
                    >
                      <div className="flex items-center gap-3">
                        <CarreInitiale nom={produit.nom} taille={40} photo={produit.medias[0]?.vignette} />
                        <span className="text-corps font-semibold text-texte">{produit.nom}</span>
                      </div>
                    </td>
                    <td className="border-b border-bordure px-4 py-3 tabular-nums text-corps font-medium text-texte">
                      {formaterMontant(produit.prix)}
                    </td>
                    <td className="border-b border-bordure px-4 py-3">
                      <PastilleStock produit={produit} />
                    </td>
                    <td className="border-b border-bordure px-4 py-3">
                      <div className="flex items-center gap-2.5 text-petit text-texte-secondaire">
                        <BadgeStatut statut={produit.statut} />
                        {categorie && <span>{categorie}</span>}
                      </div>
                    </td>
                    <td className="border-b border-bordure px-4 py-3 tabular-nums text-petit text-texte-secondaire">
                      {new Date(produit.created_at).toLocaleDateString('fr-FR')}
                    </td>
                    {(peutGererCatalogue || peutVendre) && (
                      <td className="border-b border-bordure px-4 py-3">
                        <div className="flex items-center gap-1">
                          {peutVendre && !estArchive && (
                            <button type="button" onClick={() => vendre(produit.id)} aria-label={`Vendre ${produit.nom}`} className="flex h-11 cursor-pointer items-center gap-1.5 rounded px-3 text-corps font-medium text-primaire transition-[background-color,transform] hover:bg-primaire/10 active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1">
                              <ShoppingBag aria-hidden="true" size={18} strokeWidth={1.5} />
                              Vendre
                            </button>
                          )}
                          {peutGererCatalogue && (
                          <>
                          <Link
                            to={`/admin/produits/${produit.id}/modifier`}
                            title="Modifier"
                            aria-label={`Modifier ${produit.nom}`}
                            className="flex h-11 w-11 cursor-pointer items-center justify-center rounded text-texte-secondaire transition-[background-color,color,transform] hover:bg-surface-alt hover:text-texte active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1"
                          >
                            <Pencil aria-hidden="true" size={20} strokeWidth={1.5} />
                          </Link>
                          {estArchive ? (
                            <button
                              type="button"
                              title="Republier"
                              aria-label={`Republier ${produit.nom}`}
                              onClick={() => republier(produit)}
                              disabled={republication.isPending}
                              className="flex h-11 w-11 cursor-pointer items-center justify-center rounded text-texte-secondaire transition-[background-color,color,transform] hover:bg-surface-alt hover:text-primaire active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100"
                            >
                              <ArchiveRestore aria-hidden="true" size={20} strokeWidth={1.5} />
                            </button>
                          ) : (
                            <button
                              type="button"
                              title="Archiver"
                              aria-label={`Archiver ${produit.nom}`}
                              onClick={() => demanderArchivage(produit)}
                              disabled={archivage.isPending}
                              className="flex h-11 w-11 cursor-pointer items-center justify-center rounded text-texte-secondaire transition-[background-color,color,transform] hover:bg-surface-alt hover:text-danger active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100"
                            >
                              <Archive aria-hidden="true" size={20} strokeWidth={1.5} />
                            </button>
                          )}
                          </>
                          )}
                        </div>
                      </td>
                    )}
                  </tr>
                )
              })}
            </tbody>
          </table>
          </div>

          <div className="flex flex-col items-center justify-between gap-3 pt-1 text-petit text-texte-secondaire sm:flex-row">
            <span className="tabular-nums">
              Affichage de {(data.meta.current_page - 1) * data.meta.per_page + 1} à{' '}
              {Math.min(data.meta.current_page * data.meta.per_page, data.meta.total)} sur {data.meta.total} produits
            </span>
            <div className="flex items-center gap-2">
              <Bouton variante="secondaire" taille="petite" disabled={page <= 1 || isFetching} onClick={() => setPage((p) => p - 1)}>
                Précédent
              </Bouton>
              <span className="flex h-11 min-w-11 items-center justify-center rounded-md bg-marine px-3 text-corps font-medium tabular-nums text-white">
                {data.meta.current_page}
              </span>
              <Bouton
                variante="secondaire"
                taille="petite"
                disabled={page >= data.meta.last_page || isFetching}
                onClick={() => setPage((p) => p + 1)}
              >
                Suivant
              </Bouton>
            </div>
          </div>
        </>
      )}
    </div>
  )
}

function EnteteTriable({
  libelle,
  actif,
  direction,
  onClick,
}: {
  libelle: string
  actif: boolean
  direction: 'asc' | 'desc'
  onClick: () => void
}) {
  const Icone = direction === 'asc' ? ArrowUp : ArrowDown

  return (
    <th className="border-b border-bordure px-4 py-2 font-medium">
      <button
        type="button"
        onClick={onClick}
        className="flex cursor-pointer items-center gap-1 text-texte-secondaire transition-transform active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1"
      >
        {libelle}
        {actif && <Icone aria-hidden="true" size={16} strokeWidth={1.5} />}
      </button>
    </th>
  )
}
