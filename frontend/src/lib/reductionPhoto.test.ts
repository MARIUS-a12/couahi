import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { dimensionsReduites, reduirePhoto } from './reductionPhoto'

describe('dimensions après réduction', () => {
  it('une photo de téléphone 4000 × 3000 : le petit côté ramené à 1200', () => {
    expect(dimensionsReduites(4000, 3000)).toEqual({ largeur: 1600, hauteur: 1200 })
  })

  it('un portrait garde son sens', () => {
    expect(dimensionsReduites(3000, 4000)).toEqual({ largeur: 1200, hauteur: 1600 })
  })

  it('jamais d’agrandissement : une petite image n’est pas touchée', () => {
    expect(dimensionsReduites(1200, 900)).toBeNull()
    expect(dimensionsReduites(800, 600)).toBeNull()
  })
})

describe('réduction avant l’envoi', () => {
  const original = new File([new Uint8Array(5_000_000)], 'IMG_2026.HEIC.jpeg', { type: 'image/jpeg' })
  const dessiner = vi.fn()

  beforeEach(() => {
    vi.stubGlobal('createImageBitmap', vi.fn(async () => ({ width: 4000, height: 3000, close: vi.fn() })))
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({ drawImage: dessiner } as unknown as CanvasRenderingContext2D)
    vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation(function (this: HTMLCanvasElement, rappel, type) {
      rappel(new Blob([new Uint8Array(300_000)], { type }))
    })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('une photo de 5 Mo part en JPEG d’environ 300 Ko, petit côté à 1200', async () => {
    const reduite = await reduirePhoto(original)

    expect(reduite.size).toBe(300_000)
    expect(reduite.type).toBe('image/jpeg')
    expect(reduite.name).toBe('IMG_2026.HEIC.jpg')
    expect(dessiner).toHaveBeenCalledWith(expect.anything(), 0, 0, 1600, 1200)
  })

  it('un PNG reste un PNG (un logo garde sa transparence)', async () => {
    const png = new File([new Uint8Array(5_000_000)], 'logo.png', { type: 'image/png' })

    expect((await reduirePhoto(png)).type).toBe('image/png')
  })

  it('si le navigateur ne sait pas décoder la photo, l’original part tel quel', async () => {
    vi.stubGlobal('createImageBitmap', vi.fn(async () => {
      throw new Error('format non pris en charge')
    }))

    expect(await reduirePhoto(original)).toBe(original)
  })

  it('si la version réduite n’est pas plus légère, l’original part', async () => {
    const leger = new File([new Uint8Array(200_000)], 'deja-leger.jpg', { type: 'image/jpeg' })

    expect(await reduirePhoto(leger)).toBe(leger)
  })
})
