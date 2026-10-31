/**
 * Étape 10 — équipe de l'établissement courant (/admin/equipe), réservée à
 * gerer_equipe. On ne supprime jamais un membre (voir le contrôleur côté
 * API) : seulement désactiver/réactiver, modifier nom/rôle, ou générer un
 * nouveau code d'accès.
 *
 * Correctif activation par code : la création ne renvoie plus de mot de
 * passe généré — l'administrateur ne le connaît ni ne le choisit jamais.
 * Elle renvoie un CODE D'ACTIVATION à transmettre au membre, qui choisit
 * lui-même son mot de passe sur /admin/activation (voir api/activation.ts).
 */
import { client } from '../lib/client'

export type StatutMembreEquipe = 'actif' | 'suspendu'

export type RoleMembre = {
  id: number
  nom: string
  libelle: string
}

// Lot F : avec ses permissions, d'où se calcule la phrase des droits
// (voir lib/droitsMembre.ts).
export type RoleEquipe = RoleMembre & {
  permissions: string[]
}

export type MembreEquipe = {
  id: number
  utilisateur_id: number
  nom: string
  email: string
  // Lot F : plusieurs rôles possibles, au moins un.
  roles: RoleMembre[]
  statut: StatutMembreEquipe
  ajoute_le: string
  // null pour un membre qui ne s'est jamais encore connecté.
  derniere_connexion: string | null
  // faux tant que le membre n'a pas lui-même activé son compte.
  mot_de_passe_defini: boolean
}

export async function recupererEquipe(): Promise<MembreEquipe[]> {
  const { data } = await client.get<{ data: MembreEquipe[] }>('/api/equipe')

  return data.data
}

export async function recupererRolesEquipe(): Promise<RoleEquipe[]> {
  const { data } = await client.get<{ data: RoleEquipe[] }>('/api/equipe/roles')

  return data.data
}

export type NouveauMembreEquipe = {
  nom: string
  email: string
  roleIds: number[]
}

export type MembreEquipeCree = {
  membre: MembreEquipe
  codeActivation: string
}

export async function creerMembreEquipe(payload: NouveauMembreEquipe): Promise<MembreEquipeCree> {
  const { data } = await client.post<{ data: MembreEquipe; code_activation: string }>('/api/equipe', {
    nom: payload.nom,
    email: payload.email,
    role_ids: payload.roleIds,
  })

  return { membre: data.data, codeActivation: data.code_activation }
}

export async function modifierMembreEquipe(
  id: number,
  payload: { nom?: string; roleIds?: number[] },
): Promise<MembreEquipe> {
  const { data } = await client.put<{ data: MembreEquipe }>(`/api/equipe/${id}`, {
    nom: payload.nom,
    role_ids: payload.roleIds,
  })

  return data.data
}

export async function changerStatutMembreEquipe(id: number, statut: StatutMembreEquipe): Promise<MembreEquipe> {
  const { data } = await client.patch<{ data: MembreEquipe }>(`/api/equipe/${id}/statut`, { statut })

  return data.data
}

/**
 * "Générer un nouveau code d'accès" — remplace l'ancienne réinitialisation
 * de mot de passe. L'administrateur ne voit jamais le mot de passe du
 * membre, ni avant ni après ; ce code lui permet d'en choisir un nouveau
 * lui-même sur /admin/activation.
 */
export async function genererCodeActivationMembre(id: number): Promise<string> {
  const { data } = await client.post<{ code_activation: string }>(`/api/equipe/${id}/code-activation`)

  return data.code_activation
}
