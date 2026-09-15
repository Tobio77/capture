<script setup>
import { computed, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import Ikon from '@/Components/Ikon.vue'
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

  /**
   * Kolom Tanggal — hanya berarti ketika `baris` menggabungkan LEBIH DARI
   * SATU hari sekaligus (Rekap Umum rentang tanggal, Bagian 4): pegawai
   * yang sama boleh muncul pada beberapa tanggal, dan tanpa kolom ini
   * baris-barisnya tidak terbedakan sama sekali.
   */
  tanggal: { type: Boolean, default: false },

  perHalaman: { type: Number, default: 25 },

  judulKosong: { type: String, default: 'Belum ada kehadiran' },
  keteranganKosong: {
    type: String,
    default: 'Baris bertambah otomatis setiap ada tap berhasil pada perangkat absen.',
  },
})

const emit = defineEmits(['dihapus'])

const halaman = ref(1)

// Tombol hapus per-baris (Bagian 2) hanya untuk superadmin — admin dinas/UPT
// tidak pernah melihatnya sama sekali, bukan sekadar dinonaktifkan.
const superadmin = computed(() => usePage().props.auth?.pengguna?.role === 'superadmin')

const kolom = computed(() => [
  { label: 'No' },
  ...(props.tanggal ? [{ label: 'Tanggal', kelas: 'whitespace-nowrap' }] : []),
  { label: 'NIP' },
  { label: 'Nama' },
  { label: 'Unit Kerja' },
  { label: 'Jam Masuk', kelas: 'whitespace-nowrap' },
  { label: 'Jam Pulang', kelas: 'whitespace-nowrap' },
  { label: 'Metode' },
  { label: 'Status' },
  ...(props.foto ? [{ label: 'Foto', cetak: false }] : []),
])

/*
 * Pegawai yang sama boleh muncul pada beberapa tanggal ketika rentangnya
 * lebih dari satu hari — `pegawai_id` saja tidak lagi unik dalam keadaan
 * itu, sehingga kuncinya ikut menyertakan tanggal.
 */
const kunciBaris = (isi) => (props.tanggal ? `${isi.pegawai_id}-${isi.tanggal}` : isi.pegawai_id)

/**
 * Hapus satu baris Absensi (Datang ATAU Pulang, bukan keduanya sekaligus —
 * keduanya baris terpisah di database walau tampil dalam satu baris tabel).
 * Dipakai untuk keperluan pengujian atau membetulkan tap yang keliru
 * tercatat; setiap penghapusan tercatat pada audit trail di sisi server.
 *
 * Baris `isi` yang dihapus diubah LANGSUNG (bukan menunggu halaman induk
 * menyegarkan diri) — objeknya sama persis dengan yang dipegang array
 * `baris` milik induk (props diteruskan lewat referensi, bukan disalin),
 * sehingga perubahan di sini langsung terlihat tanpa peduli apakah induk
 * sedang polling (tanggal hari ini) atau tidak (rekap tanggal lampau, yang
 * tidak pernah disegarkan otomatis). `emit('dihapus')` di atasnya hanya
 * untuk hal yang TIDAK dapat diketahui dari sini — kartu ringkasan (Total
 * Hadir, Tepat, Terlambat) milik induk, bukan tabel ini.
 */
function hapusAbsensi(isi, jenis) {
  const label = jenis === 'datang' ? 'Datang' : 'Pulang'
  const id = jenis === 'datang' ? isi.datang_id : isi.pulang_id

  if (!window.confirm(`Hapus absensi ${label} — ${isi.nama}? Tindakan ini tidak dapat dibatalkan.`)) {
    return
  }

  router.delete(`/admin/absensi/${id}`, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      if (jenis === 'datang') {
        isi.datang_id = null
        isi.jam_masuk = null
        isi.status_ketepatan = null
        isi.status_label = null
        isi.foto_url = null
      } else {
        isi.pulang_id = null
        isi.jam_pulang = null
      }

      emit('dihapus')
    },
  })
}

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
    :kunci="kunciBaris"
    ikon-kosong="pegawai"
    :judul-kosong="cari ? 'Tidak ada yang cocok' : judulKosong"
    :keterangan-kosong="cari ? 'Coba kata kunci lain, atau bersihkan pencarian.' : keteranganKosong"
  >
    <template #baris="{ isi, nomor }">
      <td class="px-4 py-2.5 font-display tabular-nums text-redup">{{ nomor }}</td>
      <td v-if="tanggal" class="whitespace-nowrap px-4 py-2.5 font-display tabular-nums text-sekunder">
        {{ isi.tanggal_label }}
      </td>
      <td class="px-4 py-2.5 font-display tabular-nums text-sekunder">{{ isi.nip }}</td>
      <td class="whitespace-nowrap px-4 py-2.5 font-medium text-utama">{{ isi.nama }}</td>
      <td class="max-w-[14rem] truncate px-4 py-2.5 text-sekunder" :title="isi.unit_kerja">
        {{ isi.unit_kerja ?? '—' }}
      </td>
      <td class="px-4 py-2.5">
        <div class="flex items-center gap-1.5">
          <span class="font-display tabular-nums text-utama">{{ isi.jam_masuk ?? '—' }}</span>
          <button
            v-if="superadmin && isi.datang_id"
            type="button"
            class="rounded p-0.5 text-redup transition hover:text-galat-teks print:hidden"
            title="Hapus absensi datang"
            @click="hapusAbsensi(isi, 'datang')"
          >
            <Ikon nama="hapus" ukuran="h-3.5 w-3.5" />
          </button>
        </div>
      </td>
      <td class="px-4 py-2.5">
        <div class="flex items-center gap-1.5">
          <span class="font-display tabular-nums text-utama">{{ isi.jam_pulang ?? '—' }}</span>
          <button
            v-if="superadmin && isi.pulang_id"
            type="button"
            class="rounded p-0.5 text-redup transition hover:text-galat-teks print:hidden"
            title="Hapus absensi pulang"
            @click="hapusAbsensi(isi, 'pulang')"
          >
            <Ikon nama="hapus" ukuran="h-3.5 w-3.5" />
          </button>
        </div>
      </td>
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
