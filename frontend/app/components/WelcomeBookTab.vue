<script setup lang="ts">
import type { Booking, WelcomeBook, WelcomeBookSection } from '~/types/pms'

// "Livret & TV" tab of a property: the welcome book editor (admins), the kiosk TV link and the per-booking guest links.
const props = defineProps<{ propertyId: string }>()
const api = useApi()
const toast = useToast()
const { isAdmin } = useAuth()
const base = computed(() => `/api/properties/${props.propertyId}`)
const { data: book, refresh } = await useAsyncData(`welcome-book-${props.propertyId}`, () => api<WelcomeBook>(`${base.value}/welcome-book`))
const { data: bookings } = await useAsyncData(`welcome-bookings-${props.propertyId}`, () => api<{ items: Booking[] }>(`${base.value}/bookings`).catch(() => null), { lazy: true })
const stays = computed(() => (bookings.value?.items ?? []).filter(b => b.active && b.phase !== 'past'))

const draft = reactive({} as Record<WelcomeBookSection, string>)
watch(book, (b) => {
  if (b) Object.assign(draft, b.content)
}, { immediate: true })
const saving = ref(false)
const origin = import.meta.client ? window.location.origin : ''

async function save() {
  saving.value = true
  try {
    book.value = await api<WelcomeBook>(`${base.value}/welcome-book`, { method: 'PUT', body: { content: draft } })
    toast.add({ title: 'Livret enregistré', color: 'success' })
  }
  catch (e) {
    toast.add({ title: 'Livret non enregistré', description: apiErrorMessage(e), color: 'error' })
  }
  finally {
    saving.value = false
  }
}

async function rotate(link: 'tv' | 'guest') {
  const what = link === 'tv' ? 'L’écran TV devra être rouvert avec le nouveau lien.' : 'Tous les liens voyageurs déjà envoyés cesseront de fonctionner.'
  if (!window.confirm(`Régénérer ce lien ? ${what}`)) return
  try {
    await api(`${base.value}/welcome-book/rotate`, { method: 'POST', body: { link } })
    await refresh()
    toast.add({ title: 'Lien régénéré', color: 'success' })
  }
  catch (e) {
    toast.add({ title: 'Échec', description: apiErrorMessage(e), color: 'error' })
  }
}

async function copy(text: string) {
  await navigator.clipboard.writeText(text)
  toast.add({ title: 'Lien copié', color: 'success' })
}

async function copyGuestLink(b: Booking) {
  try {
    const link = await api<{ path: string, from: string, until: string }>(`${base.value}/bookings/${b.id}/guest-link`)
    await copy(origin + link.path)
  }
  catch (e) {
    toast.add({ title: 'Lien indisponible', description: apiErrorMessage(e), color: 'error' })
  }
}
</script>

<template>
  <div class="grid gap-4 lg:grid-cols-3">
    <UCard class="lg:col-span-2">
      <template #header>
        <div class="flex items-center justify-between gap-2">
          <b>Livret d’accueil</b>
          <UButton v-if="isAdmin" icon="i-lucide-save" :loading="saving" label="Enregistrer" @click="save" />
        </div>
      </template>
      <p class="mb-4 text-sm text-muted">Chaque rubrique est facultative. <code>{{ '{' + '{guest}' + '}' }}</code> est remplacé par le prénom du voyageur. Le code de la porte n’apparaît au voyageur qu’une fois envoyé à la serrure.</p>
      <div class="space-y-4">
        <UFormField v-for="s in WELCOME_SECTIONS" :key="s.key" :label="s.label">
          <UTextarea v-if="s.multiline" v-model="draft[s.key]" :rows="3" autoresize :maxlength="4000" :disabled="!isAdmin" class="w-full" />
          <UInput v-else v-model="draft[s.key]" :maxlength="4000" :disabled="!isAdmin" class="w-full" />
        </UFormField>
      </div>
    </UCard>

    <div class="space-y-4">
      <UCard>
        <template #header><b>Écran TV</b></template>
        <p class="mb-3 text-sm text-muted">Page plein écran à ouvrir sur la TV du logement : accueil du voyageur, météo, Wi-Fi, départ. Jamais de code d’accès.</p>
        <div v-if="book" class="flex flex-wrap gap-2">
          <UButton icon="i-lucide-copy" variant="soft" label="Copier le lien" @click="copy(origin + book.tvPath)" />
          <UButton icon="i-lucide-external-link" variant="ghost" label="Ouvrir" :to="book.tvPath" target="_blank" />
          <UButton v-if="isAdmin" icon="i-lucide-refresh-cw" variant="ghost" color="warning" label="Régénérer" @click="rotate('tv')" />
        </div>
      </UCard>

      <UCard>
        <template #header><b>Liens voyageurs</b></template>
        <p class="mb-3 text-sm text-muted">Un lien secret par séjour, actif de 2 jours avant l’arrivée au lendemain du départ.</p>
        <div class="divide-y divide-default">
          <div v-for="b in stays" :key="b.id" class="flex items-center justify-between gap-2 py-2">
            <div class="text-sm">
              <b>{{ b.guest }}</b>
              <p class="text-xs text-muted">{{ b.arrival }} → {{ b.departure }}</p>
            </div>
            <UButton icon="i-lucide-link" size="sm" variant="soft" label="Copier" @click="copyGuestLink(b)" />
          </div>
          <p v-if="bookings && !stays.length" class="py-2 text-sm text-muted">Aucun séjour en cours ou à venir.</p>
        </div>
        <UButton v-if="isAdmin" class="mt-3" icon="i-lucide-refresh-cw" size="sm" variant="ghost" color="warning" label="Révoquer tous les liens voyageurs" @click="rotate('guest')" />
      </UCard>
    </div>
  </div>
</template>
