<script setup lang="ts">
import type { Lock, Place } from '~/types/pms'

// Administration: which Rocket Place place each lock opens (stored in Rocket Place, proxied by PMS).
definePageMeta({ admin: true })
const api = useApi()
const toast = useToast()
useHead({ title: `Serrures · ${useAppConfig().rocket.name}` })
const { data, refresh } = await useAsyncData('all-locks', () => api<{ demo: boolean, locks: Lock[] }>('/api/locks').catch(() => null))
const { data: places } = await useAsyncData('places', () => api<{ places: Place[] }>('/api/places').catch(() => null))
// The select refuses an empty value: "none" stands for no place
const options = computed(() => [{ label: '— aucun lieu —', value: 'none' }, ...(places.value?.places ?? []).map(p => ({ label: p.name, value: p.id }))])
async function link(lock: Lock, value: string) {
  try {
    await api(`/api/locks/${lock.id}`, { method: 'PUT', body: { place: value === 'none' ? null : value } })
    await refresh()
    toast.add({ title: 'Enregistré', color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Non enregistré', description: apiErrorMessage(error), color: 'error' })
  }
}
</script>

<template>
  <UDashboardPanel id="locks">
    <template #header>
      <UDashboardNavbar title="Serrures">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <p class="text-sm text-muted">Serrures connues de Rocket Place : choisis le lieu que chacune ouvre. Les codes clavier d’un logement lié à ce lieu sont alors prévus pour chaque séjour à venir. Les nouvelles serrures s’ajoutent dans Rocket Place.</p>
      <UCard v-for="l in data?.locks ?? []" :key="l.id">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <b>{{ l.name }}</b>
            <p class="text-sm text-muted">{{ l.state }} · batterie {{ l.battery === null ? 'inconnue' : `${l.battery} %` }}</p>
          </div>
          <USelect :model-value="l.placeId ?? 'none'" :items="options" class="w-64" @update:model-value="link(l, String($event))" />
        </div>
      </UCard>
      <UCard v-if="!data"><p class="text-sm text-muted">Rocket Place ne répond pas pour le moment.</p></UCard>
      <UCard v-else-if="!data.locks.length"><p class="text-sm text-muted">Aucune serrure dans Rocket Place.</p></UCard>
    </template>
  </UDashboardPanel>
</template>
