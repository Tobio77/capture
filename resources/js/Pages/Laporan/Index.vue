<script setup>
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Ikon from '@/Components/Ikon.vue'
import TabelData from '@/Components/UI/TabelData.vue'
import KolomCari from '@/Components/UI/KolomCari.vue'
import Pilihan from '@/Components/UI/Pilihan.vue'
import PilihanKolom from '@/Components/UI/PilihanKolom.vue'
import RentangTanggal from '@/Components/UI/RentangTanggal.vue'
import RiwayatLaporan from '@/Components/Laporan/RiwayatLaporan.vue'
import { keQueryString } from '@/lib/kueri'

/**
 * Laporan kehadiran per pegawai (FR-LAP-01 s.d. FR-LAP-03) dan Generate
 * Laporan Resmi (FR-LAP-04, revisi antrian — lihat RiwayatLaporan.vue).
 */

const props = defineProps({
  baris: { type: Object, required: true },
  ringkasan: { type: Object, required: true },
  jumlah_event: { type: Number, required: true },
  unit_kerja: { type: Array, required: true },
  filter: { type: Object, required: true },
  riwayat: { type: Array, required: true },
})

const filter = reactive({ ...props.filter })

const opsiUnit = computed(() => [
  { nilai: '', label: 'Semua unit dalam cakupan' },
  ...props.unit_kerja.map((u) => ({ nilai: u.id, label: u.nama, keterangan: u.kode })),
])

const kueri = computed(() =>
  Object.fromEntries(Object.entries(filter).filter(([, n]) => n !== '' && n !== null)),
)

function terapkan() {
  router.get('/admin/laporan', kueri.value, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

/*
 * Checklist kolom — bagian dari "Unduh Data" (CSV/Excel), tidak menyentuh
 * Generate Laporan sama sekali: dokumen resminya punya bentuk tetap.
 * NIP dan Nama terkunci selalu ikut; mengunduh tabel tanpa satu pun cara
 * mengenali barisnya tidak ada gunanya.
 */
const KOLOM_LAPORAN = [
  { kunci: 'nip', label: 'NIP', terkunci: true },
  { kunci: 'nama', label: 'Nama', terkunci: true },
  { kunci: 'unit_kerja', label: 'Unit Kerja' },
  { kunci: 'event_berlaku', label: 'Event Berlaku' },
  { kunci: 'hadir', label: 'Hadir' },
  { kunci: 'terlambat', label: 'Terlambat' },
  { kunci: 'tanpa_keterangan', label: 'Tanpa Keterangan' },
]

const kolomTerpilih = ref(KOLOM_LAPORAN.map((k) => k.kunci))

function unduh(format) {
  const kueriUnduh = { ...kueri.value, format }

  // Checklist kolom hanya berarti bagi tabel mentah (CSV/Excel); PDF adalah
  // lembar cetak dengan kolom tetap.
  if (format !== 'pdf') {
    kueriUnduh.kolom = kolomTerpilih.value
  }

  window.location.href = '/admin/laporan/ekspor?' + keQueryString(kueriUnduh)
}

/**
 * Generate Laporan Resmi — jalur TERPISAH dari Unduh Data di atas, memakai
 * filter periode/unit yang sama tetapi tidak pernah menyertakan `cari`
 * (dokumen ini ringkasan per unit, bukan daftar pegawai yang disaring) atau
 * `kolom` (bentuknya tetap, bukan tabel untuk diolah lanjut).
 *
 * Sekarang mengantre, bukan langsung mengunduh: permintaannya dikirim lewat
 * POST dan dijawab seketika dengan baris Riwayat Laporan baru berstatus
 * antre — Inertia menyegarkan prop `riwayat` dari jawabannya sendiri, jadi
 * baris barunya langsung terlihat tanpa memuat ulang halaman.
 */
const memproses = ref(null)

function generate(format) {
  const { cari: _cari, ...kueriResmi } = kueri.value

  memproses.value = format
  router.post(
    '/admin/laporan/generate',
    { ...kueriResmi, format },
    { preserveScroll: true, preserveState: true, onFinish: () => (memproses.value = null) },
  )
}

/**
 * Pratinjau — membuka PDF apa adanya di tab baru, sebelum diproses/diantre.
 * Selalu PDF (lihat docblock LaporanController::preview()) apa pun format
 * yang akhirnya dipilih, dan tidak membuat baris Riwayat Laporan sama sekali.
 */
function preview() {
  const { cari: _cari, ...kueriResmi } = kueri.value

  window.open('/admin/laporan/preview?' + keQueryString(kueriResmi), '_blank')
}

function cetak() {
  window.print()
}

function tanggalPanjang(iso) {
  return new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}

const persenKehadiran = (isi) =>
  isi.event_berlaku === 0 ? '—' : `${Math.round((isi.hadir / isi.event_berlaku) * 100)}%`

const kartu = computed(() => [
  {
    label: 'Pegawai',
    nilai: props.ringkasan.pegawai,
    warna: 'text-utama',
    ikon: 'pegawai',
    latar: 'bg-info-lembut text-info-teks',
  },
  {
    label: 'Total Hadir',
    nilai: props.ringkasan.hadir,
    warna: 'text-berhasil-teks',
    ikon: 'cek',
    latar: 'bg-berhasil-lembut text-berhasil',
  },
  {
    label: 'Total Terlambat',
    nilai: props.ringkasan.terlambat,
    warna: 'text-peringatan-teks',
    ikon: 'jam',
    latar: 'bg-peringatan-lembut text-peringatan',
  },
  {
    label: 'Tanpa Keterangan',
    nilai: props.ringkasan.tanpa_keterangan,
    warna: 'text-sekunder',
    ikon: 'peringatan',
    latar: 'bg-permukaan-2 text-redup',
  },
])
const kolom = [
  { label: 'No' },
  { label: 'NIP' },
  { label: 'Nama' },
  { label: 'Unit Kerja' },
  { label: 'Event', kelas: 'text-right' },
  { label: 'Hadir', kelas: 'text-right' },
  { label: 'Terlambat', kelas: 'text-right' },
  { label: 'Tanpa Ket.', kelas: 'text-right' },
  { label: 'Capaian', kelas: 'text-right' },
]
</script>

<template>
  <AdminLayout
    judul="Laporan Kehadiran"
    deskripsi="Rekap per pegawai untuk rentang tanggal dan unit kerja yang dipilih."
  >
    <template #aksi>
      <div class="flex flex-wrap items-center gap-4 print:hidden">
        <!--
          UNDUH DATA — tabel mentah, cepat, tanpa narasi. Sengaja beda label
          dan gaya tombol dari "Generate Laporan" di sebelahnya: keduanya
          bukan varian dari fitur yang sama.
        -->
        <div class="flex flex-wrap items-center gap-2">
          <button type="button" class="tombol tombol-garis" @click="cetak">
            <Ikon nama="cetak" ukuran="h-4 w-4" /> Cetak
          </button>
          <PilihanKolom v-model="kolomTerpilih" :kolom="KOLOM_LAPORAN" />
          <button type="button" class="tombol tombol-garis" @click="unduh('csv')">
            <Ikon nama="unduh" ukuran="h-4 w-4" /> CSV
          </button>
          <button type="button" class="tombol tombol-garis" @click="unduh('xlsx')">
            <Ikon nama="unduh" ukuran="h-4 w-4" /> Excel
          </button>
        </div>

        <div class="h-6 w-px bg-garis" aria-hidden="true"></div>

        <!--
          GENERATE LAPORAN — dokumen resmi kop surat, tersedia PDF/Word/Excel.
          FR-LAP-04.
        -->
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-xs font-medium uppercase tracking-wider text-redup">
            Generate Laporan
          </span>
          <button type="button" class="tombol tombol-garis" @click="preview">
            <Ikon nama="detail" ukuran="h-4 w-4" /> Pratinjau
          </button>
          <button
            type="button"
            class="tombol tombol-garis"
            :disabled="memproses !== null"
            @click="generate('docx')"
          >
            <Ikon nama="unduh" ukuran="h-4 w-4" /> {{ memproses === 'docx' ? 'Mengantre…' : 'Word' }}
          </button>
          <button
            type="button"
            class="tombol tombol-garis"
            :disabled="memproses !== null"
            @click="generate('xlsx')"
          >
            <Ikon nama="unduh" ukuran="h-4 w-4" /> {{ memproses === 'xlsx' ? 'Mengantre…' : 'Excel' }}
          </button>
          <button
            type="button"
            class="tombol tombol-utama"
            :disabled="memproses !== null"
            @click="generate('pdf')"
          >
            <Ikon nama="unduh" ukuran="h-4 w-4" /> {{ memproses === 'pdf' ? 'Mengantre…' : 'PDF' }}
          </button>
        </div>
      </div>
    </template>

    <!-- FR-LAP-01 -->
    <div class="mb-5 panel p-4 print:hidden">
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="sm:col-span-2">
          <RentangTanggal
            label="Rentang Tanggal"
            v-model:dari="filter.dari"
            v-model:sampai="filter.sampai"
            @ubah="terapkan"
          />
        </div>
        <div>
          <label for="unit" class="sr-only"> Unit Kerja </label>
          <Pilihan
            id="unit"
            v-model="filter.unit_kerja_id"
            :opsi="opsiUnit"
            @update:model-value="terapkan"
          />
        </div>
        <div>
          <span class="sr-only"> Cari Pegawai </span>
          <KolomCari v-model="filter.cari" placeholder="Nama, NIP, atau unit…" @cari="terapkan" />
        </div>
      </div>
    </div>

    <!-- Kop laporan; ikut tercetak -->
    <div class="panel p-6 print:border-0 print:p-0 print:shadow-none">
      <h2 class="font-display text-lg font-semibold text-utama">Rekap Kehadiran Pegawai</h2>
      <p class="mt-1 text-sm text-sekunder">
        {{ tanggalPanjang(filter.dari) }} — {{ tanggalPanjang(filter.sampai) }} ·
        {{ jumlah_event }} event pada rentang ini
      </p>

      <dl class="mt-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div
          v-for="item in kartu"
          :key="item.label"
          class="rounded-md border border-garis px-4 py-3"
        >
          <div class="flex items-start justify-between gap-2">
            <div>
              <dt class="text-xs uppercase tracking-wider text-redup">{{ item.label }}</dt>
              <dd class="mt-1 font-display text-2xl font-semibold tabular-nums" :class="item.warna">
                {{ item.nilai }}
              </dd>
            </div>
            <span class="rounded-md p-1.5 print:hidden" :class="item.latar">
              <Ikon :nama="item.ikon" ukuran="h-4 w-4" />
            </span>
          </div>
        </div>
      </dl>
    </div>

    <!-- FR-LAP-02 -->
    <TabelData
      class="mt-6"
      :kolom="kolom"
      :baris="baris.data"
      :paginator="baris"
      kunci="pegawai_id"
      ikon-kosong="pegawai"
      judul-kosong="Tidak ada pegawai"
      keterangan-kosong="Tidak ada pegawai pada cakupan, rentang, dan pencarian ini."
    >
      <template #baris="{ isi, nomor }">
        <td class="px-4 py-2.5 font-display tabular-nums text-redup">{{ nomor }}</td>
        <td class="px-4 py-2.5 font-display tabular-nums text-sekunder">{{ isi.nip }}</td>
        <td class="whitespace-nowrap px-4 py-2.5 font-medium text-utama">{{ isi.nama }}</td>
        <td class="max-w-[14rem] truncate px-4 py-2.5 text-sekunder" :title="isi.unit_kerja">
          {{ isi.unit_kerja ?? '—' }}
        </td>
        <td class="px-4 py-2.5 text-right font-display tabular-nums text-redup">
          {{ isi.event_berlaku }}
        </td>
        <td class="px-4 py-2.5 text-right font-display tabular-nums text-berhasil-teks">
          {{ isi.hadir }}
        </td>
        <td
          class="px-4 py-2.5 text-right font-display tabular-nums"
          :class="isi.terlambat > 0 ? 'text-peringatan-teks' : 'text-redup'"
        >
          {{ isi.terlambat }}
        </td>
        <td
          class="px-4 py-2.5 text-right font-display tabular-nums"
          :class="isi.tanpa_keterangan > 0 ? 'text-utama' : 'text-redup'"
        >
          {{ isi.tanpa_keterangan }}
        </td>
        <td class="px-4 py-2.5">
          <div class="flex items-center justify-end gap-2">
            <div
              class="hidden h-1.5 w-16 overflow-hidden rounded-full bg-permukaan-2 sm:block print:hidden"
            >
              <div
                class="h-full rounded-full"
                :class="
                  isi.hadir / Math.max(isi.event_berlaku, 1) >= 0.75
                    ? 'bg-berhasil'
                    : 'bg-peringatan'
                "
                :style="{
                  width: `${Math.min(100, (isi.hadir / Math.max(isi.event_berlaku, 1)) * 100)}%`,
                }"
              ></div>
            </div>
            <span class="font-display tabular-nums text-sekunder">{{ persenKehadiran(isi) }}</span>
          </div>
        </td>
      </template>
    </TabelData>

    <RiwayatLaporan :riwayat="riwayat" />
  </AdminLayout>
</template>

<style>
@media print {
  @page {
    margin: 14mm;
    size: landscape;
  }

  body {
    background: #fff;
  }
}
</style>
