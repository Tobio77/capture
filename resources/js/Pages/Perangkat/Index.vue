<script setup>
import { computed, reactive, ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Modal from '@/Components/Modal.vue'
import Ikon from '@/Components/Ikon.vue'
import TabelData from '@/Components/UI/TabelData.vue'
import KolomCari from '@/Components/UI/KolomCari.vue'
import Lencana from '@/Components/UI/Lencana.vue'
import KeadaanKosong from '@/Components/UI/KeadaanKosong.vue'
import TombolAksi from '@/Components/UI/TombolAksi.vue'
import Pilihan from '@/Components/UI/Pilihan.vue'

/**
 * Kelola perangkat absen (FR-USR-02, FR-USR-03).
 */

const props = defineProps({
  daftar: { type: Object, required: true },
  filter: { type: Object, required: true },
  unit_kerja: { type: Array, required: true },
})

const page = usePage()
const kodeAktivasi = computed(() => page.props.flash.kode_aktivasi ?? null)

/* ------------------------------------------------------------------ filter */

const filter = reactive({ ...props.filter })

const opsiUnit = computed(() => [
  { nilai: '', label: 'Semua unit dalam cakupan' },
  ...props.unit_kerja.map((u) => ({ nilai: u.id, label: u.nama, keterangan: u.kode })),
])

const opsiUnitForm = computed(() =>
  props.unit_kerja.map((u) => ({ nilai: u.id, label: u.nama, keterangan: u.kode })),
)

const kueri = computed(() =>
  Object.fromEntries(Object.entries(filter).filter(([, nilai]) => nilai !== '' && nilai !== null)),
)

const adaPenyaring = computed(() => Object.keys(kueri.value).length > 0)

function terapkan() {
  router.get('/admin/perangkat', kueri.value, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

function bersihkan() {
  filter.cari = ''
  filter.unit_kerja_id = ''
  filter.status = ''
  terapkan()
}

/* -------------------------------------------------------------------- form */

const modalTerbuka = ref(false)
const sedangDiubah = ref(null)
const riwayatTerbuka = ref(false)
const riwayat = ref(null)
const perangkatRiwayat = ref(null)

const form = useForm({ nama_titik: '', unit_kerja_id: '' })

const judulForm = computed(() =>
  sedangDiubah.value ? 'Ubah Perangkat Absen' : 'Daftarkan Perangkat Absen',
)

function bukaTambah() {
  sedangDiubah.value = null
  form.reset()
  form.clearErrors()
  modalTerbuka.value = true
}

function bukaUbah(perangkat) {
  sedangDiubah.value = perangkat
  form.clearErrors()
  form.nama_titik = perangkat.nama_titik
  form.unit_kerja_id = perangkat.unit_kerja?.id ?? ''
  modalTerbuka.value = true
}

function tutup() {
  modalTerbuka.value = false
  sedangDiubah.value = null
}

function simpan() {
  const opsi = { preserveScroll: true, onSuccess: () => tutup() }

  if (sedangDiubah.value) {
    form.patch(`/admin/perangkat/${sedangDiubah.value.id}`, opsi)
  } else {
    form.post('/admin/perangkat', opsi)
  }
}

function terbitkanKode(perangkat) {
  router.post(`/admin/perangkat/${perangkat.id}/kode`, {}, { preserveScroll: true })
}

function cabutToken(perangkat) {
  const pesan =
    `Cabut akses perangkat "${perangkat.nama_titik}"?\n\n` +
    'Perangkat langsung kehilangan akses dan harus diaktifkan ulang dengan kode baru.'

  if (!window.confirm(pesan)) return

  router.delete(`/admin/perangkat/${perangkat.id}/token`, { preserveScroll: true })
}

function ubahStatus(perangkat) {
  const aksi = perangkat.aktif ? 'menonaktifkan' : 'mengaktifkan'
  const peringatan = perangkat.aktif
    ? '\n\nAksesnya sekaligus dicabut, sehingga perangkat berhenti melayani tap.'
    : ''

  if (!window.confirm(`Yakin ${aksi} perangkat ${perangkat.nama_titik}?${peringatan}`)) return

  router.patch(
    `/admin/perangkat/${perangkat.id}/status`,
    { aktif: !perangkat.aktif },
    { preserveScroll: true },
  )
}

async function bukaRiwayat(perangkat) {
  perangkatRiwayat.value = perangkat
  riwayat.value = null
  riwayatTerbuka.value = true

  try {
    const jawaban = await fetch(`/admin/perangkat/${perangkat.id}/riwayat`, {
      headers: { Accept: 'application/json' },
    })

    riwayat.value = jawaban.ok ? (await jawaban.json()).riwayat : []
  } catch {
    riwayat.value = []
  }
}

const tersalin = ref(false)

function salin(teks) {
  navigator.clipboard?.writeText(teks)
  tersalin.value = true
  setTimeout(() => (tersalin.value = false), 2000)
}

function waktu(iso) {
  if (!iso) return '—'

  return new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
}

const pemasangan = (item) =>
  item.terpasang
    ? { warna: 'emerald', label: 'Terpasang' }
    : item.kode_aktivasi_berlaku
      ? { warna: 'amber', label: 'Menunggu aktivasi' }
      : { warna: 'slate', label: 'Belum diaktifkan' }
const kolom = [
  { label: 'Titik Absen' },
  { label: 'Unit Kerja' },
  { label: 'Pemasangan' },
  { label: 'Alamat IP', kelas: 'hidden 2xl:table-cell' },
  { label: 'Terakhir Aktif', kelas: 'hidden whitespace-nowrap lg:table-cell' },
  { label: 'Status' },
  { label: 'Aksi', kelas: 'text-right' },
]
</script>

<template>
  <AdminLayout
    judul="Perangkat Absen"
    deskripsi="Titik absen yang terdaftar beserta status aktivasi dan jejak koneksinya."
  >
    <template #aksi>
      <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded-md bg-aksen px-4 py-2 text-sm font-semibold text-white bayang transition hover:bg-aksen-kuat active:scale-95"
        @click="bukaTambah"
      >
        <Ikon nama="tambah" ukuran="h-4 w-4" /> Daftarkan Perangkat
      </button>
    </template>

    <!-- Kode aktivasi hanya tampil sekali, tepat setelah diterbitkan. -->
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="-translate-y-2 opacity-0"
      enter-to-class="translate-y-0 opacity-100"
    >
      <div
        v-if="kodeAktivasi"
        class="mb-5 rounded-lg border border-peringatan bg-peringatan-lembut px-5 py-4"
      >
        <p class="flex items-center gap-1.5 text-sm font-medium text-peringatan-teks">
          <Ikon nama="perangkat" ukuran="h-4 w-4" />
          Kode aktivasi — {{ kodeAktivasi.nama_titik }}
        </p>
        <p class="mt-1 text-xs text-peringatan-teks">
          Berlaku 24 jam. Masukkan kode ini pada layar aktivasi perangkat di lokasi.
        </p>
        <div class="mt-3 flex flex-wrap items-center gap-3">
          <code
            class="rounded bg-permukaan px-4 py-2 font-display text-lg font-semibold tracking-widest text-utama"
          >
            {{ kodeAktivasi.kode }}
          </code>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border border-peringatan px-3 py-1.5 text-xs font-medium text-peringatan-teks transition hover:bg-peringatan-lembut active:scale-95"
            @click="salin(kodeAktivasi.kode)"
          >
            <Ikon :nama="tersalin ? 'cek' : 'detail'" ukuran="h-3.5 w-3.5" />
            {{ tersalin ? 'Tersalin' : 'Salin' }}
          </button>
        </div>
      </div>
    </Transition>

    <div class="mb-4 panel p-3">
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <span class="sr-only"> Cari Perangkat </span>
          <KolomCari v-model="filter.cari" placeholder="Nama titik absen…" @cari="terapkan" />
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
          <label for="status" class="sr-only"> Status </label>
          <Pilihan
            id="status"
            v-model="filter.status"
            :opsi="[
              { nilai: '', label: 'Semua status' },
              { nilai: 'terpasang', label: 'Terpasang' },
              { nilai: 'belum', label: 'Belum diaktifkan' },
              { nilai: 'nonaktif', label: 'Nonaktif' },
            ]"
            @update:model-value="terapkan"
          />
        </div>
        <div class="flex items-end">
          <button
            v-if="adaPenyaring"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border border-garis px-3 py-2 text-sm font-medium text-sekunder transition hover:bg-permukaan-hover active:scale-95"
            @click="bersihkan"
          >
            <Ikon nama="tutup" ukuran="h-4 w-4" /> Bersihkan filter
          </button>
        </div>
      </div>
    </div>

    <TabelData
      :kolom="kolom"
      :baris="daftar.data"
      :paginator="daftar"
      kelas-gulir="tabel-aksi"
      :kelas-baris="(item) => !item.aktif && 'baris-redup bg-permukaan-2/60'"
    >
      <template #baris="{ isi: item }">
        <td class="max-w-[18rem] px-4 py-3">
          <div class="flex min-w-0 items-center gap-2">
            <span
              class="rounded-md p-1.5"
              :class="
                item.terpasang ? 'bg-berhasil-lembut text-berhasil' : 'bg-permukaan-2 text-redup'
              "
            >
              <Ikon nama="perangkat" ukuran="h-4 w-4" />
            </span>
            <!--
                Dipotong, bukan dibiarkan memanjang. Nama seperti "Perangkat
                Ad-hoc — Bidang Hubungan Industrial dan Jaminan Sosial"
                membuat kolom ini melebar sampai 600px dan mendorong seluruh
                kolom tengah ke luar layar.
              -->
            <span class="truncate font-medium text-utama" :title="item.nama_titik">
              {{ item.nama_titik }}
            </span>
            <Lencana
              v-if="item.sumber === 'ad_hoc'"
              warna="amber"
              :titik="false"
              title="Perangkat masuk sendiri selagi Mode Terbuka menyala, tanpa ditinjau admin"
            >
              {{ item.sumber_label }}
            </Lencana>
          </div>
        </td>
        <td class="max-w-[11rem] truncate px-4 py-3 text-sekunder" :title="item.unit_kerja?.nama">
          {{ item.unit_kerja?.nama ?? '—' }}
        </td>
        <td class="px-4 py-3">
          <Lencana :warna="pemasangan(item).warna" :titik="false">
            {{ pemasangan(item).label }}
          </Lencana>
        </td>
        <td class="hidden px-4 py-3 font-display tabular-nums text-sekunder 2xl:table-cell">
          {{ item.ip_terakhir ?? '—' }}
        </td>
        <td class="hidden whitespace-nowrap px-4 py-3 text-xs text-redup lg:table-cell">
          {{ waktu(item.login_terakhir_at) }}
        </td>
        <td class="px-4 py-3">
          <Lencana :warna="item.aktif ? 'emerald' : 'slate'">
            {{ item.aktif ? 'Aktif' : 'Nonaktif' }}
          </Lencana>
        </td>
        <td class="whitespace-nowrap px-4 py-3 text-right">
          <TombolAksi ringkas ikon="jam" warna="slate" @click="bukaRiwayat(item)"
            >Riwayat</TombolAksi
          >
          <TombolAksi ringkas ikon="ubah" warna="teal" @click="bukaUbah(item)">Ubah</TombolAksi>
          <TombolAksi
            ringkas
            v-if="!item.terpasang && item.aktif"
            ikon="kunci"
            warna="navy"
            @click="terbitkanKode(item)"
          >
            Kode Aktivasi
          </TombolAksi>
          <TombolAksi
            ringkas
            v-if="item.terpasang"
            ikon="cabut"
            warna="amber"
            @click="cabutToken(item)"
          >
            Cabut Akses
          </TombolAksi>
          <TombolAksi
            ringkas
            :ikon="item.aktif ? 'cabut' : 'cek'"
            :warna="item.aktif ? 'amber' : 'emerald'"
            @click="ubahStatus(item)"
          >
            {{ item.aktif ? 'Nonaktifkan' : 'Aktifkan' }}
          </TombolAksi>
        </td>
      </template>

      <template #kosong>
        <KeadaanKosong
          ikon="perangkat"
          :judul="adaPenyaring ? 'Tidak ada perangkat yang cocok' : 'Belum ada perangkat absen'"
          :keterangan="
            adaPenyaring
              ? 'Ubah kata kunci, unit kerja, atau status pada penyaring di atas.'
              : 'Daftarkan titik absen pertama melalui tombol di kanan atas.'
          "
        >
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border border-garis px-3 py-1.5 text-xs font-medium text-sekunder transition hover:bg-permukaan-hover"
            @click="bersihkan"
          >
            <Ikon nama="tutup" ukuran="h-3.5 w-3.5" /> Bersihkan filter
          </button>
        </KeadaanKosong>
      </template>
    </TabelData>

    <!-- Formulir -->
    <Modal :terbuka="modalTerbuka" :judul="judulForm" @tutup="tutup">
      <div class="space-y-4">
        <div>
          <label for="nama_titik" class="block text-sm font-medium text-utama">
            Nama Titik Absen
          </label>
          <input
            id="nama_titik"
            v-model="form.nama_titik"
            type="text"
            placeholder="mis. Aula Utama BLK Singosari"
            class="mt-1 block w-full rounded-md border-garis bayang transition focus:border-aksen focus:ring-aksen sm:text-sm"
          />
          <p v-if="form.errors.nama_titik" class="mt-1.5 text-xs text-peringatan-teks">
            {{ form.errors.nama_titik }}
          </p>
        </div>

        <div>
          <label for="unit_form" class="block text-sm font-medium text-utama">Unit Kerja</label>
          <Pilihan
            id="unit_form"
            v-model="form.unit_kerja_id"
            :opsi="opsiUnitForm"
            placeholder="Pilih unit kerja…"
            class="mt-1"
          />
          <p v-if="form.errors.unit_kerja_id" class="mt-1.5 text-xs text-peringatan-teks">
            {{ form.errors.unit_kerja_id }}
          </p>
        </div>

        <p
          v-if="!sedangDiubah"
          class="flex items-start gap-2 rounded-md bg-permukaan-2 px-3 py-2 text-xs text-sekunder"
        >
          <Ikon nama="info" ukuran="h-4 w-4 shrink-0" />
          Kode aktivasi diterbitkan otomatis setelah perangkat tersimpan, dan berlaku 24 jam.
        </p>
      </div>

      <template #aksi>
        <button
          type="button"
          class="rounded-lg px-4 py-2 text-sm font-medium text-sekunder transition hover:bg-permukaan-hover active:scale-95"
          @click="tutup"
        >
          Batal
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-lg bg-aksen px-4 py-2 text-sm font-medium text-white transition hover:bg-aksen-kuat active:scale-95 disabled:opacity-50"
          :disabled="form.processing"
          @click="simpan"
        >
          <Ikon v-if="form.processing" nama="segarkan" ukuran="h-4 w-4 animate-spin" />
          {{ form.processing ? 'Menyimpan…' : 'Simpan Perangkat' }}
        </button>
      </template>
    </Modal>

    <!-- FR-USR-03 -->
    <Modal
      :terbuka="riwayatTerbuka"
      :judul="`Riwayat — ${perangkatRiwayat?.nama_titik ?? ''}`"
      @tutup="riwayatTerbuka = false"
    >
      <p v-if="riwayat === null" class="flex items-center gap-2 text-sm text-redup">
        <Ikon nama="segarkan" ukuran="h-4 w-4 animate-spin" /> Memuat riwayat…
      </p>

      <ol v-else-if="riwayat.length > 0" class="relative space-y-4 border-l border-garis pl-5">
        <li v-for="baris in riwayat" :key="baris.id" class="relative text-sm">
          <span
            class="absolute -left-[1.55rem] top-1.5 h-2.5 w-2.5 rounded-full border-2 border-permukaan bg-aksen"
          ></span>
          <p class="font-medium text-utama">{{ baris.aksi }}</p>
          <p class="mt-0.5 text-xs text-sekunder">{{ baris.deskripsi }}</p>
          <p class="mt-1 text-xs text-redup">
            {{ waktu(baris.waktu) }}
            <span v-if="baris.ip"> · IP {{ baris.ip }}</span>
            <span v-if="baris.oleh"> · oleh {{ baris.oleh }}</span>
          </p>
        </li>
      </ol>

      <KeadaanKosong
        v-else
        ikon="jam"
        judul="Belum ada riwayat"
        keterangan="Jejak aktivasi dan koneksi perangkat ini akan muncul di sini."
      />

      <template #aksi>
        <button
          type="button"
          class="rounded-lg px-4 py-2 text-sm font-medium text-sekunder transition hover:bg-permukaan-hover active:scale-95"
          @click="riwayatTerbuka = false"
        >
          Tutup
        </button>
      </template>
    </Modal>
  </AdminLayout>
</template>
