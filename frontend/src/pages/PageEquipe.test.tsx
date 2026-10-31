/**
 * Lot F — l'écran Équipe : des rôles cochés et cumulables, Administrateur
 * qui grise les autres, la phrase des droits qui suit chaque case, et le
 * code d'activation envoyé par WhatsApp avec le lien entier, le code dans
 * le texte du message et jamais dans l'adresse.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { MembreEquipe, RoleEquipe } from '../api/equipe'
import { creerMembreEquipe, modifierMembreEquipe, recupererEquipe, recupererRolesEquipe } from '../api/equipe'
import { PageEquipe } from './PageEquipe'

vi.mock('../api/equipe', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api/equipe')>()),
  recupererEquipe: vi.fn(),
  recupererRolesEquipe: vi.fn(),
  creerMembreEquipe: vi.fn(),
  modifierMembreEquipe: vi.fn(),
}))
vi.mock('../hooks/useMoi', () => ({
  useMoi: () => ({ data: { utilisateur: { id: 1 }, etablissement: { nom: 'Wax Adjamé' }, permissions: ['gerer_equipe'] } }),
}))

// Les permissions de RolesEtPermissionsSeeder, telles que /api/equipe/roles les renvoie.
const ROLES: RoleEquipe[] = [
  {
    id: 2,
    nom: 'admin_etablissement',
    libelle: "Administrateur d'établissement",
    permissions: [
      'annuler_payees', 'creer_commandes', 'encaisser', 'gerer_catalogue', 'gerer_demandes_whatsapp', 'gerer_equipe',
      'gerer_parametres', 'gerer_stock', 'traiter_commandes', 'voir_catalogue', 'voir_commandes', 'voir_statistiques',
    ],
  },
  { id: 3, nom: 'operateur', libelle: 'Opérateur', permissions: ['creer_commandes', 'gerer_demandes_whatsapp', 'traiter_commandes', 'voir_catalogue', 'voir_commandes'] },
  { id: 4, nom: 'caissier', libelle: 'Caissier', permissions: ['encaisser', 'voir_catalogue', 'voir_commandes'] },
  { id: 5, nom: 'gestionnaire_stock', libelle: 'Gestionnaire de stock', permissions: ['gerer_stock', 'voir_catalogue', 'voir_commandes'] },
]

const fatou: MembreEquipe = {
  id: 10, utilisateur_id: 1, nom: 'Fatou', email: 'fatou@wax-adjame.test', roles: [{ id: 2, nom: 'admin_etablissement', libelle: "Administrateur d'établissement" }],
  statut: 'actif', ajoute_le: '2026-10-01T10:00:00Z', derniere_connexion: null, mot_de_passe_defini: true,
}
const yao: MembreEquipe = {
  ...fatou, id: 11, utilisateur_id: 2, nom: 'Yao', email: 'yao@wax-adjame.test', roles: [{ id: 3, nom: 'operateur', libelle: 'Opérateur' }],
}

function afficher() {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter>
        <PageEquipe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const caseRole = (zone: HTMLElement, libelle: string) => within(zone).getByRole('checkbox', { name: libelle })

async function formulaireDeCreation(): Promise<HTMLElement> {
  const formulaire = (await screen.findByRole('group', { name: /Rôles/ })) as HTMLElement
  await within(formulaire).findAllByRole('checkbox')

  return formulaire
}

describe('équipe : rôles cumulables', () => {
  beforeEach(() => {
    vi.mocked(recupererEquipe).mockReset().mockResolvedValue([fatou, yao])
    vi.mocked(recupererRolesEquipe).mockReset().mockResolvedValue(ROLES)
    vi.mocked(creerMembreEquipe).mockReset()
    vi.mocked(modifierMembreEquipe).mockReset()
  })

  it('deux rôles cochés : la phrase se met à jour à chaque case', async () => {
    afficher()
    const formulaire = await formulaireDeCreation()

    expect(within(formulaire).getByText('Cochez au moins un rôle.')).toBeInTheDocument()

    fireEvent.click(caseRole(formulaire, 'Gestionnaire de stock'))
    expect(formulaire).toHaveTextContent('Pourra : consulter le catalogue ; ajuster les quantités en stock ; voir les commandes pour les préparer.')
    expect(formulaire).toHaveTextContent(/Ne pourra pas : .*vendre au comptoir et confirmer les paiements reçus/)

    fireEvent.click(caseRole(formulaire, 'Caissier'))
    expect(formulaire).toHaveTextContent(/Pourra : .*ajuster les quantités en stock ;.*vendre au comptoir et confirmer les paiements reçus/)
    expect(formulaire).toHaveTextContent(/Ne pourra pas : .*annuler une commande payée et rembourser le client/)
    expect(formulaire).not.toHaveTextContent(/statistiques/)
  })

  it('Administrateur coché grise et décoche les autres', async () => {
    afficher()
    const formulaire = await formulaireDeCreation()

    fireEvent.click(caseRole(formulaire, 'Caissier'))
    fireEvent.click(caseRole(formulaire, "Administrateur d'établissement"))

    expect(caseRole(formulaire, 'Caissier')).not.toBeChecked()
    expect(caseRole(formulaire, 'Caissier')).toBeDisabled()
    expect(caseRole(formulaire, 'Opérateur')).toBeDisabled()
    expect(formulaire).not.toHaveTextContent('Ne pourra pas')

    fireEvent.click(caseRole(formulaire, "Administrateur d'établissement"))
    expect(caseRole(formulaire, 'Caissier')).toBeEnabled()
  })

  it('la création envoie les rôles cochés, et en refuse aucun', async () => {
    vi.mocked(creerMembreEquipe).mockResolvedValue({ membre: { ...yao, id: 12, nom: 'Koffi', email: 'koffi@wax-adjame.test' }, codeActivation: '482913' })
    afficher()
    const formulaire = await formulaireDeCreation()
    fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'Koffi' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'koffi@wax-adjame.test' } })

    fireEvent.click(screen.getByRole('button', { name: 'Ajouter' }))
    expect(await within(formulaire).findByText('Choisissez au moins un rôle.', { selector: '.text-danger' })).toBeInTheDocument()
    expect(creerMembreEquipe).not.toHaveBeenCalled()

    fireEvent.click(caseRole(formulaire, 'Opérateur'))
    fireEvent.click(caseRole(formulaire, 'Caissier'))
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter' }))

    await waitFor(() => expect(creerMembreEquipe).toHaveBeenCalled())
    expect(vi.mocked(creerMembreEquipe).mock.calls[0][0]).toEqual({ nom: 'Koffi', email: 'koffi@wax-adjame.test', roleIds: [3, 4] })
  })

  it("le code s'envoie par WhatsApp : lien entier, code dans le texte, jamais dans l'adresse", async () => {
    vi.mocked(creerMembreEquipe).mockResolvedValue({ membre: { ...yao, id: 12, nom: 'Koffi', email: 'koffi@wax-adjame.test' }, codeActivation: '482913' })
    afficher()
    const formulaire = await formulaireDeCreation()
    fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'Koffi' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'koffi@wax-adjame.test' } })
    fireEvent.click(caseRole(formulaire, 'Caissier'))
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter' }))

    const envoi = await screen.findByRole('link', { name: 'Envoyer par WhatsApp' })
    const lien = `${window.location.origin}/admin/activation`
    expect(screen.getByText(lien)).toBeInTheDocument()

    const adresse = new URL(envoi.getAttribute('href') ?? '')
    expect(adresse.origin + adresse.pathname).toBe('https://wa.me/')
    const message = adresse.searchParams.get('text') ?? ''
    expect(message).toContain('Wax Adjamé')
    expect(message).toContain(`Ouvrez ce lien : ${lien}`)
    expect(message).toContain('koffi@wax-adjame.test')
    expect(message).toContain('Saisissez ce code : 482913')
    expect(message).toContain('48 heures')
    // Le lien d'activation transmis ne porte pas le code.
    expect(message).not.toMatch(/activation[^\s]*482913/)
  })

  it("« Modifier les rôles » n'enregistre qu'au bouton, et pas pour soi-même", async () => {
    vi.mocked(modifierMembreEquipe).mockResolvedValue({ ...yao, roles: [{ id: 3, nom: 'operateur', libelle: 'Opérateur' }, { id: 4, nom: 'caissier', libelle: 'Caissier' }] })
    afficher()

    const boutons = await screen.findAllByRole('button', { name: 'Modifier les rôles' })
    expect(boutons[0]).toBeDisabled() // Fatou, soi-même

    fireEvent.click(boutons[1])
    const edition = screen.getAllByRole('group', { name: /Rôles/ })[1] as HTMLElement
    expect(caseRole(edition, 'Opérateur')).toBeChecked()
    fireEvent.click(caseRole(edition, 'Caissier'))
    expect(modifierMembreEquipe).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer les rôles' }))
    await waitFor(() => expect(modifierMembreEquipe).toHaveBeenCalledWith(11, { roleIds: [3, 4] }))
    expect(await screen.findByText('Rôles de Yao : Opérateur + Caissier.')).toBeInTheDocument()
  })
})
