<script setup lang="ts">
import type { ExplorerAdapter, ExplorerLocation } from '#file-explorer'

// Picks a document of the property's place (Rocket Place, through our API) with the documents explorer: double-click
// opens folders as usual, "Choisir ce document" (context menu, or the button once a file is selected) returns its id.
// Admins only (the explorer is writable here: a receipt can be uploaded then picked). Used for the receipt of a bilan entry and the cover image of the welcome book.
const props = defineProps<{ propertyId: string, title?: string }>()
const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{ pick: [id: string, name: string] }>()
const base = usePropertyDocuments(props.propertyId)
const adapter: ExplorerAdapter = {
  ...base,
  actions: items => items.length === 1 && items[0]?.kind === 'file' ? [{ id: 'pick', label: 'Choisir ce document', icon: 'i-lucide-check' }] : [],
}
const location = ref<ExplorerLocation>({ space: PROPERTY_SPACE, folder: null })

function onAction(id: string, items: { id: string | number, name: string, kind: string }[]) {
  const item = items[0]
  if (id !== 'pick' || !item || item.kind !== 'file') return
  emit('pick', String(item.id), item.name)
  open.value = false
}
</script>

<template>
  <UModal v-model:open="open" :title="title ?? 'Choisir un document'" description="Clic droit sur un fichier puis « Choisir ce document »." :ui="{ content: 'sm:max-w-4xl' }">
    <template #body>
      <RocketFileExplorer v-model:location="location" :adapter="adapter" :space="PROPERTY_SPACE" height="28rem" @action="onAction" />
    </template>
  </UModal>
</template>
