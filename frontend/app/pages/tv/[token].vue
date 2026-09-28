<script setup lang="ts">
import type { TvWelcome } from '~/types/pms'

// Kiosk TV screen of a property (secret per-property link, no account, no interaction): rotating welcome / weather /
// Wi-Fi / checkout panels, data refreshed every 10 minutes. Never shows an access code.
definePageMeta({ layout: false, public: true })
const route = useRoute()
const token = encodeURIComponent(String(route.params.token))
const { data, error, refresh } = await useAsyncData(`tv-${token}`, () => publicApi<TvWelcome>(`/api/public/tv/${token}`))
useHead({ title: () => data.value?.property ?? 'Écran TV', meta: [{ name: 'robots', content: 'noindex, nofollow' }, { name: 'referrer', content: 'no-referrer' }] })

const weather = ref<{ temperature: number, code: number } | null>(null)
const now = ref(new Date())
const panel = ref(0)
const panels = computed(() => {
  const c = data.value?.content ?? {}
  const out: { key: string, title: string, icon: string, text?: string }[] = [{ key: 'welcome', title: data.value?.guest ? `Bienvenue ${data.value.guest.firstName}` : 'Bienvenue', icon: 'i-lucide-hand-heart', text: c.welcomeText }]
  if (c.wifiSsid) out.push({ key: 'wifi', title: 'Wi-Fi', icon: 'i-lucide-wifi' })
  if (data.value?.guest || c.checkoutInfo) out.push({ key: 'checkout', title: 'Départ', icon: 'i-lucide-log-out', text: c.checkoutInfo })
  if (c.localTips) out.push({ key: 'tips', title: 'Bonnes adresses', icon: 'i-lucide-map', text: c.localTips })
  if (c.houseRules) out.push({ key: 'rules', title: 'Règlement intérieur', icon: 'i-lucide-scroll-text', text: c.houseRules })
  return out
})
const current = computed(() => panels.value[panel.value % Math.max(1, panels.value.length)])

let timers: ReturnType<typeof setInterval>[] = []
onMounted(async () => {
  weather.value = await fetchWeather(data.value?.latitude ?? null, data.value?.longitude ?? null)
  timers = [
    setInterval(() => (now.value = new Date()), 30_000),
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
  <div class="flex min-h-dvh flex-col bg-neutral-950 p-12 text-white">
    <p v-if="error" class="m-auto text-2xl text-neutral-400">Écran indisponible : {{ apiErrorMessage(error) }}</p>
    <template v-else-if="data">
      <header class="flex items-start justify-between">
        <div>
          <p class="text-2xl text-neutral-400">{{ data.property }}</p>
          <p class="text-6xl font-bold tabular-nums">{{ now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }}</p>
          <p class="text-2xl capitalize text-neutral-400">{{ now.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }) }}</p>
        </div>
        <div v-if="weather" class="flex items-center gap-3 text-5xl">
          <UIcon :name="weatherIcon(weather.code)" /> {{ weather.temperature }} °C
        </div>
      </header>
      <Transition name="fade" mode="out-in">
        <section v-if="current" :key="current.key" class="my-auto max-w-5xl space-y-6">
          <h1 class="flex items-center gap-4 text-6xl font-bold"><UIcon :name="current.icon" /> {{ current.title }}</h1>
          <template v-if="current.key === 'wifi'">
            <p class="text-4xl">Réseau : <b>{{ data.content.wifiSsid }}</b></p>
            <p v-if="data.content.wifiPassword" class="font-mono text-4xl">Mot de passe : <b>{{ data.content.wifiPassword }}</b></p>
          </template>
          <template v-else-if="current.key === 'checkout'">
            <p v-if="data.guest" class="text-4xl">Le {{ frenchDate(data.guest.departure) }}<template v-if="data.guest.checkOut"> avant {{ data.guest.checkOut }}</template></p>
            <p v-if="current.text" class="whitespace-pre-line text-3xl text-neutral-300">{{ current.text }}</p>
          </template>
          <p v-else-if="current.text" class="whitespace-pre-line text-3xl text-neutral-300">{{ current.text }}</p>
          <p v-else-if="current.key === 'welcome' && !data.guest && data.nextArrival" class="text-3xl text-neutral-300">Prochaine arrivée : {{ frenchDate(data.nextArrival) }}</p>
        </section>
      </Transition>
      <footer class="flex gap-2">
        <span v-for="(p, i) in panels" :key="p.key" class="h-2 w-10 rounded-full" :class="i === panel % panels.length ? 'bg-white' : 'bg-neutral-700'" />
      </footer>
    </template>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.6s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
