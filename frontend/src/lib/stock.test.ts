/**
 * La pastille de stock du back-office (et le compteur de ruptures) lit le
 * stock des variantes quand le produit en a : le riz de chez-awa (stock
 * produit 0, trois sacs en stock) s'affichait « Épuisé ».
 */
import { describe, expect, it } from 'vitest'
import type { Produit } from '../api/produits'
import { calculerEtatStock, estEnRupture } from './stock'

function produit(champs: Partial<Produit>): Produit {
  return {
    id: 1,
    categorie_id: null,
    nom: 'Riz parfumé long grain',
    slug: 'riz-parfume-long-grain',
    description: null,
    reference: null,
    prix: 1000,
    prix_barre: null,
    mode_stock: 'compte',
    quantite_stock: 0,
    quantite_reservee: 0,
    disponible: true,
    stock_variantes: null,
    statut: 'publie',
    publie_le: null,
    medias: [],
    created_at: '2026-10-23T00:00:00Z',
    updated_at: '2026-10-23T00:00:00Z',
    ...champs,
  }
}

describe('calculerEtatStock', () => {
  it('lit le stock des variantes, pas celui du produit, quand il y en a', () => {
    const riz = produit({ stock_variantes: { nombre: 3, quantite_disponible: 31, disponible: true } })

    expect(calculerEtatStock(riz)).toEqual({ libelle: 'En stock : 31', niveau: 'succes' })
    expect(estEnRupture(riz)).toBe(false)
  })

  it('dit « Épuisé » quand toutes les variantes le sont', () => {
    const riz = produit({ quantite_stock: 50, stock_variantes: { nombre: 3, quantite_disponible: 0, disponible: false } })

    expect(calculerEtatStock(riz).libelle).toBe('Épuisé')
  })

  it('en disponibilité manuelle, suit les variantes et non le drapeau du produit', () => {
    const poulet = produit({
      mode_stock: 'interrupteur',
      disponible: true,
      stock_variantes: { nombre: 2, quantite_disponible: null, disponible: false },
    })

    expect(calculerEtatStock(poulet).libelle).toBe('Épuisé')
  })

  it('un produit sans variante garde ses propres colonnes', () => {
    expect(calculerEtatStock(produit({ quantite_stock: 5, quantite_reservee: 4 })).libelle).toBe('Plus qu’1')
    expect(calculerEtatStock(produit({ mode_stock: 'interrupteur', disponible: false })).libelle).toBe('Épuisé')
  })
})
