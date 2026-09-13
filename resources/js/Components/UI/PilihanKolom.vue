<script setup>
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/shadcn/popover'
import Ikon from '@/Components/Ikon.vue'

/**
 * Checklist kolom untuk unduhan CSV/Excel — bagian dari "Unduh Data", bukan
 * Generate Laporan (yang kolomnya tetap, sebab bentuknya dokumen resmi, bukan
 * tabel untuk diolah lanjut).
 *
 * Dipakai bersama oleh Laporan dan Rekap, supaya pola checklist-nya tidak
 * ditulis ulang berbeda-beda di tiap halaman.
 *
 * Bentuk `kolom`: `{ kunci, label, terkunci? }`. Kolom `terkunci` (biasanya
 * NIP dan Nama) selalu ikut terunduh dan tidak dapat dimatikan — mengunduh
 * tabel tanpa satu pun cara mengenali barisnya tidak ada gunanya.
 */

defineProps({
  kolom: { type: Array, required: true },
})

const model = defineModel({ type: Array, required: true })

const aktif = (kunci) => model.value.includes(kunci)

function alihkan(kunci, terkunci) {
  if (terkunci) return

  model.value = aktif(kunci)
    ? model.value.filter((k) => k !== kunci)
    : [...model.value, kunci]
}
</script>

<template>
  <Popover>
    <PopoverTrigger as-child>
      <button type="button" class="tombol tombol-garis">
        <Ikon nama="filter" ukuran="h-4 w-4" /> Kolom
      </button>
    </PopoverTrigger>

    <PopoverContent align="end" class="w-60 rounded-xl border-garis bg-permukaan p-3 shadow-xl">
      <p class="mb-2 text-xs font-medium uppercase tracking-wider text-redup">
        Kolom yang diunduh
      </p>

      <label
        v-for="k in kolom"
        :key="k.kunci"
        class="flex items-center gap-2 rounded-md px-1.5 py-1.5 text-sm text-utama"
        :class="k.terkunci ? 'opacity-60' : 'cursor-pointer hover:bg-permukaan-hover'"
      >
        <input
          type="checkbox"
          :checked="aktif(k.kunci)"
          :disabled="k.terkunci"
          class="h-4 w-4 rounded border-garis text-aksen focus:ring-aksen disabled:cursor-not-allowed"
          @change="alihkan(k.kunci, k.terkunci)"
        />
        {{ k.label }}
        <span v-if="k.terkunci" class="ml-auto text-xs text-redup">wajib</span>
      </label>
    </PopoverContent>
  </Popover>
</template>
