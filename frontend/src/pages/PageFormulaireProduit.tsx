/**
 * Écran de création (/admin/produits/nouveau) ET de modification
 * (/admin/produits/:id/modifier) d'un produit — un seul composant pour les deux,
 * le paramètre d'URL décide du mode. Le bloc stock change de forme selon le
 * mode de stock (voir BlocStock) : hérité du type de l'établissement à la
 * création, modifiable ensuite.
 *
 * Séparé en deux composants : PageFormulaireProduit attend que les données
 * nécessaires soient prêtes (produit existant en modification, catégories
 * pour résoudre le nom de celle déjà associée, type de l'établissement en
 * création), puis monte FormulaireProduit avec ces valeurs déjà connues. Ça
 * évite d'avoir à "rattraper" un état local vide après coup dans un effet
 * quand la requête répond — le formulaire n'existe simplement pas encore
 * tant que ses valeurs de départ ne sont pas là.
 */
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import type { Categorie } from '../api/categories'
import type { ModeStock, Produit, ProduitPayload } from '../api/produits'
import { archiverProduit, creerProduit, modifierProduit } from '../api/produits'
import { BlocStock } from '../components/BlocStock'
import { BoutonRetour } from '../components/BoutonRetour'
import { ChampPrix } from '../components/ChampPrix'
import { ChampTexte } from '../components/ChampTexte'
import { EtatBouton } from '../components/EtatBouton'
import type { CategorieChoisie } from '../components/ComboboxCategorie'
import { ComboboxCategorie } from '../components/ComboboxCategorie'
import { EtatChargement } from '../components/EtatChargement'
import { EtatErreur } from '../components/EtatErreur'
import { GroupeSegmente } from '../components/GroupeSegmente'
import { ZoneEnvoiPhotos } from '../components/ZoneEnvoiPhotos'
import { useCategories } from '../hooks/useCategories'
import { useMoi } from '../hooks/useMoi'
import { useProduit } from '../hooks/useProduit'
import { useProtectionPerteTravail } from '../hooks/useProtectionPerteTravail'
import { confirmerArchivageProduit } from '../lib/confirmations'
import { allerAuPremierChampEnErreur, extraireErreursChamps } from '../lib/erreursValidation'

const ORDRE_CHAMPS = [
  'nom',
  'categorie_id',
  'prix',
  'prix_barre',
  'mode_stock',
  'quantite_stock',
  'disponible',
  'statut',
]

const OPTIONS_STATUT: { valeur: 'brouillon' | 'publie'; libelle: string }[] = [
  { valeur: 'brouillon', libelle: 'Brouillon' },
  { valeur: 'publie', libelle: 'Publié' },
]

export function PageFormulaireProduit() {
  const { id } = useParams()
  const produitId = id ? Number(id) : null
  const estModification = produitId !== null

  const { data: moi } = useMoi()
  const produitExistant = useProduit(produitId)
  const categoriesQuery = useCategories()

  const enAttente = (estModification && produitExistant.isPending) || categoriesQuery.isPending
  const enErreur = (estModification && produitExistant.isError) || categoriesQuery.isError

  if (enAttente) {
    return (
      <div className="mx-auto max-w-2xl">
        <BoutonRetour vers="/admin/produits" />
        <EtatChargement />
      </div>
    )
  }

  if (enErreur) {
    return (
      <div className="mx-auto max-w-2xl">
        <BoutonRetour vers="/admin/produits" />
        <EtatErreur
          erreur={produitExistant.error ?? categoriesQuery.error}
          onReessayer={() => {
            produitExistant.refetch()
            categoriesQuery.refetch()
          }}
        />
      </div>
    )
  }

  const categories = categoriesQuery.data ?? []
  const produit = estModification ? (produitExistant.data as Produit) : null

  const modeStock: ModeStock = produit
    ? produit.mode_stock
    : moi?.etablissement?.type === 'restaurant'
      ? 'interrupteur'
      : 'compte'

  const categorieExistante = produit
    ? categories.find((categorie) => categorie.id === produit.categorie_id) ?? null
    : null

  return (
    <FormulaireProduit
      key={produitId ?? 'nouveau'}
      produitId={produitId}
      produitExistant={produit}
      modeStockInitial={modeStock}
      categories={categories}
      categorieInitiale={categorieExistante}
    />
  )
}

function FormulaireProduit({
  produitId,
  produitExistant,
  modeStockInitial,
  categories,
  categorieInitiale,
}: {
  produitId: number | null
  produitExistant: Produit | null
  modeStockInitial: ModeStock
  categories: Categorie[]
  categorieInitiale: Categorie | null
}) {
  const estModification = produitId !== null
  const produitEstArchive = produitExistant?.statut === 'archive'

  const { data: moi } = useMoi()
  const peutGererCatalogue = moi?.permissions.includes('gerer_catalogue') ?? false

  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [estModifie, setEstModifie] = useState(false)
  const [nom, setNom] = useState(produitExistant?.nom ?? '')
  const [categorieChoisie, setCategorieChoisie] = useState<CategorieChoisie | null>(
    categorieInitiale ? { id: categorieInitiale.id, nom: categorieInitiale.nom } : null,
  )
  const [prix, setPrix] = useState<number | null>(produitExistant?.prix ?? null)
  const [prixBarre, setPrixBarre] = useState<number | null>(produitExistant?.prix_barre ?? null)
  const [description, setDescription] = useState(produitExistant?.description ?? '')
  const [reference, setReference] = useState(produitExistant?.reference ?? '')
  const [modeStock, setModeStock] = useState<ModeStock>(modeStockInitial)
  const [quantiteStock, setQuantiteStock] = useState(produitExistant?.quantite_stock ?? 0)
  const [disponible, setDisponible] = useState(produitExistant?.disponible ?? true)
  const [statut, setStatut] = useState<'brouillon' | 'publie'>(
    produitExistant && produitExistant.statut !== 'archive' ? produitExistant.statut : 'brouillon',
  )
  const [erreurs, setErreurs] = useState<Record<string, string>>({})
  const [erreurGenerique, setErreurGenerique] = useState<string | null>(null)

  const { permettreProchaineNavigation } = useProtectionPerteTravail(estModifie)

  function marquerModifie() {
    setEstModifie(true)
  }

  function construirePayload(): ProduitPayload {
    return {
      nom,
      categorie_id: categorieChoisie?.id ?? null,
      prix: prix ?? 0,
      prix_barre: prixBarre,
      description: description || null,
      reference: reference || null,
      // Envoyé seulement s'il change : c'est le seul cas que l'API
      // vérifie (voir ChangerModeStock).
      ...(modeStock !== modeStockInitial ? { mode_stock: modeStock } : {}),
      ...(modeStock === 'interrupteur' ? { disponible } : { quantite_stock: quantiteStock }),
      ...(produitEstArchive ? {} : { statut }),
    }
  }

  function gererErreur(erreur: unknown) {
    const champs = extraireErreursChamps(erreur)
    setErreurs(champs)
    setErreurGenerique(
      Object.keys(champs).length === 0
        ? "Impossible d'enregistrer le produit. Vérifiez votre connexion et réessayez."
        : null,
    )
    allerAuPremierChampEnErreur(champs, ORDRE_CHAMPS)
  }

  function invaliderListes() {
    queryClient.invalidateQueries({ queryKey: ['produits'] })
    queryClient.invalidateQueries({ queryKey: ['produits-stats'] })
    queryClient.invalidateQueries({ queryKey: ['categories'] })
  }

  const enregistrement = useMutation({
    mutationFn: (payload: ProduitPayload) =>
      estModification ? modifierProduit(produitId as number, payload) : creerProduit(payload),
    onSuccess: (produit) => {
      setEstModifie(false)
      invaliderListes()
      permettreProchaineNavigation()
      navigate('/admin/produits', {
        state: {
          messageSucces: estModification
            ? `« ${produit.nom} » a été modifié.`
            : `« ${produit.nom} » a été créé.`,
        },
      })
    },
    onError: gererErreur,
  })

  const archivage = useMutation({
    mutationFn: () => archiverProduit(produitId as number),
    onSuccess: () => {
      setEstModifie(false)
      invaliderListes()
      permettreProchaineNavigation()
      navigate('/admin/produits', { state: { messageSucces: `« ${nom} » a été archivé.` } })
    },
  })

  function soumettre(evenement: FormEvent<HTMLFormElement>) {
    evenement.preventDefault()
    setErreurGenerique(null)
    enregistrement.mutate(construirePayload())
  }

  function demanderArchivage() {
    if (confirmerArchivageProduit()) {
      archivage.mutate()
    }
  }

  return (
    <div className="mx-auto max-w-2xl pb-4">
      <div className="mb-4 flex items-center gap-2">
        <BoutonRetour vers="/admin/produits" />
        <h1 className="text-titre-page font-semibold text-texte">
          {estModification ? 'Modifier le produit' : 'Nouveau produit'}
        </h1>
      </div>

      <form onSubmit={soumettre} className="space-y-4">
        {erreurGenerique && (
          <p role="alert" className="animate-entree-haut border border-danger bg-surface p-3 text-corps text-danger">
            {erreurGenerique}
          </p>
        )}

        <ChampTexte
          id="nom"
          label="Nom"
          requis
          valeur={nom}
          onChange={(valeur) => {
            setNom(valeur)
            marquerModifie()
          }}
          erreur={erreurs.nom}
        />

        <div>
          <label htmlFor="categorie_id" className="mb-1 block text-petit font-medium text-texte">
            Catégorie
          </label>
          <ComboboxCategorie
            id="categorie_id"
            categories={categories}
            valeur={categorieChoisie}
            onChange={(categorie) => {
              setCategorieChoisie(categorie)
              marquerModifie()
            }}
            erreur={erreurs.categorie_id}
          />
          <Link to="/admin/categories" className="mt-1 inline-flex h-11 items-center text-petit text-primaire underline">
            Gérer les catégories
          </Link>
        </div>

        <ChampPrix
          id="prix"
          label="Prix"
          requis
          valeur={prix}
          onChange={(valeur) => {
            setPrix(valeur)
            marquerModifie()
          }}
          erreur={erreurs.prix}
        />

        <ChampPrix
          id="prix_barre"
          label="Prix barré (optionnel)"
          valeur={prixBarre}
          onChange={(valeur) => {
            setPrixBarre(valeur)
            marquerModifie()
          }}
          erreur={erreurs.prix_barre}
        />

        <ChampTexte
          id="description"
          label="Description"
          multiligne
          valeur={description}
          onChange={(valeur) => {
            setDescription(valeur)
            marquerModifie()
          }}
          erreur={erreurs.description}
        />

        <ChampTexte
          id="reference"
          label="Référence / SKU"
          valeur={reference}
          onChange={(valeur) => {
            setReference(valeur)
            marquerModifie()
          }}
          erreur={erreurs.reference}
        />

        {estModification && peutGererCatalogue ? (
          <ZoneEnvoiPhotos produitId={produitId as number} medias={produitExistant?.medias ?? []} />
        ) : (
          !estModification && (
            <p className="text-petit text-texte-secondaire">
              Enregistrez d'abord le produit pour pouvoir y ajouter des photos.
            </p>
          )
        )}

        <BlocStock
          modeStock={modeStock}
          onModeStockChange={(valeur) => {
            setModeStock(valeur)
            marquerModifie()
          }}
          erreurModeStock={erreurs.mode_stock}
          estModification={estModification}
          quantiteStock={quantiteStock}
          onQuantiteStockChange={(valeur) => {
            setQuantiteStock(valeur)
            marquerModifie()
          }}
          erreurQuantiteStock={erreurs.quantite_stock}
          disponible={disponible}
          onDisponibleChange={(valeur) => {
            setDisponible(valeur)
            marquerModifie()
          }}
          erreurDisponible={erreurs.disponible}
        />

        {produitEstArchive ? (
          <p className="text-petit text-texte-secondaire">
            Ce produit est archivé. Pour le republier, utilisez le bouton « Republier » depuis la liste des
            produits.
          </p>
        ) : (
          <div>
            <span className="mb-1 block text-petit font-medium text-texte">Statut</span>
            <GroupeSegmente
              id="statut"
              options={OPTIONS_STATUT}
              valeur={statut}
              onChange={(valeur) => {
                setStatut(valeur)
                marquerModifie()
              }}
            />
          </div>
        )}

        {estModification && !produitEstArchive && (
          <button
            type="button"
            onClick={demanderArchivage}
            disabled={archivage.isPending}
            className="flex h-11 cursor-pointer items-center text-corps font-medium text-danger underline disabled:opacity-60"
          >
            <EtatBouton chargement={archivage.isPending}>Archiver ce produit</EtatBouton>
          </button>
        )}

        <div className="sticky bottom-0 -mx-4 flex gap-3 border-t border-bordure bg-surface px-4 py-3 md:static md:mx-0 md:border-t-0 md:bg-transparent md:px-0 md:py-0">
          <button
            type="submit"
            disabled={enregistrement.isPending}
            className="h-11 flex-1 cursor-pointer rounded bg-primaire text-corps font-medium text-surface transition-[background-color,transform] hover:bg-primaire-fonce active:scale-[0.97] active:bg-primaire-fonce focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100"
          >
            <EtatBouton chargement={enregistrement.isPending}>
              {estModification ? 'Enregistrer les modifications' : 'Créer le produit'}
            </EtatBouton>
          </button>
        </div>
      </form>
    </div>
  )
}
