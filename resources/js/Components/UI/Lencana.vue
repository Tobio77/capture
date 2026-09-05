<script setup>
import { computed } from 'vue'

/**
 * Lencana status dengan titik penanda.
 *
 * Warnanya mengikuti palet proyek lewat token peran, sehingga sama terbacanya
 * di tema terang maupun gelap: emerald untuk keadaan yang diinginkan, amber
 * untuk yang perlu perhatian, netral untuk yang selesai.
 *
 * `baru` memutar animasi singkat sekali jalan — dipakai pada daftar yang
 * bertambah sendiri (Daftar e-Presensi, Rekap Absen) supaya baris yang baru
 * masuk menarik mata tanpa perlu pengguna mencarinya.
 */

const props = defineProps({
  warna: { type: String, default: 'slate' },
  titik: { type: Boolean, default: true },
  denyut: { type: Boolean, default: false },
  baru: { type: Boolean, default: false },
})

const palet = {
  emerald: { kotak: 'bg-berhasil-lembut text-berhasil-teks', titik: 'bg-berhasil' },
  amber: { kotak: 'bg-peringatan-lembut text-peringatan-teks', titik: 'bg-peringatan' },
  teal: { kotak: 'bg-aksen-lembut text-aksen-teks', titik: 'bg-aksen' },
  navy: { kotak: 'bg-info-lembut text-info-teks', titik: 'bg-info-teks' },
  langit: { kotak: 'bg-langit-lembut text-langit-teks', titik: 'bg-langit' },
  slate: { kotak: 'bg-permukaan-2 text-sekunder', titik: 'bg-redup' },
}

const gaya = computed(() => palet[props.warna] ?? palet.slate)
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors duration-150"
    :class="[gaya.kotak, baru && 'lencana-baru']"
  >
    <span
      v-if="titik"
      class="h-1.5 w-1.5 rounded-full"
      :class="[gaya.titik, denyut && 'animate-pulse']"
    ></span>
    <slot />
  </span>
</template>

<style>
/*
 * Micro-animation baris baru: lencana muncul sedikit membesar lalu mengendap.
 * Sekali jalan, tanpa perulangan — penanda kedatangan, bukan hiasan tetap.
 * `prefers-reduced-motion` sudah dimatikan global di tema.css.
 */
@keyframes lencana-masuk {
    0% {
        transform: scale(0.8);
        opacity: 0;
    }

    60% {
        transform: scale(1.06);
        opacity: 1;
    }

    100% {
        transform: scale(1);
    }
}

.lencana-baru {
    animation: lencana-masuk 420ms cubic-bezier(0.34, 1.56, 0.64, 1);
}
</style>
