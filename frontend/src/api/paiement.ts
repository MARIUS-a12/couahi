/**
 * Configuration du paiement CinetPay par établissement (Étape 6C-1). UN seul
 * contrat de données pour les deux chemins d'accès (commerçant sur son
 * propre établissement, super-admin sur n'importe lequel) — même principe
 * que api/identite.ts. Le secret ne se lit jamais : une fois saisi, il ne
 * revient plus dans aucune réponse, seulement site_id et clé API, masqués.
 */
import { client } from '../lib/client'

export type PaiementEtablissement = {
  configure: boolean
  // Configuré n'est pas disponible : faux tant que l'intégration CinetPay
  // n'est pas active sur la plateforme (CINETPAY_ACTIF côté serveur).
  disponible: boolean
  configure_le: string | null
  cinetpay_site_id_masque: string | null
  cinetpay_cle_api_masque: string | null
}

export type ConfigurerPaiementPayload = {
  cinetpay_site_id: string
  cinetpay_cle_api: string
  cinetpay_secret: string
}

export type ServicePaiement = {
  recuperer: () => Promise<PaiementEtablissement>
  configurer: (payload: ConfigurerPaiementPayload) => Promise<PaiementEtablissement>
  supprimer: () => Promise<void>
}

function creerService(base: string): ServicePaiement {
  return {
    async recuperer() {
      const { data } = await client.get<{ data: PaiementEtablissement }>(base)
      return data.data
    },
    async configurer(payload) {
      const { data } = await client.put<{ data: PaiementEtablissement }>(base, payload)
      return data.data
    },
    async supprimer() {
      await client.delete(base)
    },
  }
}

export function servicePaiementCommercant(): ServicePaiement {
  return creerService('/api/parametres/paiement')
}

export function servicePaiementSuperAdmin(etablissementId: number): ServicePaiement {
  return creerService(`/api/etablissements/${etablissementId}/paiement`)
}
