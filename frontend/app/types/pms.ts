export interface Property {
  id: string
  name: string
  lodgifyPropertyId: number | null
  lodgifyName: string | null
  color: string
  latitude: number | null
  longitude: number | null
  /** Place of the property in Rocket Place (locks, access grants, domotique, documents, stock); null: not linked. */
  placeId: string | null
}

export interface AccessCode {
  /** Access grant in Rocket Place. */
  grantId: string
  bookingId: number
  lockId: number | null
  code: string
  validFrom: string
  validUntil: string
  status: 'planned' | 'created' | 'error'
  error: string | null
  outdated?: boolean
}

export interface Booking {
  id: number
  propertyId: number
  guest: string
  guestEmail: string | null
  arrival: string
  departure: string
  checkIn: string | null
  checkOut: string | null
  nights: number
  status: string
  source: string
  total: number
  active: boolean
  phase: 'now' | 'next' | 'past' | 'other'
  access: AccessCode | null
}

export interface Pricing {
  currency: string
  total: number
  paid: number
  due: number
  nights: number
  lines: { kind: string, label: string, amount: number }[]
}

export interface Message {
  key: string
  kind: 'lodgify' | 'email'
  from: 'host' | 'guest'
  at: string
  subject: string
  text: string
  status: string
}

export interface LockLog { date: string, who: string, action: number, trigger: number }

export interface PluginField {
  key: string
  label: string
  type: string
  required?: boolean
  secret?: boolean
  help?: string
  placeholder?: string
  options?: { label: string, value: string }[]
}

export interface Plugin {
  id: string
  name: string
  description: string
  icon: string
  category: string
  fields: PluginField[]
  capabilities: string[]
}

export interface Connector {
  id: string
  propertyId: string
  pluginId: string
  name: string
  enabled: boolean
  config: Record<string, string>
  secrets: Record<string, boolean>
  lastRunAt: string | null
  lastResult: string | null
}

export interface DomotiqueSection {
  connectorId: string
  name: string
  pluginId: string
  pluginName: string
  icon: string
  cards: { title: string, icon?: string, items: { label: string, value: string }[] }[]
  error: string | null
}

export interface Lock {
  id: number
  name: string
  state: string
  locked: boolean
  battery: number | null
  batteryCritical: boolean
  keypadBatteryCritical: boolean
  logs: LockLog[]
  placeId: string | null
  place: string | null
  /** Controller providing the live state: 'nuki' (legacy, env token) unless rerouted to a connector (e.g. 'home_assistant', 'homey'). */
  provider: string
}

/** cleaningId: Rocket Place cleaning task of a "cleaning" event (its secret link: GET /api/properties/{id}/cleanings/{cleaningId}/link). */
export interface TimelineEvent { at: string, kind: string, icon: string, title: string, description: string, cleaningId?: string }

/** Secret link without account of a cleaning, for the cleaner (Rocket Place /m/<token>). */
export interface CleaningLink { url: string, path: string, expiresAt: string }

/** Result of POST /api/planning/run, per property linked to a place. */
export interface PlanningRun { ranAt: string, properties: { id: string, name: string, ok: boolean, grants: number, cleanings: number, error: string | null }[] }

export interface Place { id: string, name: string, address: string | null, color: string }

export interface StockItem { id: string, name: string, asin: string | null, reorderQty: number, subscription: boolean }
export interface StockLevel { id: string, place: string, item: string, level: 'ok' | 'low' | 'empty' }

/** Sections of the welcome book (livret d'accueil), all optional; {{guest}} is replaced by the guest's first name. */
export type WelcomeBookSection = 'welcomeText' | 'wifiSsid' | 'wifiPassword' | 'checkinInfo' | 'checkoutInfo' | 'accessDirections' | 'houseRules' | 'contacts' | 'localTips' | 'faq'

/** Languages of the welcome book: French by default, the others optional (section by section, French as fallback). */
export type WelcomeLanguage = 'fr' | 'en' | 'es' | 'de' | 'it'
export type WelcomeLayout = 'tabs' | 'columns'

export interface WelcomeStyle {
  accent: string
  coverUrl: string | null
  coverDocumentRef: string | null
  layout: WelcomeLayout
}

/** Style as seen by the public pages: an https cover URL, or coverPath (public endpoint) for a Rocket Place document. */
export interface PublicWelcomeStyle {
  accent: string
  layout: WelcomeLayout
  coverUrl: string | null
  documentCover: boolean
  coverPath: string | null
}

export interface WelcomeBook {
  content: Record<WelcomeBookSection, string>
  translations: Record<Exclude<WelcomeLanguage, 'fr'>, Partial<Record<WelcomeBookSection, string>>>
  languages: WelcomeLanguage[]
  style: WelcomeStyle
  tvToken: string
  tvPath: string
  tvUrl: string
  updatedAt: string | null
}

export interface GuestLink { token: string, path: string, url: string, message: string, from: string, until: string }

/** Visits of the public pages (no visitor data): per link and per day. */
export interface WelcomeStats {
  days: number
  total: number
  links: { link: 'guest' | 'tv', bookingId: number | null, guest: string | null, total: number, lastDay: string }[]
  daily: { link: 'guest' | 'tv', bookingId: number, day: string, count: number }[]
}

/** Public guest page (/g/:token): first name only, keypad code only once sent to the lock. */
export interface GuestWelcome {
  property: string
  latitude: number | null
  longitude: number | null
  guest: { firstName: string, arrival: string, departure: string, checkIn: string | null, checkOut: string | null }
  access: { code: string, validFrom: string, validUntil: string } | null
  content: Record<WelcomeBookSection, string>
  lang: WelcomeLanguage
  languages: WelcomeLanguage[]
  style: PublicWelcomeStyle
}

/** Public TV screen (/tv/:token). */
export interface TvWelcome {
  property: string
  latitude: number | null
  longitude: number | null
  today: string
  guest: { firstName: string, departure: string, checkOut: string | null } | null
  nextArrival: string | null
  nextArrivalAt: string | null
  reloadAt: string | null
  content: Partial<Record<WelcomeBookSection, string>>
  lang: WelcomeLanguage
  languages: WelcomeLanguage[]
  style: PublicWelcomeStyle
}

/** Conversation of the Rocket Mailer shared inbox linked to a booking (guest e-mail or booking id in the subject). */
export interface EmailConversation {
  id: string
  subject: string
  status: string
  participants: string[]
  snippet: string
  messageCount: number
  lastMessageAt: string
  unread: boolean
  matchedBy: 'guest' | 'booking'
}

export interface BookingEmails {
  demo: boolean
  available: boolean
  reason: string | null
  guestEmail: string | null
  conversations: EmailConversation[]
}

export interface Expense {
  id: string
  date: string
  amount: number
  category: string
  categoryLabel: string
  kind: 'charge' | 'income'
  note: string
  documentRef: string | null
  source: string | null
  externalId: string | null
}

/** Result of a platform statement import (dedup by source + externalId, payouts skipped). */
export interface StatementImport { inserted: number, duplicates: number, skipped: number, invalid: { line: number, reason: string }[] }

export interface CategoryOption { value: string, label: string, kind: 'charge' | 'income' }

export interface Bilan {
  property: { id: string, name: string }
  year: number
  years: number[]
  demo: boolean
  currency: string
  revenue: number
  nights: number
  stays: number
  occupancy: number
  daysConsidered: number
  since: string | null
  months: { label: string, revenue: number, nights: number, charges: number, income: number, result: number }[]
  categories: { key: string, label: string, kind: 'charge' | 'income', total: number, count: number }[]
  chargesTotal: number
  otherIncome: number
  entries: number
  result: number
  items: Expense[]
  categoryOptions: CategoryOption[]
}

/** GET /api/place-links: link of each property with its Rocket Place place, and counts read from Place. */
export type PlaceLinkStatus = 'linked' | 'unlinked' | 'missing' | 'unreachable'
export interface PlaceLinkRow {
  id: string, name: string, color: string, lodgifyPropertyId: number | null, placeId: string | null, placeName: string | null
  status: PlaceLinkStatus, error: string | null, counts: { locks: number, upcomingGrants: number, openCleanings: number, lowStock: number } | null
}
export interface PlaceLinksOverview { demo: boolean, placeFrontUrl: string | null, error: string | null, places: { id: string, name: string }[], properties: PlaceLinkRow[] }
