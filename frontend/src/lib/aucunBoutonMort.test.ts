/**
 * Un bouton qui ne mène nulle part ne doit pas exister : le panneau
 * "Paiement bientôt disponible" (ancien "Payer maintenant" sans intégration
 * CinetPay) a été supprimé, et ne doit revenir nulle part dans le code.
 * Le serveur ne propose CinetPay que s'il est réellement disponible.
 */
import { describe, expect, it } from 'vitest'

const sources = import.meta.glob(['../**/*.{ts,tsx}', '!../**/*.test.{ts,tsx}'], {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

describe('aucun bouton de paiement mort', () => {
  it('le panneau "Paiement bientôt disponible" n\'existe plus nulle part', () => {
    const fichiers = Object.keys(sources)
    expect(fichiers.length).toBeGreaterThan(50)

    const coupables = fichiers.filter((fichier) => /bientôt disponible|arrive prochainement/i.test(sources[fichier]))
    expect(coupables).toEqual([])
  })
})
