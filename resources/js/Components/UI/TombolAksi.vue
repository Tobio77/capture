<script setup>
import Ikon from '@/Components/Ikon.vue'

/**
 * Tombol aksi baris tabel: ikon + label, dengan warna sesuai sifat aksinya.
 */

defineProps({
  ikon: { type: String, required: true },
  warna: { type: String, default: 'slate' },
})

const palet = {
  teal: 'text-teal-700 hover:bg-teal-50',
  navy: 'text-navy-700 hover:bg-navy-50',
  amber: 'text-amber-700 hover:bg-amber-50',
  emerald: 'text-emerald-700 hover:bg-emerald-50',
  slate: 'text-slate-600 hover:bg-slate-100',
}
</script>

<template>
  <button
    type="button"
    class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium transition active:scale-95"
    :class="palet[warna] ?? palet.slate"
  >
    <Ikon :nama="ikon" ukuran="h-3.5 w-3.5" />
    <slot />
  </button>
</template>
