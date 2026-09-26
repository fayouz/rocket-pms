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
