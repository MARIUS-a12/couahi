/**
 * La grille des combinaisons : en colonnes, les valeurs de la dernière
 * option (le coloris des sandales) ; en lignes, celles des autres (les
 * pointures). Une case cochée est une variante. Rien n'est créé tant que
 * le commerçant n'a pas validé : il coche, décoche, puis enregistre tout
 * d'un coup, avec un stock de départ commun.
 *
 * Pas de produit cartésien : seules les cases cochées deviennent des
 * variantes. Toucher l'en-tête d'une ligne ou d'une colonne la coche ou
 * la décoche entière — 7 pointures en noir, c'est un geste, pas sept.
 *
 * Décocher une variante qui a déjà été vendue ne l'efface pas : elle
 * devient inactive (voir GererVariantes::retirerVariante), et la
 * confirmation le dit.
 */
import { Check } from 'lucide-react'
import { useEffect, useState } from 'react'
import { creerVariantes, modifierVariante, retirerVariante } from '../api/variantes'
import type { StructureVariantes, ValeurOption, Variante } from '../api/variantes'
import type { useVariantes } from '../hooks/useVariantes'
import { Bouton } from './Bouton'
import { sansEntree } from '../lib/clavier'

type Ecrire = ReturnType<typeof useVariantes>['ecrire']

const cle = (identifiants: number[]) => [...identifiants].sort((a, b) => a - b).join('-')

function produitCartesien(listes: ValeurOption[][]): ValeurOption[][] {
  return listes.reduce<ValeurOption[][]>((acc, liste) => acc.flatMap((prefixe) => liste.map((valeur) => [...prefixe, valeur])), [[]])
}

export function GrilleCombinaisons({
  produitId,
  structure,
  ecrire,
  enCours,
  onBrouillon,
}: {
  produitId: number
  structure: StructureVariantes
  ecrire: Ecrire
  enCours: boolean
  /** Cases changées mais pas encore appliquées : quitter la page les perdrait. */
  onBrouillon?: (nombre: number) => void
}) {
  const [brouillon, setBrouillon] = useState<Map<string, boolean>>(new Map())
  const [stockDepart, setStockDepart] = useState('0')
  const actives = structure.options.map((option) => option.valeurs.filter((valeur) => !valeur.archivee))

  const colonnes = structure.options.length > 1 ? actives[actives.length - 1] : []
  const lignes = produitCartesien(structure.options.length > 1 ? actives.slice(0, -1) : actives)
  const parCle = new Map<string, Variante>(structure.variantes.map((variante) => [cle(variante.valeur_ids), variante]))
  const coche = (identifiants: number[]) => brouillon.get(cle(identifiants)) ?? parCle.get(cle(identifiants))?.active === true
  const toutes = colonnes.length > 0 ? lignes.flatMap((ligne) => colonnes.map((colonne) => [...ligne, colonne])) : lignes

  function basculer(combinaisons: ValeurOption[][], valeur?: boolean) {
    setBrouillon((precedent) => {
      const suivant = new Map(precedent)
      const cible = valeur ?? !combinaisons.every((combinaison) => coche(combinaison.map((v) => v.id)))
      combinaisons.forEach((combinaison) => {
        const identifiants = combinaison.map((v) => v.id)
        const existant = parCle.get(cle(identifiants))?.active === true

        if (cible === existant) {
          suivant.delete(cle(identifiants))
        } else {
          suivant.set(cle(identifiants), cible)
        }
      })

      return suivant
    })
  }

  const aCreer = toutes.filter((c) => coche(c.map((v) => v.id)) && !parCle.has(cle(c.map((v) => v.id))))
  const aReactiver = toutes.map((c) => parCle.get(cle(c.map((v) => v.id)))).filter((v): v is Variante => v !== undefined && !v.active && coche(v.valeur_ids))
  const aRetirer = toutes.map((c) => parCle.get(cle(c.map((v) => v.id)))).filter((v): v is Variante => v !== undefined && v.active && !coche(v.valeur_ids))
  const nombreCochees = toutes.filter((c) => coche(c.map((v) => v.id))).length
  const changements = aCreer.length + aReactiver.length + aRetirer.length

  useEffect(() => {
    onBrouillon?.(changements)
  }, [changements, onBrouillon])

  if (actives.some((valeurs) => valeurs.length === 0)) {
    return (
      <p className="rounded border border-dashed border-bordure p-3 text-petit text-texte-secondaire">
        Ajoutez au moins une valeur à chaque option pour composer les variantes.
      </p>
    )
  }

  async function enregistrer() {
    if (aRetirer.length > 0) {
      const vendues = aRetirer.filter((variante) => variante.nombre_commandes > 0)
      const message =
        `Retirer ${aRetirer.length} variante(s) : ${aRetirer.map((variante) => variante.nom).join(', ')} ?` +
        (vendues.length > 0 ? `\n\n${vendues.map((variante) => variante.nom).join(', ')} figure(nt) dans des commandes : elle(s) sera(ont) archivée(s), pas effacée(s).` : '')

      if (!window.confirm(message)) {
        return
      }
    }

    if (aCreer.length > 0) {
      const stock = Math.max(0, Number.parseInt(stockDepart, 10) || 0)
      const ok = await ecrire(() =>
        creerVariantes(
          produitId,
          aCreer.map((combinaison) => ({ valeur_ids: combinaison.map((valeur) => valeur.id), quantite_stock: stock })),
        ),
      )
      if (!ok) {
        return
      }
    }
    for (const variante of aRetirer) {
      if (!(await ecrire(() => retirerVariante(produitId, variante.id)))) {
        return
      }
    }
    for (const variante of aReactiver) {
      if (!(await ecrire(() => modifierVariante(produitId, variante.id, { statut: 'actif' })))) {
        return
      }
    }
    setBrouillon(new Map())
  }

  const caseClasses = (active: boolean) =>
    `flex h-11 w-11 cursor-pointer items-center justify-center rounded border-2 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire ${
      active ? 'border-primaire bg-primaire text-surface' : 'border-bordure-forte bg-surface text-transparent hover:border-primaire'
    }`

  return (
    <div className="space-y-3">
      <div>
        <h3 className="text-corps font-semibold text-texte">Combinaisons</h3>
        <p className="text-petit text-texte-secondaire">
          {nombreCochees} cochée{nombreCochees > 1 ? 's' : ''} sur {toutes.length} possible{toutes.length > 1 ? 's' : ''}. Touchez un en-tête pour cocher toute la ligne ou la colonne.
        </p>
      </div>

      <div className="max-w-full overflow-x-auto">
        <table className="border-separate border-spacing-1">
          {colonnes.length > 0 && (
            <thead>
              <tr>
                <th scope="col" className="sticky left-0 bg-surface" />
                {colonnes.map((colonne) => (
                  <th key={colonne.id} scope="col" className="p-0">
                    <button
                      type="button"
                      onClick={() => basculer(lignes.map((ligne) => [...ligne, colonne]))}
                      aria-label={`Cocher ou décocher toute la colonne ${colonne.libelle}`}
                      className="h-11 min-w-11 cursor-pointer rounded px-2 text-petit font-semibold text-texte hover:bg-surface-alt"
                    >
                      {colonne.libelle}
                    </button>
                  </th>
                ))}
              </tr>
            </thead>
          )}
          <tbody>
            {lignes.map((ligne) => {
              const nomLigne = ligne.map((valeur) => valeur.libelle).join(' / ')
              const cellules = colonnes.length > 0 ? colonnes.map((colonne) => [...ligne, colonne]) : [ligne]

              return (
                <tr key={cle(ligne.map((valeur) => valeur.id))}>
                  <th scope="row" className="sticky left-0 bg-surface p-0 text-left">
                    <button
                      type="button"
                      onClick={() => basculer(cellules)}
                      aria-label={colonnes.length > 0 ? `Cocher ou décocher toute la ligne ${nomLigne}` : undefined}
                      className="h-11 min-w-14 cursor-pointer rounded px-2 text-left text-corps font-medium text-texte hover:bg-surface-alt"
                    >
                      {nomLigne}
                    </button>
                  </th>
                  {cellules.map((combinaison) => {
                    const identifiants = combinaison.map((valeur) => valeur.id)
                    const active = coche(identifiants)
                    const existante = parCle.get(cle(identifiants))

                    return (
                      <td key={cle(identifiants)} className="p-0">
                        <button
                          type="button"
                          role="checkbox"
                          aria-checked={active}
                          aria-label={combinaison.map((valeur) => valeur.libelle).join(' / ') + (existante && !existante.active ? ' (archivée)' : '')}
                          onClick={() => basculer([combinaison])}
                          className={caseClasses(active)}
                        >
                          <Check aria-hidden="true" className="h-5 w-5" />
                        </button>
                      </td>
                    )
                  })}
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {changements > 0 && (
        <div className="sticky bottom-0 z-10 space-y-2 rounded border border-primaire bg-surface-haute p-3 shadow-lg">
          <p className="text-petit text-texte">
            {[
              aCreer.length > 0 && `${aCreer.length} à créer`,
              aReactiver.length > 0 && `${aReactiver.length} à réactiver`,
              aRetirer.length > 0 && `${aRetirer.length} à retirer`,
            ]
              .filter(Boolean)
              .join(', ')}
          </p>
          {aCreer.length > 0 && structure.mode_stock === 'compte' && (
            <label className="flex items-center gap-2 text-petit text-texte">
              Stock de départ de chacune
              <input
                type="number"
                inputMode="numeric"
                min={0}
                value={stockDepart}
                onChange={(evenement) => setStockDepart(evenement.target.value)}
                onKeyDown={(evenement) => sansEntree(evenement)}
                className="h-11 w-20 rounded border border-bordure bg-surface px-2 text-corps text-texte"
              />
            </label>
          )}
          <div className="flex gap-2">
            {/* Pas « Enregistrer » : la barre du produit porte déjà ce mot, et
                la toucher à la place de celui-ci perdrait les cases cochées. */}
            <Bouton type="button" onClick={enregistrer} chargement={enCours}>
              {aReactiver.length === 0 && aRetirer.length === 0
                ? `Créer ${aCreer.length === 1 ? 'la variante' : `les ${aCreer.length} variantes`}`
                : 'Appliquer ces changements'}
            </Bouton>
            <Bouton type="button" variante="tertiaire" onClick={() => setBrouillon(new Map())}>
              Annuler
            </Bouton>
          </div>
        </div>
      )}
    </div>
  )
}
