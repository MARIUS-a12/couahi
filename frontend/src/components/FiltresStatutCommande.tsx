/**
 * Filtres par statut de la liste des commandes (Étape 9), dans l'ordre du
 * cycle de vie. Six options (pas quatre comme GroupeSegmente ailleurs dans
 * le back-office) : une rangée défilante plutôt qu'une pilule à largeur
 * égale, pour rester lisible au doigt sous 375px. Le filtre actif en bleu
 * marine, texte blanc.
 */
import type { StatutCommande } from '../api/commandes'

const OPTIONS: { valeur: StatutCommande | ''; libelle: string }[] = [
  { valeur: '', libelle: 'Toutes' },
  { valeur: 'attente_paiement', libelle: 'En attente' },
  { valeur: 'payee', libelle: 'Confirmées' },
  { valeur: 'prete', libelle: 'Prêtes' },
  { valeur: 'livree', libelle: 'Livrées' },
  { valeur: 'annulee', libelle: 'Annulées' },
  { valeur: 'expiree', libelle: 'Expirées' },
]

export function FiltresStatutCommande({
  valeur,
  onChange,
}: {
  valeur: StatutCommande | ''
  onChange: (valeur: StatutCommande | '') => void
}) {
  return (
    <div className="flex gap-2 overflow-x-auto pb-1">
      {OPTIONS.map((option) => (
        <button
          key={option.valeur}
          type="button"
          onClick={() => onChange(option.valeur)}
          aria-pressed={valeur === option.valeur}
          className={`h-11 shrink-0 cursor-pointer rounded-md px-3 text-corps font-medium transition-[background-color,transform] active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 ${
            valeur === option.valeur
              ? 'bg-marine text-white'
              : 'border border-bordure bg-surface text-texte hover:bg-surface-alt'
          }`}
        >
          {option.libelle}
        </button>
      ))}
    </div>
  )
}
