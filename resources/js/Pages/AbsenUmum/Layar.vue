<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import LayarAbsen from '@/Components/Absen/LayarAbsen.vue'
import Ikon from '@/Components/Ikon.vue'
import Pilihan from '@/Components/UI/Pilihan.vue'

/**
 * Layar tangkap absen umum di peramban admin.
 *
 * Memakai layar yang sama dengan perangkat absen; yang berbeda hanya
 * endpointnya, yang dipagari sesi admin alih-alih device token, dan unit
 * kerja yang dilayaninya dipilih di sini alih-alih mengikuti tempat perangkat
 * dipasang.
 */

const props = defineProps({
  event: { type: Object, default: null },
  unit_kerja: { type: Array, required: true },
  unit_kerja_id: { type: Number, default: null },
  absen_umum_aktif: { type: Boolean, required: true },
  metode: { type: Object, required: true },
  ambang_kecocokan_wajah: { type: Number, required: true },
  kompresi: { type: Object, required: true },
  daftar_presensi: { type: Array, required: true },
})

const unitDipilih = ref(props.unit_kerja_id)

const kueri = computed(() =>
  unitDipilih.value === null ? '' : `?unit_kerja_id=${unitDipilih.value}`,
)

const endpoint = computed(() => ({
  presensi: `/admin/kelola-absen/absen-umum/presensi${kueri.value}`,
  identifikasi: `/admin/kelola-absen/absen-umum/tap/identifikasi${kueri.value}`,
  simpan: `/admin/kelola-absen/absen-umum/absen${kueri.value}`,
}))

const opsiUnit = computed(() =>
  props.unit_kerja.map((unit) => ({ nilai: unit.id, label: unit.nama, keterangan: unit.kode })),
)

const namaUnit = computed(
  () => props.unit_kerja.find((unit) => unit.id === props.unit_kerja_id)?.nama ?? '',
)

/*
 * Berpindah unit memuat ulang halaman: sesi harian yang dilayani ikut
 * berganti, begitu pula daftar presensi yang sedang tampil.
 */
function gantiUnit() {
  router.get('/admin/kelola-absen/absen-umum/layar', { unit_kerja_id: unitDipilih.value })
}
</script>

<template>
  <Head title="Layar Absen Umum" />

  <div v-if="!absen_umum_aktif" class="flex min-h-screen items-center justify-center bg-kertas px-6">
    <div class="max-w-md rounded-xl border border-garis bg-permukaan p-8 text-center bayang-naik">
      <span class="inline-flex rounded-full bg-peringatan-lembut p-3 text-peringatan-teks">
        <Ikon nama="peringatan" ukuran="h-6 w-6" />
      </span>
      <h1 class="mt-4 font-display text-lg font-semibold text-utama">Absen umum sedang dimatikan</h1>
      <p class="mt-2 text-sm text-sekunder">
        Nyalakan absen umum pada Setting Absen sebelum layar ini dapat menerima tap.
      </p>
      <Link
        href="/admin/kelola-absen/setting"
        class="mt-5 inline-flex items-center gap-1.5 rounded-lg bg-aksen px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-aksen-kuat active:scale-95"
      >
        <Ikon nama="filter" ukuran="h-4 w-4" /> Buka Setting Absen
      </Link>
    </div>
  </div>

  <LayarAbsen
    v-else
    :event="event"
    :metode="metode"
    :ambang_kecocokan_wajah="ambang_kecocokan_wajah"
    :kompresi="kompresi"
    :daftar_presensi="daftar_presensi"
    :endpoint="endpoint"
    :titik="namaUnit ? `Layar absen admin · ${namaUnit}` : 'Layar absen admin'"
    judul_kosong="Pilih unit kerja untuk membuka sesi absen umum"
  >
    <template #aksi>
      <Pilihan
        v-if="unit_kerja.length > 1"
        v-model="unitDipilih"
        :opsi="opsiUnit"
        class="w-56"
        @update:model-value="gantiUnit"
      />

      <Link
        href="/admin/kelola-absen/absen-umum"
        class="tautan-aksi inline-flex items-center gap-1.5 rounded-lg border border-sidebar-garis px-3 py-2 text-xs font-medium text-sidebar-redup transition-colors duration-150 hover:bg-white/10 hover:text-sidebar-teks active:scale-95"
      >
        <Ikon nama="kiri" ukuran="h-3.5 w-3.5" /> Kembali
      </Link>
    </template>
  </LayarAbsen>
</template>
