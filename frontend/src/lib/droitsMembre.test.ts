import { describe, expect, it } from 'vitest'
import { droitsDuMembre, enumerer } from './droitsMembre'

// Les permissions telles que /api/equipe/roles les renvoie (RolesEtPermissionsSeeder).
const ROLES = [
  {
    id: 2,
    permissions: [
      'annuler_payees', 'creer_commandes', 'encaisser', 'gerer_catalogue', 'gerer_demandes_whatsapp', 'gerer_equipe',
      'gerer_parametres', 'gerer_stock', 'traiter_commandes', 'voir_catalogue', 'voir_commandes', 'voir_statistiques',
    ],
  },
  { id: 3, permissions: ['creer_commandes', 'gerer_demandes_whatsapp', 'traiter_commandes', 'voir_catalogue', 'voir_commandes'] },
  { id: 4, permissions: ['encaisser', 'voir_catalogue', 'voir_commandes'] },
  { id: 5, permissions: ['gerer_stock', 'voir_catalogue', 'voir_commandes'] },
]

describe('droitsDuMembre', () => {
  it('le gestionnaire de stock pourra voir les commandes pour les préparer', () => {
    const { pourra, nePourraPas } = droitsDuMembre(ROLES, [5])

    expect(pourra).toEqual(['consulter le catalogue', 'ajuster les quantités en stock', 'voir les commandes pour les préparer'])
    expect(nePourraPas).toContain('vendre au comptoir et confirmer les paiements reçus')
    expect(nePourraPas).toContain('annuler une commande payée et rembourser le client')
  })

  it('deux rôles cochés additionnent leurs droits', () => {
    const operateur = droitsDuMembre(ROLES, [3])
    const lesDeux = droitsDuMembre(ROLES, [3, 4])

    expect(operateur.nePourraPas).toContain('vendre au comptoir et confirmer les paiements reçus')
    expect(lesDeux.pourra).toContain('vendre au comptoir et confirmer les paiements reçus')
    expect(lesDeux.pourra).toContain('saisir les commandes reçues sur WhatsApp ou par téléphone')
    expect(lesDeux.nePourraPas).toContain('annuler une commande payée et rembourser le client')
  })

  it("l'administrateur pourra tout, et voir_statistiques n'est jamais citée", () => {
    const { pourra, nePourraPas } = droitsDuMembre(ROLES, [2])

    expect(nePourraPas).toEqual([])
    expect(pourra).toContain("gérer l'équipe")
    // gerer_catalogue suffit, consulter n'est pas redit.
    expect(pourra).not.toContain('consulter le catalogue')
    expect([...pourra, ...nePourraPas].join(' ')).not.toMatch(/statistiques/)
  })

  it('aucun rôle coché : rien de permis', () => {
    expect(droitsDuMembre(ROLES, []).pourra).toEqual([])
  })

  it('une permission sans texte prévu apparaît quand même', () => {
    const { pourra } = droitsDuMembre([...ROLES, { id: 9, permissions: ['livrer_commandes'] }], [9])

    expect(pourra).toEqual(['livrer commandes'])
  })
})

describe('enumerer', () => {
  it('sépare les actions par des points-virgules', () => {
    expect(enumerer(['a'])).toBe('a')
    expect(enumerer(['a', 'b, puis c', 'd'])).toBe('a ; b, puis c ; d')
  })
})
