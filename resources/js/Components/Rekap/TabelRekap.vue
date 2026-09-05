<script setup>
import { computed, ref, watch } from 'vue'
import Lencana from '@/Components/UI/Lencana.vue'
import TabelData from '@/Components/UI/TabelData.vue'

/**
 * Tabel kehadiran, dipakai bersama oleh Rekap Event, Rekap Umum, dan halaman
 * Absen Umum.
 *
 * Ketiganya menampilkan baris yang bentuknya memang satu — keluaran
 * `AbsensiService::rekap()` — tetapi sebelumnya masing-masing menuliskan
 * tabelnya sendiri. Salinan yang berbeda tipis itulah yang membuat perbedaan
 * pengisian Jam Masuk/Jam Pulang antar-jalur sukar ditelusuri: memperbaiki
 * satu tempat tidak memperbaiki yang lain, dan tidak ada yang memberi tahu.
 *
 * Barisnya diterima sudah tersaring. Menyaring adalah urusan halaman —
 * satu bertanya ke server, satu menyaring di peramban — sementara bentuk
 * tabelnya tidak boleh berbeda.
 */

const props = defineProps({
  baris: { type: Array, required: true },

  /** Jumlah baris sebelum disaring, untuk keterangan "x dari y". */
  totalAsli: { type: Number, default: null },

  /** Kata kunci yang sedang berlaku, hanya untuk memilih kalimat kosongnya. */
  cari: { type: String, default: '' },

  /** Kolom foto hanya berguna di layar; rekap cetak dipakai sebagai lampiran. */
  foto: { type: Boolean, default: false },

  perHalaman: { type: Number, default: 25 },

  judulKosong: { type: String, default: 'Belum ada kehadiran' },
  keteranganKosong: {
    type: String,
    default: 'Baris bertambah otomatis setiap ada tap berhasil pada perangkat absen.',
  },
})

const halaman = ref(1)

const kolom = computed(() => [
  { label: 'No' },
  { label: 'NIP' },
  { label: 'Nama' },
  { label: 'Unit Kerja' },
  { label: 'Jam Masuk', kelas: 'whitespace-nowrap' },
  { label: 'Jam Pulang', kelas: 'whitespace-nowrap' },
  { label: 'Metode' },
  { label: 'Status' },
  ...(props.foto ? [{ label: 'Foto', cetak: false }] : []),
])

const barisTampil = computed(() =>
  props.baris.slice((halaman.value - 1) * props.perHalaman, halaman.value * props.perHalaman),
)

/*
 * Daftar ini menyegarkan dirinya sendiri selama sesi berjalan, dan kata
 * kuncinya dapat berubah kapan saja. Halaman tujuh dari daftar yang menyusut
 * jadi dua baris tampil kosong tanpa sebab yang terlihat.
 */
watch(
  () => [props.cari, props.baris.length],
  () => {
    if ((halaman.value - 1) * props.perHalaman >= props.baris.length) halaman.value = 1
  },
)
</script>

<template>
  <TabelData
    v-model:halaman="halaman"
    :kolom="kolom"
    :baris="barisTampil"
    :total="baris.length"
    :total-asli="totalAsli"
    :per-halaman="perHalaman"
    kunci="pegawai_id"
    ikon-kosong="pegawai"
    :judul-kosong="cari ? 'Tidak ada yang cocok' : judulKosong"
    :keterangan-kosong="cari ? 'Coba kata kunci lain, atau bersihkan pencarian.' : keteranganKosong"
  >
    <template #baris="{ isi, nomor }">
      <td class="px-4 py-2.5 font-display tabular-nums text-redup">{{ nomor }}</td>
      <td class="px-4 py-2.5 font-display tabular-nums text-sekunder">{{ isi.nip }}</td>
      <td class="whitespace-nowrap px-4 py-2.5 font-medium text-utama">{{ isi.nama }}</td>
      <td class="max-w-[14rem] truncate px-4 py-2.5 text-sekunder" :title="isi.unit_kerja">
        {{ isi.unit_kerja ?? '—' }}
      </td>
      <td class="px-4 py-2.5 font-display tabular-nums text-utama">{{ isi.jam_masuk ?? '—' }}</td>
      <td class="px-4 py-2.5 font-display tabular-nums text-utama">{{ isi.jam_pulang ?? '—' }}</td>
      <td class="px-4 py-2.5 text-sekunder">{{ isi.metode }}</td>
      <td class="px-4 py-2.5">
        <Lencana
          v-if="isi.status_ketepatan"
          :warna="isi.status_ketepatan === 'terlambat' ? 'amber' : 'emerald'"
        >
          {{ isi.status_label }}
        </Lencana>
        <span v-else class="text-xs text-redup">—</span>
      </td>
      <td v-if="foto" class="px-4 py-2.5 print:hidden">
        <img
          v-if="isi.foto_url"
          :src="isi.foto_url"
          :alt="`Foto absen ${isi.nama}`"
          class="h-9 w-9 rounded object-cover ring-1 ring-[var(--tema-garis)]"
        />
        <span v-else class="text-xs text-redup">—</span>
      </td>
    </template>
  </TabelData>
</template>
