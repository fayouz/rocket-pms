import { FetchError } from 'ofetch'

/** Message of an API error (API Platform "detail", Symfony "detail" or HttpException message). */
export function apiErrorMessage(error: unknown): string {
  if (error instanceof FetchError) {
    const data = error.data as { detail?: string, message?: string, 'hydra:description'?: string } | undefined
    return data?.detail || data?.['hydra:description'] || data?.message || error.statusMessage || error.message
  }
  return error instanceof Error ? error.message : String(error)
}

/** Colour marker of a property (hex, so the class names do not have to be known at build time). */
export const PROPERTY_COLORS: { value: string, label: string, hex: string }[] = [
  { value: '', label: 'Aucune', hex: '' },
  { value: 'red', label: 'Rouge', hex: '#ef4444' },
  { value: 'orange', label: 'Orange', hex: '#f97316' },
  { value: 'amber', label: 'Ambre', hex: '#f59e0b' },
  { value: 'green', label: 'Vert', hex: '#22c55e' },
  { value: 'teal', label: 'Sarcelle', hex: '#14b8a6' },
  { value: 'blue', label: 'Bleu', hex: '#3b82f6' },
  { value: 'violet', label: 'Violet', hex: '#8b5cf6' },
  { value: 'pink', label: 'Rose', hex: '#ec4899' },
]
export const colorHex = (color?: string | null) => PROPERTY_COLORS.find(c => c.value === color)?.hex || null

/** Platform of a booking (Lodgify source): logo only for Airbnb and Booking.com. */
export function platformIcon(source: string): { name: string, color: string } | null {
  const s = source.toLowerCase()
  if (s.includes('airbnb')) return { name: 'i-simple-icons-airbnb', color: '#FF5A5F' }
  if (s.includes('booking')) return { name: 'i-simple-icons-bookingdotcom', color: '#0057B8' }
  return null
}

export const dayFr = (d: string) => new Date(d).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' })
export const whenFr = (d: string) => new Date(d).toLocaleString('fr-FR', { timeZone: 'Europe/Paris', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
export const money = (n: number, currency = 'EUR') => n.toLocaleString('fr-FR', { style: 'currency', currency, maximumFractionDigits: 2 })
export const LOCK_ACTIONS: Record<number, string> = { 1: 'Déverrouillage', 2: 'Verrouillage', 3: 'Ouverture (pêne)', 4: 'Lock’n’Go', 5: 'Lock’n’Go + ouverture' }
export const batteryIcon = (b: number | null) => b === null ? 'i-lucide-battery' : b <= 20 ? 'i-lucide-battery-low' : b <= 60 ? 'i-lucide-battery-medium' : 'i-lucide-battery-full'

/** Labels of the welcome book sections, in display order (backend App\Entity\WelcomeBook::SECTIONS). */
export const WELCOME_SECTIONS: { key: import('~/types/pms').WelcomeBookSection, label: string, icon: string, multiline: boolean }[] = [
  { key: 'welcomeText', label: 'Mot de bienvenue', icon: 'i-lucide-hand-heart', multiline: true },
  { key: 'wifiSsid', label: 'Wi-Fi : réseau', icon: 'i-lucide-wifi', multiline: false },
  { key: 'wifiPassword', label: 'Wi-Fi : mot de passe', icon: 'i-lucide-key-round', multiline: false },
  { key: 'checkinInfo', label: 'Arrivée', icon: 'i-lucide-log-in', multiline: true },
  { key: 'checkoutInfo', label: 'Départ', icon: 'i-lucide-log-out', multiline: true },
  { key: 'accessDirections', label: 'Accès au logement', icon: 'i-lucide-map-pin', multiline: true },
  { key: 'houseRules', label: 'Règlement intérieur', icon: 'i-lucide-scroll-text', multiline: true },
  { key: 'contacts', label: 'Contacts', icon: 'i-lucide-phone', multiline: true },
  { key: 'localTips', label: 'Bonnes adresses', icon: 'i-lucide-map', multiline: true },
  { key: 'faq', label: 'Questions fréquentes', icon: 'i-lucide-circle-help', multiline: true },
]

/** Public page of the API (no credentials): the welcome book and TV screen. */
export function publicApi<T>(path: string): Promise<T> {
  return $fetch<T>(path, { baseURL: useRuntimeConfig().public.apiBase as string, headers: { Accept: 'application/json' } })
}

/** Current weather from Open-Meteo, fetched by the browser (no key, nothing personal sent); null when unavailable. */
export async function fetchWeather(latitude: number | null, longitude: number | null): Promise<{ temperature: number, code: number } | null> {
  if (latitude === null || longitude === null) return null
  try {
    const r = await $fetch<{ current?: { temperature_2m: number, weather_code: number } }>('https://api.open-meteo.com/v1/forecast', { query: { latitude, longitude, current: 'temperature_2m,weather_code' } })
    return r.current ? { temperature: Math.round(r.current.temperature_2m), code: r.current.weather_code } : null
  }
  catch {
    return null
  }
}

export function weatherIcon(code: number): string {
  if (code === 0) return 'i-lucide-sun'
  if (code <= 3) return 'i-lucide-cloud-sun'
  if (code <= 48) return 'i-lucide-cloud-fog'
  if (code <= 67 || (code >= 80 && code <= 82)) return 'i-lucide-cloud-rain'
  if (code <= 77 || code === 85 || code === 86) return 'i-lucide-snowflake'
  return 'i-lucide-cloud-lightning'
}

export const frenchDate = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
