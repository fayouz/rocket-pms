<script setup lang="ts">
// Colour marker of a property, next to its name.
defineProps<{ color?: string | null }>()
</script>

<template>
  <span v-if="colorHex(color)" class="inline-block size-2 shrink-0 rounded-full" :style="{ backgroundColor: colorHex(color)! }" />
</template>
