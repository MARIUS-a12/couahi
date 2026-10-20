import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv } from 'vite'
import { metadonneesPartage } from './vite/metadonneesPartage.ts'

// https://vite.dev/config/
export default defineConfig(({ mode }) => ({
  // Même port d'API que le navigateur (voir src/lib/client.ts) : le greffon
  // de métadonnées interroge l'API du sous-domaine visité.
  plugins: [react(), tailwindcss(), metadonneesPartage(loadEnv(mode, process.cwd(), '').VITE_API_PORT ?? '')],
  server: {
    // L'app doit être ouverte sur un sous-domaine (chez-awa.localhost), pas
    // sur localhost : Vite refuse par défaut les requêtes dont l'en-tête
    // Host n'est pas explicitement autorisé (protection anti DNS-rebinding).
    allowedHosts: true,
  },
  preview: {
    allowedHosts: true,
  },
}))
