import { createContext, useContext } from 'react'

export type BrandKey = 'sponge-kids' | 'mnsa-safe' | 'mnsa-straight'

export interface BrandConfig {
  key: BrandKey
  name: string
  tagline: string
  hasSearch: boolean
  hasReports: boolean
  hasExtensionPage: boolean
  postAuthRoute: string
}

const brands: Record<BrandKey, BrandConfig> = {
  'sponge-kids': {
    key: 'sponge-kids',
    name: 'Sponge Kids',
    tagline: 'Their brain is a sponge. Make sure they soak up the good stuff.',
    hasSearch: true,
    hasReports: true,
    hasExtensionPage: false,
    postAuthRoute: '/home',
  },
  'mnsa-safe': {
    key: 'mnsa-safe',
    name: 'Make Netflix Safe Again',
    tagline: 'Take back control of your screen.',
    hasSearch: false,
    hasReports: false,
    hasExtensionPage: true,
    postAuthRoute: '/extension',
  },
  'mnsa-straight': {
    key: 'mnsa-straight',
    name: 'Make Netflix Straight Again',
    tagline: 'Take back control of your screen.',
    hasSearch: false,
    hasReports: false,
    hasExtensionPage: true,
    postAuthRoute: '/extension',
  },
}

const domainMap: Record<string, BrandKey> = {
  'sponge.kids': 'sponge-kids',
  'makenetflixsafeagain.com': 'mnsa-safe',
  'makenetflixstraightagain.com': 'mnsa-straight',
}

export function detectBrand(): BrandConfig {
  const host = window.location.hostname
  // Allow ?brand= override in dev OR on any host not mapped to a production
  // brand domain (Railway previews, staging URLs, etc.) — persist in
  // sessionStorage so it survives navigation.
  const allowOverride = import.meta.env.DEV || !(host in domainMap)
  if (allowOverride) {
    const params = new URLSearchParams(window.location.search)
    const override = params.get('brand') as BrandKey | null
    if (override && brands[override]) {
      sessionStorage.setItem('dev_brand', override)
      return brands[override]
    }
    const stored = sessionStorage.getItem('dev_brand') as BrandKey | null
    if (stored && brands[stored]) {
      return brands[stored]
    }
  }

  const brandKey = domainMap[host] ?? 'sponge-kids'
  return brands[brandKey]
}

export function isMnsa(key: BrandKey): boolean {
  return key === 'mnsa-safe' || key === 'mnsa-straight'
}

export const BrandContext = createContext<BrandConfig>(brands['sponge-kids'])

export function useBrand(): BrandConfig {
  return useContext(BrandContext)
}
