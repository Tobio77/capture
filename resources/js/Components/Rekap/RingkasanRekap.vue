<script setup>
import Ikon from '@/Components/Ikon.vue'

/**
 * Deret angka ringkas di kepala rekap — hadir, tepat waktu, terlambat, dan
 * satu angka penutup yang berbeda per halaman.
 *
 * Isinya memang berbeda antar-halaman (Rekap Event menutup dengan "Sudah
 * Pulang", Absen Umum dengan "Belum Absen"), tetapi bentuknya tidak boleh
 * berbeda — dan sebelumnya berbeda tipis di beberapa tempat karena disalin.
 *
 * @see TabelRekap.vue — pasangannya untuk barisnya.
 */

defineProps({
  /**
   * @type {import('vue').PropType<Array<{
   *   label: string, nilai: number, ikon: string, latar: string, warna: string
   * }>>}
   */
  kartu: { type: Array, required: true },
})
</script>

<template>
  <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
    <div v-for="item in kartu" :key="item.label" class="rounded-md border border-garis px-4 py-3">
      <div class="flex items-start justify-between gap-2">
        <div>
          <dt class="text-xs uppercase tracking-wider text-redup">{{ item.label }}</dt>
          <dd class="mt-1 font-display text-2xl font-semibold tabular-nums" :class="item.warna">
            {{ item.nilai }}
          </dd>
        </div>
        <span class="rounded-md p-1.5 print:hidden" :class="item.latar">
          <Ikon :nama="item.ikon" ukuran="h-4 w-4" />
        </span>
      </div>
    </div>
  </dl>
</template>
