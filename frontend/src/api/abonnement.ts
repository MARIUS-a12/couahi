/**
 * Abonnement de l'établissement à la plateforme (Étape 12). Deux chemins,
 * comme pour le paiement (voir api/paiement.ts) : le commerçant LIT le sien
 * (/api/abonnement, gerer_parametres exigé) ; le super-admin lit, règle et
 * enregistre les paiements reçus de n'importe quel établissement. Le
 * commerçant ne déclare jamais lui-même un paiement.
 */
import { client } from '../lib/client'

export type StatutAbonnement = 'essai' | 'actif' | 'en_retard' | 'suspendu'

export type MoyenPaiementAbonnement = 'en_ligne' | 'mobile_money' | 'especes' | 'virement'

/** Résumé renvoyé aussi par /api/moi — les jours sont calculés côté serveur. */
export type ResumeAbonnement = {
  statut: StatutAbonnement
  montant_mensuel: number
  jours_de_grace: number
  date_echeance: string
  date_coupure: string
  jours_avant_echeance: number
  jours_avant_coupure: number
  doit_avertir: boolean
}

export type PaiementAbonnement = {
  id: number
  montant: number
  periode_debut: string
  periode_fin: string
  moyen: MoyenPaiementAbonnement
  reference: string | null
  paye_le: string
}

export type DetailAbonnement = {
  abonnement: ResumeAbonnement
  paiements: PaiementAbonnement[]
  // Vides tant que le serveur ne les a pas reçues dans sa configuration.
  reglement: { beneficiaire: string | null; numero_mobile_money: string | null }
}

export type ReglagesAbonnementPayload = {
  montant_mensuel: number
  jours_de_grace: number
}

export type PaiementAbonnementPayload = {
  montant: number
  periode_debut: string
  periode_fin: string
  moyen: Exclude<MoyenPaiementAbonnement, 'en_ligne'>
  reference: string | null
}

type ReponseDetail = {
  data: ResumeAbonnement
  paiements: PaiementAbonnement[]
  reglement: DetailAbonnement['reglement']
}

function versDetail(reponse: ReponseDetail): DetailAbonnement {
  return { abonnement: reponse.data, paiements: reponse.paiements, reglement: reponse.reglement }
}

export async function recupererMonAbonnement(): Promise<DetailAbonnement> {
  const { data } = await client.get<ReponseDetail>('/api/abonnement')

  return versDetail(data)
}

export async function recupererAbonnementEtablissement(etablissementId: number): Promise<DetailAbonnement> {
  const { data } = await client.get<ReponseDetail>(`/api/etablissements/${etablissementId}/abonnement`)

  return versDetail(data)
}

export async function modifierReglagesAbonnement(
  etablissementId: number,
  payload: ReglagesAbonnementPayload,
): Promise<DetailAbonnement> {
  const { data } = await client.patch<ReponseDetail>(`/api/etablissements/${etablissementId}/abonnement`, payload)

  return versDetail(data)
}

export async function enregistrerPaiementAbonnement(
  etablissementId: number,
  payload: PaiementAbonnementPayload,
): Promise<DetailAbonnement> {
  const { data } = await client.post<ReponseDetail>(
    `/api/etablissements/${etablissementId}/abonnement/paiements`,
    payload,
  )

  return versDetail(data)
}
