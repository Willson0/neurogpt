<template>
  <div class="view" data-view="history">
    <div v-if="!app.history.loaded" class="spinner-row">Загружаем…</div>
    <div v-else-if="app.history.error" class="empty pad"><h3>Не получилось загрузить</h3><button type="button" class="pill-btn" @click="app.loadHistory()">Повторить</button></div>
    <div v-else-if="!groups.length" class="empty pad"><Sticker name="sleep" :size="96" /><h3>Операций пока нет</h3></div>
    <div v-for="g in groups" :key="g.day" style="display:grid;gap:var(--space-xs)">
      <div class="day">{{ g.day }}</div>
      <ul class="list glass">
        <li v-for="t in g.items" :key="t.id">
          <div class="row">
            <span :class="['sq', t.type === 'topup' || t.type === 'subscription' ? 'hot' : '']"><svg class="ic-s" aria-hidden="true"><use :href="'#i-' + icon(t)" /></svg></span>
            <span class="t"><b>{{ t.title }}</b><span v-if="t.details">{{ t.details }}</span></span>
            <span :class="['amt', 'num', t.amount > 0 ? 'plus' : '']">{{ t.amount > 0 ? '+' : '−' }}{{ Math.abs(t.amount) }}</span>
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>

<script>
import { useApp } from '../stores/app'
import Sticker from '../components/Sticker.vue'

const ICONS = { topup: 'wallet', spend: 'image', refund: 'redo', bonus: 'gift', subscription: 'crown', referral: 'people' }

export default {
  name: 'HistoryView',
  components: { Sticker },
  computed: {
    app() { return useApp() },
    groups() {
      const out = []
      const today = new Date().toDateString()
      const yest = new Date(Date.now() - 86400000).toDateString()
      for (const t of this.app.history.items || []) {
        const d = new Date(t.created_at)
        const label = d.toDateString() === today ? 'Сегодня' : d.toDateString() === yest ? 'Вчера' : d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })
        let g = out.find((x) => x.day === label)
        if (!g) out.push((g = { day: label, items: [] }))
        g.items.push(t)
      }
      return out
    },
  },
  mounted() { this.app.loadHistory() },
  methods: { icon: (t) => (t.type === 'spend' && /чат/i.test(t.title) ? 'text' : ICONS[t.type] || 'image') },
}
</script>
