/**
 * L'envoi d'une photo : réduite sur le téléphone avant de partir (la data
 * mobile du commerçant), et un refus du serveur affiché tel quel, parce
 * que son message dit quoi faire.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { AxiosError, AxiosHeaders } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { envoyerMediaProduit } from '../api/medias'
import { reduirePhoto } from '../lib/reductionPhoto'
import { ZoneEnvoiPhotos } from './ZoneEnvoiPhotos'

vi.mock('../api/medias', () => ({
  envoyerMediaProduit: vi.fn(),
  reordonnerMediasProduit: vi.fn(),
  supprimerMediaProduit: vi.fn(),
}))
vi.mock('../lib/reductionPhoto', () => ({ reduirePhoto: vi.fn() }))

const photoDeTelephone = new File([new Uint8Array(12 * 1024 * 1024)], 'IMG_0042.jpg', { type: 'image/jpeg' })
const photoReduite = new File([new Uint8Array(300_000)], 'IMG_0042.jpg', { type: 'image/jpeg' })

function afficher() {
  const { container } = render(
    <QueryClientProvider client={new QueryClient()}>
      <ZoneEnvoiPhotos produitId={7} medias={[]} />
    </QueryClientProvider>,
  )
  return container.querySelector<HTMLInputElement>('input[type="file"]')!
}

describe('envoi d’une photo', () => {
  beforeEach(() => {
    vi.mocked(reduirePhoto).mockResolvedValue(photoReduite)
    vi.mocked(envoyerMediaProduit).mockReset()
    URL.createObjectURL = vi.fn(() => 'blob:apercu')
    URL.revokeObjectURL = vi.fn()
  })

  it('c’est la version réduite qui part, et une photo de 12 Mo passe', async () => {
    vi.mocked(envoyerMediaProduit).mockResolvedValue({ id: 1 } as never)
    fireEvent.change(afficher(), { target: { files: [photoDeTelephone] } })

    await waitFor(() => expect(envoyerMediaProduit).toHaveBeenCalled())
    // toBe : deux File se comparent égaux par leurs propriétés, vides.
    expect(vi.mocked(envoyerMediaProduit).mock.calls[0][1]).toBe(photoReduite)
    expect(screen.queryByText(/8 Mo/)).not.toBeInTheDocument()
  })

  it('le refus du serveur s’affiche tel quel, pas « Échec de l’envoi »', async () => {
    const message = 'Cette photo est trop grande (24 mégapixels, 16 au maximum). Envoyez-la depuis votre téléphone…'
    vi.mocked(envoyerMediaProduit).mockRejectedValue(
      new AxiosError('422', '422', undefined, undefined, {
        status: 422,
        data: { errors: { photo: [message] } },
        statusText: '',
        headers: {},
        config: { headers: new AxiosHeaders() },
      }),
    )
    fireEvent.change(afficher(), { target: { files: [photoDeTelephone] } })

    expect(await screen.findByText(message)).toBeInTheDocument()
    expect(screen.queryByText(/Échec de l'envoi/)).not.toBeInTheDocument()
  })
})
