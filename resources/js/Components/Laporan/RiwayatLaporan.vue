<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import Ikon from '@/Components/Ikon.vue'
import Lencana from '@/Components/UI/Lencana.vue'
import KeadaanKosong from '@/Components/UI/KeadaanKosong.vue'
import { konfirmasi } from '@/Composables/useNotifikasi'

/**
 * Riwayat Generate Laporan Resmi (FR-LAP-04, revisi antrian).
 *
 * Generate Laporan tidak lagi langsung mengunduh berkas — ia mengantrekan
 * pembuatannya (lihat LaporanController::generate()) dan baris di sinilah
 * yang menunjukkan progresnya. Selama masih ada baris antre/diproses,
 * panel ini menyegarkan dirinya sendiri lewat polling, mengikuti pola yang
 * sama dengan Absen Umum dan Rekap — bukan menunggu pengguna memuat ulang
 * halaman untuk tahu laporannya sudah selesai atau belum.
 */

const props = defineProps({
  riwayat: { type: Array, required: true },
})

const daftar = ref(props.riwayat)

/*
 * generate() mengirim POST dan Inertia menyegarkan prop `riwayat` dari
 * jawabannya — tetapi komponen ini tidak dibuat ulang (key-nya sama), jadi
 * `ref(props.riwayat)` di atas hanya menangkap nilai awal. Tanpa watch ini,
 * baris "antre" yang baru saja diminta tidak akan terlihat sampai polling
 * pertama menyusul.
 */
watch(
  () => props.riwayat,
  (v) => (daftar.value = v),
)

const JEDA_SEGAR_MS = 3000
let jeda = null

const adaYangBerjalan = computed(() =>
  daftar.value.some((r) => r.status === 'antre' || r.status === 'diproses'),
)

const warnaStatus = (status) =>
  ({ antre: 'slate', diproses: 'teal', selesai: 'emerald', gagal: 'rose' })[status] ?? 'slate'

const ikonFormat = (format) => ({ pdf: 'unduh', docx: 'unduh', xlsx: 'unduh' })[format] ?? 'unduh'

const labelFormat = (format) => ({ pdf: 'PDF', docx: 'Word', xlsx: 'Excel' })[format] ?? format.toUpperCase()

async function segarkan() {
  if (!adaYangBerjalan.value) return

  try {
    const jawaban = await fetch('/admin/laporan/riwayat', { headers: { Accept: 'application/json' } })

    if (!jawaban.ok) return

    daftar.value = (await jawaban.json()).riwayat
  } catch {
    // Percobaan berikutnya menyusul sendiri.
  }
}

onMounted(() => {
  jeda = setInterval(segarkan, JEDA_SEGAR_MS)
})

onBeforeUnmount(() => clearInterval(jeda))

async function hapus(item) {
  const setuju = await konfirmasi({
    judul: 'Hapus riwayat laporan ini?',
    teks: `Laporan "${item.periode_label}" (${labelFormat(item.format)}) beserta berkasnya dihapus.`,
    tombolYa: 'Ya, hapus',
    nada: 'bahaya',
  })

  if (!setuju) return

  router.delete(`/admin/laporan/riwayat/${item.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      daftar.value = daftar.value.filter((r) => r.id !== item.id)
    },
  })
}
</script>

<template>
  <div class="panel mt-6 p-6 print:hidden">
    <div class="flex items-center justify-between gap-3">
      <div>
        <h2 class="font-display text-base font-semibold text-utama">Riwayat Laporan</h2>
        <p class="mt-1 text-sm text-sekunder">
          Status permintaan Generate Laporan — antre, diproses, selesai, atau gagal.
        </p>
      </div>
      <Ikon
        v-if="adaYangBerjalan"
        nama="jam"
        ukuran="h-4 w-4 animate-spin"
        class="shrink-0 text-aksen"
      />
    </div>

    <div v-if="daftar.length === 0" class="mt-4">
      <KeadaanKosong
        ikon="laporan"
        judul="Belum ada riwayat"
        keterangan="Laporan yang Anda proses lewat Generate Laporan akan muncul di sini."
      />
    </div>

    <ul v-else class="mt-4 flex flex-col gap-2">
      <li
        v-for="item in daftar"
        :key="item.id"
        class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-garis bg-permukaan-2 px-4 py-3"
      >
        <div class="flex min-w-0 items-center gap-3">
          <span class="rounded-md bg-permukaan p-2 text-redup">
            <Ikon :nama="ikonFormat(item.format)" ukuran="h-4 w-4" />
          </span>
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-utama">
              {{ labelFormat(item.format) }} · {{ item.periode_label }}
            </p>
            <p class="mt-0.5 truncate text-xs text-redup">
              {{ item.unit_kerja }} · diminta {{ item.dibuat_pada }}
              <template v-if="item.dibuat_oleh"> · oleh {{ item.dibuat_oleh }}</template>
            </p>
            <p v-if="item.status === 'gagal' && item.pesan_galat" class="mt-1 text-xs text-galat-teks">
              {{ item.pesan_galat }}
            </p>
          </div>
        </div>

        <div class="flex shrink-0 items-center gap-2">
          <Lencana :warna="warnaStatus(item.status)" :denyut="item.status === 'diproses'">
            {{ item.status_label }}
          </Lencana>

          <a
            v-if="item.status === 'selesai'"
            :href="`/admin/laporan/riwayat/${item.id}/unduh`"
            class="tombol tombol-garis px-3 py-1.5 text-xs"
          >
            <Ikon nama="unduh" ukuran="h-3.5 w-3.5" /> Unduh
          </a>

          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border border-garis px-3 py-1.5 text-xs font-medium text-redup transition hover:border-galat hover:text-galat-teks active:scale-95"
            @click="hapus(item)"
          >
            <Ikon nama="hapus" ukuran="h-3.5 w-3.5" /> Hapus
          </button>
        </div>
      </li>
    </ul>
  </div>
</template>
