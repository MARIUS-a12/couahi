/**
 * Écran de connexion du back-office (chemin /admin/connexion) — l'une des
 * deux routes publiques de l'app avec la vitrine elle-même. Volontairement
 * hors du groupe RouteProtegee (voir App.tsx) : la garde d'authentification
 * redirige VERS cette page, elle ne peut donc pas en dépendre elle-même.
 * Formulaire email / mot de passe qui appelle POST /api/connexion (voir
 * api/auth.ts). Une fois connecté, redirige vers /admin : PageAccueilAdmin
 * (Étape 10) y choisit la page d'accueil réelle selon les permissions
 * (RouteProtegee confine quant à lui le super-admin vers /etablissements si
 * besoin).
 */
import { useMutation, useQueryClient } from '@tanstack/react-query'
import axios from 'axios'
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { connecter } from '../api/auth'
import { EtatBouton } from '../components/EtatBouton'
import { CLE_MOI } from '../hooks/useMoi'
import { reinitialiserGardeRedirection } from '../lib/gardeRedirectionConnexion'
import { useTitreDocument } from '../hooks/useTitreDocument'

/**
 * Extrait un message d'erreur à afficher tel quel. Le corps de la réponse
 * (422, "Identifiants invalides." ; 429, voir LimiteurEmailEtIp — temps
 * d'attente en minutes inclus) est déjà rédigé en français par l'API et
 * doit être montré sans reformulation.
 */
function messageErreur(erreur: unknown): string {
  if (axios.isAxiosError(erreur)) {
    const messageBlocage = erreur.response?.status === 429 ? erreur.response?.data?.message : null
    if (typeof messageBlocage === 'string') {
      return messageBlocage
    }

    const messageValidation = erreur.response?.data?.errors?.email?.[0]
    if (typeof messageValidation === 'string') {
      return messageValidation
    }
  }

  return 'Impossible de contacter le serveur. Vérifiez votre connexion et réessayez.'
}

export function PageConnexionUtilisateur() {
  useTitreDocument('Connexion · Back-office')
  const [email, setEmail] = useState('')
  const [motDePasse, setMotDePasse] = useState('')
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const connexion = useMutation({
    mutationFn: () => connecter(email, motDePasse),
    onSuccess: async () => {
      reinitialiserGardeRedirection()
      await queryClient.invalidateQueries({ queryKey: CLE_MOI })
      navigate('/admin', { replace: true })
    },
  })

  function soumettre(evenement: FormEvent<HTMLFormElement>) {
    evenement.preventDefault()
    connexion.mutate()
  }

  return (
    <div className="flex min-h-screen items-center justify-center px-4">
      <form
        onSubmit={soumettre}
        className="w-full max-w-sm border border-bordure bg-surface p-4 sm:p-5"
      >
        <h1 className="mb-6 text-center text-titre-page font-semibold text-texte">Back-office</h1>

        <label htmlFor="email" className="mb-1 block text-petit font-medium text-texte">
          Email
        </label>
        <input
          id="email"
          type="email"
          autoComplete="username"
          required
          value={email}
          onChange={(evenement) => setEmail(evenement.target.value)}
          className="mb-4 h-11 w-full rounded border border-bordure bg-surface px-3 text-corps text-texte transition-colors focus:border-primaire focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1"
        />

        <label htmlFor="mot_de_passe" className="mb-1 block text-petit font-medium text-texte">
          Mot de passe
        </label>
        <input
          id="mot_de_passe"
          type="password"
          autoComplete="current-password"
          required
          value={motDePasse}
          onChange={(evenement) => setMotDePasse(evenement.target.value)}
          className="mb-4 h-11 w-full rounded border border-bordure bg-surface px-3 text-corps text-texte transition-colors focus:border-primaire focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1"
        />

        {connexion.isError && (
          <p role="alert" className="animate-entree-haut mb-4 text-petit text-danger">
            {messageErreur(connexion.error)}
          </p>
        )}

        <button
          type="submit"
          disabled={connexion.isPending}
          className="h-11 w-full cursor-pointer rounded bg-primaire text-corps font-medium text-surface transition-colors hover:bg-primaire-fonce active:bg-primaire-fonce focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-60"
        >
          <EtatBouton chargement={connexion.isPending}>Se connecter</EtatBouton>
        </button>
      </form>
    </div>
  )
}
