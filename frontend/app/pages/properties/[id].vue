<script setup lang="ts">
import type { Property, TimelineEvent } from '~/types/pms'

// A property: bookings (inbox, conversation, value, lock), its place in Rocket Place (locks and keypad codes,
// domotique, documents, stock, all proxied by PMS), welcome book, bilan, timeline.
const route = useRoute()
const api = useApi()
const id = computed(() => String(route.params.id))
const { data: property, refresh: refreshProperty } = await useAsyncData(`property-${id.value}`, () => api<Property>(`/api/properties/${id.value}`))
useHead({ title: () => `${property.value?.name ?? 'Logement'} · ${useAppConfig().rocket.name}` })

const tabs = [
  { label: 'Réservations', value: 'bookings', icon: 'i-lucide-calendar-days' },
  { label: 'Infos', value: 'infos', icon: 'i-lucide-info' },
  { label: 'Serrures', value: 'locks', icon: 'i-lucide-lock' },
  { label: 'Domotique', value: 'domotique', icon: 'i-lucide-house-wifi' },
  { label: 'Documents', value: 'documents', icon: 'i-lucide-folder' },
  { label: 'Stock', value: 'stock', icon: 'i-lucide-package' },
  { label: 'Livret & TV', value: 'livret', icon: 'i-lucide-book-open' },
  { label: 'Bilan', value: 'bilan', icon: 'i-lucide-chart-column' },
  { label: 'Timeline', value: 'timeline', icon: 'i-lucide-git-commit-vertical' },
]
const tab = computed({
  get: () => tabs.some(t => t.value === route.query.tab) ? String(route.query.tab) : 'bookings',
  set: (v: string) => navigateTo({ query: { ...route.query, tab: v === 'bookings' ? undefined : v } }, { replace: true }),
})
const { data: timeline } = await useAsyncData(`property-timeline-${id.value}`, () => api<{ now: string, events: TimelineEvent[] }>(`/api/properties/${id.value}/timeline`), { lazy: true })
</script>

<template>
  <UDashboardPanel id="property">
    <template #header>
      <UDashboardNavbar>
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #title>
          <span class="flex items-center gap-2"><PropertyDot :color="property?.color" /> {{ property?.name }}</span>
        </template>
        <template #right>
          <span class="hidden text-sm text-muted sm:inline">{{ property?.lodgifyName ? `Lodgify : ${property.lodgifyName}` : 'Non associé à Lodgify' }}</span>
        </template>
      </UDashboardNavbar>
      <UDashboardToolbar>
        <UTabs v-model="tab" :items="tabs" :content="false" variant="link" />
      </UDashboardToolbar>
    </template>
    <template #body>
      <BookingsInbox v-if="tab === 'bookings'" :property-id="id" />
      <PropertyInfoTab v-else-if="tab === 'infos' && property" :key="property.placeId ?? 'none'" :property="property" @changed="refreshProperty" />
      <LocksInbox v-else-if="tab === 'locks'" :key="`locks-${property?.placeId}`" :property-id="id" />
      <DomotiqueTab v-else-if="tab === 'domotique'" :key="`domotique-${property?.placeId}`" :property-id="id" />
      <DocumentsTab v-else-if="tab === 'documents'" :property-id="id" :place-id="property?.placeId ?? null" />
      <StockTab v-else-if="tab === 'stock'" :key="`stock-${property?.placeId}`" :property-id="id" />
      <WelcomeBookTab v-else-if="tab === 'livret'" :property-id="id" :place-id="property?.placeId ?? null" />
      <BilanTab v-else-if="tab === 'bilan'" :property-id="id" :place-id="property?.placeId ?? null" />
      <UCard v-else>
        <p class="mb-4 text-sm text-muted">Les 3 derniers jours et les 45 prochains : séjours, codes clavier, passages à la serrure.</p>
        <EventTimeline v-if="timeline" :events="timeline.events" :now="timeline.now" :property-id="id" />
        <p v-else class="text-sm text-muted">Chargement…</p>
      </UCard>
    </template>
  </UDashboardPanel>
</template>
