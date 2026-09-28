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
  kind: 'lodgify'
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

export interface TimelineEvent { at: string, kind: string, icon: string, title: string, description: string }

export interface Place { id: string, name: string, address: string | null, color: string }

export interface StockItem { id: string, name: string, asin: string | null, reorderQty: number, subscription: boolean }
export interface StockLevel { id: string, place: string, item: string, level: 'ok' | 'low' | 'empty' }
