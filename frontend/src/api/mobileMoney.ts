/**
 * Configuration du Mobile Money par établissement (Étape 13) — même
 * principe à deux chemins que api/paiement.ts (commerçant sur le sien,
 * super-admin sur n'importe lequel). En clair : ce ne sont pas des secrets,
 * chaque client qui paie voit le numéro et le nom du titulaire.
 */
import { client } from '../lib/client'

export type OperateurMobileMoney = 'wave' | 'orange_money' | 'mtn' | 'moov'

export const LIBELLES_OPERATEUR: Record<OperateurMobileMoney, string> = {
  wave: 'Wave',
  orange_money: 'Orange Money',
  mtn: 'MTN MoMo',
  moov: 'Moov Money',
}

export type MobileMoneyEtablissement = {
  configure: boolean
  numero: string | null
  numero_affiche: string | null
  operateur: OperateurMobileMoney | null
  operateur_libelle: string | null
  beneficiaire: string | null
  // CinetPay passe avant : s'il est configuré, le Mobile Money n'est pas
  // proposé aux clients, même rempli.
  cinetpay_prioritaire: boolean
}

export type ConfigurerMobileMoneyPayload = {
  mm_numero: string
  mm_operateur: OperateurMobileMoney | ''
  mm_beneficiaire: string
}

export type ServiceMobileMoney = {
  recuperer: () => Promise<MobileMoneyEtablissement>
  configurer: (payload: ConfigurerMobileMoneyPayload) => Promise<MobileMoneyEtablissement>
  retirer: () => Promise<void>
}

function creerService(base: string): ServiceMobileMoney {
  return {
    async recuperer() {
      const { data } = await client.get<{ data: MobileMoneyEtablissement }>(base)
      return data.data
    },
    async configurer(payload) {
      const { data } = await client.put<{ data: MobileMoneyEtablissement }>(base, payload)
      return data.data
    },
    async retirer() {
      await client.delete(base)
    },
  }
}

export function serviceMobileMoneyCommercant(): ServiceMobileMoney {
  return creerService('/api/parametres/mobile-money')
}

export function serviceMobileMoneySuperAdmin(etablissementId: number): ServiceMobileMoney {
  return creerService(`/api/etablissements/${etablissementId}/mobile-money`)
}
