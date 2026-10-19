/**
 * Appels HTTP liés à la session : connexion, déconnexion, et /api/moi
 * (utilisateur courant + établissement + permissions). Ce dernier sert à la
 * fois de garde d'authentification et de source pour l'en-tête de l'app.
 */
import type { ResumeAbonnement } from './abonnement'
import { client } from '../lib/client'

export type Utilisateur = {
  id: number
  nom: string
  email: string
}

export type TypeEtablissement = 'boutique' | 'restaurant'

export type Moi = {
  utilisateur: Utilisateur
  etablissement: { id: number; nom: string; type: TypeEtablissement; statut: string } | null
  role: string
  // Étape 10 : le libellé affiché sous le nom de la boutique ("Espace
  // Administrateur"...) vient de la base (voir RolesEtPermissionsSeeder),
  // jamais d'une liste codée en dur côté React — voir BarreLaterale.
  role_libelle_espace: string | null
  permissions: string[]
  // Étape 12 : null hors établissement (super-admin). Présent pour tout
  // membre — la carte de statut de la barre latérale en a besoin — mais le
  // bandeau d'avertissement n'est montré qu'à qui a gerer_parametres.
  abonnement: ResumeAbonnement | null
}

export async function connecter(email: string, motDePasse: string): Promise<void> {
  await client.post('/api/connexion', { email, mot_de_passe: motDePasse })
}

export async function deconnecter(): Promise<void> {
  await client.post('/api/deconnexion')
}

export async function recupererMoi(): Promise<Moi> {
  const { data } = await client.get<Moi>('/api/moi')

  return data
}
