<script setup lang="ts">
import type { PlaceLinkRow, PlaceLinkStatus, PlaceLinksOverview } from '~/types/pms'

// Administration: every property with its Rocket Place place, the state of the link and what Place holds for it.
definePageMeta({ admin: true })
const api = useApi()
const toast = useToast()
useHead({ title: `Liaisons Place · ${useAppConfig().rocket.name}` })
const { data, refresh, status } = await useAsyncData('place-links', () => api<PlaceLinksOverview>('/api/place-links'))
const STATUS: Record<PlaceLinkStatus, { label: string, color: 'success' | 'neutral' | 'warning' | 'error' }> = {
  linked: { label: 'lié', color: 'success' },
  unlinked: { label: 'non lié', color: 'neutral' },
  missing: { label: 'lieu introuvable dans Place', color: 'warning' },
  unreachable: { label: 'Place injoignable', color: 'error' },
}
// The select refuses an empty value: "none" stands for no place
const options = computed(() => [{ label: '— aucun lieu —', value: 'none' }, ...(data.value?.places ?? []).map(p => ({ label: p.name, value: p.id }))])
const busy = ref<string | null>(null)

async function act(row: PlaceLinkRow, call: () => Promise<unknown>, done: string) {
  busy.value = row.id
  try {
    await call()
    await refresh()
    toast.add({ title: done, color: 'success' })
  }
  catch (e) {
    toast.add({ title: 'Échec', description: apiErrorMessage(e), color: 'error' })
  }
  finally {
    busy.value = null
  }
}
function link(row: PlaceLinkRow, value: string) {
  const placeId = value === 'none' ? null : value
  if (placeId === null && !window.confirm(`Délier « ${row.name} » de son lieu ? Codes et ménages ne seront plus planifiés.`)) return
  return act(row, () => api(`/api/properties/${row.id}/place`, { method: 'PUT', body: { placeId } }), placeId ? 'Logement lié' : 'Logement délié')
}
function create(row: PlaceLinkRow) {
  return act(row, () => api(`/api/properties/${row.id}/place`, { method: 'POST' }), 'Lieu créé et lié')
}
</script>

<template>
  <UDashboardPanel id="place-links">
    <template #header>
      <UDashboardNavbar title="Liaisons Place">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-refresh-cw" variant="ghost" :loading="status === 'pending'" aria-label="Actualiser" @click="() => refresh()" />
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <p class="text-sm text-muted">
        Chaque logement de PMS et le lieu Rocket Place qui porte ses serrures, codes, ménages, documents et stock.
        <span v-if="data?.demo">Rocket Place de démonstration.</span>
      </p>
      <UAlert v-if="data?.error" color="error" variant="subtle" icon="i-lucide-cloud-off" title="Rocket Place injoignable" :description="data.error" />
      <UCard v-for="row in data?.properties ?? []" :key="row.id">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0 space-y-1">
            <div class="flex items-center gap-2">
              <PropertyDot :color="row.color" />
              <b>{{ row.name }}</b>
              <UBadge :color="STATUS[row.status].color" variant="subtle" :label="STATUS[row.status].label" />
            </div>
            <p class="text-sm text-muted">Lodgify : {{ row.lodgifyPropertyId ?? '—' }}</p>
            <p v-if="row.placeId" class="text-sm text-muted">
              Lieu :
              <ULink v-if="data?.placeFrontUrl" :to="`${data.placeFrontUrl}/places/${row.placeId}`" target="_blank" class="text-primary">{{ row.placeName || row.placeId }}</ULink>
              <span v-else>{{ row.placeName || '—' }}</span>
              <code class="ms-1 text-xs">{{ row.placeId }}</code>
            </p>
            <p v-if="row.counts" class="text-sm">
              {{ row.counts.locks }} serrure(s) · {{ row.counts.upcomingGrants }} accès à venir · {{ row.counts.openCleanings }} ménage(s) ouvert(s) · {{ row.counts.lowStock }} stock bas
            </p>
            <p v-if="row.error" class="text-sm text-error">{{ row.error }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <USelect v-if="data?.places.length" :model-value="row.placeId && row.status !== 'missing' ? row.placeId : 'none'" :items="options" :disabled="busy === row.id" class="w-64" @update:model-value="link(row, String($event))" />
            <UButton v-if="row.status === 'missing'" icon="i-lucide-unlink" color="warning" variant="outline" label="Délier" :loading="busy === row.id" @click="link(row, 'none')" />
            <UButton v-if="!row.placeId" icon="i-lucide-map-pin-plus" variant="outline" label="Créer le lieu depuis ce logement" :loading="busy === row.id" @click="create(row)" />
          </div>
        </div>
      </UCard>
      <UCard v-if="data && !data.properties.length"><p class="text-sm text-muted">Aucun logement : synchronise-les depuis Lodgify.</p></UCard>
    </template>
  </UDashboardPanel>
</template>
