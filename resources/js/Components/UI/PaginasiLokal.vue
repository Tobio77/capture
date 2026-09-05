<script setup>
import { computed } from 'vue'
import Ikon from '@/Components/Ikon.vue'

/**
 * Navigasi halaman untuk daftar yang SUDAH ada di memori.
 *
 * Berdampingan dengan {@see Paginasi.vue}, yang melayani paginator Laravel dan
 * berpindah halaman lewat kunjungan Inertia. Keduanya sengaja terpisah karena
 * sumber datanya berbeda secara mendasar — satu memerlukan perjalanan ke
 * server, satu tidak — tetapi bentuk dan bunyinya dibuat sama persis supaya
 * pengguna tidak perlu mempelajari dua pola.
 *
 * Dipakai Daftar e-Presensi pada layar titik absen dan Rekap Absen: barisnya
 * ditarik utuh secara berkala dan sudah berada di layar, sehingga meminta
 * halaman berikutnya ke server hanya akan menambah kelambatan tanpa menambah
 * apa pun.
 */

const props = defineProps({
  /** Jumlah baris yang sedang ditampilkan, setelah disaring. */
  total: { type: Number, required: true },

  /**
   * Jumlah baris sebelum disaring, bila pencarian sedang aktif.
   *
   * Tanpa penyebutnya, "menampilkan 12" pada daftar 400 orang terbaca sebagai
   * kehadiran yang anjlok, bukan sebagai pencarian yang sedang menyaring.
   */
  totalAsli: { type: Number, default: null },

  perHalaman: { type: Number, default: 10 },
})

const halaman = defineModel('halaman', { type: Number, default: 1 })

const jumlahHalaman = computed(() => Math.max(1, Math.ceil(props.total / props.perHalaman)))

const dari = computed(() => (props.total === 0 ? 0 : (halaman.value - 1) * props.perHalaman + 1))

const sampai = computed(() => Math.min(props.total, halaman.value * props.perHalaman))

const tersaring = computed(() => props.totalAsli !== null && props.totalAsli !== props.total)

/* Satu halaman penuh tanpa saringan tidak menyisakan apa pun untuk dikatakan. */
const tampil = computed(() => props.total > props.perHalaman || tersaring.value)

function pindah(ke) {
  halaman.value = Math.min(jumlahHalaman.value, Math.max(1, ke))
}
</script>

<template>
  <div
    v-if="tampil"
    class="flex flex-wrap items-center justify-between gap-3 border-t border-garis px-4 py-2.5"
  >
    <p class="text-xs text-redup">
      Menampilkan
      <span class="font-display tabular-nums text-utama">{{ dari }}–{{ sampai }}</span>
      dari
      <span class="font-display tabular-nums text-utama">{{ total }}</span>
      baris
      <template v-if="tersaring">
        — disaring dari
        <span class="font-display tabular-nums text-sekunder">{{ totalAsli }}</span>
      </template>
    </p>

    <div v-if="jumlahHalaman > 1" class="flex items-center gap-1">
      <button
        type="button"
        class="rounded-lg p-1.5 text-sekunder transition-colors duration-150 hover:bg-permukaan-hover disabled:opacity-40"
        :disabled="halaman <= 1"
        aria-label="Halaman sebelumnya"
        @click="pindah(halaman - 1)"
      >
        <Ikon nama="kiri" ukuran="h-4 w-4" />
      </button>

      <span class="px-2 font-display text-xs tabular-nums text-sekunder">
        {{ halaman }} / {{ jumlahHalaman }}
      </span>

      <button
        type="button"
        class="rounded-lg p-1.5 text-sekunder transition-colors duration-150 hover:bg-permukaan-hover disabled:opacity-40"
        :disabled="halaman >= jumlahHalaman"
        aria-label="Halaman berikutnya"
        @click="pindah(halaman + 1)"
      >
        <Ikon nama="kanan" ukuran="h-4 w-4" />
      </button>
    </div>
  </div>
</template>
