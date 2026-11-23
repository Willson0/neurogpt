import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { parse } from 'vue/compiler-sfc'
import { themeGroups } from '../src/admin/themeGroups.js'
import { labelSelector } from '../src/admin/searchText.js'

const virtualModule = 'virtual:admin-search-index'
const panels = fileURLToPath(new URL('../src/admin/', import.meta.url))

function collect(node, section, result, parent = {}) {
  if (!node.children) return
  const attr = name => node.props?.find(prop => prop.type === 6 && prop.name === name)?.value?.content
  if (node.props?.some(prop => prop.type === 6 && prop.name === 'data-admin-search-ignore')) return
  // Empty and failed states don't describe a destination users can open.
  if (attr('class')?.split(/\s+/).some(css => ['empty', 'spinner-row', 'adm-modal'].includes(css))) return

  const context = attr('data-admin-search-context') ?? parent.context
  const explicit = attr('data-admin-search-title')
  const classes = attr('class')?.split(/\s+/) ?? []
  const indexable = explicit || labelSelector.split(',').includes(node.tag)
    || classes.includes('ctl-label') || (node.tag === 'span' && parent.classes?.includes('stat'))
  if (indexable) {
    const plain = node.children.filter(child => child.type === 2).map(child => child.content).join(' ')
    for (const title of (explicit || plain).split('|')) {
      const name = title.replace(/\s+/g, ' ').trim()
      if (name.length > 1) result.push({ section, name, ...(context ? { context } : {}) })
    }
  }
  for (const child of node.children) collect(child, section, result, { context, classes })
}

export function adminSearchIndex() {
  return {
    name: 'admin-search-index',
    resolveId(id) { if (id === virtualModule) return '\0' + id },
    load(id) {
      if (id !== '\0' + virtualModule) return
      const result = []
      for (const name of readdirSync(panels).filter(name => name !== 'AdminSearch.vue' && /^Admin\w+\.vue$/.test(name)).sort()) {
        const filename = join(panels, name)
        this.addWatchFile(filename)
        const raw = name.slice(5, -4)
        const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename })
        if (descriptor.template?.ast) {
          collect(descriptor.template.ast, raw[0].toLowerCase() + raw.slice(1), result)
        }
      }
      for (const group of themeGroups) {
        for (const name of [group.name, ...group.vars.map(color => color.name)]) result.push({ section: 'theme', name })
      }
      return 'export default ' + JSON.stringify(result)
    },
    handleHotUpdate({ file, server, modules }) {
      if (!/[/\\]src[/\\]admin[/\\]Admin\w+\.vue$/.test(file)) return
      const index = server.moduleGraph.getModuleById('\0' + virtualModule)
      if (index) return [...modules, index]
    },
  }
}
