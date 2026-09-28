<script setup lang="ts">
import type { GuestWelcome, WelcomeLanguage } from '~/types/pms'

// Public welcome book of a stay (secret link per booking, no account): first name, dates, keypad code once sent,
// sections. Language: ?lang= then the browser's (server side), switchable on the page; look set by the host (accent
// colour, cover image, tabs or columns).
definePageMeta({ layout: 'bare', public: true })
const route = useRoute()
const router = useRouter()
const token = computed(() => encodeURIComponent(String(route.params.token)))
const requested = computed(() => typeof route.query.lang === 'string' ? route.query.lang : '')
const { data, error } = await useAsyncData(
  () => `guest-${token.value}-${requested.value}`,
  () => publicApi<GuestWelcome>(`/api/public/guest/${token.value}${requested.value ? `?lang=${encodeURIComponent(requested.value)}` : ''}`),
)
const ui = computed(() => WELCOME_UI[data.value?.lang ?? 'fr'] ?? WELCOME_UI.fr)
useHead({ htmlAttrs: { lang: () => data.value?.lang ?? 'fr' }, title: () => data.value ? `${ui.value.welcome} · ${data.value.property}` : 'Livret d’accueil', meta: [{ name: 'robots', content: 'noindex, nofollow' }, { name: 'referrer', content: 'no-referrer' }] })
const weather = ref<{ temperature: number, code: number } | null>(null)
onMounted(async () => {
  if (data.value) weather.value = await fetchWeather(data.value.latitude, data.value.longitude)
})
const sections = computed(() => WELCOME_SECTIONS.filter(s => !['welcomeText', 'wifiSsid', 'wifiPassword'].includes(s.key) && data.value?.content[s.key]))
const languages = computed(() => WELCOME_LANGUAGES.filter(l => data.value?.languages.includes(l.value)))
const cover = computed(() => coverSrc(data.value?.style))
const tab = ref<string>('')
const tabItems = computed(() => sections.value.map(s => ({ label: ui.value.sections[s.key], icon: s.icon, value: s.key, slot: 'section' as const })))
const time = (iso: string) => new Date(iso).toLocaleString(ui.value.locale, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
const date = (ymd: string) => localDate(ymd, ui.value.locale)
function switchLang(l: WelcomeLanguage) {
  router.replace({ query: { ...route.query, lang: l } })
}
</script>

<template>
  <main class="mx-auto space-y-4 px-4 py-10" :class="data?.style.layout === 'columns' ? 'max-w-5xl' : 'max-w-xl'" :style="accentStyle(data?.style.accent)">
    <UAlert v-if="error" color="warning" variant="subtle" icon="i-lucide-link-2-off" title="Livret indisponible" :description="apiErrorMessage(error)" />
    <template v-else-if="data">
      <img v-if="cover" :src="cover" alt="" class="h-48 w-full rounded-lg object-cover sm:h-64" referrerpolicy="no-referrer">
      <header class="space-y-1">
        <div class="flex items-start justify-between gap-2">
          <p class="text-sm text-muted">{{ data.property }}</p>
          <div v-if="languages.length > 1" class="flex gap-1" role="group" aria-label="Langue / Language">
            <button
              v-for="l in languages" :key="l.value" type="button" :title="l.label" :aria-pressed="l.value === data.lang"
              class="rounded px-1.5 py-0.5 text-xs font-semibold" :class="l.value === data.lang ? 'bg-[var(--pms-accent)] text-white' : 'text-muted hover:text-default'"
              @click="switchLang(l.value)"
            >{{ l.flag }}</button>
          </div>
        </div>
        <h1 class="text-2xl font-bold text-[var(--pms-accent)]">{{ ui.welcome }} {{ data.guest.firstName }} !</h1>
        <p class="text-sm text-muted">{{ ui.stay(date(data.guest.arrival) + (data.guest.checkIn ? ` (${data.guest.checkIn})` : ''), date(data.guest.departure) + (data.guest.checkOut ? ` (${data.guest.checkOut})` : '')) }}</p>
        <p v-if="weather" class="flex items-center gap-1 text-sm"><UIcon :name="weatherIcon(weather.code)" /> {{ weather.temperature }} °C</p>
      </header>
      <UCard v-if="data.content.welcomeText"><p class="whitespace-pre-line">{{ data.content.welcomeText }}</p></UCard>
      <div class="grid gap-4" :class="{ 'sm:grid-cols-2': data.style.layout === 'columns' && data.access && (data.content.wifiSsid || data.content.wifiPassword) }">
        <UCard v-if="data.access" class="border-l-4 border-[var(--pms-accent)]">
          <p class="flex items-center gap-2 text-sm text-muted"><UIcon name="i-lucide-key-round" /> {{ ui.code }}</p>
          <p class="text-3xl font-bold tracking-widest">{{ data.access.code }}</p>
          <p class="text-xs text-muted">{{ ui.valid(time(data.access.validFrom), time(data.access.validUntil)) }}</p>
        </UCard>
        <UCard v-if="data.content.wifiSsid || data.content.wifiPassword">
          <p class="flex items-center gap-2 text-sm text-muted"><UIcon name="i-lucide-wifi" /> {{ ui.wifi }}</p>
          <p><b>{{ data.content.wifiSsid }}</b></p>
          <p v-if="data.content.wifiPassword" class="font-mono">{{ data.content.wifiPassword }}</p>
        </UCard>
      </div>
      <UTabs v-if="data.style.layout === 'tabs' && sections.length > 1" v-model="tab" :items="tabItems" :default-value="sections[0]?.key" variant="link" class="w-full" :ui="{ list: 'overflow-x-auto', indicator: 'bg-[var(--pms-accent)]' }">
        <template #section="{ item }">
          <UCard><p class="whitespace-pre-line">{{ data.content[item.value as keyof typeof data.content] }}</p></UCard>
        </template>
      </UTabs>
      <div v-else class="grid gap-4" :class="{ 'sm:grid-cols-2': data.style.layout === 'columns' }">
        <UCard v-for="s in sections" :key="s.key">
          <p class="mb-1 flex items-center gap-2 text-sm text-[var(--pms-accent)]"><UIcon :name="s.icon" /> {{ ui.sections[s.key] }}</p>
          <p class="whitespace-pre-line">{{ data.content[s.key] }}</p>
        </UCard>
      </div>
    </template>
  </main>
</template>
