<template>
  <section class="adm-sec">
    <div v-if="loading" class="spinner-row">Загружаем…</div>
    <div v-else-if="failed" class="empty pad"><h3>Не получилось загрузить</h3><button type="button" class="pill-btn" @click="load">Повторить</button></div>
    <template v-else>
      <form class="adm-card glass" data-admin-target="limits" @submit.prevent="save(['limits', 'upload_max_kb'], 'Лимиты сохранены')">
        <h3>Лимиты</h3>
        <div class="adm-grid wide">
          <label class="adm-f">Генераций одновременно у одного человека<input v-model.number="v.limits.active_generations" type="number" min="1" max="100" required></label>
          <label class="adm-f">Бесплатных улучшений промпта в день<input v-model.number="v.limits.improve_per_day" type="number" min="0" required></label>
          <label class="adm-f">Наград за друзей на одного человека<input v-model.number="v.limits.referral_rewards" type="number" min="0" required></label>
          <label class="adm-f">Через сколько секунд зависшая генерация отменяется<input v-model.number="v.limits.generation_timeout" type="number" min="60" max="86400" required></label>
          <label class="adm-f">Максимальный размер фото, МБ (до 10)<input v-model.number="uploadMb" type="number" min="1" max="10" required></label>
        </div>
        <div class="adm-actions"><button type="submit" class="adm-btn" :disabled="busy">Сохранить</button></div>
      </form>

      <form class="adm-card glass" @submit.prevent="save(['features', 'telegram.support_url', 'providers.openai.text_model', 'providers.openai.image_model', 'providers.kie.translate'], 'Сохранено')">
        <h3>Приложение</h3>
        <div class="adm-grid wide">
          <label class="adm-check" data-admin-target="songs"><input v-model="v.features.songs" type="checkbox">Вкладка «Песня» включена</label>
          <label class="adm-check" data-admin-target="translation"><input v-model="v['providers.kie.translate']" type="checkbox">Переводить промпты видео на английский</label>
          <label class="adm-f" data-admin-target="support">Ссылка на поддержку<input v-model.trim="v['telegram.support_url']" type="url" placeholder="https://t.me/your_support" maxlength="255"></label>
          <label class="adm-f">Текстовая модель (улучшение и перевод промптов)<input v-model.trim="v['providers.openai.text_model']" required maxlength="120"></label>
          <label class="adm-f">Модель картинок по умолчанию для новых инструментов<input v-model.trim="v['providers.openai.image_model']" required maxlength="120"></label>
        </div>
        <p class="adm-note">Ключи API и токен бота остаются в файле .env на сервере: через админку они не меняются и не показываются. ЮKassa настраивается в разделе «Платежи и ЮKassa».</p>
        <div class="adm-actions"><button type="submit" class="adm-btn" :disabled="busy">Сохранить</button></div>
      </form>

      <form class="adm-card glass" data-admin-target="styles" @submit.prevent="saveStyles">
        <h3>Стили картинок</h3>
        <p class="adm-note">Текст стиля добавляется к промпту пользователя. У стиля «any» текста нет.</p>
        <div v-for="(s, i) in styles" :key="i" class="adm-line">
          <label class="adm-f">Id<input v-model.trim="s.id" required pattern="[a-z0-9_-]+" maxlength="32" :readonly="s.id === 'any' && s.locked"></label>
          <label class="adm-f">Название<input v-model.trim="s.name" required maxlength="32"></label>
          <label class="adm-f" style="grid-column:span 2">Текст для модели<input v-model.trim="s.prompt" maxlength="1000" :disabled="s.id === 'any'"></label>
          <button v-if="!(s.id === 'any' && s.locked)" type="button" class="x" aria-label="Убрать стиль" @click="styles.splice(i, 1)"><svg class="ic-s" aria-hidden="true"><use href="#i-close" /></svg></button>
        </div>
        <div class="adm-actions"><button type="submit" class="adm-btn" :disabled="busy">Сохранить</button><button type="button" class="adm-btn soft" @click="styles.push({ id: '', name: '', prompt: '' })">Добавить стиль</button></div>
      </form>
    </template>
  </section>
</template>

<script>
import { adminApi } from '../services/api'
import { section } from './shared'

export default {
  name: 'AdminSettings',
  mixins: [section],
  props: { initialTarget: { type: String, default: '' } },
  data: () => ({ v: null, styles: [], uploadMb: 10 }),
  async mounted() {
    await this.load()
    if (!['limits', 'songs', 'translation', 'support', 'styles'].includes(this.initialTarget)) return
    await this.$nextTick()
    const target = this.$el.querySelector('[data-admin-target="' + this.initialTarget + '"]')
    target?.scrollIntoView({ block: 'center' })
    target?.querySelector('input')?.focus({ preventScroll: true })
  },
  methods: {
    load() { return this.fetch(async () => { this.take((await adminApi.settings()).data) }) },
    take(v) {
      this.v = v
      this.uploadMb = Math.floor(v.upload_max_kb / 1024)
      this.styles = Object.entries(v.styles).map(([id, s]) => ({ id, name: s.name, prompt: s.prompt || '', locked: id === 'any' }))
    },
    save(keys, ok) {
      this.v.upload_max_kb = this.uploadMb * 1024
      const values = Object.fromEntries(keys.map((k) => [k, this.v[k] === '' ? null : this.v[k]]))
      return this.run(async () => { this.take((await adminApi.saveSettings(values)).data); this.app.reloadCatalog() }, ok)
    },
    saveStyles() {
      const styles = Object.fromEntries(this.styles.map((s) => [s.id, { name: s.name, prompt: s.id === 'any' ? null : s.prompt || null }]))
      return this.run(async () => { this.take((await adminApi.saveSettings({ styles })).data); this.app.reloadCatalog() }, 'Стили сохранены')
    },
  },
}
</script>
