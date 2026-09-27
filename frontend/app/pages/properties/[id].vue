<script setup lang="ts">
import type { Property, TimelineEvent } from '~/types/pms'

// A property: bookings (inbox, conversation, value, lock), smart locks and keypad codes, timeline.
const route = useRoute()
const api = useApi()
const id = computed(() => String(route.params.id))
const { data: property } = await useAsyncData(`property-${id.value}`, () => api<Property>(`/api/properties/${id.value}`))
useHead({ title: () => `${property.value?.name ?? 'Logement'} · ${useAppConfig().rocket.name}` })

const tabs = [
  { label: 'Réservations', value: 'bookings', icon: 'i-lucide-calendar-days' },
  { label: 'Serrures', value: 'locks', icon: 'i-lucide-lock' },
  { label: 'Domotique', value: 'domotique', icon: 'i-lucide-house-wifi' },
  { label: 'Documents', value: 'documents', icon: 'i-lucide-folder' },
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
      <LocksInbox v-else-if="tab === 'locks'" :property-id="id" />
      <DomotiqueTab v-else-if="tab === 'domotique'" :property-id="id" />
      <DocumentsTab v-else-if="tab === 'documents'" :property-id="id" />
      <UCard v-else>
        <p class="mb-4 text-sm text-muted">Les 3 derniers jours et les 45 prochains : séjours, codes clavier, passages à la serrure.</p>
        <EventTimeline v-if="timeline" :events="timeline.events" :now="timeline.now" />
        <p v-else class="text-sm text-muted">Chargement…</p>
      </UCard>
    </template>
  </UDashboardPanel>
</template>
