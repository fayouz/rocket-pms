<script setup lang="ts">
// Logo of the platform (Airbnb, Booking.com) is enough; other sources are shown as text.
const props = defineProps<{ source: string }>()
const icon = computed(() => platformIcon(props.source))
</script>

<template>
  <span class="inline-flex items-center text-sm text-muted" :title="source">
    <UIcon v-if="icon" :name="icon.name" class="size-4 shrink-0" :style="{ color: icon.color }" />
    <template v-else>{{ source }}</template>
  </span>
</template>
