/**
 * Lot F — les rôles d'un membre, cumulables : une case par rôle, et sous
 * les cases la phrase de ce qu'il pourra et ne pourra pas faire, recalculée
 * à chaque case touchée depuis les permissions renvoyées par l'API (voir
 * lib/droitsMembre.ts).
 *
 * Administrateur a déjà tous les droits : le cocher décoche et grise les
 * autres, qui n'ajouteraient rien. Le serveur fait de même avec une
 * requête forgée (ModifierMembreEquipe::normaliser).
 */
import type { RoleEquipe } from '../api/equipe'
import { droitsDuMembre, enumerer } from '../lib/droitsMembre'

const ADMINISTRATEUR = 'admin_etablissement'

export function SelecteurRoles({
  id,
  roles,
  coches,
  onChange,
  erreur,
  desactive = false,
}: {
  id: string
  roles: RoleEquipe[]
  coches: number[]
  onChange: (coches: number[]) => void
  erreur?: string
  desactive?: boolean
}) {
  const administrateur = roles.find((role) => role.nom === ADMINISTRATEUR)
  const administrateurCoche = administrateur !== undefined && coches.includes(administrateur.id)
  const { pourra, nePourraPas } = droitsDuMembre(roles, coches)

  function basculer(role: RoleEquipe, coche: boolean) {
    if (role.nom === ADMINISTRATEUR) {
      onChange(coche ? [role.id] : [])
      return
    }
    onChange(coche ? [...coches, role.id] : coches.filter((roleId) => roleId !== role.id))
  }

  return (
    <fieldset aria-describedby={`${id}-droits`}>
      <legend className="mb-1 block text-petit font-medium text-texte">
        Rôles <span aria-hidden="true" className="text-danger">*</span>
      </legend>
      <div className="flex flex-wrap gap-x-4">
        {roles.map((role) => {
          const grise = desactive || (administrateurCoche && role.nom !== ADMINISTRATEUR)

          return (
            <label
              key={role.id}
              className={`flex h-11 items-center gap-2 text-corps ${grise ? 'cursor-not-allowed text-texte-secondaire opacity-60' : 'cursor-pointer text-texte'}`}
            >
              <input
                type="checkbox"
                checked={coches.includes(role.id)}
                disabled={grise}
                onChange={(evenement) => basculer(role, evenement.target.checked)}
                className="h-5 w-5 cursor-pointer disabled:cursor-not-allowed"
              />
              {role.libelle}
            </label>
          )
        })}
      </div>

      <div id={`${id}-droits`} aria-live="polite" className="mt-2 space-y-1 rounded bg-surface-alt p-3 text-petit">
        {coches.length === 0 ? (
          <p className="text-texte-secondaire">Cochez au moins un rôle.</p>
        ) : (
          <>
            <p className="text-texte">
              <span className="font-medium">Pourra :</span> {enumerer(pourra)}.
            </p>
            {nePourraPas.length > 0 && (
              <p className="text-texte-secondaire">
                <span className="font-medium">Ne pourra pas :</span> {enumerer(nePourraPas)}.
              </p>
            )}
          </>
        )}
      </div>
      {erreur && <p className="animate-entree-champ mt-1 text-petit text-danger">{erreur}</p>}
    </fieldset>
  )
}
