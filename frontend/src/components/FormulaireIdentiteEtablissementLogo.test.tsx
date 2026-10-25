/**
 * Le logo suit le même chemin que les photos produit : réduit sur le
 * téléphone avant l'envoi, et un refus du serveur (image trop grande)
 * affiché tel quel, parce que son message dit quoi faire.
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { AxiosError, AxiosHeaders } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { IdentiteEtablissement, ServiceIdentite } from '../api/identite'
import { reduirePhoto } from '../lib/reductionPhoto'
import { FormulaireIdentiteEtablissement } from './FormulaireIdentiteEtablissement'

vi.mock('../lib/reductionPhoto', () => ({ reduirePhoto: vi.fn() }))

const identite: IdentiteEtablissement = {
  id: 1,
  nom: 'Wax Adjamé',
  description: null,
  couleur_accent: null,
  couleur_accent_par_defaut: '#9a3412',
  logo: null,
  telephone_whatsapp: null,
  telephone_fixe: null,
  email_contact: null,
  adresse: null,
  horaires: null,
  lien_facebook: null,
  lien_instagram: null,
  lien_tiktok: null,
  lien_site_web: null,
}

const logoDuTelephone = new File([new Uint8Array(6_000_000)], 'logo.png', { type: 'image/png' })
const logoReduit = new File([new Uint8Array(90_000)], 'logo.png', { type: 'image/png' })

function service(uploaderLogo: ServiceIdentite['uploaderLogo']): ServiceIdentite {
  return { recuperer: vi.fn().mockResolvedValue(identite), mettreAJour: vi.fn(), uploaderLogo, supprimerLogo: vi.fn() }
}

async function choisirLogo(container: HTMLElement) {
  await screen.findByDisplayValue('Wax Adjamé')
  fireEvent.change(container.querySelector('input[type="file"]')!, { target: { files: [logoDuTelephone] } })
}

describe('envoi du logo', () => {
  beforeEach(() => {
    vi.mocked(reduirePhoto).mockResolvedValue(logoReduit)
  })

  it('c’est la version réduite qui part', async () => {
    const uploaderLogo = vi.fn().mockResolvedValue(identite)
    const { container } = render(<FormulaireIdentiteEtablissement service={service(uploaderLogo)} />)

    await choisirLogo(container)

    // toBe et non toHaveBeenCalledWith : deux File se comparent égaux par leurs propriétés, vides.
    await waitFor(() => expect(uploaderLogo).toHaveBeenCalled())
    expect(uploaderLogo.mock.calls[0][0]).toBe(logoReduit)
  })

  it('le refus du serveur s’affiche tel quel', async () => {
    const message = 'Cette photo est trop grande (24 mégapixels, 16 au maximum).'
    const uploaderLogo = vi.fn().mockRejectedValue(
      new AxiosError('422', '422', undefined, undefined, {
        status: 422,
        data: { errors: { logo: [message] } },
        statusText: '',
        headers: {},
        config: { headers: new AxiosHeaders() },
      }),
    )
    const { container } = render(<FormulaireIdentiteEtablissement service={service(uploaderLogo)} />)

    await choisirLogo(container)

    expect(await screen.findByText(message)).toBeInTheDocument()
  })
})
