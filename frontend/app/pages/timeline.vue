<script setup lang="ts">
import type { TimelineEvent } from '~/types/pms'

// Timeline of every property: yesterday and the next 7 days, merged and sorted.
const api = useApi()
useHead({ title: `Timeline · ${useAppConfig().rocket.name}` })
const { data } = await useAsyncData('timeline', () => api<{ now: string, properties: { id: string, name: string, color: string, events: TimelineEvent[] }[] }>('/api/timeline', { query: { past: 1, future: 7 } }))
const events = computed(() => (data.value?.properties ?? [])
  .flatMap(p => p.events.map(e => ({ ...e, property: p.name, color: p.color })))
  .sort((a, b) => a.at.localeCompare(b.at)))
</script>

<template>
  <UDashboardPanel id="timeline">
    <template #header>
      <UDashboardNavbar title="Timeline · tous les logements">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <UCard>
        <p class="mb-4 text-sm text-muted">Hier et les 7 prochains jours : séjours, codes clavier, passages aux serrures.</p>
        <EventTimeline v-if="data" :events="events" :now="data.now" />
      </UCard>
    </template>
  </UDashboardPanel>
</template>
