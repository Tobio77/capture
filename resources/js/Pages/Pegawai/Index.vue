<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import PendaftaranWajah from '@/Components/PendaftaranWajah.vue'
import PendaftaranKartu from '@/Components/PendaftaranKartu.vue'
import Ikon from '@/Components/Ikon.vue'
import TabelData from '@/Components/UI/TabelData.vue'
import KolomCari from '@/Components/UI/KolomCari.vue'
import Lencana from '@/Components/UI/Lencana.vue'
import KeadaanKosong from '@/Components/UI/KeadaanKosong.vue'
import TombolAksi from '@/Components/UI/TombolAksi.vue'
import Pilihan from '@/Components/UI/Pilihan.vue'

const props = defineProps({
  pegawai: { type: Object, required: true },
  unit_kerja: { type: Array, required: true },
  filter: { type: Object, required: true },
  dapat_sinkron: { type: Boolean, required: true },
  status_sinkron: { type: Object, required: true },
})

const filter = reactive({ ...props.filter })

const opsiUnit = computed(() => [
  { nilai: '', label: 'Semua unit kerja' },
  ...props.unit_kerja.map((u) => ({ nilai: String(u.id), label: u.nama, keterangan: u.kode })),
])
const wajahDikelola = ref(null) // pegawai yang sedang didaftarkan wajahnya
const kartuDikelola = ref(null) // pegawai yang sedang didaftarkan kartunya
const sedangSinkron = ref(false)
const koneksi = ref(null) // null = belum diperiksa

const kueri = computed(() =>
  Object.fromEntries(Object.entries(filter).filter(([, nilai]) => nilai !== '' && nilai !== null)),
)

const adaPenyaring = computed(() => Object.keys(kueri.value).length > 0)

const terapkanFilter = () => {
  router.get('/admin/pegawai', kueri.value, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

function bersihkanFilter() {
  filter.cari = ''
  filter.unit_kerja_id = ''
  filter.status_foto = ''
  filter.status = ''
  terapkanFilter()
}

const sinkron = (penuh) => {
  const konfirmasi = penuh
    ? 'Sinkronisasi penuh menarik seluruh pegawai dari WORKA dan menonaktifkan pegawai yang sudah tidak ada di sana. Lanjutkan?'
    : null

  if (konfirmasi && !window.confirm(konfirmasi)) return

  sedangSinkron.value = true
  router.post(
    '/admin/pegawai/sinkron',
    { penuh },
    {
      preserveScroll: true,
      onFinish: () => {
        sedangSinkron.value = false
        periksaKoneksi()
      },
    },
  )
}

const periksaKoneksi = async () => {
  try {
    const jawaban = await fetch('/admin/pegawai/status', {
      headers: { Accept: 'application/json' },
    })
    const isi = await jawaban.json()
    koneksi.value = isi.terkonfigurasi ? isi.terhubung : 'belum'
  } catch {
    koneksi.value = false
  }
}

onMounted(periksaKoneksi)

const statusKoneksi = computed(() => {
  if (!props.status_sinkron.terkonfigurasi) {
    return { label: 'Belum dikonfigurasi', warna: 'slate', denyut: false }
  }
  if (koneksi.value === null) {
    return { label: 'Memeriksa koneksi…', warna: 'slate', denyut: true }
  }
  if (koneksi.value === true) {
    return { label: 'Terhubung ke WORKA', warna: 'emerald', denyut: false }
  }
  return { label: 'Tidak terhubung ke WORKA', warna: 'amber', denyut: false }
})

const waktu = (iso) =>
  iso
    ? new Date(iso).toLocaleString('id-ID', { dateStyle: 'long', timeStyle: 'short' })
    : 'Belum pernah'

const tanggalSingkat = (iso) =>
  iso
    ? new Date(iso).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
    : '—'
const kolom = [
  { label: 'NIP' },
  { label: 'Nama' },
  { label: 'Unit Kerja' },
  { label: 'Jabatan', kelas: 'hidden 2xl:table-cell' },
  { label: 'Foto Wajah' },
  { label: 'Kartu' },
  { label: 'Sinkron', kelas: 'hidden whitespace-nowrap 2xl:table-cell' },
  { label: 'Aksi', kelas: 'text-right' },
]
</script>

<template>
  <AdminLayout
    judul="Kelola Pegawai"
    deskripsi="Data pegawai hasil sinkronisasi dari WORKA. Perubahan data induk dilakukan di WORKA, bukan di sini."
  >
    <!--
      Status sinkronisasi sebagai SATU BARIS, bukan kartu setinggi 210px.
      Panel lamanya mendorong tabel pegawai sampai ke luar lipatan pada laptop
      1366×768 — hanya dua baris yang terlihat — padahal yang dibuka orang di
      halaman ini adalah daftar pegawainya, bukan status integrasinya. Angkanya
      tetap lengkap; yang hilang hanya ruang kosong di antaranya.
    -->
    <div class="panel flex flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3">
      <div class="flex min-w-0 items-center gap-2.5">
        <span class="ubin-ikon h-8 w-8 shrink-0">
          <Ikon nama="segarkan" ukuran="h-4 w-4" :class="sedangSinkron && 'animate-spin'" />
        </span>

        <div class="min-w-0 leading-tight">
          <p class="truncate text-sm font-medium text-utama">Sinkronisasi WORKA</p>
          <p class="truncate text-xs text-redup">
            Terakhir {{ waktu(status_sinkron.sinkron_terakhir_at) }}
          </p>
        </div>
      </div>

      <dl class="flex flex-wrap items-center gap-x-5 gap-y-1 text-sm">
        <div class="flex items-baseline gap-1.5">
          <dd class="font-display font-semibold tabular-nums text-utama">
            {{ status_sinkron.total_pegawai_lokal }}
          </dd>
          <dt class="text-xs text-redup">tersimpan</dt>
        </div>

        <div class="flex items-baseline gap-1.5">
          <dd class="font-display font-semibold tabular-nums text-utama">
            {{ status_sinkron.total_pegawai_worka || '—' }}
          </dd>
          <dt class="text-xs text-redup">di WORKA</dt>
        </div>

        <Lencana :warna="statusKoneksi.warna" :denyut="statusKoneksi.denyut">
          {{ statusKoneksi.label }}
        </Lencana>
      </dl>

      <div v-if="dapat_sinkron" class="ml-auto flex flex-wrap gap-2">
        <button
          type="button"
          :disabled="sedangSinkron"
          class="tombol tombol-utama px-3 py-2 text-xs"
          @click="sinkron(false)"
        >
          <Ikon nama="segarkan" ukuran="h-3.5 w-3.5" :class="sedangSinkron && 'animate-spin'" />
          {{ sedangSinkron ? 'Menyinkronkan…' : 'Inkremental' }}
        </button>

        <button
          type="button"
          :disabled="sedangSinkron"
          class="tombol tombol-garis px-3 py-2 text-xs"
          @click="sinkron(true)"
        >
          <Ikon nama="unduh" ukuran="h-3.5 w-3.5" /> Penuh
        </button>

        <Link href="/admin/setting/worka" class="tautan-aksi tombol tombol-garis px-3 py-2 text-xs">
          <Ikon nama="filter" ukuran="h-3.5 w-3.5" /> Setting
        </Link>
      </div>
    </div>

    <!-- Filter -->
    <div class="mt-4 panel p-3">
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div class="lg:col-span-2">
          <span class="sr-only"> Cari Pegawai </span>
          <KolomCari v-model="filter.cari" placeholder="Nama atau NIP…" @cari="terapkanFilter" />
        </div>
        <div>
          <label for="unit" class="sr-only"> Unit Kerja </label>
          <Pilihan
            id="unit"
            v-model="filter.unit_kerja_id"
            :opsi="opsiUnit"
            @update:model-value="terapkanFilter"
          />
        </div>
        <div>
          <label for="status_foto" class="sr-only"> Foto Wajah </label>
          <Pilihan
            id="status_foto"
            v-model="filter.status_foto"
            :opsi="[
              { nilai: '', label: 'Semua status foto' },
              { nilai: 'terdaftar', label: 'Foto terdaftar' },
              { nilai: 'belum', label: 'Foto belum ada' },
            ]"
            @update:model-value="terapkanFilter"
          />
        </div>
        <div>
          <label for="status" class="sr-only"> Status </label>
          <Pilihan
            id="status"
            v-model="filter.status"
            :opsi="[
              { nilai: '', label: 'Aktif dan nonaktif' },
              { nilai: 'aktif', label: 'Hanya aktif' },
              { nilai: 'nonaktif', label: 'Hanya nonaktif' },
            ]"
            @update:model-value="terapkanFilter"
          />
        </div>
      </div>

      <div v-if="adaPenyaring" class="mt-3 flex justify-end">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-md border border-garis px-3 py-1.5 text-xs font-medium text-sekunder transition hover:bg-permukaan-hover active:scale-95"
          @click="bersihkanFilter"
        >
          <Ikon nama="tutup" ukuran="h-3.5 w-3.5" /> Bersihkan filter
        </button>
      </div>
    </div>

    <TabelData
      class="mt-4"
      :kolom="kolom"
      :baris="pegawai.data"
      :paginator="pegawai"
      kelas-gulir="tabel-aksi"
      :kelas-baris="(orang) => !orang.aktif && 'baris-redup bg-permukaan-2/60'"
    >
      <template #baris="{ isi: orang }">
        <td class="whitespace-nowrap px-4 py-3 font-display text-xs tabular-nums text-sekunder">
          {{ orang.nip }}
        </td>
        <!--
          Status pindah ke sini dan hanya muncul ketika NONAKTIF. Kolom Status
          sebelumnya terdorong ke balik kolom Aksi yang lengket pada 1366px,
          sehingga justru tidak terbaca — dan menuliskan "Aktif" pada ratusan
          baris yang memang aktif hanya menambah kolom tanpa menambah
          keterangan. Yang perlu terlihat adalah pengecualiannya.
        -->
        <td class="whitespace-nowrap px-4 py-3 font-medium text-utama">
          {{ orang.nama }}
          <Lencana v-if="!orang.aktif" warna="slate" class="ml-1.5">Nonaktif</Lencana>
        </td>
        <td class="max-w-[11rem] truncate px-4 py-3 text-sekunder" :title="orang.unit_kerja?.nama">
          {{ orang.unit_kerja?.nama ?? '—' }}
        </td>
        <td
          class="hidden max-w-[11rem] truncate px-4 py-3 text-sekunder 2xl:table-cell"
          :title="orang.jabatan"
        >
          {{ orang.jabatan ?? '—' }}
        </td>
        <!--
          Lencana hanya untuk yang SUDAH terdaftar. Dari 666 pegawai baru
          segelintir yang punya foto referensi, sehingga menandai yang belum
          berarti menaburkan ratusan lencana amber di sepanjang tabel — dan
          yang sebenarnya perlu ditemukan justru yang sudah.
        -->
        <td class="px-4 py-3">
          <Lencana v-if="orang.wajah_terdaftar" warna="emerald">Terdaftar</Lencana>
          <span v-else class="text-xs text-redup">Belum ada</span>
        </td>
        <td class="px-4 py-3">
          <span
            v-if="orang.uid_kartu"
            class="inline-flex items-center gap-1.5 font-display text-xs tabular-nums text-sekunder"
            title="UID kartu terdaftar"
          >
            <Ikon nama="kartu" ukuran="h-3.5 w-3.5 text-redup" />
            {{ orang.uid_kartu }}
          </span>
          <span v-else class="text-xs text-redup">Belum ada</span>
        </td>
        <td class="hidden whitespace-nowrap px-4 py-3 text-xs text-redup 2xl:table-cell">
          {{ tanggalSingkat(orang.sumber_sinkron_terakhir) }}
        </td>
        <td class="whitespace-nowrap px-4 py-3 text-right">
          <TombolAksi
            ikon="wajah"
            warna="teal"
            :title="orang.wajah_terdaftar ? 'Perbarui foto wajah' : 'Daftarkan foto wajah'"
            @click="wajahDikelola = orang"
          >
            Wajah
          </TombolAksi>
          <TombolAksi
            ikon="kartu"
            warna="navy"
            :title="orang.uid_kartu ? 'Ganti kartu RFID' : 'Daftarkan kartu RFID'"
            @click="kartuDikelola = orang"
          >
            Kartu
          </TombolAksi>
        </td>
      </template>

      <template #kosong>
        <KeadaanKosong
          ikon="pegawai"
          :judul="
            status_sinkron.total_pegawai_lokal === 0
              ? 'Data pegawai belum ditarik'
              : 'Tidak ada pegawai yang cocok'
          "
          :keterangan="
            status_sinkron.total_pegawai_lokal === 0
              ? 'Jalankan sinkronisasi dari WORKA untuk menarik data pegawai.'
              : 'Ubah kata kunci, unit kerja, atau status pada penyaring di atas.'
          "
        >
          <button
            v-if="adaPenyaring && status_sinkron.total_pegawai_lokal > 0"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border border-garis px-3 py-1.5 text-xs font-medium text-sekunder transition hover:bg-permukaan-hover"
            @click="bersihkanFilter"
          >
            <Ikon nama="tutup" ukuran="h-3.5 w-3.5" /> Bersihkan filter
          </button>
        </KeadaanKosong>
      </template>
    </TabelData>

    <PendaftaranWajah :pegawai="wajahDikelola" @tutup="wajahDikelola = null" />
    <PendaftaranKartu :pegawai="kartuDikelola" @tutup="kartuDikelola = null" />
  </AdminLayout>
</template>
