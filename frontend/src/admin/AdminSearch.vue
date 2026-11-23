<template>
  <form ref="root" class="adm-search" role="search" @submit.prevent="select(active)" @focusout="onFocusOut">
    <div class="search-input">
      <span class="search-icon" aria-hidden="true"></span>
      <label class="visually-hidden" for="admin-search">Поиск в админке</label>
      <input id="admin-search" ref="input" v-model="query" type="search" maxlength="128" autocomplete="off"
        placeholder="Поиск в админке" role="combobox" aria-autocomplete="list" :aria-expanded="shown"
        :aria-controls="shown && canSearch ? 'admin-search-options' : undefined"
        :aria-activedescendant="shown && active >= 0 ? optionId(active) : undefined"
        @focus="open = true" @input="queryChanged" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
        @keydown.esc.stop.prevent="open = false">
      <button v-if="query" type="button" class="search-clear" aria-label="Очистить поиск" @click="clear">
        <svg class="ic-s" aria-hidden="true"><use href="#i-close" /></svg>
      </button>
    </div>
    <div v-if="shown" class="search-menu">
      <p v-if="!canSearch" class="search-hint" role="status">Введите хотя бы два символа.</p>
      <template v-else>
        <p v-if="!results.length" class="search-hint" role="status">В настройках совпадений нет.</p>
        <div id="admin-search-options" class="search-options" role="listbox" aria-label="Результаты поиска">
          <button v-for="(item, index) in options" :id="optionId(index)" :key="item.key" type="button"
            role="option" :aria-selected="active === index" tabindex="-1" :class="['search-option', item.lookup && 'search-lookup']"
            @pointermove="active = index" @mousedown.prevent @click="select(index)">
            <span class="search-glyph" aria-hidden="true"><svg><use :href="'#' + item.icon" /></svg></span>
            <span class="search-label">
              <small v-if="item.description">{{ item.description }}</small>
              <span><template v-for="(part, j) in item.parts" :key="j"><mark v-if="part.match">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>
            </span>
            <svg class="search-arrow" aria-hidden="true"><use href="#i-chev" /></svg>
          </button>
        </div>
      </template>
    </div>
  </form>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import headings from 'virtual:admin-search-index'
import { matchParts, normalize } from './searchText'

const props = defineProps({ sections: { type: Array, required: true } })
const emit = defineEmits(['select'])
const root = ref(null), input = ref(null), query = ref(''), open = ref(false), active = ref(-1)

const aliases = {
  dashboard: 'статистика сводка', providerEvents: 'логи ошибка журнал api kie suno replicate openai hydra openrouter',
  content: 'генерации история фото видео аудио', models: 'провайдер suno kling', showcase: 'фото видеосеты фотосеты витрина',
  pricing: 'токены баланс бонусы цены', settings: 'конфигурация', payments: 'юкасса yookassa webhook вебхук звезды оплата транзакции',
  renewal: 'повтор интервал попытки подписка продление автосписания срок', promos: 'скидки процент купон промокод',
  theme: 'оформление интерфейс тема', users: 'telegram аккаунты',
}
const fieldAliases = {
  'Вкладка «Песня» включена': 'suno музыка песни песню аудио',
  'Максимальный размер фото, МБ (до 10)': 'лимит загрузка файл',
  'Тип скидки': 'разновидность процент фиксированная сумма',
  'Адрес для уведомлений': 'webhook вебхук callback',
}
const icons = {
  dashboard: 'i-home', content: 'i-gallery', models: 'i-spark', tools: 'i-wand', showcase: 'i-image',
  pricing: 'i-wallet', settings: 'i-gear', payments: 'i-wallet', renewal: 'i-clock', promos: 'i-gift',
  providerEvents: 'i-history', theme: 'i-star', users: 'i-people',
}
const subpages = [
  ['payments', 'shop', 'Настройка ЮKassa'], ['users', 'admins', 'Администраторы'],
  ['showcase', 'photosets', 'Фото- и видеосеты'], ['showcase', 'news', 'Новости'],
  ['content', 'chat', 'Чат'], ['models', 'video-tab', 'Видеомодели'],
]
const contextNames = {
  shop: 'Настройка ЮKassa', receipts: 'Чеки', admins: 'Администраторы', chat: 'Чат', 'showcase-news': 'Новости',
  'showcase-photos': 'Фото- и видеосеты', 'model-video': 'Видео',
}

const catalog = computed(() => {
  const sections = Object.fromEntries(props.sections.map(section => [section.id, section.name]))
  const navigation = props.sections.map(({ id, name }) => ({ id, name, terms: aliases[id] }))
  const pages = subpages.map(([id, context, name]) => ({ id, context, name }))
  const fields = headings.filter(item => sections[item.section]).map(({ section: id, name, context }) => ({
    id, name, context, target: name, terms: fieldAliases[name],
  }))
  const items = [...navigation, ...pages, ...fields].map(item => ({
    ...item, description: item.name === sections[item.id] ? 'Раздел' : [sections[item.id], contextNames[item.context]].filter(Boolean).join(' › '),
    key: [item.id, item.context, item.name].join(':'), icon: icons[item.id],
  }))
  return [...new Map(items.map(item => [item.key, item])).values()]
})
const text = computed(() => normalize(query.value))
const terms = computed(() => text.value.split(' ').filter(Boolean))
const canSearch = computed(() => text.value.replaceAll(' ', '').length >= 2)
const shown = computed(() => open.value && !!query.value.trim())

function score(item) {
  const title = normalize(item.name)
  const extra = normalize(item.description + ' ' + (item.terms || ''))
  if (!terms.value.every(word => title.includes(word) || extra.includes(word))) return 0
  if (title === text.value) return 100
  if (title.startsWith(text.value)) return 80
  if (title.includes(text.value)) return 65
  if (terms.value.every(word => title.split(' ').some(chunk => chunk.startsWith(word)))) return 55
  if (terms.value.every(word => title.includes(word))) return 40
  return terms.value.some(word => title.includes(word)) ? 25 : 10
}

const results = computed(() => canSearch.value ? catalog.value.map(item => ({ item, rank: score(item) }))
  .filter(entry => entry.rank)
  .sort((a, b) => b.rank - a.rank || Number(!b.item.target) - Number(!a.item.target) || a.item.name.localeCompare(b.item.name, 'ru'))
  .slice(0, 9).map(({ item }) => item) : [])
const options = computed(() => {
  if (!canSearch.value) return []
  const q = query.value.trim()
  const lookups = [
    { id: 'providerEvents', name: 'Искать в журнале API', icon: 'i-history' },
    { id: 'users', name: 'Искать среди пользователей', icon: 'i-people' },
  ].map(item => ({ ...item, key: 'lookup:' + item.id, lookup: true, query: q }))
  if (q.startsWith('@')) lookups.reverse()
  return [...results.value, ...lookups].map(item => ({ ...item, parts: matchParts(item.name, terms.value) }))
})
const optionId = index => 'admin-search-result-' + index

function queryChanged(event) {
  open.value = true
  active.value = normalize(event.target.value).replaceAll(' ', '').length >= 2 ? 0 : -1
  nextTick(() => { const list = root.value?.querySelector('.search-options'); if (list) list.scrollTop = 0 })
}
function move(delta) {
  if (!options.value.length) return
  const wasOpen = open.value
  open.value = true
  const total = options.value.length
  active.value = wasOpen && active.value >= 0 ? (active.value + delta + total) % total : (delta > 0 ? 0 : total - 1)
  nextTick(() => document.getElementById(optionId(active.value))?.scrollIntoView({ block: 'nearest', inline: 'nearest' }))
}
function select(index) {
  const selected = shown.value ? options.value[Math.max(0, index)] : null
  if (!selected) return
  open.value = false
  query.value = ''
  active.value = -1
  input.value?.blur()
  emit('select', selected)
}
function clear() {
  query.value = ''
  active.value = -1
  input.value?.focus()
}
function outside(event) { if (!root.value?.contains(event.target)) open.value = false }
function onFocusOut(event) { if (event.relatedTarget && !root.value?.contains(event.relatedTarget)) open.value = false }

onMounted(() => document.addEventListener('pointerdown', outside))
onBeforeUnmount(() => document.removeEventListener('pointerdown', outside))
</script>

<style scoped>
.adm-search { position: relative; z-index: 3; min-width: 0; }
.search-input {
  display: grid; grid-template-columns: 16px minmax(0, 1fr) auto; align-items: center; gap: 9px;
  min-height: 44px; padding: 0 9px 0 12px; color: var(--color-muted);
  background: var(--color-surface-2); border: 1px solid var(--color-rule); border-radius: var(--radius-ctl);
}
.search-input:focus-within { border-color: var(--color-accent); box-shadow: 0 0 0 2px color-mix(in oklab, var(--color-accent) 15%, transparent); }
.search-icon { position: relative; width: 12px; height: 12px; border: 1.5px solid currentColor; border-radius: 50%; }
.search-icon::after { content: ''; position: absolute; width: 5px; height: 1.5px; background: currentColor; border-radius: 2px; right: -4px; bottom: -2px; transform: rotate(45deg); }
.search-input input { min-width: 0; width: 100%; padding: 9px 0; border: 0; outline: 0; background: transparent; color: var(--color-ink); font: inherit; font-size: var(--text-2); }
.search-input input::-webkit-search-cancel-button { display: none; }
.search-input input::placeholder { color: var(--color-muted); opacity: 1; }
.search-clear { appearance: none; border: 0; color: var(--color-muted); background: transparent; border-radius: 50%; display: grid; place-items: center; min-width: 28px; min-height: 28px; padding: 0; }
.search-clear:hover { background: var(--color-surface); color: var(--color-ink); }
.search-menu {
  position: absolute; inset: calc(100% + 7px) 0 auto; min-width: 0;
  border: 1px solid var(--color-rule); border-radius: var(--radius-card);
  background: var(--color-surface);
  box-shadow: 0 14px 36px color-mix(in oklab, var(--shadow-deep) 26%, transparent),
    0 3px 8px color-mix(in oklab, var(--shadow-deep) 22%, transparent);
  overflow: hidden;
}
.search-options { overflow-y: auto; max-height: min(415px, 56dvh); padding: 5px; scrollbar-width: thin; overscroll-behavior: contain; }
.search-option {
  appearance: none; border: 0; width: 100%; display: grid; grid-template-columns: 32px minmax(0, 1fr) 12px;
  align-items: center; gap: 10px; padding: 8px; border-radius: var(--radius-ctl);
  background: transparent; color: var(--color-ink); font: inherit; font-size: var(--text-2); text-align: start;
}
.search-option[aria-selected='true'] { background: var(--color-surface-2); }
.search-glyph { width: 32px; height: 32px; border-radius: 10px; display: grid; place-items: center; color: var(--color-muted); background: var(--color-surface-2); }
.search-option[aria-selected='true'] .search-glyph { background: color-mix(in oklab, var(--color-accent) 14%, var(--color-surface)); color: var(--color-ink); }
.search-glyph svg { width: 16px; height: 16px; }
.search-label { display: grid; min-width: 0; gap: 2px; overflow-wrap: anywhere; }
.search-label > span { font-weight: 600; }
.search-label small, .search-hint { font-size: var(--text-cap); color: var(--color-muted); }
.search-label mark { color: var(--color-ink); border-radius: 3px; background: color-mix(in oklab, var(--color-accent) 20%, var(--color-surface)); }
.search-arrow { width: 12px; height: 12px; color: var(--color-muted); }
.search-option:not([aria-selected='true']) .search-arrow { opacity: .55; }
.search-lookup .search-label > span { font-weight: 400; }
.search-option:not(.search-lookup) + .search-lookup { border-top: 1px solid var(--color-rule); margin-top: 4px; }
.search-hint { margin: 0; padding: 12px 14px; }
@media (min-width: 900px) { .search-menu { inset-inline-end: auto; width: 355px; } }
</style>
