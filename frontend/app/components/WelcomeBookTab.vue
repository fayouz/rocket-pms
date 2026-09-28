<script setup lang="ts">
import type { Booking, GuestLink, WelcomeBook, WelcomeBookSection, WelcomeLanguage, WelcomeStats, WelcomeStyle } from '~/types/pms'

// "Livret & TV" tab of a property: the welcome book editor (admins) in French and optional translations, its look
// (accent colour, cover image, layout), the kiosk TV link and the per-booking guest links (copy, QR code), and the
// visit counters of the public pages (no visitor data).
const props = defineProps<{ propertyId: string, placeId: string | null }>()
const api = useApi()
const toast = useToast()
const { isAdmin } = useAuth()
const base = computed(() => `/api/properties/${props.propertyId}`)
const { data: book, refresh } = await useAsyncData(`welcome-book-${props.propertyId}`, () => api<WelcomeBook>(`${base.value}/welcome-book`))
const { data: bookings } = await useAsyncData(`welcome-bookings-${props.propertyId}`, () => api<{ items: Booking[] }>(`${base.value}/bookings`).catch(() => null), { lazy: true })
const { data: stats } = await useAsyncData(`welcome-stats-${props.propertyId}`, () => api<WelcomeStats>(`${base.value}/welcome-book/stats`).catch(() => null), { lazy: true })
const stays = computed(() => (bookings.value?.items ?? []).filter(b => b.active && b.phase !== 'past'))

// Editor: French is the content, the other languages are translations (an empty section falls back to French)
const lang = ref<WelcomeLanguage>('fr')
const draft = reactive({} as Record<WelcomeLanguage, Record<WelcomeBookSection, string>>)
const style = reactive<WelcomeStyle>({ accent: '#0f766e', coverUrl: null, coverDocumentRef: null, layout: 'tabs' })
watch(book, (b) => {
  if (!b) return
  draft.fr = { ...b.content }
  for (const l of WELCOME_LANGUAGES) {
    const v = l.value
    if (v !== 'fr') draft[v] = Object.fromEntries(WELCOME_SECTIONS.map(s => [s.key, b.translations[v]?.[s.key] ?? ''])) as Record<WelcomeBookSection, string>
  }
  Object.assign(style, b.style)
}, { immediate: true })
const translated = (l: WelcomeLanguage) => l === 'fr' || Object.values(draft[l] ?? {}).some(t => t !== '')
const langItems = computed(() => WELCOME_LANGUAGES.map(l => ({ label: `${l.label}${l.value === 'fr' ? ' (par défaut)' : translated(l.value) ? '' : ' (non traduit)'}`, value: l.value })))
const layoutItems = [{ label: 'Onglets', value: 'tabs' }, { label: 'Colonnes', value: 'columns' }]
const saving = ref(false)
const origin = import.meta.client ? window.location.origin : ''

async function save() {
  saving.value = true
  try {
    const { fr, ...translations } = draft
    book.value = await api<WelcomeBook>(`${base.value}/welcome-book`, { method: 'PUT', body: {
      content: fr, translations,
      style: { accent: style.accent, layout: style.layout, coverUrl: style.coverDocumentRef ? null : (style.coverUrl || null), coverDocumentRef: style.coverDocumentRef || null },
    } })
    toast.add({ title: 'Livret enregistré', color: 'success' })
  }
  catch (e) {
    toast.add({ title: 'Livret non enregistré', description: apiErrorMessage(e), color: 'error' })
  }
  finally {
    saving.value = false
  }
}

const pickerOpen = ref(false)
function pickCover(id: string) {
  style.coverDocumentRef = id
  style.coverUrl = null
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

async function guestLink(b: Booking): Promise<string | null> {
  try {
    return origin + (await api<GuestLink>(`${base.value}/bookings/${b.id}/guest-link`)).path
  }
  catch (e) {
    toast.add({ title: 'Lien indisponible', description: apiErrorMessage(e), color: 'error' })
    return null
  }
}

async function copyGuestLink(b: Booking) {
  const url = await guestLink(b)
  if (url) await copy(url)
}

// QR code, drawn in the browser (the link never leaves it)
const qr = ref<{ title: string, url: string, svg: string } | null>(null)
function showQr(title: string, url: string) {
  qr.value = { title, url, svg: qrSvg(url) }
}
async function showGuestQr(b: Booking) {
  const url = await guestLink(b)
  if (url) showQr(`Livret de ${b.guest}`, url)
}
const qrOpen = computed({ get: () => qr.value !== null, set: (v) => { if (!v) qr.value = null } })

const guestOf = (bookingId: number | null) => bookingId === null ? 'Écran TV' : (stats.value?.links.find(l => l.bookingId === bookingId)?.guest ?? `Réservation ${bookingId}`)
</script>

<template>
  <div class="grid gap-4 lg:grid-cols-3">
    <UCard class="lg:col-span-2">
      <template #header>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <b>Livret d’accueil</b>
          <div class="flex items-center gap-2">
            <USelect v-model="lang" :items="langItems" class="w-48" aria-label="Langue" />
            <UButton v-if="isAdmin" icon="i-lucide-save" :loading="saving" label="Enregistrer" @click="save" />
          </div>
        </div>
      </template>
      <p class="mb-4 text-sm text-muted">
        Chaque rubrique est facultative. <code>{{ '{' + '{guest}' + '}' }}</code> est remplacé par le prénom du voyageur. Le code de la porte n’apparaît au voyageur qu’une fois envoyé à la serrure.
        <template v-if="lang !== 'fr'"> Une rubrique non traduite est affichée en français. La langue du voyageur est choisie d’après son navigateur, il peut en changer sur la page.</template>
      </p>
      <div v-if="draft[lang]" class="space-y-4">
        <UFormField v-for="s in WELCOME_SECTIONS" :key="`${lang}-${s.key}`" :label="s.label" :hint="lang !== 'fr' && draft.fr?.[s.key] && !draft[lang][s.key] ? 'en français' : undefined">
          <UTextarea v-if="s.multiline" v-model="draft[lang][s.key]" :rows="3" autoresize :maxlength="4000" :disabled="!isAdmin" :placeholder="lang !== 'fr' ? draft.fr?.[s.key] : undefined" class="w-full" />
          <UInput v-else v-model="draft[lang][s.key]" :maxlength="4000" :disabled="!isAdmin" :placeholder="lang !== 'fr' ? draft.fr?.[s.key] : undefined" class="w-full" />
        </UFormField>
      </div>
    </UCard>

    <div class="space-y-4">
      <UCard>
        <template #header><b>Apparence</b></template>
        <div class="space-y-3">
          <UFormField label="Couleur d’accent">
            <div class="flex items-center gap-2">
              <input v-model="style.accent" type="color" :disabled="!isAdmin" class="h-9 w-12 cursor-pointer rounded border border-default" aria-label="Couleur d’accent">
              <UInput v-model="style.accent" :disabled="!isAdmin" class="w-32" />
            </div>
          </UFormField>
          <UFormField label="Disposition du livret"><USelect v-model="style.layout" :items="layoutItems" :disabled="!isAdmin" class="w-full" /></UFormField>
          <UFormField label="Image de couverture" help="Adresse https://, ou une image des documents du lieu.">
            <div v-if="style.coverDocumentRef" class="flex items-center gap-2 text-sm">
              <UIcon name="i-lucide-image" /> <span class="truncate">{{ style.coverDocumentRef }}</span>
              <UButton v-if="isAdmin" icon="i-lucide-x" size="xs" color="neutral" variant="ghost" aria-label="Retirer l’image" @click="style.coverDocumentRef = null" />
            </div>
            <UInput v-else :model-value="style.coverUrl ?? ''" placeholder="https://…" :disabled="!isAdmin" class="w-full" @update:model-value="(v) => (style.coverUrl = String(v) || null)" />
            <UButton v-if="isAdmin && placeId" class="mt-2" icon="i-lucide-folder-search" size="sm" color="neutral" variant="outline" label="Choisir dans les documents" @click="pickerOpen = true" />
          </UFormField>
        </div>
      </UCard>

      <UCard>
        <template #header><b>Écran TV</b></template>
        <p class="mb-3 text-sm text-muted">Page plein écran à ouvrir sur la TV du logement : accueil du voyageur, météo, Wi-Fi, départ. Jamais de code d’accès. Elle se recharge seule 30 minutes avant chaque arrivée.</p>
        <div v-if="book" class="flex flex-wrap gap-2">
          <UButton icon="i-lucide-copy" variant="soft" label="Copier le lien" @click="copy(origin + book.tvPath)" />
          <UButton icon="i-lucide-qr-code" variant="soft" label="QR code" @click="showQr('Écran TV', origin + book.tvPath)" />
          <UButton icon="i-lucide-external-link" variant="ghost" label="Ouvrir" :to="book.tvPath" target="_blank" />
          <UButton v-if="isAdmin" icon="i-lucide-refresh-cw" variant="ghost" color="warning" label="Régénérer" @click="rotate('tv')" />
          <UButton v-if="book.castFrontUrl" icon="i-lucide-cast" variant="ghost" label="Afficher sur un écran Rocket Cast" :to="book.castFrontUrl" target="_blank" external />
        </div>
        <p v-if="book?.castFrontUrl" class="mt-2 text-xs text-muted">Dans Rocket Cast, ajoute une source « Rocket PMS » et choisis ce logement ({{ book.propertyId }}).</p>
      </UCard>

      <UCard>
        <template #header><b>Liens voyageurs</b></template>
        <p class="mb-3 text-sm text-muted">Un lien secret par séjour, actif de 2 jours avant l’arrivée au lendemain du départ. Pour l’envoyer au voyageur : onglet Réservations, « Envoyer le livret ».</p>
        <div class="divide-y divide-default">
          <div v-for="b in stays" :key="b.id" class="flex items-center justify-between gap-2 py-2">
            <div class="text-sm">
              <b>{{ b.guest }}</b>
              <p class="text-xs text-muted">{{ b.arrival }} → {{ b.departure }}</p>
            </div>
            <div class="flex gap-1">
              <UButton icon="i-lucide-link" size="sm" variant="soft" label="Copier" @click="copyGuestLink(b)" />
              <UButton icon="i-lucide-qr-code" size="sm" variant="ghost" aria-label="QR code" @click="showGuestQr(b)" />
            </div>
          </div>
          <p v-if="bookings && !stays.length" class="py-2 text-sm text-muted">Aucun séjour en cours ou à venir.</p>
        </div>
        <UButton v-if="isAdmin" class="mt-3" icon="i-lucide-refresh-cw" size="sm" variant="ghost" color="warning" label="Révoquer tous les liens voyageurs" @click="rotate('guest')" />
      </UCard>

      <UCard>
        <template #header><b>Visites (30 jours)</b></template>
        <p v-if="!stats" class="text-sm text-muted">Chargement…</p>
        <template v-else>
          <p class="mb-2 text-sm text-muted">{{ stats.total }} ouverture{{ stats.total > 1 ? 's' : '' }} des pages publiques. Aucune donnée sur les visiteurs n’est conservée.</p>
          <dl class="space-y-1 text-sm">
            <div v-for="l in stats.links" :key="`${l.link}-${l.bookingId}`" class="flex justify-between gap-2">
              <dt class="truncate">{{ guestOf(l.bookingId) }} <span class="text-xs text-muted">· {{ dayFr(l.lastDay) }}</span></dt>
              <dd class="tabular-nums">{{ l.total }}</dd>
            </div>
          </dl>
        </template>
      </UCard>
    </div>

    <UModal v-model:open="qrOpen" :title="qr?.title ?? 'QR code'" description="À imprimer ou afficher : le voyageur le scanne avec son téléphone.">
      <template #body>
        <div v-if="qr" class="flex flex-col items-center gap-3">
          <!-- SVG computed locally by uqr from our own link: no user HTML -->
          <!-- eslint-disable-next-line vue/no-v-html -->
          <div class="w-64 bg-white p-2 [&>svg]:h-auto [&>svg]:w-full" v-html="qr.svg" />
          <p class="break-all text-center text-xs text-muted">{{ qr.url }}</p>
        </div>
      </template>
    </UModal>

    <DocumentPicker v-if="placeId && isAdmin" v-model:open="pickerOpen" :property-id="propertyId" title="Choisir l’image de couverture" @pick="pickCover" />
  </div>
</template>
