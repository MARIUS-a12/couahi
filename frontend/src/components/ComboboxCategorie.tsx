/**
 * Champ Catégorie en saisie libre avec suggestions : tape un nom, les
 * catégories existantes qui correspondent s'affichent dessous, un clic les
 * sélectionne. Si aucune ne correspond, propose de créer la catégorie
 * directement (POST /api/categories) et l'associe au produit. Le champ
 * reste optionnel — ne rien saisir ou ne rien choisir revient à "aucune
 * catégorie".
 */
import { useEffect, useRef, useState } from 'react'
import type { KeyboardEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Categorie } from '../api/categories'
import { cheminCategorie, creerCategorie } from '../api/categories'
import { EtatBouton } from './EtatBouton'

export type CategorieChoisie = { id: number; nom: string }

export function ComboboxCategorie({
  id,
  categories,
  valeur,
  onChange,
  erreur,
}: {
  id: string
  categories: Categorie[]
  valeur: CategorieChoisie | null
  onChange: (categorie: CategorieChoisie | null) => void
  erreur?: string
}) {
  // valeur ne change jamais que par ce composant lui-même (choisir/creer/
  // remise à zéro au clic extérieur, plus bas) : un simple état initial
  // suffit, pas besoin de le resynchroniser dans un effet à chaque rendu.
  const [saisie, setSaisie] = useState(valeur?.nom ?? '')
  const [ouvert, setOuvert] = useState(false)
  const conteneurRef = useRef<HTMLDivElement>(null)
  const queryClient = useQueryClient()

  useEffect(() => {
    function surClicExterieur(evenement: MouseEvent) {
      if (conteneurRef.current && !conteneurRef.current.contains(evenement.target as Node)) {
        setOuvert(false)
        // Un texte tapé sans être résolu en sélection ni en création ne
        // doit pas laisser le champ afficher autre chose que la valeur
        // réellement retenue.
        setSaisie(valeur?.nom ?? '')
      }
    }

    document.addEventListener('mousedown', surClicExterieur)

    return () => document.removeEventListener('mousedown', surClicExterieur)
  }, [valeur])

  const creation = useMutation({
    mutationFn: (nom: string) => creerCategorie(nom),
    onSuccess: (categorie) => {
      queryClient.invalidateQueries({ queryKey: ['categories'] })
      onChange({ id: categorie.id, nom: categorie.nom })
      setSaisie(categorie.nom)
      setOuvert(false)
    },
  })

  const saisieNormalisee = saisie.trim().toLowerCase()
  const categoriesActives = categories.filter((categorie) => categorie.statut === 'actif')
  const suggestions = saisieNormalisee
    ? categoriesActives.filter((categorie) => categorie.nom.toLowerCase().includes(saisieNormalisee))
    : categoriesActives
  const correspondanceExacte = categoriesActives.some(
    (categorie) => categorie.nom.trim().toLowerCase() === saisieNormalisee,
  )
  const proposerCreation = saisie.trim() !== '' && suggestions.length === 0 && !correspondanceExacte

  function choisir(categorie: Categorie) {
    onChange({ id: categorie.id, nom: categorie.nom })
    setSaisie(categorie.nom)
    setOuvert(false)
  }

  function creer() {
    creation.mutate(saisie.trim())
  }

  function gererTouche(evenement: KeyboardEvent<HTMLInputElement>) {
    if (evenement.key !== 'Enter' || !ouvert) {
      return
    }

    evenement.preventDefault()

    if (suggestions.length === 1) {
      choisir(suggestions[0])
    } else if (proposerCreation) {
      creer()
    }
  }

  return (
    <div ref={conteneurRef} className="relative">
      <input
        id={id}
        type="text"
        role="combobox"
        aria-expanded={ouvert}
        aria-autocomplete="list"
        autoComplete="off"
        placeholder="Aucune catégorie"
        value={saisie}
        onFocus={() => setOuvert(true)}
        onKeyDown={gererTouche}
        onChange={(evenement) => {
          setSaisie(evenement.target.value)
          setOuvert(true)
          if (valeur) {
            onChange(null)
          }
        }}
        className={`h-11 w-full rounded border bg-surface px-3 text-corps text-texte transition-colors focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1 ${
          erreur ? 'border-danger' : 'border-bordure focus:border-primaire'
        }`}
      />

      {ouvert && (suggestions.length > 0 || proposerCreation) && (
        <ul className="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded border border-bordure bg-surface">
          {suggestions.map((categorie) => (
            <li key={categorie.id}>
              <button
                type="button"
                onClick={() => choisir(categorie)}
                className="block w-full cursor-pointer px-3 py-2 text-left text-corps text-texte transition-[background-color,transform] hover:bg-surface-alt active:scale-[0.97]"
              >
                {cheminCategorie(categorie, categories)}
              </button>
            </li>
          ))}

          {proposerCreation && (
            <li>
              <button
                type="button"
                disabled={creation.isPending}
                onClick={creer}
                className="block w-full cursor-pointer px-3 py-2 text-left text-corps text-primaire transition-[background-color,transform] hover:bg-surface-alt active:scale-[0.97] disabled:opacity-60 disabled:active:scale-100"
              >
                <EtatBouton chargement={creation.isPending}>
                  {`Créer la catégorie « ${saisie.trim()} »`}
                </EtatBouton>
              </button>
            </li>
          )}
        </ul>
      )}

      {erreur && <p className="animate-entree-champ mt-1 text-petit text-danger">{erreur}</p>}
    </div>
  )
}
