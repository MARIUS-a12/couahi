/**
 * Étape 10 — /admin/equipe : le commerçant gère son personnel lui-même.
 * Réservée à gerer_equipe (en pratique le seul admin_etablissement, voir
 * RolesEtPermissionsSeeder) — BarreLaterale ne montre même pas l'entrée à
 * qui n'a pas la permission, voir ENTREES[].permissions.
 *
 * Les garde-fous serveur (pas son propre rôle, pas sa propre désactivation,
 * au moins un administrateur actif) sont appliqués CÔTÉ API — cette page se
 * contente de les REFLÉTER en désactivant les contrôles de sa propre ligne,
 * jamais de les PORTER elle-même : un contournement direct de l'API serait
 * de toute façon refusé par ModifierMembreEquipe/ChangerStatutMembreEquipe.
 *
 * Correctif activation par code : l'administrateur ne choisit ni ne voit
 * JAMAIS le mot de passe d'un membre. La création ne demande que nom/email/
 * rôles et affiche un CODE D'ACTIVATION à transmettre (voir
 * BandeauCodeActivationGenere) ; "mot de passe oublié" devient "Générer un
 * nouveau code d'accès", avec le même principe.
 *
 * Lot F : des rôles cumulables, cochés (voir SelecteurRoles). Sur la ligne
 * d'un membre, « Modifier » ouvre les mêmes cases et n'enregistre qu'au
 * bouton : une case touchée par erreur ne retire rien.
 */
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { KeyRound, Users } from 'lucide-react'
import type { MembreEquipe, RoleEquipe, StatutMembreEquipe } from '../api/equipe'
import {
  changerStatutMembreEquipe,
  creerMembreEquipe,
  genererCodeActivationMembre,
  modifierMembreEquipe,
} from '../api/equipe'
import { BandeauCodeActivationGenere } from '../components/BandeauCodeActivationGenere'
import { BandeauSucces } from '../components/BandeauSucces'
import { Bascule } from '../components/Bascule'
import { EnteteDePage } from '../components/EnteteDePage'
import { EtatBouton } from '../components/EtatBouton'
import { EtatChargement } from '../components/EtatChargement'
import { EtatErreur } from '../components/EtatErreur'
import { SelecteurRoles } from '../components/SelecteurRoles'
import { CLE_EQUIPE, useEquipe, useRolesEquipe } from '../hooks/useEquipe'
import { useMoi } from '../hooks/useMoi'
import type { DestinataireCode } from '../lib/activation'
import { extraireErreursChamps } from '../lib/erreursValidation'

function formaterDerniereConnexion(date: string | null): string {
  return date ? new Date(date).toLocaleDateString('fr-FR') : 'Jamais connecté'
}

const CLASSES_CHAMP =
  'h-11 w-full rounded border bg-surface px-3 text-corps text-texte transition-colors focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1'

export function PageEquipe() {
  const { data: moi } = useMoi()
  const { data: membres, isPending, isError, error, refetch } = useEquipe()
  const { data: roles } = useRolesEquipe()
  const queryClient = useQueryClient()

  const [nom, setNom] = useState('')
  const [email, setEmail] = useState('')
  const [roleIds, setRoleIds] = useState<number[]>([])
  const [erreurs, setErreurs] = useState<Record<string, string>>({})
  const [codeAffiche, setCodeAffiche] = useState<{ code: string; destinataire: DestinataireCode } | null>(null)
  const [messageSucces, setMessageSucces] = useState<string | null>(null)
  const [membreEnEdition, setMembreEnEdition] = useState<number | null>(null)

  function invaliderEquipe() {
    queryClient.invalidateQueries({ queryKey: CLE_EQUIPE })
  }

  const creation = useMutation({
    mutationFn: creerMembreEquipe,
    onSuccess: ({ membre, codeActivation }) => {
      setNom('')
      setEmail('')
      setRoleIds([])
      setErreurs({})
      setCodeAffiche({ code: codeActivation, destinataire: { nom: membre.nom, email: membre.email } })
      invaliderEquipe()
    },
    onError: (erreur) => setErreurs(extraireErreursChamps(erreur)),
  })

  const changementStatut = useMutation({
    mutationFn: ({ id, statut }: { id: number; statut: StatutMembreEquipe }) => changerStatutMembreEquipe(id, statut),
  })

  const generationCode = useMutation({
    mutationFn: (membre: MembreEquipe) => genererCodeActivationMembre(membre.id),
    onSuccess: (code, membre) => {
      setCodeAffiche({ code, destinataire: { nom: membre.nom, email: membre.email } })
      invaliderEquipe()
    },
  })

  function soumettreCreation(evenement: FormEvent<HTMLFormElement>) {
    evenement.preventDefault()
    if (!nom.trim() || !email.trim()) {
      return
    }
    if (roleIds.length === 0) {
      setErreurs({ ...erreurs, role_ids: 'Choisissez au moins un rôle.' })
      return
    }
    creation.mutate({ nom: nom.trim(), email: email.trim(), roleIds })
  }

  function demanderNouveauCode(membre: MembreEquipe) {
    const confirme = window.confirm(
      `Un nouveau code d'accès sera généré pour ${membre.nom}. Il devra l'utiliser sur /admin/activation pour choisir un nouveau mot de passe, et ses sessions en cours seront fermées.\n\nContinuer ?`,
    )

    if (confirme) {
      generationCode.mutate(membre)
    }
  }

  function demanderChangementStatut(membre: MembreEquipe, nouveauStatutActif: boolean) {
    const nouveauStatut: StatutMembreEquipe = nouveauStatutActif ? 'actif' : 'suspendu'

    if (nouveauStatut === 'suspendu') {
      const confirme = window.confirm(
        `${membre.nom} ne pourra plus se connecter et ses sessions en cours seront fermées. Réversible.\n\nDésactiver ${membre.nom} ?`,
      )
      if (!confirme) {
        return
      }
    }

    changementStatut.mutate(
      { id: membre.id, statut: nouveauStatut },
      {
        onSuccess: () => {
          invaliderEquipe()
          setMessageSucces(`${membre.nom} a été ${nouveauStatut === 'actif' ? 'réactivé(e)' : 'désactivé(e)'}.`)
        },
      },
    )
  }

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <EnteteDePage
        icone={<Users aria-hidden="true" size={22} strokeWidth={1.75} />}
        couleur="bleu"
        titre="Équipe"
        sousTitre="Gérez les comptes de votre personnel et leurs accès."
      />

      {messageSucces && <BandeauSucces message={messageSucces} onFermer={() => setMessageSucces(null)} />}

      {codeAffiche && (
        <BandeauCodeActivationGenere
          code={codeAffiche.code}
          destinataire={codeAffiche.destinataire}
          boutique={moi?.etablissement?.nom ?? ''}
          onFermer={() => setCodeAffiche(null)}
        />
      )}

      <form onSubmit={soumettreCreation} className="grid gap-3 border border-bordure bg-surface p-4 sm:grid-cols-2 sm:items-start">
        <div>
          <label htmlFor="nom" className="mb-1 block text-petit font-medium text-texte">
            Nom
          </label>
          <input
            id="nom"
            type="text"
            value={nom}
            onChange={(evenement) => setNom(evenement.target.value)}
            className={`${CLASSES_CHAMP} ${erreurs.nom ? 'border-danger' : 'border-bordure focus:border-primaire'}`}
          />
          {erreurs.nom && <p className="animate-entree-champ mt-1 text-petit text-danger">{erreurs.nom}</p>}
        </div>

        <div>
          <label htmlFor="email" className="mb-1 block text-petit font-medium text-texte">
            Email
          </label>
          <input
            id="email"
            type="email"
            value={email}
            onChange={(evenement) => setEmail(evenement.target.value)}
            className={`${CLASSES_CHAMP} ${erreurs.email ? 'border-danger' : 'border-bordure focus:border-primaire'}`}
          />
          {erreurs.email && <p className="animate-entree-champ mt-1 text-petit text-danger">{erreurs.email}</p>}
        </div>

        <div className="sm:col-span-2">
          <SelecteurRoles
            id="nouveau-membre"
            roles={roles ?? []}
            coches={roleIds}
            onChange={(coches) => {
              setRoleIds(coches)
              setErreurs((precedentes) => {
                const autres = { ...precedentes }
                delete autres.role_ids
                return autres
              })
            }}
            erreur={erreurs.role_ids}
          />
        </div>

        <button
          type="submit"
          disabled={creation.isPending}
          className="h-11 shrink-0 cursor-pointer sm:col-span-2 sm:justify-self-start rounded bg-primaire px-4 text-corps font-medium text-surface transition-[background-color,transform] hover:bg-primaire-fonce active:scale-[0.97] active:bg-primaire-fonce focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100"
        >
          <EtatBouton chargement={creation.isPending}>Ajouter</EtatBouton>
        </button>
      </form>

      {isPending && <EtatChargement />}
      {isError && <EtatErreur erreur={error} onReessayer={() => refetch()} />}

      {!isPending && !isError && membres && membres.length === 0 && (
        <p className="text-corps text-texte-secondaire">Aucun membre pour le moment.</p>
      )}

      {!isPending && !isError && membres && membres.length > 0 && (
        <ul className="divide-y divide-bordure border border-bordure">
          {membres.map((membre) => {
            const estSoiMeme = membre.utilisateur_id === moi?.utilisateur.id

            return (
              <li key={membre.id} className="flex flex-col gap-3 p-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                <div className="min-w-0">
                  <p className="text-corps font-medium text-texte">
                    {membre.nom} {estSoiMeme && <span className="text-petit text-texte-secondaire">(vous)</span>}
                    {!membre.mot_de_passe_defini && (
                      <span className="ml-2 rounded bg-alerte/10 px-1.5 py-0.5 text-[11px] font-medium text-alerte">
                        En attente d'activation
                      </span>
                    )}
                  </p>
                  <p className="truncate text-petit text-texte-secondaire">{membre.email}</p>
                  <p className="text-petit font-medium text-texte">{membre.roles.map((role) => role.libelle).join(' + ')}</p>
                  <p className="text-petit text-texte-secondaire">
                    Ajouté le {new Date(membre.ajoute_le).toLocaleDateString('fr-FR')} · Dernière connexion :{' '}
                    {formaterDerniereConnexion(membre.derniere_connexion)}
                  </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                  <button
                    type="button"
                    onClick={() => setMembreEnEdition(membreEnEdition === membre.id ? null : membre.id)}
                    disabled={estSoiMeme}
                    aria-expanded={membreEnEdition === membre.id}
                    title={estSoiMeme ? 'Vous ne pouvez pas modifier vos propres rôles.' : undefined}
                    className="h-11 cursor-pointer rounded border border-bordure bg-surface px-3 text-corps text-texte transition-[background-color,transform] hover:bg-surface-alt active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    Modifier les rôles
                  </button>

                  <Bascule
                    actif={membre.statut === 'actif'}
                    onChange={(actif) => demanderChangementStatut(membre, actif)}
                    libelleActif="Actif"
                    libelleInactif="Désactivé"
                    disabled={estSoiMeme || changementStatut.isPending}
                  />

                  <button
                    type="button"
                    onClick={() => demanderNouveauCode(membre)}
                    disabled={generationCode.isPending}
                    title="Générer un nouveau code d'accès"
                    aria-label={`Générer un nouveau code d'accès pour ${membre.nom}`}
                    className="flex h-11 w-11 cursor-pointer items-center justify-center rounded text-texte-secondaire transition-[background-color,color,transform] hover:bg-surface-alt hover:text-primaire active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    <KeyRound aria-hidden="true" size={20} strokeWidth={1.5} />
                  </button>
                </div>

                {membreEnEdition === membre.id && roles && (
                  <EditionRoles
                    membre={membre}
                    roles={roles}
                    onFermer={() => setMembreEnEdition(null)}
                    onEnregistre={(modifie) => {
                      setMembreEnEdition(null)
                      invaliderEquipe()
                      setMessageSucces(`Rôles de ${modifie.nom} : ${modifie.roles.map((role) => role.libelle).join(' + ')}.`)
                    }}
                  />
                )}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}

/**
 * Les cases d'un membre existant, enregistrées au bouton seulement. Les
 * refus du serveur (dernier administrateur actif, au moins un rôle) sont
 * affichés tels quels : c'est lui qui décide.
 */
function EditionRoles({
  membre,
  roles,
  onFermer,
  onEnregistre,
}: {
  membre: MembreEquipe
  roles: RoleEquipe[]
  onFermer: () => void
  onEnregistre: (membre: MembreEquipe) => void
}) {
  const [coches, setCoches] = useState(() => membre.roles.map((role) => role.id))
  const [erreur, setErreur] = useState<string | undefined>()

  const modification = useMutation({
    mutationFn: () => modifierMembreEquipe(membre.id, { roleIds: coches }),
    onSuccess: onEnregistre,
    onError: (echec) => setErreur(extraireErreursChamps(echec).role_ids ?? "Les rôles n'ont pas pu être enregistrés. Réessayez."),
  })

  return (
    <div className="w-full space-y-3 rounded border border-bordure p-3">
      <SelecteurRoles
        id={`roles-${membre.id}`}
        roles={roles}
        coches={coches}
        onChange={(nouvelles) => {
          setCoches(nouvelles)
          setErreur(undefined)
        }}
        erreur={erreur}
      />
      <div className="flex flex-wrap gap-2">
        <button
          type="button"
          onClick={() => modification.mutate()}
          disabled={coches.length === 0 || modification.isPending}
          className="h-11 cursor-pointer rounded bg-primaire px-4 text-corps font-medium text-surface transition-[background-color,transform] hover:bg-primaire-fonce active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100"
        >
          <EtatBouton chargement={modification.isPending}>Enregistrer les rôles</EtatBouton>
        </button>
        <button
          type="button"
          onClick={onFermer}
          className="h-11 cursor-pointer rounded px-4 text-corps text-texte-secondaire transition-[background-color,transform] hover:bg-surface-alt active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1"
        >
          Annuler
        </button>
      </div>
    </div>
  )
}
