import { renderSVG } from 'uqr'
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

/** QR code of a link as an SVG string (uqr, pure TS, computed in the browser: the link never leaves it). */
export function qrSvg(text: string): string {
  return renderSVG(text, { ecc: 'M', pixelSize: 6, border: 2 })
}

/** Languages of the welcome book (backend App\Entity\WelcomeBook::LANGUAGES), French first (default). */
export const WELCOME_LANGUAGES: { value: import('~/types/pms').WelcomeLanguage, label: string, flag: string }[] = [
  { value: 'fr', label: 'Français', flag: 'FR' },
  { value: 'en', label: 'English', flag: 'EN' },
  { value: 'es', label: 'Español', flag: 'ES' },
  { value: 'de', label: 'Deutsch', flag: 'DE' },
  { value: 'it', label: 'Italiano', flag: 'IT' },
]

type WelcomeUi = { welcome: string, code: string, valid: (from: string, until: string) => string, stay: (from: string, until: string) => string, wifi: string, network: string, password: string, checkout: string, nextArrival: string, locale: string, sections: Record<import('~/types/pms').WelcomeBookSection, string> }

/** Texts of the public pages in each language of the book (the content itself is written by the host). */
export const WELCOME_UI: Record<import('~/types/pms').WelcomeLanguage, WelcomeUi> = {
  fr: { welcome: 'Bienvenue', code: 'Code de la porte', valid: (f, u) => `Valable du ${f} au ${u}`, stay: (f, u) => `Du ${f} au ${u}`, wifi: 'Wi-Fi', network: 'Réseau', password: 'Mot de passe', checkout: 'Départ', nextArrival: 'Prochaine arrivée', locale: 'fr-FR',
    sections: { welcomeText: 'Mot de bienvenue', wifiSsid: 'Wi-Fi', wifiPassword: 'Mot de passe', checkinInfo: 'Arrivée', checkoutInfo: 'Départ', accessDirections: 'Accès au logement', houseRules: 'Règlement intérieur', contacts: 'Contacts', localTips: 'Bonnes adresses', faq: 'Questions fréquentes' } },
  en: { welcome: 'Welcome', code: 'Door code', valid: (f, u) => `Valid from ${f} to ${u}`, stay: (f, u) => `From ${f} to ${u}`, wifi: 'Wi-Fi', network: 'Network', password: 'Password', checkout: 'Check-out', nextArrival: 'Next arrival', locale: 'en-GB',
    sections: { welcomeText: 'Welcome', wifiSsid: 'Wi-Fi', wifiPassword: 'Password', checkinInfo: 'Check-in', checkoutInfo: 'Check-out', accessDirections: 'Getting there', houseRules: 'House rules', contacts: 'Contacts', localTips: 'Local tips', faq: 'FAQ' } },
  es: { welcome: 'Bienvenido', code: 'Código de la puerta', valid: (f, u) => `Válido del ${f} al ${u}`, stay: (f, u) => `Del ${f} al ${u}`, wifi: 'Wi-Fi', network: 'Red', password: 'Contraseña', checkout: 'Salida', nextArrival: 'Próxima llegada', locale: 'es-ES',
    sections: { welcomeText: 'Bienvenida', wifiSsid: 'Wi-Fi', wifiPassword: 'Contraseña', checkinInfo: 'Llegada', checkoutInfo: 'Salida', accessDirections: 'Cómo llegar', houseRules: 'Normas de la casa', contacts: 'Contactos', localTips: 'Recomendaciones', faq: 'Preguntas frecuentes' } },
  de: { welcome: 'Willkommen', code: 'Türcode', valid: (f, u) => `Gültig von ${f} bis ${u}`, stay: (f, u) => `Vom ${f} bis ${u}`, wifi: 'WLAN', network: 'Netzwerk', password: 'Passwort', checkout: 'Abreise', nextArrival: 'Nächste Anreise', locale: 'de-DE',
    sections: { welcomeText: 'Willkommen', wifiSsid: 'WLAN', wifiPassword: 'Passwort', checkinInfo: 'Anreise', checkoutInfo: 'Abreise', accessDirections: 'Anfahrt', houseRules: 'Hausordnung', contacts: 'Kontakte', localTips: 'Tipps', faq: 'Häufige Fragen' } },
  it: { welcome: 'Benvenuto', code: 'Codice della porta', valid: (f, u) => `Valido dal ${f} al ${u}`, stay: (f, u) => `Dal ${f} al ${u}`, wifi: 'Wi-Fi', network: 'Rete', password: 'Password', checkout: 'Partenza', nextArrival: 'Prossimo arrivo', locale: 'it-IT',
    sections: { welcomeText: 'Benvenuto', wifiSsid: 'Wi-Fi', wifiPassword: 'Password', checkinInfo: 'Arrivo', checkoutInfo: 'Partenza', accessDirections: 'Come arrivare', houseRules: 'Regole della casa', contacts: 'Contatti', localTips: 'Consigli', faq: 'Domande frequenti' } },
}

/** Local date (Y-m-d) in a language, e.g. "lundi 3 mars". */
export const localDate = (ymd: string, locale: string) => new Date(`${ymd}T12:00:00`).toLocaleDateString(locale, { weekday: 'long', day: 'numeric', month: 'long' })

/** Accent colour as a CSS custom property of the public pages (validated #rrggbb by the backend). */
export const accentStyle = (accent: string | undefined) => ({ '--pms-accent': /^#[0-9a-f]{6}$/i.test(accent ?? '') ? accent : '#0f766e' })

/** Absolute URL of the cover image of a public page (https URL, or the public cover endpoint of the API). */
export function coverSrc(style: import('~/types/pms').PublicWelcomeStyle | undefined): string | null {
  if (!style) return null
  if (style.coverPath) return `${useRuntimeConfig().public.apiBase as string}${style.coverPath}`
  return style.coverUrl && style.coverUrl.startsWith('https://') ? style.coverUrl : null
}
