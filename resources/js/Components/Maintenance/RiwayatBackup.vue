<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import Ikon from '@/Components/Ikon.vue'
import Lencana from '@/Components/UI/Lencana.vue'
import KeadaanKosong from '@/Components/UI/KeadaanKosong.vue'
import Modal from '@/Components/Modal.vue'
import TombolProses from '@/Components/UI/TombolProses.vue'
import { konfirmasi } from '@/Composables/useNotifikasi'

/**
 * Riwayat Backup data absensi (FR-MTN-01) — pola yang sama persis dengan
 * Riwayat Laporan (lihat Components/Laporan/RiwayatLaporan.vue): backup
 * manual mengantre lewat afterResponse(), baris di sinilah yang
 * menunjukkan progresnya, dan panel ini menyegarkan dirinya sendiri lewat
 * polling selama ada baris yang masih antre/diproses.
 *
 * Beda dari Riwayat Laporan: TIDAK dibagi per-pemohon — backup milik
 * seluruh sistem, jadi setiap baris menyebut siapa memintanya (atau
 * "Terjadwal" bila bukan siapa pun).
 */

const props = defineProps({
  riwayat: { type: Array, required: true },
})

const daftar = ref(props.riwayat)

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

async function segarkan() {
  if (!adaYangBerjalan.value) return

  try {
    const jawaban = await fetch('/admin/setting/maintenance/backup/data', {
      headers: { Accept: 'application/json' },
    })

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
    judul: 'Hapus riwayat backup ini?',
    teks: `Berkas "${item.nama_berkas}" ikut dihapus dari penyimpanan.`,
    tombolYa: 'Ya, hapus',
    nada: 'bahaya',
  })

  if (!setuju) return

  router.delete(`/admin/setting/maintenance/backup/${item.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      daftar.value = daftar.value.filter((r) => r.id !== item.id)
    },
  })
}

/*
 * Pulihkan (restore) — aksi paling berisiko di halaman ini, jadi satu-
 * satunya di sini yang menuntut lebih dari dialog konfirmasi biasa: mengetik ULANG
 * nama berkasnya sendiri, diperiksa PERSIS SAMA di server (lihat
 * MaintenanceController::pulihkan()) — tombol yang bisa tertekan tanpa
 * sengaja tidak boleh cukup untuk menulis ulang data lintas lima tabel.
 */
const targetPulihkan = ref(null)
const formPulihkan = useForm({ konfirmasi: '' })

function bukaPulihkan(item) {
  targetPulihkan.value = item
  formPulihkan.reset()
  formPulihkan.clearErrors()
}

function tutupPulihkan() {
  targetPulihkan.value = null
}

const konfirmasiCocok = computed(
  () => targetPulihkan.value !== null && formPulihkan.konfirmasi === targetPulihkan.value.nama_berkas,
)

function kirimPulihkan() {
  if (!konfirmasiCocok.value) return

  formPulihkan.post(`/admin/setting/maintenance/backup/${targetPulihkan.value.id}/pulihkan`, {
    preserveScroll: true,
    onSuccess: () => tutupPulihkan(),
  })
}
</script>

<template>
  <div class="panel mt-6 p-6">
    <div class="flex items-center justify-between gap-3">
      <div>
        <h2 class="font-display text-base font-semibold text-utama">Riwayat Backup</h2>
        <p class="mt-1 text-sm text-sekunder">
          Status proses backup — antre, diproses, selesai, atau gagal.
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
        ikon="perangkat"
        judul="Belum ada riwayat"
        keterangan="Backup yang dibuat manual maupun terjadwal akan muncul di sini."
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
            <Ikon nama="unduh" ukuran="h-4 w-4" />
          </span>
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-utama">{{ item.nama_berkas }}</p>
            <p class="mt-0.5 truncate text-xs text-redup">
              {{ item.dipicu_oleh === 'manual' ? 'Manual' : 'Terjadwal' }}
              <template v-if="item.dibuat_oleh"> · oleh {{ item.dibuat_oleh }}</template>
              · {{ item.dibuat_pada }}
              <template v-if="item.ukuran_label"> · {{ item.ukuran_label }}</template>
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
            :href="`/admin/setting/maintenance/backup/${item.id}/unduh`"
            class="tombol tombol-garis px-3 py-1.5 text-xs"
          >
            <Ikon nama="unduh" ukuran="h-3.5 w-3.5" /> Unduh
          </a>

          <button
            v-if="item.status === 'selesai'"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border border-garis px-3 py-1.5 text-xs font-medium text-redup transition hover:border-aksen hover:text-aksen-teks active:scale-95"
            @click="bukaPulihkan(item)"
          >
            <Ikon nama="segarkan" ukuran="h-3.5 w-3.5" /> Pulihkan
          </button>

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

    <Modal
      :terbuka="targetPulihkan !== null"
      judul="Pulihkan Backup"
      keterangan="Menggabungkan data dari backup ke data yang sedang berjalan."
      @tutup="tutupPulihkan"
    >
      <div v-if="targetPulihkan" class="space-y-4">
        <p class="rounded-lg bg-peringatan-lembut px-3.5 py-2.5 text-sm text-peringatan-teks">
          Baris dari <strong class="font-semibold">{{ targetPulihkan.nama_berkas }}</strong>
          akan ditambahkan atau MENIMPA baris yang ber-ID sama pada data yang berjalan sekarang.
          Data yang dibuat SETELAH backup ini tetap ada, tidak dihapus — tetapi baris yang
          nilainya sudah berubah sejak backup ini akan kembali ke nilai lama.
        </p>

        <div>
          <label for="konfirmasi-pulihkan" class="block text-sm font-medium text-utama">
            Ketik ulang nama berkas untuk melanjutkan
          </label>
          <p class="mt-1 text-xs text-redup">{{ targetPulihkan.nama_berkas }}</p>
          <input
            id="konfirmasi-pulihkan"
            v-model="formPulihkan.konfirmasi"
            type="text"
            autocomplete="off"
            class="kolom-isian mt-2 font-mono text-xs"
            @keyup.enter="kirimPulihkan"
          />
          <p v-if="formPulihkan.errors.konfirmasi" class="mt-1.5 text-xs text-peringatan-teks">
            {{ formPulihkan.errors.konfirmasi }}
          </p>
        </div>
      </div>

      <template #aksi>
        <button
          type="button"
          class="rounded-lg px-4 py-2 text-sm font-medium text-sekunder hover:bg-permukaan-hover"
          @click="tutupPulihkan"
        >
          Batal
        </button>
        <TombolProses
          tipe="button"
          ikon="segarkan"
          teks-proses="Memulihkan…"
          :proses="formPulihkan.processing"
          :nonaktif="!konfirmasiCocok"
          @click="kirimPulihkan"
        >
          Pulihkan Data
        </TombolProses>
      </template>
    </Modal>
  </div>
</template>
