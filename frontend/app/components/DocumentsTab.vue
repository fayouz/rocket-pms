<script setup lang="ts">
import type { ExplorerLocation } from '#file-explorer'

// "Documents" tab of a property: the reusable explorer (@rocket/file-explorer) on the property's Rocket Cloud folder,
// proxied by our own API (usePropertyDocuments). Read-only for non-admins (the explorer still lets them browse and download).
const props = defineProps<{ propertyId: string }>()
const { isAdmin } = useAuth()
const adapter = usePropertyDocuments(props.propertyId)
const location = ref<ExplorerLocation>({ space: PROPERTY_SPACE, folder: null })
</script>

<template>
  <UCard :ui="{ body: 'p-0 sm:p-0' }">
    <RocketFileExplorer
      v-model:location="location" :adapter="adapter" :space="PROPERTY_SPACE" :readonly="!isAdmin"
      height="calc(100vh - 14rem)"
    />
  </UCard>
</template>
