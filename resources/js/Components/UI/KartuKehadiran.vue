<script setup>
import { computed } from 'vue'
import { useAngkaBerjalan } from '@/Composables/useAngkaBerjalan'

/**
 * Kartu utama dashboard: kehadiran hari ini (FR-DASH-01).
 *
 * Inilah satu-satunya "momen visual" halaman ini. Sebelumnya dashboard membuka
 * dengan empat kartu berukuran sama, dan empat hal yang sama besar berarti
 * tidak ada satu pun yang jadi pusat perhatian — mata harus memilih sendiri,
 * dan biasanya memilih yang paling kiri.
 *
 * Cincinnya mewakili proporsi yang SUNGGUHAN: berapa dari sekian pegawai dalam
 * cakupan yang sudah tercatat hadir hari ini. Di dalamnya, kehadiran itu
 * dipecah lagi menjadi tepat waktu dan terlambat — dua angka yang selama ini
 * berdiri sebagai panel terpisah di bawah, mengulang cerita yang sama dengan
 * cincin kedua yang bersaing dengan yang ini.
 */

const props = defineProps({
  hadir: { type: Number, required: true },
  total: { type: Number, required: true },
  tepat: { type: Number, required: true },
  terlambat: { type: Number, required: true },
})

/* Keliling cincin: 2πr dengan r = 52 pada viewBox 140×140. */
const KELILING = 2 * Math.PI * 52

const persen = computed(() =>
  props.total === 0 ? 0 : Math.round((props.hadir / props.total) * 1000) / 10,
)

const panjangTerisi = computed(() => (persen.value / 100) * KELILING)

const persenBerjalan = useAngkaBerjalan(persen, { desimal: 1, tunda: 200 })
const hadirBerjalan = useAngkaBerjalan(
  computed(() => props.hadir),
  { tunda: 200 },
)
const tepatBerjalan = useAngkaBerjalan(
  computed(() => props.tepat),
  { tunda: 280 },
)
const terlambatBerjalan = useAngkaBerjalan(
  computed(() => props.terlambat),
  { tunda: 340 },
)

const belumAbsen = computed(() => Math.max(0, props.total - props.hadir))
</script>

<template>
  <!--
    Isinya dibatasi lebarnya, tidak dibiarkan melebar mengikuti kartu. Pada
    layar 1440px, tiga angka rincian yang direntang selebar halaman berjarak
    ratusan piksel satu sama lain dan berhenti terbaca sebagai satu kelompok.
  -->
  <div class="panel flex flex-col gap-7 p-6 sm:flex-row sm:items-center">
    <div class="relative mx-auto h-36 w-36 shrink-0 sm:mx-0">
      <svg viewBox="0 0 140 140" class="h-full w-full -rotate-90">
        <circle
          cx="70"
          cy="70"
          r="52"
          fill="none"
          stroke="var(--tema-permukaan-2)"
          stroke-width="16"
        />
        <!--
          Tidak digambar sama sekali pada nol persen. Ujung membulat
          (`stroke-linecap="round"`) tetap menggambar setitik warna walau
          panjang busurnya nol, dan titik itu terbaca sebagai noda — atau lebih
          buruk, sebagai kehadiran yang sebenarnya tidak ada.
        -->
        <circle
          v-if="persen > 0"
          cx="70"
          cy="70"
          r="52"
          fill="none"
          stroke="var(--tema-berhasil)"
          stroke-width="16"
          stroke-linecap="round"
          class="cincin-terisi"
          :stroke-dasharray="`${panjangTerisi} ${KELILING}`"
          :style="{ '--panjang-garis': panjangTerisi }"
        />
      </svg>

      <div class="absolute inset-0 flex flex-col items-center justify-center">
        <span class="font-display text-3xl font-semibold tabular-nums text-berhasil-teks">
          {{ persenBerjalan }}<span class="text-xl">%</span>
        </span>
        <span class="text-[0.625rem] uppercase tracking-[0.1em] text-redup">hadir</span>
      </div>
    </div>

    <div class="min-w-0">
      <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.1em] text-redup">
        Kehadiran Hari Ini
      </p>

      <p class="mt-1.5 font-display text-2xl font-semibold tabular-nums">
        {{ hadirBerjalan }}
        <span class="text-base font-normal text-redup">dari {{ total }} pegawai</span>
      </p>
    </div>

    <!--
      Rincian kehadiran didorong ke tepi kanan kartu, bukan menempel pada
      cincinnya. Tepat, terlambat, dan belum absen adalah pecahan dari angka
      hadir yang sama — dan pada kartu selebar ini, meletakkan semuanya di kiri
      menyisakan separuh kartu kosong yang terbaca sebagai belum selesai.
    -->
    <dl
      class="flex flex-wrap gap-x-10 gap-y-3 border-t border-garis pt-4 sm:ml-auto sm:border-l sm:border-t-0 sm:pl-10 sm:pt-0"
    >
      <div class="nada-emerald">
        <dd class="font-display text-xl font-semibold tabular-nums text-berhasil-teks">
          {{ tepatBerjalan }}
        </dd>
        <dt class="mt-0.5 text-xs text-redup">tepat waktu</dt>
      </div>

      <div class="nada-amber">
        <dd class="font-display text-xl font-semibold tabular-nums text-peringatan-teks">
          {{ terlambatBerjalan }}
        </dd>
        <dt class="mt-0.5 text-xs text-redup">terlambat</dt>
      </div>

      <div>
        <dd class="font-display text-xl font-semibold tabular-nums text-sekunder">
          {{ belumAbsen }}
        </dd>
        <dt class="mt-0.5 text-xs text-redup">belum absen</dt>
      </div>
    </dl>
  </div>
</template>
