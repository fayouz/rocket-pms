<script setup lang="ts">
import type { TvWelcome } from '~/types/pms'

// Kiosk TV screen of a property (secret per-property link, no account, no interaction): rotating welcome / weather /
// Wi-Fi / checkout panels, data refreshed every 10 minutes, full reload 30 minutes before the next check-in (the new
// guest is greeted by name). Language: ?lang= then the browser's. Never shows an access code.
definePageMeta({ layout: false, public: true })
const route = useRoute()
const token = encodeURIComponent(String(route.params.token))
const lang = typeof route.query.lang === 'string' ? `?lang=${encodeURIComponent(route.query.lang)}` : ''
const { data, error, refresh } = await useAsyncData(`tv-${token}`, () => publicApi<TvWelcome>(`/api/public/tv/${token}${lang}`))
const ui = computed(() => WELCOME_UI[data.value?.lang ?? 'fr'] ?? WELCOME_UI.fr)
const cover = computed(() => coverSrc(data.value?.style))
// Cover dimmed behind the text (its URL is validated server side: https, no quotes)
const screenStyle = computed(() => ({ ...accentStyle(data.value?.style.accent), ...(cover.value ? { backgroundImage: `linear-gradient(rgb(10 10 10 / 0.8), rgb(10 10 10 / 0.9)), url("${cover.value}")` } : {}) }))
useHead({ htmlAttrs: { lang: () => data.value?.lang ?? 'fr' }, title: () => data.value?.property ?? 'Écran TV', meta: [{ name: 'robots', content: 'noindex, nofollow' }, { name: 'referrer', content: 'no-referrer' }] })

/** Reloads the whole page once reloadAt is reached (remembered per arrival, so a reload never loops). */
function reloadBeforeArrival() {
  const at = data.value?.reloadAt
  if (!at || Date.now() < Date.parse(at)) return
  try {
    if (sessionStorage.getItem('pms-tv-reloaded') === at) return
    sessionStorage.setItem('pms-tv-reloaded', at)
  }
  catch {
    return // no storage: better no automatic reload than a loop
  }
  window.location.reload()
}

const weather = ref<{ temperature: number, code: number } | null>(null)
const now = ref(new Date())
const panel = ref(0)
const panels = computed(() => {
  const c = data.value?.content ?? {}
  const t = ui.value
  const out: { key: string, title: string, icon: string, text?: string }[] = [{ key: 'welcome', title: data.value?.guest ? `${t.welcome} ${data.value.guest.firstName}` : t.welcome, icon: 'i-lucide-hand-heart', text: c.welcomeText }]
  if (c.wifiSsid) out.push({ key: 'wifi', title: t.wifi, icon: 'i-lucide-wifi' })
  if (data.value?.guest || c.checkoutInfo) out.push({ key: 'checkout', title: t.checkout, icon: 'i-lucide-log-out', text: c.checkoutInfo })
  if (c.localTips) out.push({ key: 'tips', title: t.sections.localTips, icon: 'i-lucide-map', text: c.localTips })
  if (c.houseRules) out.push({ key: 'rules', title: t.sections.houseRules, icon: 'i-lucide-scroll-text', text: c.houseRules })
  return out
})
const current = computed(() => panels.value[panel.value % Math.max(1, panels.value.length)])

let timers: ReturnType<typeof setInterval>[] = []
onMounted(async () => {
  weather.value = await fetchWeather(data.value?.latitude ?? null, data.value?.longitude ?? null)
  timers = [
    setInterval(() => {
      now.value = new Date()
      reloadBeforeArrival()
    }, 30_000),
    setInterval(() => (panel.value += 1), 15_000),
    setInterval(async () => {
      await refresh()
      weather.value = await fetchWeather(data.value?.latitude ?? null, data.value?.longitude ?? null)
    }, 600_000),
  ]
})
onBeforeUnmount(() => timers.forEach(clearInterval))
</script>

<template>
  <div class="relative flex min-h-dvh flex-col bg-neutral-950 bg-cover bg-center p-12 text-white" :style="screenStyle">
    <p v-if="error" class="m-auto text-2xl text-neutral-400">Écran indisponible : {{ apiErrorMessage(error) }}</p>
    <template v-else-if="data">
      <header class="flex items-start justify-between">
        <div>
          <p class="text-2xl text-neutral-400">{{ data.property }}</p>
          <p class="text-6xl font-bold tabular-nums">{{ now.toLocaleTimeString(ui.locale, { hour: '2-digit', minute: '2-digit' }) }}</p>
          <p class="text-2xl capitalize text-neutral-400">{{ now.toLocaleDateString(ui.locale, { weekday: 'long', day: 'numeric', month: 'long' }) }}</p>
        </div>
        <div v-if="weather" class="flex items-center gap-3 text-5xl">
          <UIcon :name="weatherIcon(weather.code)" /> {{ weather.temperature }} °C
        </div>
      </header>
      <Transition name="fade" mode="out-in">
        <section v-if="current" :key="current.key" class="my-auto max-w-5xl space-y-6">
          <h1 class="flex items-center gap-4 text-6xl font-bold"><UIcon :name="current.icon" class="text-[var(--pms-accent)]" /> {{ current.title }}</h1>
          <template v-if="current.key === 'wifi'">
            <p class="text-4xl">{{ ui.network }} : <b>{{ data.content.wifiSsid }}</b></p>
            <p v-if="data.content.wifiPassword" class="font-mono text-4xl">{{ ui.password }} : <b>{{ data.content.wifiPassword }}</b></p>
          </template>
          <template v-else-if="current.key === 'checkout'">
            <p v-if="data.guest" class="text-4xl">{{ localDate(data.guest.departure, ui.locale) }}<template v-if="data.guest.checkOut"> · {{ data.guest.checkOut }}</template></p>
            <p v-if="current.text" class="whitespace-pre-line text-3xl text-neutral-300">{{ current.text }}</p>
          </template>
          <p v-else-if="current.text" class="whitespace-pre-line text-3xl text-neutral-300">{{ current.text }}</p>
          <p v-else-if="current.key === 'welcome' && !data.guest && data.nextArrival" class="text-3xl text-neutral-300">{{ ui.nextArrival }} : {{ localDate(data.nextArrival, ui.locale) }}</p>
        </section>
      </Transition>
      <footer class="flex gap-2">
        <span v-for="(p, i) in panels" :key="p.key" class="h-2 w-10 rounded-full" :class="i === panel % panels.length ? 'bg-[var(--pms-accent)]' : 'bg-neutral-700'" />
      </footer>
    </template>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.6s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
