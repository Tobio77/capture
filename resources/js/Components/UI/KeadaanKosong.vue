<script setup>
import Ikon from '@/Components/Ikon.vue'

/**
 * Keadaan kosong dengan ikon dan penjelasan.
 *
 * Membedakan "belum ada data" dari "tidak ada yang cocok dengan filter"
 * penting: keduanya terlihat sama di tabel kosong, tetapi tindakan yang
 * diharapkan dari pengguna berbeda.
 */

defineProps({
  ikon: { type: String, default: 'kosong' },
  judul: { type: String, required: true },
  keterangan: { type: String, default: '' },
})
</script>

<template>
  <div class="flex flex-col items-center px-6 py-14 text-center">
    <span class="rounded-full bg-permukaan-2 p-3 text-redup">
      <Ikon :nama="ikon" ukuran="h-6 w-6" />
    </span>
    <p class="mt-3 text-sm font-medium text-utama">{{ judul }}</p>
    <p v-if="keterangan" class="mt-1 max-w-sm text-xs text-redup">{{ keterangan }}</p>
    <div class="mt-4"><slot /></div>
  </div>
</template>
