/**
 * Identité d'établissement (Étape 6A ter) : UN seul contrat de données pour
 * les deux chemins d'accès (commerçant sur son propre établissement,
 * super-admin sur n'importe lequel) — voir hooks/useServiceIdentite.ts, qui
 * construit un ServiceIdentite pointant vers l'un ou l'autre selon
 * l'appelant, et FormulaireIdentiteEtablissement, le seul formulaire.
 */
import { client } from '../lib/client'

export type JourSemaine = 'lundi' | 'mardi' | 'mercredi' | 'jeudi' | 'vendredi' | 'samedi' | 'dimanche'

export const JOURS_SEMAINE: JourSemaine[] = [
  'lundi',
  'mardi',
  'mercredi',
  'jeudi',
  'vendredi',
  'samedi',
  'dimanche',
]

export type HoraireJour = {
  ouverture: string | null
  fermeture: string | null
  ferme: boolean
}

export type Horaires = Partial<Record<JourSemaine, HoraireJour>>

export type FormatLogo = { webp: string | null; jpg: string | null }
export type Logo = { petit: FormatLogo; grand: FormatLogo }

export type IdentiteEtablissement = {
  id: number
  nom: string
  description: string | null
  couleur_accent: string | null
  /** Valeur affichée tant que couleur_accent est null — fournie par l'API, jamais recopiée ici. */
  couleur_accent_par_defaut: string
  logo: Logo | null
  telephone_whatsapp: string | null
  telephone_fixe: string | null
  email_contact: string | null
  adresse: string | null
  horaires: Horaires | null
  lien_facebook: string | null
  lien_instagram: string | null
  lien_tiktok: string | null
  lien_site_web: string | null
}

export type IdentiteEtablissementPayload = Partial<{
  nom: string
  description: string | null
  couleur_accent: string | null
  telephone_whatsapp: string | null
  telephone_fixe: string | null
  email_contact: string | null
  adresse: string | null
  horaires: Horaires | null
  lien_facebook: string | null
  lien_instagram: string | null
  lien_tiktok: string | null
  lien_site_web: string | null
}>

export type ServiceIdentite = {
  recuperer: () => Promise<IdentiteEtablissement>
  mettreAJour: (payload: IdentiteEtablissementPayload) => Promise<IdentiteEtablissement>
  uploaderLogo: (fichier: File) => Promise<IdentiteEtablissement>
  supprimerLogo: () => Promise<IdentiteEtablissement>
}

function creerService(base: string): ServiceIdentite {
  return {
    async recuperer() {
      const { data } = await client.get<{ data: IdentiteEtablissement }>(base)
      return data.data
    },
    async mettreAJour(payload) {
      const { data } = await client.patch<{ data: IdentiteEtablissement }>(base, payload)
      return data.data
    },
    async uploaderLogo(fichier) {
      const corps = new FormData()
      corps.append('logo', fichier)
      const { data } = await client.post<{ data: IdentiteEtablissement }>(`${base}/logo`, corps)
      return data.data
    },
    async supprimerLogo() {
      await client.delete(`${base}/logo`)
      return this.recuperer()
    },
  }
}

export function serviceIdentiteCommercant(): ServiceIdentite {
  return creerService('/api/parametres/etablissement')
}

export function serviceIdentiteSuperAdmin(etablissementId: number): ServiceIdentite {
  return creerService(`/api/etablissements/${etablissementId}/identite`)
}
