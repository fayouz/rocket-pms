<script setup lang="ts">
import type { Plugin } from '~/types/pms'

// Administration: catalogue of the built-in plugins (code-defined, nothing external is executed). Connectors are
// configured per property in the "Domotique" tab of each property.
definePageMeta({ admin: true })
useHead({ title: () => `Plugins · ${useAppConfig().rocket.name}` })
const api = useApi()
const { data: plugins } = await useAsyncData('plugins-catalogue', () => api<Plugin[]>('/api/plugins'), { default: () => [] })
</script>

<template>
  <UDashboardPanel id="plugins">
    <template #header>
      <UDashboardNavbar title="Plugins">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <p class="text-sm text-muted">Catalogue intégré à l'application (aucun code externe n'est exécuté). Un connecteur = un plugin configuré pour un logement ; ajoute-en dans l'onglet « Domotique » de chaque logement.</p>
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <UCard v-for="p in plugins" :key="p.id">
          <template #header>
            <span class="flex items-center gap-2"><UIcon :name="p.icon" class="size-5" /> <b>{{ p.name }}</b></span>
          </template>
          <p class="text-sm text-muted">{{ p.description }}</p>
          <ul class="mt-2 space-y-0.5 text-xs text-muted">
            <li v-for="f in p.fields" :key="f.key">
              {{ f.label }} <span v-if="f.required">*</span><span v-if="f.secret"> (variable .env)</span>
            </li>
          </ul>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
