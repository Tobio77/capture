<script setup>
import { Head, Link } from '@inertiajs/vue3'
import LayarAbsen from '@/Components/Absen/LayarAbsen.vue'
import Ikon from '@/Components/Ikon.vue'

/**
 * Layar tangkap absen umum di peramban admin.
 *
 * Memakai layar yang sama dengan perangkat absen; yang berbeda hanya
 * endpointnya, yang dipagari sesi admin alih-alih device token.
 *
 * Pemilih unit kerja yang dulu berdiri di sini sudah tidak ada: sejak S49 sesi
 * absen umum SATU untuk seluruh dinas, sehingga tidak ada lagi yang perlu
 * dipilih — layar ini selalu melayani sesi hari ini, dan pegawai unit mana pun
 * dapat mengabsen di sini.
 */

defineProps({
  event: { type: Object, default: null },
  absen_umum_aktif: { type: Boolean, required: true },
  metode: { type: Object, required: true },
  ambang_kecocokan_wajah: { type: Number, required: true },
  kompresi: { type: Object, required: true },
  daftar_presensi: { type: Array, required: true },
  waktu_server: { type: String, default: null },
  daftar_wajah_otomatis: { type: Boolean, default: false },
  status_jendela: { type: Object, default: null },
})

const endpoint = {
  presensi: '/admin/kelola-absen/absen-umum/presensi',
  identifikasi: '/admin/kelola-absen/absen-umum/tap/identifikasi',
  simpan: '/admin/kelola-absen/absen-umum/absen',
}
</script>

<template>
  <Head title="Layar Absen Umum" />

  <div v-if="!absen_umum_aktif" class="flex min-h-screen items-center justify-center bg-kertas px-6">
    <div class="max-w-md panel p-8 text-center bayang-naik">
      <span class="inline-flex rounded-full bg-peringatan-lembut p-3 text-peringatan-teks">
        <Ikon nama="peringatan" ukuran="h-6 w-6" />
      </span>
      <h1 class="mt-4 font-display text-lg font-semibold text-utama">Absen umum sedang dimatikan</h1>
      <p class="mt-2 text-sm text-sekunder">
        Nyalakan absen umum pada Setting Absen sebelum layar ini dapat menerima tap.
      </p>
      <Link
        href="/admin/kelola-absen/setting"
        class="tombol tombol-utama mt-5"
      >
        <Ikon nama="filter" ukuran="h-4 w-4" /> Buka Setting Absen
      </Link>
    </div>
  </div>

  <!--
    `titik` menyatakan bahwa layar ini dibuka di peramban admin, bukan di
    perangkat titik absen — satu-satunya beda yang berarti bagi petugas yang
    berdiri di depannya.
  -->
  <LayarAbsen
    v-else
    :event="event"
    :metode="metode"
    :ambang_kecocokan_wajah="ambang_kecocokan_wajah"
    :kompresi="kompresi"
    :daftar_presensi="daftar_presensi"
    :waktu_server="waktu_server"
    :daftar_wajah_otomatis="daftar_wajah_otomatis"
    :status_jendela="status_jendela"
    :endpoint="endpoint"
    label="Absen Umum"
    judul="Seluruh Unit Kerja"
    titik="Layar absen admin, bukan perangkat titik absen"
    judul_kosong="Sesi absen umum hari ini belum dibuka"
  >
    <template #aksi>
      <Link
        href="/admin/kelola-absen/absen-umum"
        class="tautan-aksi inline-flex items-center gap-1.5 rounded-lg border border-sidebar-garis px-3 py-2 text-xs font-medium text-sidebar-redup transition-colors duration-150 hover:bg-white/10 hover:text-sidebar-teks active:scale-95"
      >
        <Ikon nama="kiri" ukuran="h-3.5 w-3.5" /> Kembali
      </Link>
    </template>
  </LayarAbsen>
</template>
