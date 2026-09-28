<script setup lang="ts">
import type { Bilan, Expense } from '~/types/pms'

// Bilan of a property for a year: Lodgify revenue (spread night by night), charges and other income entered here
// (admins), totals per month and per category, CSV export. Ported from LoussaHousing's bilan.
const props = defineProps<{ propertyId: string }>()
const api = useApi()
const auth = useAuth()
const config = useRuntimeConfig()
const toast = useToast()
const { isAdmin } = useAuth()
const base = computed(() => `/api/properties/${props.propertyId}`)

const year = ref(new Date().getFullYear())
const { data: bilan, refresh, error } = await useAsyncData(`bilan-${props.propertyId}`, () => api<Bilan>(`${base.value}/bilan`, { query: { year: year.value } }), { watch: [year] })
const yearItems = computed(() => (bilan.value?.years ?? [year.value]).map(y => ({ label: String(y), value: y })))
const categoryItems = computed(() => (bilan.value?.categoryOptions ?? []).map(c => ({ label: c.kind === 'income' ? `${c.label} (recette)` : c.label, value: c.value })))

// Entry form (create or edit)
const blank = () => ({ id: null as string | null, date: `${year.value}-${String(new Date().getMonth() + 1).padStart(2, '0')}-01`, amount: '', category: 'menage', note: '', documentRef: '' })
const form = ref(blank())
const formOpen = ref(false)
const saving = ref(false)
function edit(e?: Expense) {
  form.value = e ? { id: e.id, date: e.date, amount: String(e.amount), category: e.category, note: e.note, documentRef: e.documentRef ?? '' } : blank()
  formOpen.value = true
}
async function save() {
  saving.value = true
  try {
    const body = { date: form.value.date, amount: form.value.amount, category: form.value.category, note: form.value.note, documentRef: form.value.documentRef || null }
    if (form.value.id) await api(`/api/expenses/${form.value.id}`, { method: 'PATCH', body })
    else await api(`${base.value}/expenses`, { method: 'POST', body })
    formOpen.value = false
    await refresh()
  }
  catch (e) {
    toast.add({ title: 'Écriture non enregistrée', description: apiErrorMessage(e), color: 'error' })
  }
  finally {
    saving.value = false
  }
}
async function remove(e: Expense) {
  if (!confirm(`Supprimer « ${e.categoryLabel} » du ${dayFr(e.date)} (${money(e.amount)}) ?`)) return
  try {
    await api(`/api/expenses/${e.id}`, { method: 'DELETE' })
    await refresh()
  }
  catch (err) {
    toast.add({ title: 'Suppression impossible', description: apiErrorMessage(err), color: 'error' })
  }
}

// CSV: fetched with the session token, then saved by the browser
const exporting = ref(false)
async function exportCsv() {
  exporting.value = true
  try {
    const blob = await $fetch<Blob>(`${base.value}/bilan.csv`, {
      baseURL: config.public.apiBase, query: { year: year.value }, headers: { Authorization: auth.authorizationHeader() ?? '' }, responseType: 'blob',
    })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `bilan-${bilan.value?.property.name ?? 'logement'}-${year.value}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }
  catch (e) {
    toast.add({ title: 'Export impossible', description: apiErrorMessage(e), color: 'error' })
  }
  finally {
    exporting.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div class="flex items-center gap-2">
        <USelect v-model="year" :items="yearItems" class="w-28" />
        <UBadge v-if="bilan?.demo" color="warning" variant="subtle" label="Lodgify démo" />
      </div>
      <div class="flex gap-2">
        <UButton v-if="isAdmin" icon="i-lucide-plus" label="Ajouter une écriture" @click="edit()" />
        <UButton icon="i-lucide-download" color="neutral" variant="outline" label="Export CSV" :loading="exporting" @click="exportCsv" />
      </div>
    </div>

    <p v-if="error" class="text-sm text-error">{{ apiErrorMessage(error) }}</p>
    <template v-else-if="bilan">
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <UCard>
          <p class="text-xs uppercase tracking-wide text-muted">Revenus Lodgify</p>
          <p class="text-2xl font-bold tabular-nums">{{ money(bilan.revenue) }}</p>
          <p class="text-xs text-muted">{{ bilan.stays }} séjour{{ bilan.stays > 1 ? 's' : '' }} · {{ bilan.nights }} nuit{{ bilan.nights > 1 ? 's' : '' }} · occupation {{ bilan.occupancy }} %</p>
        </UCard>
        <UCard>
          <p class="text-xs uppercase tracking-wide text-muted">Autres recettes</p>
          <p class="text-2xl font-bold tabular-nums">{{ money(bilan.otherIncome) }}</p>
        </UCard>
        <UCard>
          <p class="text-xs uppercase tracking-wide text-muted">Charges</p>
          <p class="text-2xl font-bold tabular-nums">{{ money(bilan.chargesTotal) }}</p>
          <p class="text-xs text-muted">{{ bilan.entries }} écriture{{ bilan.entries > 1 ? 's' : '' }}</p>
        </UCard>
        <UCard>
          <p class="text-xs uppercase tracking-wide text-muted">Résultat</p>
          <p class="text-2xl font-bold tabular-nums" :class="bilan.result < 0 ? 'text-error' : 'text-success'">{{ money(bilan.result) }}</p>
          <p v-if="bilan.since" class="text-xs text-muted">Occupation depuis le {{ dayFr(bilan.since) }}</p>
        </UCard>
      </div>

      <div class="grid gap-4 lg:grid-cols-3">
        <UCard class="overflow-x-auto lg:col-span-2">
          <template #header><p class="text-sm font-semibold">Par mois</p></template>
          <table class="w-full text-sm">
            <thead class="text-left text-xs text-muted">
              <tr><th class="py-1">Mois</th><th class="text-right">Nuits</th><th class="text-right">Revenus</th><th class="text-right">Recettes</th><th class="text-right">Charges</th><th class="text-right">Résultat</th></tr>
            </thead>
            <tbody class="tabular-nums">
              <tr v-for="m in bilan.months" :key="m.label" class="border-t border-default">
                <td class="py-1">{{ m.label }}</td><td class="text-right">{{ m.nights }}</td><td class="text-right">{{ money(m.revenue) }}</td>
                <td class="text-right">{{ money(m.income) }}</td><td class="text-right">{{ money(m.charges) }}</td>
                <td class="text-right" :class="{ 'text-error': m.result < 0 }">{{ money(m.result) }}</td>
              </tr>
              <tr class="border-t border-default font-semibold">
                <td class="py-1">Total</td><td class="text-right">{{ bilan.nights }}</td><td class="text-right">{{ money(bilan.revenue) }}</td>
                <td class="text-right">{{ money(bilan.otherIncome) }}</td><td class="text-right">{{ money(bilan.chargesTotal) }}</td><td class="text-right">{{ money(bilan.result) }}</td>
              </tr>
            </tbody>
          </table>
        </UCard>
        <UCard>
          <template #header><p class="text-sm font-semibold">Par catégorie</p></template>
          <p v-if="!bilan.categories.length" class="text-sm text-muted">Aucune écriture cette année.</p>
          <dl v-else class="space-y-1.5 text-sm">
            <div v-for="c in bilan.categories" :key="c.key" class="flex justify-between gap-2">
              <dt class="text-muted">{{ c.label }} <span class="text-xs">({{ c.count }})</span></dt>
              <dd class="tabular-nums" :class="c.kind === 'income' ? 'text-success' : ''">{{ c.kind === 'income' ? '+' : '−' }} {{ money(c.total) }}</dd>
            </div>
          </dl>
        </UCard>
      </div>

      <UCard>
        <template #header><p class="text-sm font-semibold">Écritures {{ bilan.year }}</p></template>
        <p v-if="!bilan.items.length" class="text-sm text-muted">Aucune écriture. {{ isAdmin ? 'Ajoute les charges (ménage, énergie, assurance…) et autres recettes du logement.' : '' }}</p>
        <div v-else class="divide-y divide-default">
          <div v-for="e in bilan.items" :key="e.id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
            <div class="min-w-0">
              <b>{{ e.categoryLabel }}</b> <span class="text-muted">· {{ dayFr(e.date) }}</span>
              <p v-if="e.note || e.documentRef" class="truncate text-xs text-muted">{{ e.note }}<template v-if="e.documentRef"> · <UIcon name="i-lucide-paperclip" class="size-3" /> {{ e.documentRef }}</template></p>
            </div>
            <div class="flex items-center gap-1">
              <span class="tabular-nums" :class="e.kind === 'income' ? 'text-success' : ''">{{ e.kind === 'income' ? '+' : '−' }} {{ money(e.amount) }}</span>
              <template v-if="isAdmin">
                <UButton icon="i-lucide-pencil" size="xs" color="neutral" variant="ghost" aria-label="Modifier" @click="edit(e)" />
                <UButton icon="i-lucide-trash-2" size="xs" color="error" variant="ghost" aria-label="Supprimer" @click="remove(e)" />
              </template>
            </div>
          </div>
        </div>
      </UCard>
    </template>
    <p v-else class="text-sm text-muted">Chargement…</p>

    <UModal v-model:open="formOpen" :title="form.id ? 'Modifier l’écriture' : 'Nouvelle écriture'">
      <template #body>
        <div class="space-y-3">
          <UFormField label="Date"><UInput v-model="form.date" type="date" class="w-full" /></UFormField>
          <UFormField label="Montant (€)"><UInput v-model="form.amount" inputmode="decimal" placeholder="0,00" class="w-full" /></UFormField>
          <UFormField label="Catégorie"><USelect v-model="form.category" :items="categoryItems" class="w-full" /></UFormField>
          <UFormField label="Note"><UInput v-model="form.note" :maxlength="500" class="w-full" /></UFormField>
          <UFormField label="Document Rocket Place (optionnel)" help="Identifiant du justificatif dans l’onglet Documents, ex. file:…">
            <UInput v-model="form.documentRef" :maxlength="255" class="w-full" />
          </UFormField>
        </div>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton color="neutral" variant="ghost" label="Annuler" @click="formOpen = false" />
          <UButton icon="i-lucide-save" label="Enregistrer" :loading="saving" :disabled="!form.date || !form.amount" @click="save" />
        </div>
      </template>
    </UModal>
  </div>
</template>
