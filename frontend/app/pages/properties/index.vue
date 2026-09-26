<script setup lang="ts">
import type { Property } from '~/types/pms'

const api = useApi()
const toast = useToast()
const { isAdmin } = useAuth()
useHead({ title: `Logements · ${useAppConfig().rocket.name}` })

const { data: properties, refresh } = await useAsyncData('properties', () => api<Property[]>('/api/properties'), { default: () => [] })

// Creates a property for every Lodgify property not linked yet, and registers the Nuki locks
const syncing = ref(false)
async function sync() {
  syncing.value = true
  try {
    const r = await api<{ created: number, locks: number }>('/api/properties/sync', { method: 'POST', body: {} })
    toast.add({ title: 'Synchronisation terminée', description: `${r.created} logement(s) et ${r.locks} serrure(s) ajoutés.`, color: 'success' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Synchronisation impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    syncing.value = false
  }
}

async function setColor(property: Property, color: string) {
  try {
    await api(`/api/properties/${property.id}`, { method: 'PATCH', body: { color } })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Couleur non enregistrée', description: apiErrorMessage(error), color: 'error' })
  }
}
</script>

<template>
  <UDashboardPanel id="properties">
    <template #header>
      <UDashboardNavbar title="Logements">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton v-if="isAdmin" icon="i-lucide-refresh-cw" label="Synchroniser Lodgify et Nuki" color="neutral" variant="outline" :loading="syncing" @click="sync" />
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <UCard v-for="p in properties" :key="p.id">
          <NuxtLink :to="`/properties/${p.id}`" class="flex items-center gap-2 font-semibold hover:underline">
            <PropertyDot :color="p.color" />
            {{ p.name }}
          </NuxtLink>
          <p class="mt-1 text-sm text-muted">{{ p.lodgifyName ? `Lodgify : ${p.lodgifyName}` : 'Non associé à Lodgify' }}</p>
          <div v-if="isAdmin" class="mt-3 flex items-center gap-1.5">
            <span class="mr-1 text-xs text-muted">Couleur :</span>
            <button
              v-for="c in PROPERTY_COLORS" :key="c.value" type="button" :title="c.label"
              class="flex size-5 items-center justify-center rounded-full border border-default ring-2 ring-offset-1 ring-offset-default"
              :class="p.color === c.value ? 'ring-primary' : 'ring-transparent'" :style="{ backgroundColor: c.hex || 'transparent' }"
              @click="setColor(p, c.value)"
            >
              <UIcon v-if="!c.value" name="i-lucide-ban" class="size-3 text-muted" />
            </button>
          </div>
        </UCard>
      </div>
      <UCard v-if="!properties.length">
        <p class="text-sm text-muted">Aucun logement. {{ isAdmin ? '« Synchroniser Lodgify et Nuki » crée un logement pour chaque logement Lodgify.' : 'Un administrateur doit d’abord synchroniser Lodgify.' }}</p>
      </UCard>
    </template>
  </UDashboardPanel>
</template>
