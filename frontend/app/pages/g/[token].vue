<script setup lang="ts">
import type { GuestWelcome } from '~/types/pms'

// Public welcome book of a stay (secret link per booking, no account): first name, dates, keypad code once sent, sections.
definePageMeta({ layout: 'bare', public: true })
const route = useRoute()
const { data, error } = await useAsyncData(`guest-${String(route.params.token)}`, () => publicApi<GuestWelcome>(`/api/public/guest/${encodeURIComponent(String(route.params.token))}`))
useHead({ title: () => data.value ? `Bienvenue · ${data.value.property}` : 'Livret d’accueil', meta: [{ name: 'robots', content: 'noindex, nofollow' }, { name: 'referrer', content: 'no-referrer' }] })
const weather = ref<{ temperature: number, code: number } | null>(null)
onMounted(async () => {
  if (data.value) weather.value = await fetchWeather(data.value.latitude, data.value.longitude)
})
const sections = computed(() => WELCOME_SECTIONS.filter(s => !['welcomeText', 'wifiSsid', 'wifiPassword'].includes(s.key) && data.value?.content[s.key]))
const time = (iso: string) => new Date(iso).toLocaleString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
</script>

<template>
  <main class="mx-auto max-w-xl space-y-4 px-4 py-10">
    <UAlert v-if="error" color="warning" variant="subtle" icon="i-lucide-link-2-off" title="Livret indisponible" :description="apiErrorMessage(error)" />
    <template v-else-if="data">
      <header class="space-y-1">
        <p class="text-sm text-muted">{{ data.property }}</p>
        <h1 class="text-2xl font-bold">Bienvenue {{ data.guest.firstName }} !</h1>
        <p class="text-sm text-muted">Du {{ frenchDate(data.guest.arrival) }}<template v-if="data.guest.checkIn"> ({{ data.guest.checkIn }})</template> au {{ frenchDate(data.guest.departure) }}<template v-if="data.guest.checkOut"> ({{ data.guest.checkOut }})</template></p>
        <p v-if="weather" class="flex items-center gap-1 text-sm"><UIcon :name="weatherIcon(weather.code)" /> {{ weather.temperature }} °C</p>
      </header>
      <UCard v-if="data.content.welcomeText"><p class="whitespace-pre-line">{{ data.content.welcomeText }}</p></UCard>
      <UCard v-if="data.access">
        <p class="flex items-center gap-2 text-sm text-muted"><UIcon name="i-lucide-key-round" /> Code de la porte</p>
        <p class="text-3xl font-bold tracking-widest">{{ data.access.code }}</p>
        <p class="text-xs text-muted">Valable du {{ time(data.access.validFrom) }} au {{ time(data.access.validUntil) }}</p>
      </UCard>
      <UCard v-if="data.content.wifiSsid || data.content.wifiPassword">
        <p class="flex items-center gap-2 text-sm text-muted"><UIcon name="i-lucide-wifi" /> Wi-Fi</p>
        <p><b>{{ data.content.wifiSsid }}</b></p>
        <p v-if="data.content.wifiPassword" class="font-mono">{{ data.content.wifiPassword }}</p>
      </UCard>
      <UCard v-for="s in sections" :key="s.key">
        <p class="mb-1 flex items-center gap-2 text-sm text-muted"><UIcon :name="s.icon" /> {{ s.label }}</p>
        <p class="whitespace-pre-line">{{ data.content[s.key] }}</p>
      </UCard>
    </template>
  </main>
</template>
