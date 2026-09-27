<script setup lang="ts">
import type { Connector, DomotiqueSection, Plugin } from '~/types/pms'

// "Domotique" tab of a property: read-only info cards of every enabled connector, and (admin) the connectors themselves.
const props = defineProps<{ propertyId: string }>()
const api = useApi()
const toast = useToast()
const { isAdmin } = useAuth()

const { data: domotique, refresh: refreshDomotique } = await useAsyncData(`domotique-${props.propertyId}`, () => api<{ sections: DomotiqueSection[] }>(`/api/properties/${props.propertyId}/domotique`))
const { data: connectors, refresh: refreshConnectors } = await useAsyncData(`connectors-${props.propertyId}`, () => api<Connector[]>(`/api/properties/${props.propertyId}/connectors`))
const { data: plugins } = await useAsyncData('plugins', () => api<Plugin[]>('/api/plugins'), { default: () => [] })
const pluginOf = (id: string) => plugins.value.find(p => p.id === id)

const showEditor = ref(false)
const editing = ref<Connector | null>(null)
const form = reactive<{ pluginId: string, name: string, config: Record<string, string> }>({ pluginId: '', name: '', config: {} })
const pluginOptions = computed(() => plugins.value.map(p => ({ label: p.name, value: p.id })))

function openCreate() {
  editing.value = null
  form.pluginId = plugins.value[0]?.id ?? ''
  form.name = ''
  form.config = {}
  showEditor.value = true
}
function openEdit(c: Connector) {
  editing.value = c
  form.pluginId = c.pluginId
  form.name = c.name
  form.config = { ...c.config }
  showEditor.value = true
}

const saving = ref(false)
async function save() {
  saving.value = true
  try {
    if (editing.value) {
      await api(`/api/connectors/${editing.value.id}`, { method: 'PATCH', body: { name: form.name, config: form.config } })
    } else {
      await api(`/api/properties/${props.propertyId}/connectors`, { method: 'POST', body: { pluginId: form.pluginId, name: form.name, config: form.config } })
    }
    showEditor.value = false
    await Promise.all([refreshConnectors(), refreshDomotique()])
    toast.add({ title: 'Enregistré', color: 'success' })
  } catch (error) {
    toast.add({ title: 'Non enregistré', description: apiErrorMessage(error), color: 'error' })
  }
  saving.value = false
}

async function toggle(c: Connector) {
  try {
    await api(`/api/connectors/${c.id}`, { method: 'PATCH', body: { enabled: !c.enabled } })
    await Promise.all([refreshConnectors(), refreshDomotique()])
  } catch (error) {
    toast.add({ title: 'Non modifié', description: apiErrorMessage(error), color: 'error' })
  }
}

async function remove(c: Connector) {
  if (!confirm(`Supprimer le connecteur « ${c.name} » ?`)) return
  try {
    await api(`/api/connectors/${c.id}`, { method: 'DELETE' })
    await Promise.all([refreshConnectors(), refreshDomotique()])
  } catch (error) {
    toast.add({ title: 'Non supprimé', description: apiErrorMessage(error), color: 'error' })
  }
}

const testing = ref<string | null>(null)
async function test(c: Connector) {
  testing.value = c.id
  try {
    const r = await api<{ result: string }>(`/api/connectors/${c.id}/test`, { method: 'POST' })
    toast.add({ title: 'Test réussi', description: r.result, color: 'success' })
  } catch (error) {
    toast.add({ title: 'Test échoué', description: apiErrorMessage(error), color: 'error' })
  }
  await refreshConnectors()
  testing.value = null
}
</script>

<template>
  <div class="space-y-4">
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
    <p v-if="domotique && !domotique.sections.length" class="text-sm text-muted">Aucun connecteur domotique pour ce logement.</p>

    <UCard v-if="isAdmin">
      <template #header>
        <div class="flex items-center justify-between">
          <b>Connecteurs</b>
          <UButton icon="i-lucide-plus" size="sm" label="Ajouter" @click="openCreate" />
        </div>
      </template>
      <div class="space-y-2">
        <div v-for="c in connectors ?? []" :key="c.id" class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-default p-2.5">
          <div>
            <b class="text-sm">{{ c.name }}</b>
            <p class="text-xs text-muted">{{ pluginOf(c.pluginId)?.name ?? c.pluginId }} · {{ c.lastResult ?? 'jamais testé' }}</p>
          </div>
          <div class="flex items-center gap-1.5">
            <UBadge :color="c.enabled ? 'success' : 'neutral'" variant="subtle" :label="c.enabled ? 'Actif' : 'Inactif'" />
            <UButton size="xs" variant="soft" :loading="testing === c.id" label="Tester" @click="test(c)" />
            <UButton size="xs" variant="soft" :label="c.enabled ? 'Désactiver' : 'Activer'" @click="toggle(c)" />
            <UButton size="xs" variant="soft" icon="i-lucide-pencil" @click="openEdit(c)" />
            <UButton size="xs" variant="soft" color="error" icon="i-lucide-trash" @click="remove(c)" />
          </div>
        </div>
        <p v-if="connectors && !connectors.length" class="text-sm text-muted">Aucun connecteur : ajoute un Homey ou un service web.</p>
      </div>
    </UCard>

    <UModal v-model:open="showEditor" :title="editing ? 'Modifier le connecteur' : 'Ajouter un connecteur'">
      <template #body>
        <div class="space-y-3">
          <UFormField v-if="!editing" label="Plugin">
            <USelect v-model="form.pluginId" :items="pluginOptions" class="w-full" />
          </UFormField>
          <UFormField label="Nom">
            <UInput v-model="form.name" class="w-full" />
          </UFormField>
          <UFormField v-for="f in pluginOf(form.pluginId)?.fields ?? []" :key="f.key" :label="f.label" :help="f.help">
            <USelect v-if="f.type === 'select'" v-model="form.config[f.key]" :items="f.options ?? []" class="w-full" />
            <UInput v-else v-model="form.config[f.key]" :type="f.secret ? 'text' : (f.type === 'url' ? 'url' : 'text')" :placeholder="f.placeholder" class="w-full" />
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
