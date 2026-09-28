<script setup lang="ts">
import type { Booking, BookingEmails, GuestLink, Lock, Message, Pricing, WelcomeLanguage } from '~/types/pms'

// Bookings of a property, like a mail client: compact list on the left (search, filter, sort), detail on the right
// (conversation with the guest and reply 2/3; value of the stay and smart lock 1/3). The composer writes either a
// Lodgify message or an e-mail through Rocket Mailer; e-mail conversations of the shared inbox linked to the booking
// are listed under the Lodgify thread.
const props = defineProps<{ propertyId: string }>()
const api = useApi()
const toast = useToast()
const base = computed(() => `/api/properties/${props.propertyId}`)

const { data, refresh } = await useAsyncData(`bookings-${props.propertyId}`, () => api<{ demo: boolean, items: Booking[] }>(`${base.value}/bookings`))

// List: search by guest, filter by state, sort by arrival
const search = ref('')
const filter = ref<'all' | 'now' | 'next' | 'past' | 'cancelled'>('all')
const filterItems = [
  { label: 'Toutes', value: 'all' }, { label: 'En cours', value: 'now' }, { label: 'À venir', value: 'next' },
  { label: 'Passées', value: 'past' }, { label: 'Annulées', value: 'cancelled' },
]
const sortAsc = ref(false)
const items = computed(() => {
  const q = search.value.trim().toLowerCase()
  return (data.value?.items ?? []).filter((b) => {
    if (q && !b.guest.toLowerCase().includes(q)) return false
    if (filter.value === 'cancelled') return !b.active
    if (filter.value !== 'all') return b.active && b.phase === filter.value
    return true
  }).sort((a, b) => sortAsc.value ? a.arrival.localeCompare(b.arrival) : b.arrival.localeCompare(a.arrival))
})

// Selected booking: the stay in progress, otherwise the first of the list
const selected = ref<number | null>(null)
watchEffect(() => {
  if (selected.value !== null && items.value.some(b => b.id === selected.value)) return
  selected.value = (items.value.find(b => b.phase === 'now') ?? items.value[0])?.id ?? null
})
const current = computed(() => data.value?.items.find(b => b.id === selected.value) ?? null)

// Reply draft (declared before the watcher below, which resets it); channel: Lodgify message or e-mail (Rocket Mailer)
const channel = ref<'lodgify' | 'email'>('lodgify')
const channelItems = [{ label: 'Message Lodgify', value: 'lodgify', icon: 'i-lucide-message-circle' }, { label: 'E-mail', value: 'email', icon: 'i-lucide-mail' }]
const subject = ref('')
const draft = ref('')
const draftId = ref(crypto.randomUUID())
const sending = ref(false)

// Sending the welcome-book link to the guest: prepared in a window, sent only on "Envoyer" + confirmation
const linkOpen = ref(false)
const linkChannel = ref<'lodgify' | 'email'>('lodgify')
const linkLang = ref<WelcomeLanguage>('fr')
const linkText = ref('')
const linkUrl = ref('')
const linkId = ref('')
const linkSending = ref(false)
async function openLink() {
  if (!current.value) return
  try {
    const link = await api<GuestLink>(`${base.value}/bookings/${current.value.id}/guest-link`)
    linkUrl.value = link.url
    linkText.value = link.message
    linkChannel.value = current.value.guestEmail ? 'email' : 'lodgify'
    linkId.value = crypto.randomUUID()
    linkOpen.value = true
  }
  catch (e) {
    toast.add({ title: 'Lien indisponible', description: apiErrorMessage(e), color: 'error' })
  }
}
// Another language: the server writes its default message in that language (unless a text is typed again)
watch(linkLang, (l) => {
  if (l !== 'fr') linkText.value = ''
})
async function sendLink() {
  if (!current.value) return
  const to = linkChannel.value === 'email' ? `par e-mail à ${current.value.guestEmail}` : 'par la messagerie Lodgify'
  if (!window.confirm(`Envoyer le livret à ${current.value.guest} ${to} ?`)) return
  linkSending.value = true
  try {
    const r = await api<{ duplicate: boolean, demo?: boolean }>(`${base.value}/bookings/${current.value.id}/guest-link/send`, { method: 'POST', body: { channel: linkChannel.value, lang: linkLang.value, text: linkText.value, messageId: linkId.value } })
    toast.add({ title: r.duplicate ? 'Déjà envoyé' : r.demo ? 'Envoyé (Rocket Mailer démo)' : 'Livret envoyé', color: 'success' })
    linkOpen.value = false
  }
  catch (e) {
    toast.add({ title: 'Envoi impossible', description: apiErrorMessage(e), color: 'error' })
  }
  finally {
    linkSending.value = false
  }
}

// Conversation (Lodgify thread) and value of the stay, loaded on selection
const conv = ref<Message[] | null>(null)
const convError = ref('')
const pricing = ref<Pricing | null>(null)
const convBox = ref<HTMLElement | null>(null)
// E-mail conversations of the shared inbox (Rocket Mailer) linked to the booking; one opened at a time
const emails = ref<BookingEmails | null>(null)
const emailsError = ref('')
const openedEmail = ref<string | null>(null)
const emailThread = ref<Message[] | null>(null)
watch(selected, async (id) => {
  conv.value = null
  pricing.value = null
  emails.value = null
  emailsError.value = ''
  openedEmail.value = null
  emailThread.value = null
  convError.value = ''
  draft.value = ''
  subject.value = ''
  draftId.value = crypto.randomUUID()
  if (id === null) return
  const [c, p, e] = await Promise.allSettled([
    api<{ messages: Message[] }>(`${base.value}/bookings/${id}/conversation`),
    api<Pricing>(`${base.value}/bookings/${id}/pricing`),
    api<BookingEmails>(`${base.value}/bookings/${id}/emails`),
  ])
  if (selected.value !== id) return
  if (c.status === 'fulfilled') conv.value = c.value.messages
  else convError.value = apiErrorMessage(c.reason)
  if (p.status === 'fulfilled') pricing.value = p.value
  if (e.status === 'fulfilled') emails.value = e.value
  else emailsError.value = apiErrorMessage(e.reason)
  await nextTick()
  if (convBox.value) convBox.value.scrollTop = convBox.value.scrollHeight
}, { immediate: true })

async function toggleEmail(conversationId: string) {
  if (openedEmail.value === conversationId) {
    openedEmail.value = null
    return
  }
  openedEmail.value = conversationId
  emailThread.value = null
  try {
    emailThread.value = (await api<{ messages: Message[] }>(`${base.value}/bookings/${selected.value}/emails/${conversationId}`)).messages
  }
  catch (error) {
    openedEmail.value = null
    toast.add({ title: 'Conversation indisponible', description: apiErrorMessage(error), color: 'error' })
  }
}

const canSend = computed(() => channel.value === 'lodgify'
  ? !!draft.value.trim() && !data.value?.demo && !convError.value
  : !!draft.value.trim() && !!subject.value.trim() && !!emails.value?.guestEmail)

// Reply to the guest, only on click: Lodgify pushes a message on the booking's channel, Rocket Mailer sends an
// e-mail to the guest's address. One id per draft: a retry is not sent twice.
async function send() {
  if (!canSend.value || selected.value === null) return
  const id = selected.value
  sending.value = true
  try {
    if (channel.value === 'email') {
      const r = await api<{ demo: boolean }>(`${base.value}/bookings/${id}/emails`, { method: 'POST', body: { subject: subject.value, text: draft.value, messageId: draftId.value } })
      toast.add({ title: r.demo ? 'Mode démo : e-mail enregistré, non envoyé' : 'E-mail confié à Rocket Mailer', color: 'success' })
      subject.value = ''
      emails.value = await api<BookingEmails>(`${base.value}/bookings/${id}/emails`)
    }
    else {
      await api(`${base.value}/bookings/${id}/conversation`, { method: 'POST', body: { text: draft.value, messageId: draftId.value } })
      conv.value = (await api<{ messages: Message[] }>(`${base.value}/bookings/${id}/conversation`)).messages
    }
    draft.value = ''
    draftId.value = crypto.randomUUID()
  }
  catch (error) {
    toast.add({ title: 'Message non envoyé', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    sending.value = false
  }
}

// Smart lock of the property and keypad code of the booking
const { data: locks } = await useAsyncData(`locks-${props.propertyId}`, () => api<{ locks: Lock[] }>(`${base.value}/locks`).catch(() => null))
const stayLogs = (lock: Lock) => current.value ? lock.logs.filter(g => g.date.slice(0, 10) >= current.value!.arrival && g.date.slice(0, 10) <= current.value!.departure) : []
const generating = ref(false)
async function generateCode() {
  const b = current.value
  if (!b?.access || !confirm(`Créer le code ${b.access.code} sur la serrure pour ${b.guest} (${dayFr(b.arrival)} → ${dayFr(b.departure)}) ?`)) return
  generating.value = true
  try {
    await api(`${base.value}/access-grants/${b.access.grantId}/send`, { method: 'POST' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Code non créé', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    generating.value = false
  }
}
</script>

<template>
  <div v-if="data" class="flex flex-col gap-4 lg:h-[calc(100vh-12rem)] lg:flex-row">
    <!-- Left: compact list of the bookings -->
    <div class="min-w-0 space-y-1 overflow-y-auto lg:w-80 lg:shrink-0 lg:border-r lg:border-default lg:pr-3">
      <div class="mb-2 space-y-2">
        <UInput v-model="search" icon="i-lucide-search" placeholder="Rechercher un voyageur…" size="sm" class="w-full" />
        <div class="flex items-center gap-1.5">
          <USelect v-model="filter" :items="filterItems" size="sm" class="flex-1" />
          <UButton
            size="sm" color="neutral" variant="outline" square :icon="sortAsc ? 'i-lucide-arrow-up-narrow-wide' : 'i-lucide-arrow-down-narrow-wide'"
            :title="sortAsc ? 'Plus anciennes en premier' : 'Plus récentes en premier'" @click="sortAsc = !sortAsc"
          />
        </div>
      </div>
      <button
        v-for="b in items" :key="b.id" type="button" class="block w-full rounded-md p-2.5 text-left transition-colors"
        :class="[selected === b.id ? 'bg-primary/10 ring-1 ring-primary/30' : 'hover:bg-elevated', { 'opacity-60': !b.active }]"
        @click="selected = b.id"
      >
        <div class="flex items-center justify-between gap-2">
          <b class="truncate text-sm">{{ b.guest }}</b>
          <UBadge v-if="b.phase === 'now'" size="sm" color="success" label="En cours" />
          <UBadge v-else-if="b.phase === 'next'" size="sm" color="info" variant="subtle" label="À venir" />
        </div>
        <p class="truncate text-xs text-muted">{{ dayFr(b.arrival) }} → {{ dayFr(b.departure) }} · {{ b.nights }} nuit{{ b.nights > 1 ? 's' : '' }}</p>
      </button>
      <p v-if="!items.length" class="text-sm text-muted">Aucune réservation{{ data.items.length ? ' pour ce filtre' : ' sur cette période' }}.</p>
    </div>

    <!-- Right: detail (2/3) and value + lock (1/3) -->
    <div v-if="current" class="grid min-w-0 flex-1 gap-4 lg:grid-cols-3 lg:grid-rows-[minmax(0,1fr)]">
      <UCard class="lg:col-span-2" :ui="{ root: 'flex flex-col lg:min-h-0', body: 'flex min-h-0 flex-1 flex-col' }">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <h3 class="text-lg font-semibold">{{ current.guest }}</h3>
            <p class="text-sm text-muted">
              {{ dayFr(current.arrival) }}{{ current.checkIn ? ` à ${current.checkIn}` : '' }} → {{ dayFr(current.departure) }}{{ current.checkOut ? ` à ${current.checkOut}` : '' }}
              · {{ current.nights }} nuit{{ current.nights > 1 ? 's' : '' }}
            </p>
          </div>
          <div class="flex flex-wrap justify-end gap-1">
            <UBadge v-if="current.phase === 'now'" color="success" label="En cours" />
            <UBadge v-else-if="current.phase === 'next'" color="info" variant="subtle" label="À venir" />
            <UBadge :color="/book/i.test(current.status) ? 'success' : /declin|cancel/i.test(current.status) ? 'error' : 'info'" variant="subtle" :label="current.status" />
            <PlatformBadge :source="current.source" />
            <UButton v-if="current.active && current.phase !== 'past'" icon="i-lucide-send" size="xs" variant="soft" label="Envoyer le livret" @click="openLink" />
          </div>
        </div>

        <h4 class="mt-6 mb-3 flex items-center gap-1.5 text-sm font-semibold"><UIcon name="i-lucide-messages-square" class="size-4 text-muted" /> Conversation</h4>
        <p v-if="convError" class="text-sm text-muted">Conversation indisponible pour le moment.</p>
        <p v-else-if="!conv" class="text-sm text-muted">Chargement…</p>
        <p v-else-if="!conv.length" class="text-sm text-muted">Aucun message.</p>
        <div v-else ref="convBox" class="max-h-[60vh] min-h-0 flex-1 space-y-3 overflow-y-auto pr-1 lg:max-h-none">
          <div v-for="m in conv" :key="m.key" class="flex" :class="m.from === 'host' ? 'justify-end' : 'justify-start'">
            <div class="max-w-[85%] rounded-lg px-3 py-2 text-sm" :class="m.from === 'host' ? 'bg-primary/10' : 'bg-elevated'">
              <p class="mb-1 text-xs text-muted">
                {{ m.from === 'host' ? 'Toi' : current.guest }} · {{ whenFr(m.at) }}<template v-if="m.from === 'host' && m.status"> · {{ m.status === 'Delivered' ? 'Délivré' : m.status }}</template>
              </p>
              <p v-if="m.from === 'host' && m.subject" class="mb-1 font-medium">{{ m.subject }}</p>
              <p class="whitespace-pre-line">{{ m.text }}</p>
            </div>
          </div>
        </div>

        <!-- E-mails of the shared inbox (Rocket Mailer) linked to the booking -->
        <h4 class="mt-6 mb-2 flex items-center gap-1.5 text-sm font-semibold"><UIcon name="i-lucide-mail" class="size-4 text-muted" /> E-mails liés</h4>
        <p v-if="emailsError" class="text-sm text-muted">E-mails indisponibles : {{ emailsError }}</p>
        <p v-else-if="!emails" class="text-sm text-muted">Chargement…</p>
        <p v-else-if="!emails.available" class="text-sm text-muted">{{ emails.reason }}</p>
        <p v-else-if="!emails.conversations.length" class="text-sm text-muted">Aucun e-mail lié{{ emails.guestEmail ? ` (${emails.guestEmail})` : ' : Lodgify ne donne pas l’adresse du voyageur' }}.</p>
        <div v-else class="space-y-1.5">
          <div v-for="c in emails.conversations" :key="c.id" class="rounded-md border border-default">
            <button type="button" class="flex w-full items-start justify-between gap-2 p-2 text-left hover:bg-elevated" @click="toggleEmail(c.id)">
              <span class="min-w-0">
                <b class="block truncate text-sm">{{ c.subject || '(sans objet)' }}</b>
                <span class="block truncate text-xs text-muted">{{ c.snippet }}</span>
              </span>
              <span class="shrink-0 text-right text-xs text-muted">
                {{ whenFr(c.lastMessageAt) }}<br>
                <UBadge size="sm" variant="subtle" :color="c.matchedBy === 'guest' ? 'info' : 'neutral'" :label="c.matchedBy === 'guest' ? 'Voyageur' : 'N° de réservation'" />
              </span>
            </button>
            <div v-if="openedEmail === c.id" class="space-y-2 border-t border-default p-2">
              <p v-if="!emailThread" class="text-sm text-muted">Chargement…</p>
              <div v-for="m in emailThread ?? []" :key="m.key" class="flex" :class="m.from === 'host' ? 'justify-end' : 'justify-start'">
                <div class="max-w-[85%] rounded-lg px-3 py-2 text-sm" :class="m.from === 'host' ? 'bg-primary/10' : 'bg-elevated'">
                  <p class="mb-1 text-xs text-muted">{{ m.from === 'host' ? 'Toi' : current.guest }} · {{ whenFr(m.at) }}</p>
                  <p class="whitespace-pre-line">{{ m.text }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="mt-4 space-y-2 border-t border-default pt-4">
          <UTabs v-model="channel" :items="channelItems" :content="false" size="xs" variant="pill" class="w-fit" />
          <UInput v-if="channel === 'email'" v-model="subject" placeholder="Objet" :maxlength="200" class="w-full" :disabled="sending || !emails?.guestEmail" />
          <UTextarea v-model="draft" :rows="3" autoresize :placeholder="channel === 'email' ? 'Écrire un e-mail au voyageur…' : 'Écrire au voyageur…'" class="w-full" :disabled="sending || (channel === 'lodgify' && !!convError)" />
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p v-if="channel === 'lodgify'" class="text-xs text-muted">{{ convError ? 'Messagerie Lodgify indisponible.' : `Envoyé à ${current.guest} via ${current.source || 'Lodgify'}.` }}</p>
            <p v-else class="text-xs text-muted">{{ emails?.guestEmail ? `E-mail à ${emails.guestEmail} via Rocket Mailer.` : 'Pas d’adresse e-mail pour ce voyageur.' }}</p>
            <UButton icon="i-lucide-send" :label="channel === 'email' ? 'Envoyer l’e-mail' : 'Envoyer'" :loading="sending" :disabled="!canSend" @click="send" />
          </div>
        </div>
      </UCard>

      <div class="min-h-0 space-y-4 lg:col-span-1 lg:overflow-y-auto">
        <!-- Value of the stay (Lodgify quote) -->
        <UCard>
          <template #header>
            <p class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted"><UIcon name="i-lucide-receipt-euro" class="size-3.5" /> Valeur de la réservation</p>
            <p class="mt-1 text-2xl font-bold">{{ money(pricing?.total ?? current.total, pricing?.currency) }}</p>
            <p v-if="pricing?.nights" class="text-xs text-muted">{{ money((pricing.lines.find(l => l.kind === 'RoomRate')?.amount ?? 0) / pricing.nights, pricing.currency) }} / nuit en moyenne</p>
          </template>
          <p v-if="!pricing" class="text-sm text-muted">Chargement du détail…</p>
          <template v-else>
            <dl class="space-y-1.5 text-sm">
              <div v-for="(l, i) in pricing.lines" :key="i" class="flex justify-between gap-2"><dt class="text-muted">{{ l.label }}</dt><dd class="tabular-nums">{{ money(l.amount, pricing.currency) }}</dd></div>
              <div class="flex justify-between gap-2 border-t border-default pt-1.5 font-semibold"><dt>Total</dt><dd class="tabular-nums">{{ money(pricing.total, pricing.currency) }}</dd></div>
            </dl>
            <div v-if="pricing.paid || pricing.due" class="mt-3 flex flex-wrap gap-1">
              <UBadge v-if="pricing.paid" color="success" variant="subtle" :label="`Payé ${money(pricing.paid, pricing.currency)}`" />
              <UBadge v-if="pricing.due" color="warning" variant="subtle" :label="`Reste dû ${money(pricing.due, pricing.currency)}`" />
            </div>
            <p class="mt-3 text-xs text-muted">Montants du devis Lodgify, hors commission de la plateforme.</p>
          </template>
        </UCard>

        <!-- Smart lock: state and battery, keypad code of the booking, events during the stay -->
        <UCard>
          <template #header>
            <p class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted"><UIcon name="i-lucide-lock" class="size-3.5" /> Serrure connectée</p>
            <div v-for="l in locks?.locks ?? []" :key="l.id" class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
              <b class="text-sm">{{ l.name }}</b>
              <UBadge size="sm" :color="l.locked ? 'success' : 'warning'" variant="subtle" :label="l.state" />
              <UBadge size="sm" :color="l.batteryCritical ? 'error' : 'neutral'" variant="subtle" :icon="batteryIcon(l.battery)" :label="l.battery === null ? '?' : `${l.battery} %`" />
              <UBadge v-if="l.keypadBatteryCritical" size="sm" color="error" variant="subtle" label="Pile clavier faible" />
            </div>
            <p v-if="!locks" class="mt-2 text-xs text-muted">Serrures indisponibles (logement sans lieu Rocket Place, ou Rocket Place injoignable).</p>
            <p v-else-if="!locks.locks.length" class="mt-2 text-xs text-muted">Aucune serrure associée à ce logement.</p>
          </template>
          <div v-if="current.access" class="space-y-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="font-mono text-2xl font-semibold tracking-widest">{{ current.access.code }}</span>
              <UBadge
                :color="current.access.status === 'created' ? 'success' : current.access.status === 'error' ? 'error' : 'neutral'" variant="subtle"
                :label="current.access.status === 'created' ? 'Envoyé à la serrure' : current.access.status === 'error' ? 'Erreur' : 'Prévu'"
              />
            </div>
            <p class="text-xs text-muted">Valable du {{ whenFr(current.access.validFrom) }} au {{ whenFr(current.access.validUntil) }}</p>
            <p v-if="current.access.outdated" class="text-xs text-warning">⚠ Dates modifiées depuis la création : à refaire à la main sur la serrure.</p>
            <p v-if="current.access.status === 'error' && current.access.error" class="text-xs text-error">⚠ {{ current.access.error }}</p>
            <template v-if="current.access.status !== 'created'">
              <UButton v-if="current.phase === 'next'" block icon="i-lucide-key-round" label="Générer sur la serrure" :loading="generating" @click="generateCode" />
              <p v-else class="text-xs text-muted">Séjour commencé ou passé : le code n’est plus envoyé à la serrure.</p>
            </template>
          </div>
          <p v-else class="text-sm text-muted">Pas de code clavier prévu pour cette réservation.</p>
          <template v-for="l in locks?.locks ?? []" :key="l.id">
            <div v-if="stayLogs(l).length" class="mt-3 border-t border-default pt-3">
              <p class="text-xs font-medium text-muted">Passages pendant ce séjour</p>
              <p v-for="(g, i) in stayLogs(l)" :key="i" class="text-xs text-muted">{{ whenFr(g.date) }} · {{ LOCK_ACTIONS[g.action] || `Action ${g.action}` }}<template v-if="g.who"> · {{ g.who }}</template></p>
            </div>
          </template>
        </UCard>
      </div>
    </div>
    <UCard v-else class="min-w-0 flex-1"><p class="text-sm text-muted">Sélectionne une réservation dans la liste.</p></UCard>

    <UModal v-model:open="linkOpen" title="Envoyer le livret d’accueil" description="Rien n’est envoyé avant « Envoyer » et ta confirmation.">
      <template #body>
        <div class="space-y-3">
          <div class="flex flex-wrap gap-2">
            <USelect v-model="linkChannel" :items="[{ label: 'Message Lodgify', value: 'lodgify' }, { label: 'E-mail', value: 'email', disabled: !current?.guestEmail }]" class="w-44" />
            <USelect v-model="linkLang" :items="WELCOME_LANGUAGES.map(l => ({ label: l.label, value: l.value }))" class="w-36" aria-label="Langue du message" />
          </div>
          <UTextarea v-model="linkText" :rows="7" autoresize :maxlength="5000" placeholder="Message par défaut dans la langue choisie" class="w-full" />
          <p class="break-all text-xs text-muted">Lien : {{ linkUrl }} (ajouté au message s’il n’y figure pas). {{ linkChannel === 'email' ? `E-mail à ${current?.guestEmail} via Rocket Mailer.` : 'Message sur le canal de la réservation.' }}</p>
        </div>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton color="neutral" variant="ghost" label="Annuler" @click="linkOpen = false" />
          <UButton icon="i-lucide-send" label="Envoyer" :loading="linkSending" @click="sendLink" />
        </div>
      </template>
    </UModal>
  </div>
</template>
