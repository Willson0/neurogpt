<template>
  <section ref="studio" class="music-studio" aria-labelledby="music-title">
    <header class="music-hero">
      <div class="music-hero-copy">
        <p><svg class="ic-s" aria-hidden="true"><use href="#i-music" /></svg> Suno V6</p>
        <h1 id="music-title">Музыкальная<br>студия</h1>
        <span>От первой идеи до готового трека</span>
      </div>
      <div class="music-bars" aria-hidden="true"><i v-for="(height, index) in bars" :key="index" :style="{ '--height': height + '%' }" /></div>
    </header>

    <nav class="music-tabs" aria-label="Студия">
      <button v-for="item in tabs" :key="item.id" type="button" :aria-pressed="tab === item.id" @click="selectTab(item.id)">{{ item.label }}</button>
    </nav>

    <form v-if="tab === 'compose' && !editing" class="music-panel" @submit.prevent="generate">
      <div class="music-heading">
        <div><h2>Новая песня</h2><p>{{ draft.custom_mode ? 'Соберите песню из своего текста и настроек.' : 'Задайте настроение и расскажите, о чём песня.' }}</p></div>
        <select v-model="draft.model" class="music-model" aria-label="Модель Suno">
          <option v-for="model in models" :key="model.id" :value="model.id" :disabled="app.isLocked(model)">{{ model.name }}</option>
        </select>
      </div>
      <div class="music-switch" role="group" aria-label="Режим создания">
        <button type="button" :aria-pressed="!draft.custom_mode" @click="draft.custom_mode = false">По идее</button>
        <button type="button" :aria-pressed="draft.custom_mode" @click="draft.custom_mode = true">Свои настройки</button>
      </div>

      <label v-if="!draft.custom_mode" class="music-field">
        <span>Идея песни</span>
        <textarea v-model.trim="draft.prompt" rows="4" maxlength="3000" placeholder="Например, ночная поездка по городу, тёплый синтвейв и припев, который хочется подпевать" />
      </label>
      <label v-else class="music-field"><span>Название песни</span><input v-model.trim="draft.title" maxlength="80" placeholder="Как назвать трек?" required></label>
      <label class="music-check"><input v-model="draft.instrumental" type="checkbox"> Инструментальная версия</label>

      <div class="music-chips" aria-label="Быстрые стили">
        <button v-for="preset in styles" :key="preset.name" type="button" class="pill-btn" :aria-pressed="draft.style === preset.value" @click="draft.style = draft.style === preset.value ? '' : preset.value">{{ preset.name }}</button>
      </div>

      <label v-if="draft.custom_mode" class="music-field"><span>Жанр, настроение и инструменты</span><textarea v-model.trim="draft.style" rows="2" maxlength="1000" placeholder="Инди-поп, мягкие синтезаторы, 100 BPM" /></label>
      <div v-if="draft.custom_mode && !draft.instrumental" class="music-stack">
        <label class="music-field"><span>Текст песни <small>{{ draft.lyrics.length }} / 5000</small></span><textarea v-model="draft.lyrics" rows="6" maxlength="5000" placeholder="[Verse]&#10;[Chorus]" /></label>
        <div class="music-chips">
          <button type="button" class="pill-btn" @click="insertSection('Verse')">+ Куплет</button>
          <button type="button" class="pill-btn" @click="insertSection('Chorus')">+ Припев</button>
          <button type="button" class="pill-btn" @click="insertSection('Bridge')">+ Бридж</button>
        </div>
      </div>

      <details v-if="!draft.instrumental" class="music-more" :open="lyricsOpen" @toggle="lyricsOpen = $event.target.open">
        <summary>Сочинить текст</summary>
        <div class="music-stack">
          <label class="music-field"><span>Тема для текста</span><textarea v-model.trim="draft.lyricPrompt" rows="2" maxlength="200" placeholder="Тема, язык и настроение песни" /></label>
          <button type="button" class="pill-btn hot" :disabled="busy || running('lyrics') || !(draft.lyricPrompt || draft.prompt)" @click="generateLyrics">{{ running('lyrics') ? 'Пишем текст…' : 'Сочинить · ' + priceLabel('lyrics') }}</button>
          <div v-for="(variant, index) in lyricOptions" :key="index" class="music-option">
            <details><summary>{{ variant.title || 'Черновик ' + (index + 1) }}</summary><p class="music-lyrics">{{ variant.text }}</p></details>
            <button type="button" class="pill-btn" @click="applyLyrics(variant)">В редактор</button>
          </div>
        </div>
      </details>

      <details class="music-more">
        <summary>{{ draft.custom_mode ? 'Параметры звучания' : 'Стиль и референсы' }}</summary>
        <div class="music-stack">
          <template v-if="!draft.custom_mode">
            <label class="music-field"><span>Музыкальный стиль</span><textarea v-model.trim="draft.style" rows="2" maxlength="1000" /></label>
            <label v-if="!draft.instrumental" class="music-field"><span>Текст как референс</span><textarea v-model="draft.lyrics" rows="3" maxlength="5000" /></label>
            <label class="music-field" v-for="item in references" :key="item.id"><span>{{ item.label }}</span><textarea v-model="draft[item.id]" :rows="item.rows" placeholder="https://…" /></label>
            <p class="music-muted">Одна HTTPS-ссылка на строку. MIDI использует Suno V6 Wild.</p>
          </template>
          <div v-if="draft.style" class="music-chips">
            <button type="button" class="pill-btn" :disabled="busy || running('boost_style')" @click="boostStyle">{{ running('boost_style') ? 'Уточняем стиль…' : 'Уточнить стиль · ' + priceLabel('boost_style') }}</button>
          </div>
          <div v-if="styleSuggestion" class="music-option">
            <p class="music-lyrics">{{ styleSuggestion }}</p>
            <button type="button" class="pill-btn" @click="draft.style = styleSuggestion; styleSuggestion = ''">Применить описание</button>
          </div>
          <label class="music-field"><span>Исключить из звучания</span><input v-model.trim="draft.negative_tags" maxlength="1000" placeholder="Например, перегруженные гитары"></label>
          <label v-if="selectableProfiles.length" class="music-field"><span>Голос или шаблон из коллекции</span><select v-model="draft.profile_id"><option value="">Без шаблона</option><option v-for="p in selectableProfiles" :key="p.id" :value="p.id">{{ p.music.name }} · {{ p.music.profile_type === 'voice_persona' ? 'голос' : 'стиль' }}</option></select></label>
          <template v-if="draft.custom_mode">
            <div class="music-fields-pair">
              <label class="music-field"><span>Длина, сек.</span><input v-model.number="draft.duration" type="number" min="10" max="360" placeholder="Авто"></label>
              <label v-if="!draft.instrumental" class="music-field"><span>Вокал</span><select v-model="draft.vocal_gender"><option value="">Любой</option><option value="f">Женский</option><option value="m">Мужской</option></select></label>
            </div>
            <label v-for="slider in sliders" :key="slider.id" class="music-field">
              <span>{{ slider.label }} <small>{{ draft[slider.id] === '' ? 'Авто' : draft[slider.id] }}</small></span>
              <input type="range" :min="0" :max="slider.max" :step="slider.step" :value="draft[slider.id] === '' ? slider.default : draft[slider.id]" @input="draft[slider.id] = Number($event.target.value)">
              <button v-if="draft[slider.id] !== ''" type="button" class="music-reset" @click="draft[slider.id] = ''">Сбросить</button>
            </label>
          </template>
        </div>
      </details>

      <details class="music-more">
        <summary>Мои шаблоны</summary>
        <div class="music-stack">
          <div v-for="preset in saved" :key="preset.name" class="music-option">
            <button type="button" class="pill-btn" @click="applyTemplate(preset)">{{ preset.name }}</button>
            <button type="button" class="pill-btn" :aria-label="'Удалить шаблон ' + preset.name" @click="removeTemplate(preset.name)">Удалить</button>
          </div>
          <label class="music-field"><span>Сохранить настройки как шаблон</span><input v-model.trim="templateName" maxlength="60" placeholder="Название шаблона"></label>
          <button type="button" class="pill-btn" :disabled="!templateName" @click="saveTemplate">Сохранить шаблон</button>
          <p class="music-muted">Шаблоны формы хранятся на этом устройстве. Стиль, сохранённый из трека, доступен в вашем аккаунте.</p>
        </div>
      </details>

      <label class="music-check"><input v-model="draft.auto_send" type="checkbox"> Отправить готовые треки в Telegram</label>
      <p v-if="error" class="music-error" role="alert">{{ error }}</p>
      <button class="music-submit" type="submit" :disabled="busy || !chosenModel">{{ busy ? 'Отправляем…' : 'Создать песню · ' + priceLabel('generate') }}</button>
    </form>

    <form v-else-if="tab === 'compose' && editing" class="music-panel" @submit.prevent="submitEdit">
      <div class="music-heading">
        <div><h2>{{ editLabels[editing.operation] }}</h2><p v-if="editing.track">{{ editing.track.title || 'Выбранная дорожка' }}</p></div>
        <button class="pill-btn" type="button" @click="closeEdit">Назад</button>
      </div>
      <MusicAudioInput v-if="uploadEdits.includes(editing.operation)" v-model="editFile" label="Исходное аудио · до 20 МБ" />
      <template v-if="editing.operation === 'mashup'">
        <label class="music-field"><span>Первая HTTPS-ссылка на аудио</span><input v-model.trim="editForm.firstUrl" type="url" required></label>
        <label class="music-field"><span>Вторая HTTPS-ссылка на аудио</span><input v-model.trim="editForm.secondUrl" type="url" required></label>
        <label class="music-field"><span>Длина, сек.</span><input v-model.number="editForm.duration" type="number" min="10" max="360" placeholder="20"></label>
      </template>
      <template v-if="editing.operation === 'stems'">
        <label class="music-field"><span>Что выделить?</span><select v-model="editForm.stem_type"><option value="separate_vocal">Вокал и аккомпанемент</option><option value="split_stem">Отдельные инструменты</option><option value="split_stem_advanced">Конкретный инструмент или голос</option></select></label>
        <label v-if="editForm.stem_type === 'split_stem_advanced'" class="music-field"><span>Инструмент или голос</span><input v-model.trim="editForm.stem_name" maxlength="60" placeholder="Например, guitar" required></label>
      </template>
      <p v-if="editing.operation === 'wav'" class="music-muted">Файл WAV появится в «Моих треках» отдельной дорожкой.</p>
      <label v-if="!['cover', 'wav', 'stems'].includes(editing.operation)" class="music-field"><span>{{ editing.operation === 'persona' ? 'Название шаблона' : 'Название трека' }}</span><input v-model.trim="editForm.title" maxlength="80" :required="['persona', 'add_vocals', 'add_instrumental', 'mashup'].includes(editing.operation)"></label>
      <label v-if="['replace', 'cover'].includes(editing.operation)" class="music-field"><span>{{ editing.operation === 'cover' ? 'Как должна выглядеть обложка?' : 'Что изменить во фрагменте?' }}</span><textarea v-model.trim="editForm.prompt" rows="3" maxlength="1500" required /></label>
      <label v-if="!['cover', 'wav', 'stems'].includes(editing.operation)" class="music-field"><span>Музыкальный стиль</span><input v-model.trim="editForm.style" maxlength="1000" :required="['replace', 'add_vocals', 'add_instrumental', 'mashup'].includes(editing.operation)"></label>
      <label v-if="editing.operation === 'persona'" class="music-field"><span>Описание характера шаблона</span><textarea v-model.trim="editForm.description" rows="3" maxlength="1000" required /></label>
      <div v-if="['extend', 'extend_upload', 'replace', 'persona'].includes(editing.operation)" class="music-fields-pair">
        <label class="music-field"><span>{{ ['extend', 'extend_upload'].includes(editing.operation) ? 'Продолжить с (сек.)' : 'Начало (сек.)' }}</span><input v-model.number="editForm.start" type="number" min="0" step=".01" :placeholder="editing.operation === 'persona' ? 'Авто' : ''" :required="editing.operation === 'replace'"></label>
        <label v-if="['replace', 'persona'].includes(editing.operation)" class="music-field"><span>Конец (сек.)</span><input v-model.number="editForm.end" type="number" min="0" step=".01" :required="editing.operation === 'replace'"></label>
      </div>
      <label v-if="['extend', 'extend_upload', 'cover_audio'].includes(editing.operation)" class="music-check"><input v-model="editForm.instrumental" type="checkbox"> Инструментальная версия</label>
      <label v-if="!editForm.instrumental && !noSongSettings.includes(editing.operation)" class="music-field"><span>{{ editing.operation === 'replace' ? 'Полный текст песни' : 'Текст песни' }}</span><textarea v-model="editForm.lyrics" rows="4" maxlength="5000" /></label>
      <label v-if="editing.operation === 'replace'" class="music-field"><span>Текст для нового фрагмента</span><textarea v-model="editForm.description" rows="2" maxlength="1000" /></label>
      <label v-if="!noSongSettings.includes(editing.operation) && editing.operation !== 'mashup'" class="music-field"><span>Исключить из звучания</span><input v-model.trim="editForm.negative_tags" maxlength="1000" :required="['add_vocals', 'add_instrumental'].includes(editing.operation)"></label>
      <details v-if="!noSongSettings.includes(editing.operation)" class="music-more">
        <summary>Параметры звучания</summary>
        <div class="music-stack">
          <label v-if="uploadEdits.includes(editing.operation) || editing.operation === 'mashup'" class="music-field"><span>Модель</span><select v-model="editForm.model"><option v-for="model in models" :key="model.id" :value="model.id" :disabled="app.isLocked(model)">{{ model.name }}</option></select></label>
          <label v-if="!editForm.instrumental" class="music-field"><span>Вокал</span><select v-model="editForm.vocal_gender"><option value="">Любой</option><option value="f">Женский</option><option value="m">Мужской</option></select></label>
          <label v-if="profiles.length && !['add_vocals', 'add_instrumental'].includes(editing.operation)" class="music-field"><span>Голос или шаблон</span><select v-model="editForm.profile_id"><option value="">Без шаблона</option><option v-for="p in profiles.filter(item => !editForm.instrumental || item.music.profile_type === 'style_persona')" :key="p.id" :value="p.id">{{ p.music.name }}</option></select></label>
          <label v-for="slider in sliderSettings.filter(item => !editForm.instrumental || item.id !== 'audio_weight')" :key="slider.id" class="music-field"><span>{{ slider.label }} <small>{{ editForm[slider.id] ?? 'Авто' }}</small></span><input type="range" min="0" :max="slider.max" :step="slider.step" :value="editForm[slider.id] ?? slider.default" @input="editForm[slider.id] = Number($event.target.value)"><button v-if="editForm[slider.id] != null" type="button" class="music-reset" @click="delete editForm[slider.id]">Сбросить</button></label>
        </div>
      </details>
      <label v-if="!['cover', 'persona'].includes(editing.operation)" class="music-check"><input v-model="editForm.auto_send" type="checkbox"> Отправить в Telegram</label>
      <p v-if="error" class="music-error" role="alert">{{ error }}</p>
      <button class="music-submit" type="submit" :disabled="busy">{{ busy ? 'Отправляем…' : editLabels[editing.operation] + ' · ' + priceLabel(editing.operation, editForm.model) }}</button>
    </form>

    <section v-else-if="tab === 'voice'" class="music-panel">
      <div class="music-heading"><div><h2>Свой голос</h2><p>Добавьте запись своего вокала, затем произнесите фразу для проверки.</p></div></div>
      <div v-if="profiles.some(p => p.music.profile_type === 'voice_persona')" class="music-chips">
        <button v-for="voice in profiles.filter(p => p.music.profile_type === 'voice_persona')" :key="voice.id" type="button" class="pill-btn" @click="useVoice(voice)">{{ voice.music.name }} · использовать</button>
      </div>
      <form v-if="!phraseJob" class="music-stack" @submit.prevent="requestPhrase">
        <MusicAudioInput v-model="voiceFile" label="Запись вокала · до 20 МБ" recordable />
        <div class="music-fields-pair">
          <label class="music-field"><span>Начало фрагмента (сек.)</span><input v-model.number="voice.start" type="number" min="0" step=".01" required></label>
          <label class="music-field"><span>Конец фрагмента (сек.)</span><input v-model.number="voice.end" type="number" min=".01" step=".01" required></label>
        </div>
        <label class="music-field"><span>Язык проверочной фразы</span><select v-model="voice.language"><option value="en">English</option><option value="ru">Русский</option><option value="es">Español</option><option value="de">Deutsch</option><option value="fr">Français</option><option value="pt">Português</option><option value="ja">日本語</option><option value="ko">한국어</option><option value="zh">中文</option></select></label>
        <button class="music-submit" type="submit" :disabled="!voiceFile || busy || running('voice_phrase')">{{ running('voice_phrase') ? 'Готовим проверочную фразу…' : 'Получить проверочную фразу' }}</button>
      </form>
      <form v-else class="music-stack" @submit.prevent="createVoice">
        <blockquote class="music-phrase">{{ phraseJob.music.phrase }}</blockquote>
        <MusicAudioInput :key="phraseJob.id" v-model="verificationFile" label="Запись проверочной фразы" recordable />
        <label class="music-field"><span>Имя голоса</span><input v-model.trim="voice.title" maxlength="80" required></label>
        <details class="music-more">
          <summary>Описание голоса</summary>
          <div class="music-stack">
            <label class="music-field"><span>Особенности голоса</span><textarea v-model.trim="voice.description" rows="2" maxlength="1000" /></label>
            <label class="music-field"><span>Стиль вокала</span><input v-model.trim="voice.style" maxlength="1000"></label>
            <label class="music-field"><span>Уровень певца</span><select v-model="voice.singer_skill_level"><option value="">Авто</option><option value="beginner">Начинающий</option><option value="intermediate">Опытный</option><option value="professional">Профессиональный</option></select></label>
          </div>
        </details>
        <button class="music-submit" type="submit" :disabled="!verificationFile || busy || running('voice_create') || running('voice_retry')">{{ running('voice_create') ? 'Проверяем голос…' : 'Сохранить голос' }}</button>
        <div class="music-chips">
          <button type="button" class="pill-btn" :disabled="busy || running('voice_retry') || running('voice_create')" @click="retryPhrase">{{ running('voice_retry') ? 'Готовим новую фразу…' : 'Новая фраза' }}</button>
          <button type="button" class="pill-btn" :disabled="busy || running('voice_create')" @click="resetVoice">Другая запись</button>
        </div>
      </form>
      <p v-if="error" class="music-error" role="alert">{{ error }}</p>
    </section>

    <section v-else class="music-panel" aria-label="Мои треки">
      <div class="music-heading"><div><h2>Мои треки</h2><p>Прослушивайте варианты, скачивайте и редактируйте дорожки.</p></div></div>
      <details class="music-more">
        <summary>Работа со своим аудио</summary>
        <div class="music-chips">
          <button v-for="operation in uploadEdits" :key="operation" type="button" class="pill-btn" @click="openEdit({ operation })">{{ editLabels[operation] }}</button>
          <button type="button" class="pill-btn" @click="openEdit({ operation: 'mashup' })">{{ editLabels.mashup }}</button>
        </div>
      </details>
      <p v-if="loading && !jobs.length" class="music-muted" role="status">Загружаем треки…</p>
      <p v-else-if="!tracks.length && !activeJobs.length && !loadError" class="music-muted">Готовые песни появятся здесь.</p>
      <MusicTrack v-for="item in tracks" :key="item.key" v-bind="item" @edit="openEdit" @play="play" />
      <button v-if="nextPage || loadError" type="button" class="pill-btn" :disabled="loading" @click="load(nextPage || 1)">{{ loadError ? 'Повторить загрузку' : 'Показать ещё' }}</button>
    </section>

    <div v-if="visibleJobs.length" class="music-queue" aria-live="polite">
      <div v-for="item in visibleJobs" :key="item.id" class="music-job">
        <span><b>{{ item.title }}</b><small>{{ statusLabel(item) }}</small></span>
        <button v-if="item.status === 'queued'" type="button" class="pill-btn" :disabled="cancelling === item.id" @click="cancel(item)">Отменить</button>
        <button v-else-if="item.status === 'failed' || item.status === 'cancelled'" type="button" class="pill-btn" @click="dismiss(item.id)" aria-label="Скрыть уведомление">Скрыть</button>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import MusicAudioInput from './MusicAudioInput.vue'
import MusicTrack from './MusicTrack.vue'
import { useApp } from '../stores/app'
import { api } from '../services/api'
import { tok } from '../services/format'
import { tg } from '../services/telegram'

const app = useApp()
const tabs = [{ id: 'compose', label: 'Создать' }, { id: 'library', label: 'Мои треки' }, { id: 'voice', label: 'Голос' }]
const styles = [
  { name: 'Поп', value: 'Modern pop, bright hook, melodic vocals' },
  { name: 'Инди', value: 'Intimate indie folk, acoustic guitar, warm vocals' },
  { name: 'Хип-хоп', value: 'Hip-hop, deep bass, crisp beats, rhythmic flow' },
  { name: 'Электроника', value: 'Melodic electronica, layered synths, deep bass' },
  { name: 'Рок', value: 'Alternative rock, electric guitars, live drums' },
]
const references = [
  { id: 'image_urls', label: 'Изображения · до 5', rows: 2 },
  { id: 'audio_urls', label: 'Аудиореференсы', rows: 2 },
  { id: 'video_urls', label: 'Видео · 1 ссылка', rows: 1 },
  { id: 'midi_urls', label: 'MIDI · 1 ссылка', rows: 1 },
]
const sliderSettings = [
  { id: 'style_weight', label: 'Следование стилю', max: 1, step: .01, default: .5 },
  { id: 'weirdness_constraint', label: 'Экспериментальность', max: 1, step: .01, default: .5 },
  { id: 'audio_weight', label: 'Влияние звуковых признаков', max: 1, step: .01, default: .5 },
  { id: 'variety', label: 'Разнообразие вариантов', max: 4, step: 1, default: 1 },
]
const editLabels = {
  extend: 'Продолжить песню', replace: 'Заменить фрагмент', persona: 'Сохранить стиль',
  cover: 'Создать обложку', cover_audio: 'Сделать кавер', extend_upload: 'Продолжить аудио',
  add_vocals: 'Добавить вокал', add_instrumental: 'Добавить аккомпанемент',
  mashup: 'Свести два трека', wav: 'Экспортировать в WAV', stems: 'Разделить дорожки',
}
const uploadEdits = ['cover_audio', 'extend_upload', 'add_vocals', 'add_instrumental']
const noSongSettings = ['cover', 'persona', 'wav', 'stems']
const bars = [11, 21, 37, 23, 49, 71, 49, 83, 100, 64, 54, 76, 49, 28, 41, 22, 11]
const tab = ref('compose')
const studio = ref(null)
const models = computed(() => app.models.song || [])
if (!app.musicDraft) app.musicDraft = {
  model: models.value.find(m => !app.isLocked(m))?.id || 'suno-v6', custom_mode: false, prompt: '', title: '', style: '',
  lyrics: '', lyricPrompt: '', instrumental: false, duration: '', vocal_gender: '', negative_tags: '',
  style_weight: '', weirdness_constraint: '', audio_weight: '', variety: '', profile_id: '',
  image_urls: '', audio_urls: '', video_urls: '', midi_urls: '', auto_send: false,
}
const draft = app.musicDraft
const jobs = ref([])
const profiles = ref([])
const lyricsOpen = ref(false)
const tabJobs = ref([])
const lyricOptions = ref([])
const styleSuggestion = ref('')
const ignoredPhrases = new Set()
const usedPhrases = new Set()
const dismissed = ref(new Set())
const busy = ref(false)
const loading = ref(false)
const loadError = ref(false)
const error = ref('')
const nextPage = ref(null)
const cancelling = ref(null)
const editing = ref(null)
const editForm = ref({})
const editFile = ref(null)
const voiceFile = ref(null)
const verificationFile = ref(null)
const voice = reactive({ start: 0, end: 10, language: 'en', title: '', description: '', style: '', singer_skill_level: '' })
const phraseJob = ref(null)
const templateName = ref('')
const templateKey = 'ng_music_templates_' + (app.user?.id || app.user?.telegram_id || 'local')
const saved = ref(readTemplates())
let timer
let lastPlayer
let closed = false
let polling = false

const listUrls = (value) => value.split(/\r?\n/).map(url => url.trim()).filter(Boolean)
const midiModel = computed(() => !draft.custom_mode && listUrls(draft.midi_urls).length > 0 ? 'suno-v6-wild' : draft.model)
const chosenModel = computed(() => models.value.find(model => model.id === midiModel.value && !app.isLocked(model)))
const selectableProfiles = computed(() => profiles.value.filter(p => !draft.instrumental || p.music.profile_type === 'style_persona'))
const sliders = computed(() => sliderSettings.filter(s => !draft.instrumental || s.id !== 'audio_weight'))
const activeJobs = computed(() => jobs.value.filter(item => ['queued', 'processing'].includes(item.status)))
const visibleJobs = computed(() => jobs.value.filter(item => !dismissed.value.has(item.id) && (
  ['queued', 'processing'].includes(item.status) || (['failed', 'cancelled'].includes(item.status)
    && (tabJobs.value.includes(item.id) || Date.now() - new Date(item.finished_at).getTime() < 10 * 60_000))
)).slice(0, 8))
const tracks = computed(() => {
  const covers = new Map()
  for (const job of jobs.value) {
    if (job.music?.operation === 'cover' && job.status === 'done') {
      const key = job.music.parent_id + '-' + job.music.track
      if (!covers.has(key)) covers.set(key, job)
    }
  }
  return jobs.value.filter(job => job.status === 'done').flatMap(generation => {
    const versions = generation.music?.tracks || (generation.result_url ? [{ url: generation.result_url, title: generation.prompt || generation.title }] : [])
    return versions.map((track, index) => {
      const key = generation.id + '-' + index
      return { generation, track, index, key, cover: covers.get(key) || null }
    })
  })
})

function readTemplates() {
  try {
    const value = JSON.parse(localStorage.getItem(templateKey) || '[]')
    return Array.isArray(value) ? value.filter(item => typeof item.name === 'string' && item.settings) : []
  } catch { return [] }
}

function writeTemplates(items) {
  try {
    localStorage.setItem(templateKey, JSON.stringify(items))
    saved.value = items
  } catch {
    app.toast('Хранилище шаблонов недоступно')
  }
}

function saveTemplate() {
  const { lyricPrompt, ...settings } = draft
  writeTemplates([{ name: templateName.value, settings }, ...saved.value.filter(item => item.name !== templateName.value)].slice(0, 20))
  templateName.value = ''
}

function removeTemplate(name) { writeTemplates(saved.value.filter(item => item.name !== name)) }

function applyTemplate(preset) {
  const allowed = Object.keys(draft).filter(key => key !== 'lyricPrompt' && key in preset.settings)
  Object.assign(draft, Object.fromEntries(allowed.map(key => [key, preset.settings[key]])))
}

function priceLabel(operation, model) {
  const songModel = model
    ? models.value.find(item => item.id === model)
    : operation === 'generate' ? chosenModel.value : models.value.find(item => item.id === 'suno-v6')
  const price = app.musicPrices[operation] ?? songModel?.price
  return price == null ? '—' : price === 0 ? 'бесплатно' : tok(price)
}

function running(operation) { return activeJobs.value.some(item => item.music?.operation === operation) }

function selectTab(id) {
  tab.value = id
  error.value = ''
  if (id === 'library') editing.value = null
}

function play(audio) {
  if (lastPlayer && lastPlayer !== audio) lastPlayer.pause()
  lastPlayer = audio
}

function updateJobs(items) {
  const byId = new Map(jobs.value.map(job => [job.id, job]))
  items.forEach(job => byId.set(job.id, job))
  jobs.value = [...byId.values()].sort((a, b) => b.id - a.id)
}

async function load(page = 1) {
  if (loading.value) return
  loading.value = true
  try {
    const result = await api.generations({ kind: 'song', page, include_failed: 1 })
    if (closed) return
    updateJobs(result.data)
    nextPage.value = result.next_page
    loadError.value = false
    restoreTasks()
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
}

async function loadProfiles() {
  try {
    const result = await api.musicProfiles()
    if (!closed) profiles.value = result.data
  } catch { /* a successful generation still updates the local collection */ }
}

function restoreTasks() {
  const lyrics = jobs.value.find(item => item.music?.operation === 'lyrics' && item.status === 'done')
  if (lyrics && !lyricOptions.value.length) lyricOptions.value = lyrics.music.lyrics
  for (const item of jobs.value) {
    if (item.status === 'done' && item.music?.operation === 'voice_create') usedPhrases.add(item.music.parent_id)
  }
  if (!phraseJob.value) {
    phraseJob.value = jobs.value.find(item => item.status === 'done' && item.music?.phrase && !ignoredPhrases.has(item.id) && !usedPhrases.has(item.id)
      && !jobs.value.some(next => next.status === 'done' && next.music?.operation === 'voice_retry' && next.music.parent_id === item.id)) || null
  }
}

function statusLabel(item) {
  if (item.status === 'queued') return 'В очереди'
  if (item.status === 'processing') return 'В работе'
  if (item.status === 'cancelled') return 'Отменено'
  if (item.error === 'content_filtered') return 'Запрос не прошёл фильтр'
  if (item.error === 'unavailable') return 'Модель временно недоступна'
  if (item.music?.operation === 'voice_create') return 'Проверка голоса не завершилась. Попробуйте новую запись.'
  return item.cost ? 'Не получилось. Токены возвращены.' : 'Не получилось. Попробуйте ещё раз.'
}

async function poll() {
  if (closed || polling) return
  polling = true
  timer = undefined
  try {
    const results = await Promise.allSettled(activeJobs.value.map(item => api.generation(item.id)))
    if (closed) return
    for (const result of results) {
      if (result.status !== 'fulfilled') continue
      const { data, user } = result.value
      app.setUser(user)
      updateJobs([data])
      if (data.status === 'done') completed(data)
    }
  } finally {
    polling = false
    if (!closed) timer = setTimeout(poll, activeJobs.value.length ? 4000 : 7500)
  }
}

function completed(item) {
  const type = item.music?.operation
  if (type === 'lyrics') {
    lyricOptions.value = item.music.lyrics
  } else if (type === 'boost_style') {
    styleSuggestion.value = item.music.style || ''
  } else if (['voice_phrase', 'voice_retry'].includes(type)) {
    phraseJob.value = item
    verificationFile.value = null
    voiceFile.value = null
  } else if (item.music?.profile_type) {
    if (!profiles.value.some(p => p.id === item.id)) profiles.value = [item, ...profiles.value]
    if (type === 'voice_create') { usedPhrases.add(item.music.parent_id); resetVoice() }
    app.toast(type === 'voice_create' ? 'Голос добавлен' : 'Стиль сохранён')
  } else if (item.music?.tracks?.length || type === 'cover') {
    app.toast(type === 'cover' ? 'Обложка готова' : 'Треки готовы')
    tg.haptic('success')
  }
}

function dismiss(id) { dismissed.value.add(id) }

async function cancel(item) {
  cancelling.value = item.id
  try {
    const result = await api.cancelGeneration(item.id)
    updateJobs([result.data])
    app.setUser(result.user)
  } catch (e) {
    app.handleActionError(e)
  } finally {
    cancelling.value = null
  }
}

async function submit(operation, fields, file) {
  if (busy.value) return null
  busy.value = true
  error.value = ''
  try {
    const result = await api.createMusic({ operation, ...fields }, file)
    app.setUser(result.user)
    if (closed) return null
    updateJobs(result.data)
    tabJobs.value.push(...result.data.map(item => item.id))
    result.data.filter(item => item.status === 'done').forEach(completed)
    if (activeJobs.value.length && timer) {
      clearTimeout(timer)
      timer = undefined
      void poll()
    }
    return result.data[0]
  } catch (e) {
    if (e.status === 422) error.value = Object.values(e.body?.errors || {}).flat()[0] || 'Проверьте параметры.'
    else if (e.status === 413) error.value = 'Аудиофайл слишком большой.'
    else if (e.status === 404) error.value = 'Модель, профиль или исходный трек недоступны.'
    else app.handleActionError(e)
    return null
  } finally {
    busy.value = false
  }
}

async function generate() {
  if (!chosenModel.value) return
  const { model, prompt, title, style, lyrics, custom_mode, instrumental, auto_send, profile_id, negative_tags } = draft
  const fields = { model: midiModel.value || model, custom_mode, instrumental, style, lyrics, profile_id: selectableProfiles.value.some(p => p.id === profile_id) ? profile_id : '', auto_send, negative_tags }
  if (custom_mode) {
    fields.title = title
    fields.duration = draft.duration
    fields.vocal_gender = draft.vocal_gender
    sliders.value.forEach(s => { fields[s.id] = draft[s.id] })
  } else {
    fields.prompt = prompt
    references.forEach(item => { const urls = listUrls(draft[item.id]); if (urls.length) fields[item.id] = urls })
  }
  if (await submit('generate', fields)) selectTab('library')
}

function generateLyrics() {
  lyricsOpen.value = true
  return submit('lyrics', { prompt: (draft.lyricPrompt || draft.prompt).slice(0, 200) })
}

function boostStyle() { return submit('boost_style', { style: draft.style }) }

function insertSection(section) {
  draft.lyrics = (draft.lyrics.trimEnd() + (draft.lyrics ? '\n\n' : '') + '[' + section + ']\n').slice(0, 5000)
}

function applyLyrics(variant) {
  draft.custom_mode = true
  draft.lyrics = variant.text.slice(0, 5000)
  if (!draft.title) draft.title = variant.title.slice(0, 80)
  lyricsOpen.value = false
  nextTick(() => studio.value?.querySelector('textarea[maxlength="5000"]')?.focus())
}

function openEdit(details) {
  editing.value = details
  const track = details.track
  const version = details.generation?.music?.model
  const sourceModel = version ? 'suno-' + version.toLowerCase().replaceAll('_', '-') : null
  editForm.value = {
    model: details.operation === 'extend' || details.operation === 'replace' ? sourceModel || 'suno-v6' : chosenModel.value?.id || 'suno-v6',
    title: ['extend', 'replace'].includes(details.operation) ? track?.title?.slice(0, 80) || '' : '',
    prompt: details.operation === 'cover' && track?.title ? 'Обложка для песни «' + track.title.slice(0, 80) + '»' : '',
    style: ['extend', 'replace'].includes(details.operation) ? track?.style?.slice(0, 1000) || '' : '',
    lyrics: details.operation === 'replace' ? track?.lyrics?.slice(0, 5000) || '' : '',
    description: '', start: details.operation === 'replace' ? 0 : '', end: '',
    stem_type: details.operation === 'stems' ? 'separate_vocal' : '',
    instrumental: false, auto_send: draft.auto_send, negative_tags: '',
  }
  editFile.value = null
  selectTab('compose')
  nextTick(() => studio.value?.querySelector('form')?.scrollIntoView({ block: 'start', behavior: 'smooth' }))
}

function closeEdit() {
  editing.value = null
  selectTab('library')
}

async function submitEdit() {
  const details = editing.value
  const form = { ...editForm.value, source_id: details.generation?.id, track: details.index }
  if (form.instrumental && profiles.value.find(p => p.id === form.profile_id)?.music.profile_type === 'voice_persona') delete form.profile_id
  if (noSongSettings.includes(details.operation)) form.model = 'suno-v6'
  if (details.operation === 'mashup') form.mashup_urls = [form.firstUrl, form.secondUrl]
  if (await submit(details.operation, form, editFile.value)) closeEdit()
}

function requestPhrase() {
  return submit('voice_phrase', { start: voice.start, end: voice.end, language: voice.language }, voiceFile.value)
}

function retryPhrase() { return submit('voice_retry', { source_id: phraseJob.value.id }) }

function createVoice() {
  return submit('voice_create', {
    source_id: phraseJob.value.id, title: voice.title, description: voice.description,
    style: voice.style, singer_skill_level: voice.singer_skill_level,
  }, verificationFile.value)
}

function resetVoice() {
  if (phraseJob.value) ignoredPhrases.add(phraseJob.value.id)
  phraseJob.value = null
  voiceFile.value = null
  verificationFile.value = null
  error.value = ''
}

function useVoice(item) {
  draft.instrumental = false
  draft.profile_id = item.id
  draft.custom_mode = true
  editing.value = null
  selectTab('compose')
  app.toast('Голос выбран')
}

async function focusJob(id) {
  if (!id) return
  selectTab('library')
  try {
    if (!jobs.value.some(item => item.id === id)) updateJobs([(await api.generation(id)).data])
    await nextTick()
    document.getElementById('music-track-' + id + '-0')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
  } catch (e) {
    app.handleActionError(e)
  }
}

watch(() => app.musicFocus, focusJob)
watch(() => app.musicComposeRequest, async () => {
  editing.value = null
  selectTab('compose')
  await nextTick()
  studio.value?.querySelector('form textarea, form input')?.focus()
})
onMounted(async () => {
  await Promise.all([load(), loadProfiles(), focusJob(app.musicFocus)])
  if (!closed) void poll()
})
onBeforeUnmount(() => {
  closed = true
  clearTimeout(timer)
  app.musicFocus = null
})
</script>

<style src="../styles/music.css"></style>
