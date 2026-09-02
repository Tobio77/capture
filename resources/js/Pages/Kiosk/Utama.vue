<script setup>
import { computed } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import LayarAbsen from '@/Components/Absen/LayarAbsen.vue'

/**
 * Layar Utama Perangkat Absen (UIUX §4.2).
 *
 * Isinya sepenuhnya {@see LayarAbsen}; halaman ini hanya menyambungkannya ke
 * endpoint yang dipagari device token dan menambahkan aksi khas perangkat.
 */

defineProps({
  event: { type: Object, default: null },
  metode: { type: Object, required: true },
  ambang_kecocokan_wajah: { type: Number, required: true },
  kompresi: { type: Object, required: true },
  daftar_presensi: { type: Array, required: true },
})

const page = usePage()
const kiosk = computed(() => page.props.kiosk)

const endpoint = {
  presensi: '/kiosk/presensi',
  identifikasi: '/kiosk/tap/identifikasi',
  simpan: '/kiosk/absen',
}

const lepas = () => {
  if (
    window.confirm(
      'Lepaskan perangkat ini dari titik absen? Perangkat harus diaktifkan ulang dengan kode baru.',
    )
  ) {
    router.post('/kiosk/lepas')
  }
}
</script>

<template>
  <Head title="Layar Perangkat Absen" />

  <LayarAbsen
    :event="event"
    :metode="metode"
    :ambang_kecocokan_wajah="ambang_kecocokan_wajah"
    :kompresi="kompresi"
    :daftar_presensi="daftar_presensi"
    :endpoint="endpoint"
    :titik="`${kiosk.nama_titik} · ${kiosk.unit_kerja?.nama ?? ''}`"
  >
    <template #aksi>
      <button
        type="button"
        class="rounded-md border border-white/20 px-3 py-1.5 text-xs font-medium text-navy-100 transition hover:bg-white/10 hover:text-white active:scale-95"
        @click="lepas"
      >
        Lepas Perangkat
      </button>
    </template>
  </LayarAbsen>
</template>
