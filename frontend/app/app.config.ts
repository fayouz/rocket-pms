/**
 * Identity of the application and its own menu entries: the rest of the interface (layout, dashboard,
 * administration pages) is shared by every Rocket application.
 */
export default defineAppConfig({
  ui: {
    colors: {
      primary: 'green',
      neutral: 'slate',
    },
  },
  rocket: {
    id: 'pms',
    name: 'Rocket PMS',
    icon: 'i-lucide-building-2',
    // Login page subtitle.
    tagline: 'La gestion de tes locations courte durée : logements, réservations Lodgify, voyageurs, serrures connectées.',
    // Main menu: the domain pages ("label" entries start a group).
    navigation: [
      { label: 'Logements', type: 'label' },
      { label: 'Tous les logements', icon: 'i-lucide-building-2', to: '/properties', exact: true },
      { label: 'Timeline', icon: 'i-lucide-git-commit-vertical', to: '/timeline' },
    ] as { label: string, icon?: string, to?: string, type?: 'label', exact?: boolean, exactQuery?: boolean, admin?: boolean }[],
    // Extra entries of the Administration menu.
    adminNavigation: [
      { label: 'Serrures Nuki', icon: 'i-lucide-lock', to: '/locks' },
    ] as { label: string, icon: string, to: string, exactQuery?: boolean }[],
    // "Services & raccourcis" of the dashboard, besides the documentation, changelog and API.
    shortcuts: [] as { label: string, description: string, icon: string, to: string, admin?: boolean }[],
    // Hero banner of the dashboard: one quote per day.
    quotes: [
      ['L’hospitalité, c’est d’abord l’attention aux détails.', 'Adage de l’hôtellerie'],
      ['Un voyageur bien accueilli revient, et en parle.', 'Proverbe d’hôte'],
      ['Ce qui n’est pas noté n’est pas fait.', 'Adage de gestion'],
    ] as [string, string][],
  },
})
