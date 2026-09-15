<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import Ikon from '@/Components/Ikon.vue'
import Lencana from '@/Components/UI/Lencana.vue'
import KeadaanKosong from '@/Components/UI/KeadaanKosong.vue'

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

function hapus(item) {
  if (!window.confirm(`Hapus riwayat backup "${item.nama_berkas}"?`)) {
    return
  }

  router.delete(`/admin/setting/maintenance/backup/${item.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      daftar.value = daftar.value.filter((r) => r.id !== item.id)
    },
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
