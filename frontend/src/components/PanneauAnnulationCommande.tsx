/**
 * Confirmation d'annulation d'une commande (Étape 9) : contrairement aux
 * autres transitions, l'annulation demande un motif (liste courte + champ
 * libre pour "autre") ET une confirmation. Même esprit que
 * PanneauSuppressionEtablissement (fond assombri + panneau, pas de
 * bibliothèque de modale).
 */
import { useState } from 'react'
import { formaterMontant } from '../lib/formatage'
import type { FormEvent } from 'react'
import type { MotifAnnulation } from '../api/commandes'
import { Bouton } from './Bouton'

const OPTIONS_MOTIF: { valeur: string; libelle: string }[] = [
  { valeur: 'article_indisponible', libelle: 'Article indisponible' },
  { valeur: 'client_injoignable', libelle: 'Client injoignable' },
  { valeur: 'client_annule', libelle: 'Le client a annulé' },
  { valeur: 'autre', libelle: 'Autre' },
]

export function PanneauAnnulationCommande({
  numero,
  montantPaye = null,
  enCours,
  erreur,
  onConfirmer,
  onFermer,
}: {
  numero: string
  // Étape 13 : argent déjà reçu pour cette commande — l'annuler oblige à le
  // rendre, le commerçant doit le lire AVANT de confirmer.
  montantPaye?: number | null
  enCours: boolean
  erreur: string | null
  onConfirmer: (motif: MotifAnnulation) => void
  onFermer: () => void
}) {
  const [enFermeture, setEnFermeture] = useState(false)
  const [motifType, setMotifType] = useState('')
  const [motifAutre, setMotifAutre] = useState('')

  function demanderFermeture() {
    if (enCours) {
      return
    }
    setEnFermeture(true)
    setTimeout(onFermer, 150)
  }

  function soumettre(evenement: FormEvent<HTMLFormElement>) {
    evenement.preventDefault()

    if (motifType === 'autre') {
      if (!motifAutre.trim()) {
        return
      }
      onConfirmer({ motif_type: 'autre', motif_autre: motifAutre.trim() })
      return
    }

    if (motifType === 'article_indisponible' || motifType === 'client_injoignable' || motifType === 'client_annule') {
      onConfirmer({ motif_type: motifType })
    }
  }

  const peutSoumettre = motifType !== '' && (motifType !== 'autre' || motifAutre.trim() !== '')

  return (
    <div
      role="presentation"
      onClick={demanderFermeture}
      className={`fixed inset-0 z-20 flex items-end justify-center bg-texte/40 p-4 transition-opacity duration-rapide ease-disparition sm:items-center ${
        enFermeture ? 'opacity-0' : 'animate-entree-fondu'
      }`}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label={`Annuler la commande ${numero}`}
        onClick={(evenement) => evenement.stopPropagation()}
        className={`w-full max-w-sm rounded-xl border border-bordure-forte bg-surface-haute p-4 shadow-flottant transition-[opacity,transform] duration-rapide ease-disparition sm:p-5 ${
          enFermeture ? 'translate-y-4 opacity-0' : 'animate-entree-panneau'
        }`}
      >
        <h2 className="text-titre-section font-semibold text-texte">Annuler la commande {numero}</h2>
        <p className="mt-2 text-corps text-texte-secondaire">Cette action est irréversible.</p>
        {montantPaye != null && (
          <p role="alert" className="mt-3 rounded-md border border-alerte bg-alerte/10 p-3 text-corps font-medium text-alerte">
            Le client a déjà payé {formaterMontant(montantPaye)} : pensez à le rembourser.
          </p>
        )}

        <form onSubmit={soumettre} className="mt-4 space-y-3">
          <fieldset className="space-y-2">
            <legend className="mb-1 text-petit font-medium text-texte">Motif de l'annulation</legend>
            {OPTIONS_MOTIF.map((option) => (
              <label
                key={option.valeur}
                className="flex h-11 cursor-pointer items-center gap-2.5 rounded-md border border-bordure px-3 text-corps text-texte transition-colors has-[:checked]:border-danger has-[:checked]:bg-danger/5"
              >
                <input
                  type="radio"
                  name="motif_type"
                  value={option.valeur}
                  checked={motifType === option.valeur}
                  onChange={(evenement) => setMotifType(evenement.target.value)}
                  className="h-4 w-4 accent-danger"
                />
                {option.libelle}
              </label>
            ))}
          </fieldset>

          {motifType === 'autre' && (
            <div>
              <label htmlFor="motif_autre" className="mb-1 block text-petit font-medium text-texte">
                Précisez
              </label>
              <input
                id="motif_autre"
                type="text"
                value={motifAutre}
                onChange={(evenement) => setMotifAutre(evenement.target.value)}
                className="h-11 w-full rounded-md border border-bordure bg-surface px-3 text-corps text-texte transition-colors focus:border-primaire focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1"
              />
            </div>
          )}

          {erreur && (
            <p role="alert" className="animate-entree-champ text-petit text-danger">
              {erreur}
            </p>
          )}

          <div className="flex gap-3 pt-1">
            <Bouton variante="tertiaire" type="button" onClick={demanderFermeture} disabled={enCours}>
              Retour
            </Bouton>
            <Bouton variante="danger" type="submit" disabled={!peutSoumettre} chargement={enCours} className="flex-1">
              Annuler la commande
            </Bouton>
          </div>
        </form>
      </div>
    </div>
  )
}
