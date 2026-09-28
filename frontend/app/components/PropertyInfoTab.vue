<script setup lang="ts">
import type { Connector, Place, Plugin, Property } from '~/types/pms'

// "Infos" tab of a property: its place in Rocket Place (locks, domotique, documents, stock come from there) and,
// for an admin, the Lodgify connectors of the property (the only integration PMS keeps itself).
const props = defineProps<{ property: Property }>()
const emit = defineEmits<{ changed: [] }>()
const api = useApi()
const toast = useToast()
const { isAdmin } = useAuth()

const { data: places, refresh: refreshPlaces } = await useAsyncData(`places-${props.property.id}`, () => isAdmin.value ? api<{ demo: boolean, places: Place[] }>('/api/places').catch(() => null) : Promise.resolve(null))
// The select refuses an empty value: "none" stands for no place
const placeOptions = computed(() => [{ label: '— aucun lieu —', value: 'none' }, ...(places.value?.places ?? []).map(p => ({ label: p.name, value: p.id }))])
const placeName = computed(() => places.value?.places.find(p => p.id === props.property.placeId)?.name ?? null)

const linking = ref(false)
async function link(value: string) {
  linking.value = true
  try {
    await api(`/api/properties/${props.property.id}/place`, { method: 'PUT', body: { placeId: value === 'none' ? null : value } })
    toast.add({ title: 'Lieu enregistré', color: 'success' })
    emit('changed')
  }
  catch (error) {
    toast.add({ title: 'Lieu non enregistré', description: apiErrorMessage(error), color: 'error' })
  }
  linking.value = false
}
async function createPlace() {
  if (!confirm(`Créer dans Rocket Place un lieu « ${props.property.name} » et y lier ce logement ?`)) return
  linking.value = true
  try {
    await api(`/api/properties/${props.property.id}/place`, { method: 'POST' })
    await refreshPlaces()
    toast.add({ title: 'Lieu créé et lié', color: 'success' })
    emit('changed')
  }
  catch (error) {
    toast.add({ title: 'Lieu non créé', description: apiErrorMessage(error), color: 'error' })
  }
  linking.value = false
}

// Lodgify connectors (admin)
const { data: connectors, refresh: refreshConnectors } = await useAsyncData(`connectors-${props.property.id}`, () => isAdmin.value ? api<Connector[]>(`/api/properties/${props.property.id}/connectors`) : Promise.resolve([] as Connector[]))
const { data: plugins } = await useAsyncData('plugins', () => isAdmin.value ? api<Plugin[]>('/api/plugins') : Promise.resolve([] as Plugin[]), { default: () => [] as Plugin[] })
const lodgify = computed(() => plugins.value.find(p => p.id === 'lodgify'))
const showEditor = ref(false)
const editing = ref<Connector | null>(null)
const form = reactive<{ name: string, config: Record<string, string> }>({ name: '', config: {} })
function openEditor(c: Connector | null) {
  editing.value = c
  form.name = c?.name ?? 'Lodgify'
  form.config = { ...(c?.config ?? {}) }
  showEditor.value = true
}
const saving = ref(false)
async function save() {
  saving.value = true
  try {
    if (editing.value) await api(`/api/connectors/${editing.value.id}`, { method: 'PATCH', body: { name: form.name, config: form.config } })
    else await api(`/api/properties/${props.property.id}/connectors`, { method: 'POST', body: { pluginId: 'lodgify', name: form.name, config: form.config } })
    showEditor.value = false
    await refreshConnectors()
    toast.add({ title: 'Enregistré', color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Non enregistré', description: apiErrorMessage(error), color: 'error' })
  }
  saving.value = false
}
async function act(c: Connector, action: 'toggle' | 'remove' | 'test') {
  try {
    if (action === 'toggle') await api(`/api/connectors/${c.id}`, { method: 'PATCH', body: { enabled: !c.enabled } })
    if (action === 'remove') {
      if (!confirm(`Supprimer le connecteur « ${c.name} » ?`)) return
      await api(`/api/connectors/${c.id}`, { method: 'DELETE' })
    }
    if (action === 'test') toast.add({ title: 'Test réussi', description: (await api<{ result: string }>(`/api/connectors/${c.id}/test`, { method: 'POST' })).result, color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Échec', description: apiErrorMessage(error), color: 'error' })
  }
  await refreshConnectors()
}
</script>

<template>
  <div class="space-y-4">
    <UCard>
      <template #header><b>Lieu Rocket Place</b></template>
      <p class="mb-3 text-sm text-muted">Serrures et codes clavier, domotique, documents et stock de ce logement sont gérés par Rocket Place, sur le lieu lié ici.</p>
      <div v-if="isAdmin" class="flex flex-wrap items-center gap-2">
        <USelect :model-value="property.placeId ?? 'none'" :items="placeOptions" :disabled="linking || !places" class="w-72" @update:model-value="link(String($event))" />
        <UButton v-if="!property.placeId" icon="i-lucide-map-pin-plus" variant="soft" label="Créer un lieu depuis ce logement" :loading="linking" :disabled="!places" @click="createPlace" />
        <p v-if="!places" class="text-sm text-error">Rocket Place ne répond pas pour le moment.</p>
        <UBadge v-else-if="places.demo" color="neutral" variant="subtle" label="Rocket Place de démo" />
      </div>
      <p v-else class="text-sm">{{ property.placeId ? `Lié au lieu ${placeName ?? property.placeId}.` : 'Pas encore lié à un lieu : un administrateur doit le choisir.' }}</p>
    </UCard>

    <UCard v-if="isAdmin">
      <template #header>
        <div class="flex items-center justify-between">
          <b>Connecteurs Lodgify</b>
          <UButton icon="i-lucide-plus" size="sm" label="Ajouter" @click="openEditor(null)" />
        </div>
      </template>
      <p class="mb-3 text-sm text-muted">Sans connecteur, le compte Lodgify global (LODGIFY_API_KEY) est utilisé.</p>
      <div class="space-y-2">
        <div v-for="c in connectors ?? []" :key="c.id" class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-default p-2.5">
          <div>
            <b class="text-sm">{{ c.name }}</b>
            <p class="text-xs text-muted">{{ c.config.secretVar }} · {{ c.lastResult ?? 'jamais testé' }}</p>
          </div>
          <div class="flex items-center gap-1.5">
            <UBadge :color="c.enabled ? 'success' : 'neutral'" variant="subtle" :label="c.enabled ? 'Actif' : 'Inactif'" />
            <UButton size="xs" variant="soft" label="Tester" @click="act(c, 'test')" />
            <UButton size="xs" variant="soft" :label="c.enabled ? 'Désactiver' : 'Activer'" @click="act(c, 'toggle')" />
            <UButton size="xs" variant="soft" icon="i-lucide-pencil" @click="openEditor(c)" />
            <UButton size="xs" variant="soft" color="error" icon="i-lucide-trash" @click="act(c, 'remove')" />
          </div>
        </div>
        <p v-if="connectors && !connectors.length" class="text-sm text-muted">Aucun connecteur Lodgify propre à ce logement.</p>
      </div>
    </UCard>

    <UModal v-model:open="showEditor" :title="editing ? 'Modifier le connecteur' : 'Ajouter un connecteur Lodgify'">
      <template #body>
        <div class="space-y-3">
          <UFormField label="Nom">
            <UInput v-model="form.name" class="w-full" />
          </UFormField>
          <UFormField v-for="f in lodgify?.fields ?? []" :key="f.key" :label="f.label" :help="f.help">
            <UInput v-model="form.config[f.key]" :placeholder="f.placeholder" class="w-full" />
          </UFormField>
        </div>
      </template>
      <template #footer>
        <UButton variant="ghost" label="Annuler" @click="showEditor = false" />
        <UButton :loading="saving" label="Enregistrer" @click="save" />
      </template>
    </UModal>
  </div>
</template>
