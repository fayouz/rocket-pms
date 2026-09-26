<script setup lang="ts">
import type { TimelineEvent } from '~/types/pms'

// Vertical timeline: past events filled, upcoming ones greyed; optional property name (and colour) before the description.
const props = defineProps<{ events: (TimelineEvent & { property?: string, color?: string })[], now: string }>()
const items = computed(() => props.events.map((e, i) => {
  const platform = platformIcon(e.description)
  return {
    value: i, date: whenFr(e.at), title: e.title, icon: e.icon, color: e.color, platform,
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
    </template>
  </UTimeline>
  <p v-else class="text-sm text-muted">Rien sur cette période.</p>
</template>
