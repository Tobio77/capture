<script setup>
import { computed } from 'vue'
import Ikon from '@/Components/Ikon.vue'
import { useAngkaBerjalan } from '@/Composables/useAngkaBerjalan'

/**
 * Kartu angka ringkas untuk dashboard (FR-DASH-01).
 *
 * **Indikator visual hanya bila ada penyebut nyata** (revisi S31). Versi
 * sebelumnya memasang bar kemajuan di kartu "Perangkat Aktif 0 dari 5" dan
 * "Kehadiran 0,0%", dan keduanya tergambar sebagai jalur abu-abu kosong yang
 * terbaca seperti komponen rusak. Yang lebih buruk: kartu "Total Pegawai 666"
 * nyaris ikut diberi bar juga — padahal 666 bukan bagian dari apa pun, jadi
 * bar itu tidak akan pernah punya arti.
 *
 * Aturannya sekarang: angka tanpa penyebut tampil sebagai angka saja, dan
 * ruang yang tersisa diisi keterangan yang benar-benar menerangkan.
 *
 * Untuk penyebut kecil (≤ 12) dipakai PIP DISKRET, bukan bar. Lima titik
 * dengan nol terisi menyatakan "nol dari lima" dengan jujur; bar 0% hanya
 * menyatakan "kosong", dan pembacanya tidak pernah tahu kosong dari berapa.
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
  tunda: { type: Number, default: 0 },

  /**
   * Pip diskret, hanya bila angkanya memang bagian dari sebuah penyebut.
   * Bentuknya `{ terisi, total }`; null berarti tidak ada penyebut.
   */
  pip: { type: Object, default: null },
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

/* Lebih dari selusin titik berhenti terbaca sebagai hitungan. */
const pipTampil = computed(() =>
  props.pip !== null && props.pip.total > 0 && props.pip.total <= 12 ? props.pip : null,
)
</script>

<template>
  <div class="panel flex flex-col p-5" :class="`nada-${nada}`">
    <div class="flex items-start justify-between gap-3">
      <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.1em] text-redup">
        {{ label }}
      </p>

      <span class="ubin-ikon h-9 w-9 shrink-0">
        <Ikon :nama="ikon" ukuran="h-[1.125rem] w-[1.125rem]" />
      </span>
    </div>

    <!--
      Angkanya bertinta navy, bukan berwarna nada. Empat angka berwarna
      berjajar membuat tidak ada yang menonjol; warna disimpan untuk ubin ikon
      dan pip, yang memang menandai kategori.
    -->
    <p class="mt-3 font-display text-[2rem] font-semibold leading-none tabular-nums">
      {{ terformat }}<span v-if="satuan" class="text-xl text-redup">{{ satuan }}</span>
    </p>

    <p v-if="keterangan" class="mt-2 text-xs text-redup">{{ keterangan }}</p>

    <!-- Pip: satu titik per satuan, terisi sebanyak yang tercapai. -->
    <div v-if="pipTampil" class="mt-3 flex flex-wrap items-center gap-1.5">
      <span
        v-for="ke in pipTampil.total"
        :key="ke"
        class="h-2 w-2 rounded-full transition-colors duration-300"
        :style="{
          backgroundColor:
            ke <= pipTampil.terisi ? 'var(--nada-kuat)' : 'var(--tema-garis-kuat)',
        }"
      ></span>
    </div>
  </div>
</template>
