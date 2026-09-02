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
  teal: 'text-aksen-teks hover:bg-aksen-lembut',
  navy: 'text-utama hover:bg-info-lembut',
  amber: 'text-peringatan-teks hover:bg-peringatan-lembut',
  emerald: 'text-berhasil-teks hover:bg-berhasil-lembut',
  slate: 'text-sekunder hover:bg-permukaan-hover',
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
