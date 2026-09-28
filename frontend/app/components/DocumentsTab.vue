<script setup lang="ts">
import type { ExplorerLocation } from '#file-explorer'

// "Documents" tab of a property: the reusable explorer (@rocket/file-explorer) on the documents of its place in
// Rocket Place, proxied by our own API (usePropertyDocuments). Read-only for non-admins (they can still browse and download).
const props = defineProps<{ propertyId: string, placeId: string | null }>()
const { isAdmin } = useAuth()
const adapter = usePropertyDocuments(props.propertyId)
const location = ref<ExplorerLocation>({ space: PROPERTY_SPACE, folder: null })
</script>

<template>
  <UAlert v-if="!placeId" color="warning" variant="subtle" icon="i-lucide-map-pin-off" description="Ce logement n’est lié à aucun lieu Rocket Place : choisis son lieu dans l’onglet « Infos »." />
  <UCard v-else :ui="{ body: 'p-0 sm:p-0' }">
    <RocketFileExplorer
      v-model:location="location" :adapter="adapter" :space="PROPERTY_SPACE" :readonly="!isAdmin"
      height="calc(100vh - 14rem)"
    />
  </UCard>
</template>
