<template>
  <section class="adm-sec">
    <div class="adm-bar"><p class="adm-note grow">Инструменты вкладки «Ещё». В инструкции {input} заменяется текстом пользователя, {option} выбранным вариантом.</p><button type="button" class="adm-btn sm" @click="add">Добавить инструмент</button></div>
    <div v-if="loading" class="spinner-row">Загружаем…</div>
    <div v-else-if="failed" class="empty pad"><h3>Не получилось загрузить</h3><button type="button" class="pill-btn" @click="load">Повторить</button></div>
    <div v-else>
      <div v-for="t in tools" :key="t.slug || 'new'" :class="['adm-item', 'glass', t.is_active ? '' : 'off']">
        <button type="button" class="row" :aria-expanded="String(edit === t)" @click="toggle(t)">
          <span class="sq"><svg class="ic-s" aria-hidden="true"><use :href="'#i-' + t.icon" /></svg></span>
          <span class="t"><b>{{ t.name || 'Новый инструмент' }}</b><span>{{ t.hint }}</span></span>
          <span class="end"><span v-if="!t.is_active" class="adm-pill bad">выкл</span>{{ t.cost }} ток.</span>
        </button>
        <form v-if="edit === t" class="adm-edit" @submit.prevent="save">
          <div class="adm-grid">
            <label v-if="isNew" class="adm-f">Id (латиницей)<input v-model.trim="f.slug" required pattern="[a-z0-9_]+" maxlength="32" placeholder="my_tool"></label>
            <label class="adm-f">Название<input v-model.trim="f.name" required maxlength="64"></label>
            <label class="adm-f">Цена, токенов<input v-model.number="f.cost" type="number" min="0" required></label>
            <label class="adm-f">Группа<select v-model="f.group"><option value="fix">Исправить</option><option value="create">Создать</option></select></label>
            <label class="adm-f">Иконка<select v-model="f.icon"><option v-for="i in icons" :key="i" :value="i">{{ i }}</option></select></label>
            <label class="adm-f">Результат<select v-model="f.output"><option value="image">Картинка JPEG</option><option value="png">PNG с прозрачностью</option><option value="video">Видео</option></select></label>
            <label class="adm-f full">Подсказка под названием<input v-model.trim="f.hint" maxlength="255"></label>
            <label class="adm-f">Что вводит пользователь<select v-model="f.input"><option value="none">Только фото</option><option value="text">Фото и текст по желанию</option><option value="text_required">Фото и обязательный текст</option><option value="choice">Фото и выбор варианта</option></select></label>
            <label v-if="f.input === 'text' || f.input === 'text_required'" class="adm-f">Подсказка в поле ввода<input v-model.trim="f.placeholder" maxlength="120"></label>
            <label class="adm-check"><input v-model="f.is_active" type="checkbox">Показывать в приложении</label>
            <label class="adm-f full">Инструкция для модели (промпт)<textarea v-model.trim="f.prompt" required maxlength="4000" rows="5" /></label>
            <label v-if="f.input === 'text'" class="adm-f full">Текст перед пожеланием пользователя<input v-model="f.input_prefix" maxlength="200"></label>
          </div>
          <div v-if="f.input === 'choice'" class="adm-sub">
            <span class="ctl-label">Варианты на выбор</span>
            <div v-for="(c, i) in f.choices" :key="i" class="adm-line">
              <label class="adm-f">Id<input v-model.trim="c.id" required pattern="[a-z0-9_]+" maxlength="32"></label>
              <label class="adm-f">Название<input v-model.trim="c.name" required maxlength="64"></label>
              <label class="adm-f" style="grid-column:span 2">Текст для {option}<input v-model.trim="c.prompt" required maxlength="1000"></label>
              <button type="button" class="x" aria-label="Убрать вариант" @click="f.choices.splice(i, 1)"><svg class="ic-s" aria-hidden="true"><use href="#i-close" /></svg></button>
            </div>
            <button type="button" class="adm-btn soft sm" style="justify-self:start" @click="f.choices.push({ id: '', name: '', prompt: '' })">Добавить вариант</button>
          </div>
          <div class="adm-sub">
            <span class="ctl-label">Провайдер</span>
            <div class="adm-grid">
              <label class="adm-f">Через что работает<select v-model="f.driver"><option v-for="d in drivers" :key="d" :value="d">{{ driverNames[d] || d }}</option></select></label>
              <label class="adm-f">Модель у провайдера<input v-model.trim="f.provider_model" maxlength="255"></label>
            </div>
            <div v-for="(o, i) in f.extra" :key="i" class="adm-line">
              <label class="adm-f">Параметр<input v-model.trim="o.key" pattern="[a-z_]+" maxlength="32"></label>
              <label class="adm-f">Значение<input v-model.trim="o.value" maxlength="120"></label>
              <button type="button" class="x" aria-label="Убрать параметр" @click="f.extra.splice(i, 1)"><svg class="ic-s" aria-hidden="true"><use href="#i-close" /></svg></button>
            </div>
            <button type="button" class="adm-btn soft sm" style="justify-self:start" @click="f.extra.push({ key: '', value: '' })">Добавить параметр</button>
          </div>
          <div class="adm-actions">
            <button type="submit" class="adm-btn" :disabled="busy">Сохранить</button>
            <button type="button" class="adm-btn soft" @click="cancel">Отмена</button>
            <button v-if="!isNew" type="button" class="adm-btn danger" :disabled="busy" @click="remove(t)">{{ confirming ? 'Точно удалить?' : 'Удалить' }}</button>
          </div>
        </form>
      </div>
    </div>
  </section>
</template>

<script>
import { adminApi } from '../services/api'
import { section, DRIVERS, toPairs, fromPairs } from './shared'

export default {
  name: 'AdminTools',
  mixins: [section],
  data: () => ({
    tools: [], drivers: [], driverNames: DRIVERS, edit: null, f: {}, isNew: false, confirming: false,
    icons: ['wand', 'upscale', 'eraser', 'image', 'user', 'history', 'dna', 'spark', 'heart', 'crown', 'frame', 'video', 'star', 'bolt', 'gift', 'fire', 'music', 'text'],
  }),
  mounted() { this.load() },
  methods: {
    load() { return this.fetch(async () => { const r = await adminApi.list('tools'); this.tools = r.data; this.drivers = r.drivers }) },
    toggle(t) {
      if (this.edit === t) return this.cancel()
      this.cancel()
      this.edit = t
      this.isNew = false
      this.confirming = false
      this.f = { ...t, choices: t.choices.map((c) => ({ ...c })), extra: toPairs(t.options), driver: t.driver || this.drivers[0], provider_model: t.provider_model || '' }
    },
    add() {
      this.cancel()
      const fresh = { slug: '', name: '', hint: '', cost: 10, icon: 'wand', group: 'create', input: 'none', placeholder: '', prompt: '', input_prefix: 'Additional wish from the user: ', output: 'image', choices: [], is_active: true, driver: this.tools.find((x) => x.driver && x.driver !== 'kie')?.driver || this.drivers[0], provider_model: this.tools.find((x) => x.driver !== 'kie')?.provider_model || '', options: {} }
      this.tools.push(fresh)
      this.toggle(this.tools[this.tools.length - 1])
      this.isNew = true
    },
    cancel() {
      if (this.isNew) this.tools = this.tools.filter((t) => t !== this.edit)
      this.edit = null
      this.isNew = false
    },
    async save() {
      const f = this.f
      const body = { name: f.name, hint: f.hint, cost: f.cost, icon: f.icon, group: f.group, input: f.input, placeholder: f.placeholder, prompt: f.prompt, input_prefix: f.input === 'text' ? f.input_prefix : '', output: f.output, choices: f.input === 'choice' ? f.choices : [], is_active: f.is_active, driver: f.driver, provider_model: f.provider_model || null, options: fromPairs(f.extra) }
      if (f.input === 'choice' && !f.choices.length) return this.app.toast('Добавьте хотя бы один вариант')
      await this.run(async () => {
        const r = this.isNew ? await adminApi.create('tools', { ...body, slug: f.slug }) : await adminApi.update('tools', this.edit.slug, body, 'PUT')
        const i = this.tools.indexOf(this.edit)
        this.tools.splice(i, 1, r.data)
        this.edit = null
        this.isNew = false
        this.app.reloadCatalog()
      }, 'Инструмент сохранён')
    },
    async remove(t) {
      if (!this.confirming) { this.confirming = true; return }
      await this.run(async () => { await adminApi.remove('tools', t.slug); this.tools = this.tools.filter((x) => x !== t); this.edit = null; this.app.reloadCatalog() }, 'Инструмент удалён')
    },
  },
}
</script>
