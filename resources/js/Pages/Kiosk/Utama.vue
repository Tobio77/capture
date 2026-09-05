<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import LayarAbsen from '@/Components/Absen/LayarAbsen.vue'
import Ikon from '@/Components/Ikon.vue'

/**
 * Layar tap pada perangkat absen (UIUX §4.2).
 *
 * Isinya sepenuhnya {@see LayarAbsen}; halaman ini hanya menyambungkannya ke
 * endpoint yang dipagari device token dan menambahkan aksi khas perangkat.
 *
 * Satu halaman melayani dua mode. Bentuk endpointnya sama persis — yang
 * berbeda hanya prefiksnya, dan server yang menentukan event mana yang
 * dilayani masing-masing (revisi S29).
 */

const props = defineProps({
  mode: { type: String, required: true },
  event: { type: Object, default: null },
  absen_umum_aktif: { type: Boolean, default: true },
  metode: { type: Object, required: true },
  ambang_kecocokan_wajah: { type: Number, required: true },
  kompresi: { type: Object, required: true },
  daftar_presensi: { type: Array, required: true },
  waktu_server: { type: String, default: null },
  daftar_wajah_otomatis: { type: Boolean, default: false },
})

const page = usePage()
const kiosk = computed(() => page.props.kiosk)

const modeEvent = computed(() => props.mode === 'event')

const endpoint = computed(() => ({
  presensi: `/kiosk/${props.mode}/presensi`,
  identifikasi: `/kiosk/${props.mode}/tap/identifikasi`,
  simpan: `/kiosk/${props.mode}/absen`,
}))

/*
 * Keterangan yang dibaca petugas ketika kolom tap terkunci. Pada Absen Event
 * keadaan ini praktis tidak terjadi — perangkat yang belum bergabung sudah
 * dipulangkan server ke beranda — sementara pada Absen Umum ia berarti admin
 * mematikan absen harian di Setting Absen.
 */
const judulKosong = computed(() =>
  modeEvent.value
    ? 'Tidak ada event aktif'
    : props.absen_umum_aktif
      ? 'Sesi absen umum hari ini belum dibuka'
      : 'Absen umum sedang dimatikan admin',
)

function keluarDariEvent() {
  if (window.confirm('Keluar dari event ini? Perangkat perlu kode unit kerja untuk bergabung lagi.')) {
    router.post('/kiosk/event/keluar')
  }
}
</script>

<template>
  <Head :title="modeEvent ? 'Absen Event' : 'Absen Umum'" />

  <LayarAbsen
    :event="event"
    :metode="metode"
    :ambang_kecocokan_wajah="ambang_kecocokan_wajah"
    :kompresi="kompresi"
    :daftar_presensi="daftar_presensi"
    :waktu_server="waktu_server"
    :daftar_wajah_otomatis="daftar_wajah_otomatis"
    :endpoint="endpoint"
    :titik="`${kiosk.nama_titik} · ${kiosk.unit_kerja?.nama ?? ''}`"
    :judul_kosong="judulKosong"
  >
    <template #aksi>
      <button
        v-if="modeEvent"
        type="button"
        class="rounded-lg border border-sidebar-garis px-3 py-2 text-xs font-medium text-sidebar-redup transition-colors duration-150 hover:bg-white/10 hover:text-sidebar-teks active:scale-95"
        @click="keluarDariEvent"
      >
        Keluar dari Event
      </button>

      <Link
        href="/kiosk"
        class="tautan-aksi inline-flex items-center gap-1.5 rounded-lg border border-sidebar-garis px-3 py-2 text-xs font-medium text-sidebar-redup transition-colors duration-150 hover:bg-white/10 hover:text-sidebar-teks active:scale-95"
      >
        <Ikon nama="kiri" ukuran="h-3.5 w-3.5" /> Beranda
      </Link>
    </template>
  </LayarAbsen>
</template>
