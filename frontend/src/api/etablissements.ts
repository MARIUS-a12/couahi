/**
 * Gestion des établissements de la plateforme — réservé au super-admin côté
 * API (voir EtablissementPolicy côté backend). N'est jamais appelé pour un
 * utilisateur rattaché à un établissement.
 */
import type { StatutAbonnement } from './abonnement'
import { client } from '../lib/client'

export type TypeEtablissement = 'boutique' | 'restaurant'

export type Etablissement = {
  id: number
  nom: string
  type: TypeEtablissement
  sous_domaine: string | null
  statut: string
  produits_count?: number
  created_at: string
  // Étape 6A ter : 'logo' | 'couleur' | 'whatsapp' | 'horaires', vide quand
  // l'identité est complète — voir la pastille "Vitrine incomplète".
  identite_champs_manquants: string[]
  // Étape 6C-1 : pour savoir qui accompagner — jamais les identifiants.
  paiement_configure: boolean
  // Étape 12 : colonne "Abonnement", triable par échéance.
  abonnement: { statut: StatutAbonnement; date_echeance: string } | null
}

export type Domaine = {
  id: number
  hote: string
  est_principal: boolean
  statut: string
}

export type UtilisateurRattache = {
  id: number
  nom: string
  email: string
  role: string
  statut: string
}

export type EtablissementDetail = {
  id: number
  nom: string
  type: TypeEtablissement
  statut: string
  email: string | null
  telephone: string | null
  couleur_accent: string | null
  couleur_accent_par_defaut: string
  created_at: string
  domaines: Domaine[]
  utilisateurs: UtilisateurRattache[]
  // Étape 7 : conditionne la présence du bouton "Supprimer définitivement" —
  // la vérification qui compte reste côté serveur.
  nombre_commandes: number
  // Lot A : le type n'est modifiable que tant que c'est 0 — revérifié par
  // le serveur (EtablissementController::modifierType).
  nombre_produits: number
}

export type NouvelEtablissementPayload = {
  nom: string
  type: TypeEtablissement
  sous_domaine: string
  nom_administrateur: string
  email_administrateur: string
}

export type EtablissementCree = {
  etablissement: EtablissementDetail
  motDePasseGenere: string
}

export async function recupererEtablissements(): Promise<Etablissement[]> {
  const { data } = await client.get<{ data: Etablissement[] }>('/api/etablissements')

  return data.data
}

export async function recupererEtablissement(id: number): Promise<EtablissementDetail> {
  const { data } = await client.get<{ data: EtablissementDetail }>(`/api/etablissements/${id}`)

  return data.data
}

/**
 * mot_de_passe_genere n'est présent que dans CETTE réponse — jamais dans la
 * liste ni dans la fiche récupérées ensuite (voir EtablissementController
 * côté backend). Le composant appelant est responsable de ne l'afficher
 * qu'une fois, jamais de le conserver au-delà de cet écran.
 */
export async function creerEtablissement(payload: NouvelEtablissementPayload): Promise<EtablissementCree> {
  const { data } = await client.post<{ data: EtablissementDetail; mot_de_passe_genere: string }>(
    '/api/etablissements',
    payload,
  )

  return { etablissement: data.data, motDePasseGenere: data.mot_de_passe_genere }
}

export async function suspendreEtablissement(id: number): Promise<EtablissementDetail> {
  const { data } = await client.post<{ data: EtablissementDetail }>(`/api/etablissements/${id}/suspendre`)

  return data.data
}

export async function modifierTypeEtablissement(id: number, type: TypeEtablissement): Promise<EtablissementDetail> {
  const { data } = await client.patch<{ data: EtablissementDetail }>(`/api/etablissements/${id}/type`, { type })

  return data.data
}

export async function reactiverEtablissement(id: number): Promise<EtablissementDetail> {
  const { data } = await client.post<{ data: EtablissementDetail }>(`/api/etablissements/${id}/reactiver`)

  return data.data
}

/**
 * Destruction définitive et irréversible — voir SupprimerEtablissement côté
 * backend. nom_confirmation doit être le nom EXACT de l'établissement : la
 * vérification est refaite côté serveur, jamais seulement côté interface.
 */
export async function supprimerEtablissement(id: number, nomConfirmation: string): Promise<void> {
  await client.delete(`/api/etablissements/${id}`, { data: { nom_confirmation: nomConfirmation } })
}

export async function verifierDisponibiliteSousDomaine(valeur: string): Promise<boolean> {
  const { data } = await client.get<{ disponible: boolean }>('/api/etablissements/sous-domaine-disponible', {
    params: { valeur },
  })

  return data.disponible
}
