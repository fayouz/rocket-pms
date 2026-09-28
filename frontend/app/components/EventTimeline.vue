<script setup lang="ts">
import type { CleaningLink, TimelineEvent } from '~/types/pms'

// Vertical timeline: past events filled, upcoming ones greyed; optional property name (and colour) before the description.
// Cleaning events offer "Copier le lien ménage" to admins (secret link for the cleaner, from Rocket Place via PMS).
const props = defineProps<{ events: (TimelineEvent & { property?: string, color?: string, propertyId?: string })[], now: string, propertyId?: string }>()
const api = useApi()
const toast = useToast()
const { isAdmin } = useAuth()

async function copyCleaningLink(propertyId: string, cleaningId: string) {
  try {
    const link = await api<CleaningLink>(`/api/properties/${propertyId}/cleanings/${cleaningId}/link`)
    await navigator.clipboard.writeText(link.url)
    toast.add({ title: 'Lien ménage copié', description: `Valable jusqu’au ${whenFr(link.expiresAt)}`, color: 'success' })
  }
  catch (e) {
    toast.add({ title: 'Lien ménage indisponible', description: apiErrorMessage(e), color: 'error' })
  }
}
const items = computed(() => props.events.map((e, i) => {
  const platform = platformIcon(e.description)
  return {
    value: i, date: whenFr(e.at), title: e.title, icon: e.icon, color: e.color, platform,
    cleaning: e.kind === 'cleaning' && e.cleaningId && (e.propertyId ?? props.propertyId) ? { propertyId: (e.propertyId ?? props.propertyId) as string, id: e.cleaningId } : null,
    description: [e.property, platform ? '' : e.description].filter(Boolean).join(' · '),
  }
}))
const lastPast = computed(() => props.events.filter(e => e.at <= props.now).length - 1)
</script>

<template>
  <UTimeline v-if="events.length" :items="items" :model-value="lastPast" size="sm">
    <template #description="{ item }">
      <span class="inline-flex items-center gap-1.5">
        <PropertyDot :color="item.color" />
        {{ item.description }}
        <UIcon v-if="item.platform" :name="item.platform.name" class="size-3.5 shrink-0" :style="{ color: item.platform.color }" />
      </span>
      <UButton v-if="isAdmin && item.cleaning" size="xs" variant="link" icon="i-lucide-link" label="Copier le lien ménage" class="ms-2 p-0" @click="copyCleaningLink(item.cleaning.propertyId, item.cleaning.id)" />
    </template>
  </UTimeline>
  <p v-else class="text-sm text-muted">Rien sur cette période.</p>
</template>
