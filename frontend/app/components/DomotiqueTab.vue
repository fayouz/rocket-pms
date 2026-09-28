<script setup lang="ts">
import type { DomotiqueSection } from '~/types/pms'

// "Domotique" tab of a property: read-only info cards of the connectors of its place in Rocket Place (proxied by PMS).
// Connectors themselves (Homey, Home Assistant, web services...) are configured in Rocket Place.
const props = defineProps<{ propertyId: string }>()
const api = useApi()
const error = ref<string | null>(null)
const { data: domotique } = await useAsyncData(`domotique-${props.propertyId}`, () => api<{ sections: DomotiqueSection[] }>(`/api/properties/${props.propertyId}/domotique`).catch((e) => {
  error.value = apiErrorMessage(e)
  return null
}))
</script>

<template>
  <div class="space-y-4">
    <UAlert v-if="error" color="warning" variant="subtle" icon="i-lucide-map-pin-off" :description="error" />
    <UCard v-for="s in domotique?.sections ?? []" :key="s.connectorId">
      <template #header>
        <div class="flex items-center gap-2">
          <UIcon :name="s.icon" class="size-5" />
          <b>{{ s.name }}</b>
          <span class="text-sm text-muted">({{ s.pluginName }})</span>
        </div>
      </template>
      <p v-if="s.error" class="text-sm text-error">{{ s.error }}</p>
      <p v-else-if="!s.cards.length" class="text-sm text-muted">Aucune information disponible.</p>
      <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <UCard v-for="card in s.cards" :key="card.title" variant="subtle">
          <template #header>
            <span class="flex items-center gap-1.5 text-sm font-medium"><UIcon v-if="card.icon" :name="card.icon" /> {{ card.title }}</span>
          </template>
          <dl class="space-y-1 text-sm">
            <div v-for="item in card.items" :key="item.label" class="flex justify-between gap-2">
              <dt class="text-muted">{{ item.label }}</dt>
              <dd class="font-medium">{{ item.value }}</dd>
            </div>
          </dl>
        </UCard>
      </div>
    </UCard>
    <p v-if="domotique && !domotique.sections.length" class="text-sm text-muted">Aucun connecteur domotique sur ce lieu (ils se configurent dans Rocket Place).</p>

  </div>
</template>
