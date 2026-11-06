<template>
  <div class="view" data-view="chat">
    <div class="topbar">
      <button type="button" class="pill-btn" @click="app.openSheet('models')"><svg class="ic-s" aria-hidden="true"><use href="#i-bolt" /></svg><span>{{ app.textModel.name }}</span></button>
      <BalanceButton />
    </div>
    <div class="chat">
      <template v-for="(m, i) in app.chat.messages" :key="m.id">
        <div v-if="m.role === 'user'" class="msg me">{{ m.content }}</div>
        <div v-else class="msg bot glass">
          <span v-if="m.thinking" class="think">Думаю</span>
          <template v-else>{{ m.content }}<span v-if="m.streaming" class="cursor" /></template>
        </div>
        <div v-if="m.role === 'assistant' && !m.thinking && !m.streaming" class="msg-meta">
          <span class="spent"><svg class="token" style="width:14px;height:14px" aria-hidden="true"><use href="#i-token" /></svg>Потрачено {{ tok(m.cost || 1) }}</span>
          <button type="button" class="icon-btn" aria-label="Копировать" @click="copy(m)"><svg class="ic-s" aria-hidden="true"><use href="#i-copy" /></svg></button>
          <button v-if="i === app.chat.messages.length - 1 && prevUser(i)" type="button" class="icon-btn" aria-label="Перегенерировать" @click="regen(i)"><svg class="ic-s" aria-hidden="true"><use href="#i-redo" /></svg></button>
        </div>
      </template>
      <p v-if="!app.chat.messages.length" class="lead" style="text-align:center;color:var(--color-muted)">Спросите что угодно, ответ придёт сюда</p>
    </div>
  </div>
</template>

<script>
import { useApp } from '../stores/app'
import { tok } from '../services/format'
import { tg } from '../services/telegram'
import BalanceButton from '../components/BalanceButton.vue'

export default {
  name: 'ChatView',
  components: { BalanceButton },
  computed: { app() { return useApp() } },
  watch: {
    'app.chat.messages': { deep: true, handler() { this.$nextTick(() => { const s = document.getElementById('scroll'); if (s) s.scrollTop = s.scrollHeight }) } },
  },
  methods: {
    tok,
    prevUser(i) { return this.app.chat.messages[i - 1]?.role === 'user' },
    async copy(m) { tg.haptic('light'); await tg.copy(m.content); this.app.toast('Скопировано') },
    regen(i) {
      const q = this.app.chat.messages[i - 1].content
      this.app.chat.messages.splice(i - 1, 2)
      this.app.sendMessage(q)
    },
  },
}
</script>
