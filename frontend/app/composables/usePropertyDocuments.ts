import type { ExplorerAdapter, ExplorerItem } from '#file-explorer'

/** A document (folder or file) of a property, proxied by the PMS API (never Rocket Cloud directly). */
export interface PmsDocument {
  id: string // "folder:<id>" or "file:<id>"
  kind: 'folder' | 'file'
  name: string
  size: number | null
  updatedAt: string
}

/** The property is the only space of the explorer (one space per page, no cross-property navigation). */
export const PROPERTY_SPACE = 'property'

/**
 * Adapter of @rocket/file-explorer on the "Documents" tab of a property: every call goes through our own API
 * (/api/properties/{id}/documents…), scoped by the backend to that property's Rocket Cloud folder, so the browser
 * never needs a Rocket Cloud account or token. Read-only for non-admins (the backend also enforces it).
 */
export function usePropertyDocuments(propertyId: string): ExplorerAdapter {
  const api = useApi()
  const auth = useAuth()
  const config = useRuntimeConfig()
  const base = `/api/properties/${propertyId}/documents`
  const item = (d: PmsDocument): ExplorerItem => ({ id: d.id, kind: d.kind, name: d.name, size: d.size ?? undefined, updatedAt: d.updatedAt, spaceId: PROPERTY_SPACE })

  return {
    rootLabel: 'Documents',
    async list(location) {
      const space = { id: PROPERTY_SPACE, name: 'Documents', icon: 'i-lucide-folder' }
      const folder = location.folder ? String(location.folder) : ''
      const { items } = await api<{ items: PmsDocument[] }>(base, { query: folder ? { folder } : {} })

      return { space, spaces: [space], items: items.map(item) }
    },
    async createFolder(location, name) {
      await api(`${base}/folders`, { method: 'POST', body: { name, folder: location.folder ? String(location.folder) : undefined } })
    },
    async upload(location, files) {
      const errors: string[] = []
      for (const file of files) {
        const body = new FormData()
        body.append('file', file)
        if (location.folder) body.append('folder', String(location.folder))
        try {
          await api(`${base}/upload`, { method: 'POST', body })
        }
        catch (error) {
          errors.push(`${file.name} : ${apiErrorMessage(error)}`)
        }
      }
      return { errors }
    },
    async rename(item, name) {
      await api(`${base}/${item.id}`, { method: 'PATCH', body: { name } })
    },
    async move(items, target) {
      for (const it of items) await api(`${base}/${it.id}`, { method: 'PATCH', body: { folder: target.folder ? String(target.folder) : null } })
    },
    async remove(items) {
      for (const it of items) await api(`${base}/${it.id}`, { method: 'DELETE' })
    },
    async resolveFileUrl(item, download) {
      const blob = await $fetch<Blob>(`${base}/${item.id}/content`, {
        baseURL: config.public.apiBase,
        query: download ? { download: 1 } : {},
        headers: { Authorization: auth.authorizationHeader() ?? '' },
        responseType: 'blob',
      })
      return URL.createObjectURL(blob)
    },
    errorMessage: apiErrorMessage,
  }
}
