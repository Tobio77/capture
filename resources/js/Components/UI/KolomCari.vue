<script setup>
import { ref, watch } from 'vue'
import Ikon from '@/Components/Ikon.vue'

/**
 * Kolom pencarian dengan jeda ketik.
 *
 * Jeda 350 ms menahan permintaan sampai pengguna berhenti mengetik: tanpa itu
 * setiap huruf menghasilkan satu perjalanan ke server.
 */

const model = defineModel({ type: String, default: '' })

const props = defineProps({
  placeholder: { type: String, default: 'Cari…' },
  jeda: { type: Number, default: 350 },
})

const emit = defineEmits(['cari'])

let tunda = null
const fokus = ref(false)

watch(model, () => {
  clearTimeout(tunda)
  tunda = setTimeout(() => emit('cari'), props.jeda)
})

function bersihkan() {
  model.value = ''
  clearTimeout(tunda)
  emit('cari')
}
</script>

<template>
  <div
    class="relative flex items-center rounded-md border bg-white transition"
    :class="fokus ? 'border-teal-500 ring-1 ring-teal-500' : 'border-slate-300'"
  >
    <Ikon nama="cari" ukuran="h-4 w-4" class="pointer-events-none absolute left-3 text-slate-400" />
    <input
      v-model="model"
      type="search"
      :placeholder="placeholder"
      class="w-full border-0 bg-transparent py-2 pl-9 pr-9 text-sm placeholder:text-slate-400 focus:outline-none focus:ring-0"
      @focus="fokus = true"
      @blur="fokus = false"
    />
    <button
      v-if="model"
      type="button"
      class="absolute right-2 rounded p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
      aria-label="Bersihkan pencarian"
      @click="bersihkan"
    >
      <Ikon nama="tutup" ukuran="h-3.5 w-3.5" />
    </button>
  </div>
</template>
