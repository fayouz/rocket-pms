<script setup lang="ts">
import type { PlanningRun, TimelineEvent } from '~/types/pms'

// Timeline of every property: yesterday and the next 7 days, merged and sorted.
const api = useApi()
useHead({ title: `Timeline · ${useAppConfig().rocket.name}` })
const toast = useToast()
const { isAdmin } = useAuth()
const { data, refresh } = await useAsyncData('timeline', () => api<{ now: string, properties: { id: string, name: string, color: string, events: TimelineEvent[] }[] }>('/api/timeline', { query: { past: 1, future: 7 } }))
const events = computed(() => (data.value?.properties ?? [])
  .flatMap(p => p.events.map(e => ({ ...e, property: p.name, color: p.color, propertyId: p.id })))
  .sort((a, b) => a.at.localeCompare(b.at)))

// The timeline only reads: codes and cleanings are planned every 15 min by the server, or now with this button.
const planning = ref(false)
async function runPlanning() {
  planning.value = true
  try {
    const run = await api<PlanningRun>('/api/planning/run', { method: 'POST' })
    const failed = run.properties.filter(p => !p.ok)
    toast.add(failed.length
      ? { title: 'Planification partielle', description: failed.map(p => `${p.name} : ${p.error}`).join(' · '), color: 'warning' }
      : { title: 'Planification faite', description: `${run.properties.length} logement(s) lié(s) à un lieu`, color: 'success' })
    await refresh()
  }
  catch (e) {
    toast.add({ title: 'Échec de la planification', description: apiErrorMessage(e), color: 'error' })
  }
  finally {
    planning.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="timeline">
    <template #header>
      <UDashboardNavbar title="Timeline · tous les logements">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton v-if="isAdmin" icon="i-lucide-calendar-sync" label="Lancer la planification" :loading="planning" @click="runPlanning" />
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <UCard>
        <p class="mb-4 text-sm text-muted">Hier et les 7 prochains jours : séjours, codes clavier, ménages, passages aux serrures. Codes et ménages sont planifiés toutes les 15 minutes.</p>
        <EventTimeline v-if="data" :events="events" :now="data.now" />
      </UCard>
    </template>
  </UDashboardPanel>
</template>
