<script setup lang="ts">
import type { AccessCode, Lock } from '~/types/pms'

type CodeItem = AccessCode & { guest: string, source: string, arrival: string, departure: string }
// Locks of a property on the left; on the right the chosen lock: state and battery, keypad codes of the upcoming stays
// ("Créer sur Nuki", always confirmed) and its latest events.
const props = defineProps<{ propertyId: string }>()
const api = useApi()
const toast = useToast()
const { data: locks } = await useAsyncData(`locks-${props.propertyId}`, () => api<{ demo: boolean, locks: Lock[] }>(`/api/properties/${props.propertyId}/locks`).catch(() => null))
const { data: codes, refresh: refreshCodes } = await useAsyncData(`codes-${props.propertyId}`, () => api<{ demo: boolean, items: CodeItem[] }>(`/api/properties/${props.propertyId}/codes`))

const selected = ref<number | null>(null)
watchEffect(() => {
  const list = locks.value?.locks ?? []
  if (!list.some(l => l.id === selected.value)) selected.value = list[0]?.id ?? null
})
const current = computed(() => locks.value?.locks.find(l => l.id === selected.value) ?? null)
const codesOf = (lockId: number) => (codes.value?.items ?? []).filter(i => i.lockId === lockId)

const busy = ref<number | null>(null)
async function send(i: CodeItem) {
  if (!confirm(`Créer le code ${i.code} sur la serrure pour ${i.guest} (${dayFr(i.arrival)} → ${dayFr(i.departure)}) ?`)) return
  busy.value = i.bookingId
  try {
    await api(`/api/codes/${i.bookingId}`, { method: 'POST' })
  }
  catch (error) {
    toast.add({ title: 'Code non créé', description: apiErrorMessage(error), color: 'error' })
  }
  busy.value = null
  await refreshCodes()
}
</script>

<template>
  <div class="flex flex-col gap-4 lg:h-[calc(100vh-12rem)] lg:flex-row">
    <div class="min-w-0 space-y-1 overflow-y-auto lg:w-80 lg:shrink-0 lg:border-r lg:border-default lg:pr-3">
      <button
        v-for="l in locks?.locks ?? []" :key="l.id" type="button" class="block w-full rounded-md p-2.5 text-left transition-colors"
        :class="selected === l.id ? 'bg-primary/10 ring-1 ring-primary/30' : 'hover:bg-elevated'" @click="selected = l.id"
      >
        <div class="flex items-center justify-between gap-2">
          <b class="truncate text-sm">{{ l.name }}</b>
          <UBadge size="sm" :color="l.locked ? 'success' : 'warning'" variant="subtle" :label="l.state" />
        </div>
        <p class="text-xs text-muted">
          <UBadge v-if="l.provider !== 'nuki'" size="sm" variant="subtle" color="neutral" :label="l.provider" class="mr-1" />
          Batterie {{ l.battery === null ? 'inconnue' : `${l.battery} %` }} · {{ codesOf(l.id).length }} code{{ codesOf(l.id).length > 1 ? 's' : '' }} à venir
          <span v-if="l.batteryCritical || l.keypadBatteryCritical" class="text-error"> · ⚠</span>
        </p>
      </button>
      <p v-if="!locks" class="text-sm text-muted">Nuki ne répond pas pour le moment.</p>
      <p v-else-if="!locks.locks.length" class="text-sm text-muted">Aucune serrure liée à ce logement (Administration → Serrures Nuki).</p>
    </div>

    <div v-if="current" class="grid min-w-0 flex-1 gap-4 lg:grid-cols-3 lg:grid-rows-[minmax(0,1fr)]">
      <UCard class="lg:col-span-2" :ui="{ root: 'flex flex-col lg:min-h-0', body: 'min-h-0 flex-1 space-y-3 overflow-y-auto' }">
        <template #header>
          <div class="flex flex-wrap items-center gap-2">
            <h3 class="text-lg font-semibold">{{ current.name }}</h3>
            <UBadge :color="current.locked ? 'success' : 'warning'" variant="subtle" :label="current.state" />
            <UBadge :color="current.batteryCritical ? 'error' : 'neutral'" variant="subtle" :icon="batteryIcon(current.battery)" :label="current.battery === null ? '?' : `${current.battery} %`" />
            <UBadge v-if="current.keypadBatteryCritical" color="error" variant="subtle" label="Pile clavier faible" />
          </div>
          <p class="mt-1 text-sm text-muted">Codes clavier : chacun s’ouvre 1 h avant le check-in et se ferme 1 h après le check-out (horaires Lodgify). Rien n’est envoyé à Nuki avant ton clic.</p>
        </template>
        <div v-for="i in codesOf(current.id)" :key="i.bookingId" class="rounded-md border border-default p-3" :class="{ 'border-l-4 border-l-error': i.status === 'error' || i.outdated }">
          <div class="flex justify-between gap-3"><b>{{ i.guest }}</b><PlatformBadge :source="i.source" /></div>
          <p class="text-sm text-muted">{{ dayFr(i.arrival) }} → {{ dayFr(i.departure) }}</p>
          <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
            <span><span class="font-mono text-lg font-semibold tracking-widest">{{ i.code }}</span><span class="text-sm text-muted"> · {{ whenFr(i.validFrom) }} → {{ whenFr(i.validUntil) }}</span></span>
            <UBadge v-if="i.status === 'created'" color="success" variant="subtle" label="Créé sur Nuki" />
            <UButton v-else size="sm" icon="i-lucide-key-round" label="Créer sur Nuki" :loading="busy === i.bookingId" :disabled="codes?.demo" @click="send(i)" />
          </div>
          <p v-if="i.outdated" class="text-sm text-warning">⚠ Dates modifiées depuis la création : à refaire à la main dans Nuki.</p>
          <p v-if="i.error && i.status === 'error'" class="text-sm text-error">⚠ {{ i.error }}</p>
        </div>
        <p v-if="!codesOf(current.id).length" class="text-sm text-muted">Aucune réservation à venir sur cette serrure.</p>
      </UCard>
      <UCard class="lg:col-span-1" :ui="{ root: 'flex flex-col lg:min-h-0', body: 'min-h-0 flex-1 overflow-y-auto' }">
        <template #header><p class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted"><UIcon name="i-lucide-history" class="size-3.5" /> Historique</p></template>
        <p v-for="(g, i) in current.logs" :key="i" class="text-sm text-muted">{{ whenFr(g.date) }} · {{ LOCK_ACTIONS[g.action] || `Action ${g.action}` }}<template v-if="g.who"> · {{ g.who }}</template></p>
        <p v-if="!current.logs.length" class="text-sm text-muted">Aucun passage récent.</p>
      </UCard>
    </div>
  </div>
</template>
