<script setup>
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Ikon from '@/Components/Ikon.vue'
import TombolProses from '@/Components/UI/TombolProses.vue'
import RiwayatBackup from '@/Components/Maintenance/RiwayatBackup.vue'

/**
 * Maintenance & Backup — arsip data absensi (FR-MTN-01). Superadmin saja.
 *
 * Dua aksi berdiri sendiri di sini: retensi (berapa lama backup lama
 * disimpan) dan "Buat Backup Sekarang" (tombol, bukan formulir — tidak
 * ada apa pun untuk diisi). Riwayatnya sendiri berdiri di komponen
 * terpisah ({@see RiwayatBackup.vue}), pola yang sama dengan Riwayat
 * Laporan.
 */

const props = defineProps({
  riwayat: { type: Array, required: true },
  retensi_hari: { type: Number, required: true },
  batas: { type: Object, required: true },
})

const form = useForm({ retensi_hari: props.retensi_hari })

function simpanRetensi() {
  form.post('/admin/setting/maintenance', { preserveScroll: true })
}

const memproses = ref(false)

function buatBackup() {
  memproses.value = true
  router.post(
    '/admin/setting/maintenance/backup',
    {},
    { preserveScroll: true, onFinish: () => (memproses.value = false) },
  )
}
</script>

<template>
  <AdminLayout
    judul="Maintenance & Backup"
    deskripsi="Cadangkan dan pulihkan data absensi secara manual maupun terjadwal, dan atur berapa lama backup lama disimpan."
  >
    <div class="grid gap-6 lg:grid-cols-2">
      <!-- Cakupan backup -->
      <div class="panel p-6">
        <h2 class="font-display text-sm font-semibold text-utama">Cakupan Backup</h2>
        <p class="mt-1 text-xs text-redup">
          Backup ini berisi baris DATA ABSENSI — unit kerja, pegawai, perangkat, event/sesi, dan
          catatan kehadiran — dibundel sebagai satu berkas .zip berisi JSON per tabel.
        </p>
        <p class="mt-2 text-xs text-redup">
          <strong class="font-medium text-peringatan-teks">Tidak termasuk:</strong>
          berkas foto (absen maupun referensi wajah), akun pengguna, audit trail, dan pengaturan
          sistem. Ini bukan snapshot penuh server.
        </p>

        <button
          type="button"
          class="tombol tombol-utama mt-5"
          :disabled="memproses"
          @click="buatBackup"
        >
          <Ikon :nama="memproses ? 'segarkan' : 'unduh'" ukuran="h-4 w-4" :class="memproses && 'animate-spin'" />
          {{ memproses ? 'Mengantre…' : 'Buat Backup Sekarang' }}
        </button>
        <p class="mt-2 text-xs text-redup">
          Diproses di belakang layar — lihat progresnya di Riwayat Backup di bawah, lalu unduh
          begitu selesai.
        </p>
      </div>

      <!-- Retensi -->
      <div class="panel p-6">
        <h2 class="font-display text-sm font-semibold text-utama">Retensi Backup</h2>
        <p class="mt-1 text-xs text-redup">
          Backup yang sudah selesai lebih tua dari ini dihapus otomatis setelah backup terjadwal
          berikutnya — bukan langsung saat masa retensinya lewat.
        </p>

        <form class="mt-4" @submit.prevent="simpanRetensi">
          <label for="retensi_hari" class="block text-sm font-medium text-utama">
            Simpan backup selama (hari)
          </label>
          <input
            id="retensi_hari"
            v-model.number="form.retensi_hari"
            type="number"
            :min="batas.retensi_min"
            :max="batas.retensi_maks"
            class="kolom-isian mt-2 w-32 font-display tabular-nums"
          />
          <p v-if="form.errors.retensi_hari" class="mt-1.5 text-xs text-peringatan-teks">
            {{ form.errors.retensi_hari }}
          </p>
          <p v-else class="mt-1.5 text-xs text-redup">
            Antara {{ batas.retensi_min }} dan {{ batas.retensi_maks }} hari.
          </p>

          <TombolProses class="mt-4" :proses="form.processing">Simpan Retensi</TombolProses>
        </form>
      </div>
    </div>

    <!-- Backup terjadwal -->
    <div class="panel mt-6 p-6">
      <h2 class="font-display text-sm font-semibold text-utama">Backup Terjadwal</h2>
      <p class="mt-1 text-xs text-redup">
        Sistem membuat backup sendiri setiap hari pukul 03:00, di luar jam kegiatan — tidak perlu
        diatur di sini. Tombol "Buat Backup Sekarang" di atas hanya untuk backup TAMBAHAN di luar
        jadwal itu, mis. sebelum melakukan perubahan besar.
      </p>
    </div>

    <RiwayatBackup :riwayat="riwayat" />
  </AdminLayout>
</template>
