/**
 * Un cookie XSRF périmé ne doit pas bloquer la connexion : un 419 redemande
 * un jeton neuf et rejoue la requête une fois, pas davantage.
 */
import { AxiosError, AxiosHeaders } from 'axios'
import type { AxiosAdapter, InternalAxiosRequestConfig } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { client } from './client'

function reponse(config: InternalAxiosRequestConfig, status: number) {
  const resultat = { data: {}, status, statusText: '', headers: {}, config }
  if (status >= 400) {
    return Promise.reject(new AxiosError(String(status), String(status), config, null, resultat))
  }
  return Promise.resolve(resultat)
}

describe('jeton CSRF périmé', () => {
  const adaptateurOriginal = client.defaults.adapter
  let appels: string[]

  beforeEach(() => {
    appels = []
    // Un cookie existe déjà : c'est le cas du cookie périmé.
    document.cookie = 'XSRF-TOKEN=perime'
  })

  afterEach(() => {
    client.defaults.adapter = adaptateurOriginal
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
  })

  it('un 419 redemande un jeton, puis la connexion repasse', async () => {
    let tentatives = 0
    client.defaults.adapter = ((config) => {
      appels.push(`${config.method} ${config.url}`)
      if (config.url === '/api/connexion') {
        tentatives++
        return reponse(config, tentatives === 1 ? 419 : 200)
      }
      return reponse(config, 204)
    }) as AxiosAdapter

    const resultat = await client.post('/api/connexion', { email: 'fatou@wax-adjame.test' }, { headers: new AxiosHeaders() })

    expect(resultat.status).toBe(200)
    expect(appels).toEqual(['post /api/connexion', 'get /sanctum/csrf-cookie', 'post /api/connexion'])
  })

  it('un second 419 n’est pas rejoué à l’infini : c’est une vraie erreur', async () => {
    client.defaults.adapter = ((config) => {
      appels.push(`${config.method} ${config.url}`)
      return reponse(config, config.url === '/api/connexion' ? 419 : 204)
    }) as AxiosAdapter

    await expect(client.post('/api/connexion', {})).rejects.toMatchObject({ response: { status: 419 } })
    expect(appels.filter((appel) => appel === 'post /api/connexion')).toHaveLength(2)
  })

  it('une autre erreur (422) n’est jamais rejouée', async () => {
    const adaptateur = vi.fn((config: InternalAxiosRequestConfig) => reponse(config, 422))
    client.defaults.adapter = adaptateur as unknown as AxiosAdapter

    await expect(client.post('/api/connexion', {})).rejects.toMatchObject({ response: { status: 422 } })
    expect(adaptateur).toHaveBeenCalledTimes(1)
  })
})
