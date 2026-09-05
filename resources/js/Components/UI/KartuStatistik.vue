<script setup>
import { computed } from 'vue'
import Ikon from '@/Components/Ikon.vue'
import { useAngkaBerjalan } from '@/Composables/useAngkaBerjalan'

/**
 * Kartu angka ringkas untuk dashboard (FR-DASH-01).
 *
 * Dijadikan komponen sendiri, bukan disusun di dalam `v-for` pada halaman,
 * karena tiap kartu memerlukan pencacah angkanya sendiri — dan composable
 * hanya boleh dipanggil di `setup`, bukan di dalam perulangan template.
 *
 * Susunannya membaca dari kiri-atas ke kanan-bawah: label kecil, angka besar,
 * lalu keterangan. Ubin ikon duduk di sudut sebagai penanda kategori, bukan
 * sebagai kolom kedua yang bersaing dengan angkanya.
 */

const props = defineProps({
  label: { type: String, required: true },
  nilai: { type: Number, required: true },
  satuan: { type: String, default: '' },
  keterangan: { type: String, default: '' },
  ikon: { type: String, required: true },

  // Nada warna kategori: teal, emerald, amber, biru, atau langit.
  nada: { type: String, default: 'teal' },

  desimal: { type: Number, default: 0 },

  // Jeda kemunculan, supaya deretan kartu masuk satu per satu.
  tunda: { type: Number, default: 0 },

  // Bar tipis di kaki kartu; null berarti tidak ada.
  persen: { type: Number, default: null },
})

const angka = useAngkaBerjalan(
  computed(() => props.nilai),
  { desimal: props.desimal, tunda: props.tunda + 120 },
)

const terformat = computed(() =>
  angka.value.toLocaleString('id-ID', {
    minimumFractionDigits: props.desimal,
    maximumFractionDigits: props.desimal,
  }),
)
</script>

<template>
  <div
    class="panel kartu-naik masuk relative overflow-hidden p-5"
    :class="`nada-${nada}`"
    :style="{ '--tunda': `${tunda}ms` }"
  >
    <!--
      Pendar nada di sudut kanan atas. Sangat pucat, dan justru itu gunanya:
      kartu memperoleh warna kategorinya tanpa satu pun teks kehilangan
      kontras di atasnya.
    -->
    <span
      class="pointer-events-none absolute -right-8 -top-10 h-28 w-28 rounded-full opacity-60"
      :style="{ background: 'var(--nada-lembut)' }"
      aria-hidden="true"
    ></span>

    <div class="relative flex items-start justify-between gap-3">
      <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.1em] text-redup">
        {{ label }}
      </p>

      <span class="ubin-ikon h-9 w-9 shrink-0">
        <Ikon :nama="ikon" ukuran="h-[1.125rem] w-[1.125rem]" />
      </span>
    </div>

    <p
      class="relative mt-3 font-display text-[2rem] font-semibold leading-none tabular-nums"
      :style="{ color: 'var(--nada-teks)' }"
    >
      {{ terformat }}<span v-if="satuan" class="text-xl">{{ satuan }}</span>
    </p>

    <p v-if="keterangan" class="relative mt-2 text-xs text-redup">{{ keterangan }}</p>

    <div v-if="persen !== null" class="bar-jalur relative mt-3 h-1.5">
      <span
        class="bar-isi"
        :style="{ width: `${Math.min(100, Math.max(0, persen))}%`, '--tunda': `${tunda + 240}ms` }"
      ></span>
    </div>
  </div>
</template>
