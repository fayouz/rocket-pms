<script setup lang="ts">
import type { StockItem, StockLevel } from '~/types/pms'

// "Stock" tab of a property: consumables of its place in Rocket Place (catalogue items and this place's levels).
const props = defineProps<{ propertyId: string }>()
const api = useApi()
const toast = useToast()
const error = ref<string | null>(null)
const { data, refresh } = await useAsyncData(`stock-${props.propertyId}`, () => api<{ items: StockItem[], levels: StockLevel[] }>(`/api/properties/${props.propertyId}/stock`).catch((e) => {
  error.value = apiErrorMessage(e)
  return null
}))
const LEVELS = [{ label: 'OK', value: 'ok' }, { label: 'Bas', value: 'low' }, { label: 'Vide', value: 'empty' }]
const itemOf = (iri: string) => data.value?.items.find(i => iri.endsWith(`/${i.id}`))
const rows = computed(() => (data.value?.levels ?? []).map(l => ({ level: l, item: itemOf(l.item) })).sort((a, b) => (a.item?.name ?? '').localeCompare(b.item?.name ?? '')))

async function setLevel(level: StockLevel, value: string) {
  try {
    await api(`/api/properties/${props.propertyId}/stock/${level.id}`, { method: 'PATCH', body: { level: value } })
    await refresh()
  }
  catch (e) {
    toast.add({ title: 'Niveau non enregistré', description: apiErrorMessage(e), color: 'error' })
  }
}
</script>

<template>
  <div class="space-y-4">
    <UAlert v-if="error" color="warning" variant="subtle" icon="i-lucide-map-pin-off" :description="error" />
    <UCard v-else>
      <template #header><b>Stock du logement</b></template>
      <div class="divide-y divide-default">
        <div v-for="r in rows" :key="r.level.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
          <div>
            <b class="text-sm">{{ r.item?.name ?? r.level.item }}</b>
            <p v-if="r.item" class="text-xs text-muted">Réassort : {{ r.item.reorderQty }}<template v-if="r.item.subscription"> · abonnement</template></p>
          </div>
          <USelect :model-value="r.level.level" :items="LEVELS" class="w-32" :color="r.level.level === 'ok' ? 'neutral' : r.level.level === 'low' ? 'warning' : 'error'" @update:model-value="setLevel(r.level, String($event))" />
        </div>
        <p v-if="data && !rows.length" class="py-2 text-sm text-muted">Aucun article suivi sur ce lieu (le catalogue se gère dans Rocket Place).</p>
      </div>
    </UCard>
  </div>
</template>
