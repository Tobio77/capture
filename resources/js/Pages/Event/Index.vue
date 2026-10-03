<script setup>
import { computed, reactive, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Modal from '@/Components/Modal.vue'
import Ikon from '@/Components/Ikon.vue'
import TabelData from '@/Components/UI/TabelData.vue'
import KolomCari from '@/Components/UI/KolomCari.vue'
import Lencana from '@/Components/UI/Lencana.vue'
import KeadaanKosong from '@/Components/UI/KeadaanKosong.vue'
import TombolAksi from '@/Components/UI/TombolAksi.vue'
import Pilihan from '@/Components/UI/Pilihan.vue'
import RentangTanggal from '@/Components/UI/RentangTanggal.vue'
import TanggalIsian from '@/Components/UI/TanggalIsian.vue'
import TombolProses from '@/Components/UI/TombolProses.vue'
import { hariIniIso } from '@/lib/tanggal'

/**
 * Daftar Event (FR-EVT-01 s.d. FR-EVT-05).
 */

const props = defineProps({
  daftar: { type: Object, required: true },
  filter: { type: Object, required: true },
  status_pilihan: { type: Array, required: true },
  nilai_awal: { type: Object, required: true },

  /*
   * Membuat, mengubah, menutup, dan menghapus event berdampak pada SELURUH
   * dinas sekaligus, sehingga hanya peran lintas unit yang boleh melakukannya
   * (FR-EVT-02). Admin UPT tetap membaca daftar dan detailnya — pegawainya
   * justru berhak hadir di setiap kegiatan yang ada di sana.
   */
  boleh_kelola: { type: Boolean, required: true },
})

const filter = reactive({ ...props.filter })

const formTerbuka = ref(false)
const sedangDiubah = ref(null)
const detailTerbuka = ref(false)
const detail = ref(null)
const detailGagal = ref(null)

const form = useForm({
  nama: '',
  tanggal: hariIniIso(),
  jam_mulai: '07:30',
  toleransi_menit: props.nilai_awal.toleransi_menit,
  catatan: '',
})

const judulForm = computed(() => (sedangDiubah.value ? 'Ubah Event' : 'Buat Event Baru'))

const adaFilter = computed(() => Object.values(filter).some((n) => n !== '' && n !== null))

const opsiStatus = computed(() => [
  { nilai: '', label: 'Semua status' },
  ...props.status_pilihan.map((s) => ({ nilai: s.nilai, label: s.label })),
])

const kueri = computed(() =>
  Object.fromEntries(Object.entries(filter).filter(([, n]) => n !== '' && n !== null)),
)

function terapkan() {
  router.get('/admin/kelola-absen/event', kueri.value, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

function bersihkanFilter() {
  Object.keys(filter).forEach((kunci) => {
    filter[kunci] = ''
  })
  terapkan()
}

function unduh(format) {
  window.location.href =
    '/admin/kelola-absen/event/ekspor?' + new URLSearchParams({ ...kueri.value, format }).toString()
}

function bukaBuat() {
  sedangDiubah.value = null
  form.reset()
  form.clearErrors()
  form.toleransi_menit = props.nilai_awal.toleransi_menit
  formTerbuka.value = true
}

function bukaUbah(event) {
  sedangDiubah.value = event
  form.clearErrors()
  form.nama = event.nama
  form.tanggal = event.tanggal
  form.jam_mulai = event.jam_mulai
  form.toleransi_menit = event.toleransi_menit
  form.catatan = event.catatan ?? ''
  formTerbuka.value = true
}

function tutupForm() {
  formTerbuka.value = false
  sedangDiubah.value = null
}

function simpan() {
  const opsi = { preserveScroll: true, onSuccess: () => tutupForm() }

  if (sedangDiubah.value) {
    form.patch(`/admin/kelola-absen/event/${sedangDiubah.value.id}`, opsi)
  } else {
    form.post('/admin/kelola-absen/event', opsi)
  }
}

function bukaDetail(event) {
  detailTerbuka.value = true

  return muatDetail(event.id)
}

async function muatDetail(id) {
  detail.value = null
  detailGagal.value = null

  try {
    const jawaban = await fetch(`/admin/kelola-absen/event/${id}/detail`, {
      headers: { Accept: 'application/json' },
    })

    if (!jawaban.ok) throw new Error()

    detail.value = await jawaban.json()
  } catch {
    detailGagal.value = 'Rincian event gagal dimuat.'
  }
}

function tutup(event) {
  const pesan =
    `Tutup entry event "${event.nama}"?\n\n` +
    'Tap baru pada perangkat absen untuk event ini akan ditolak, dan event tidak dapat dibuka kembali.'

  if (window.confirm(pesan)) {
    router.post(`/admin/kelola-absen/event/${event.id}/tutup`, {}, { preserveScroll: true })
  }
}

function hapus(event) {
  if (
    window.confirm(
      `Hapus event "${event.nama}" secara permanen? Tindakan ini tidak dapat dibatalkan.`,
    )
  ) {
    router.delete(`/admin/kelola-absen/event/${event.id}`, { preserveScroll: true })
  }
}

function tanggalPanjang(iso) {
  return new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

function waktuSingkat(iso) {
  if (!iso) return '—'

  return new Date(iso.replace(' ', 'T')).toLocaleString('id-ID', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}
const kolom = [
  { label: 'Nama Event' },
  { label: 'Jadwal' },
  { label: 'Masuk', kelas: 'text-right' },
  { label: 'Aksi', kelas: 'text-right' },
]
</script>

<template>
  <AdminLayout
    judul="Daftar Event"
    deskripsi="Setiap event berlaku bagi seluruh unit kerja Disnakertrans. Perangkat absen hanya melayani tap untuk event yang masih aktif, dan hanya satu event yang boleh aktif pada satu waktu."
  >
    <template #aksi>
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="tombol tombol-garis" @click="unduh('csv')">
          <Ikon nama="unduh" ukuran="h-4 w-4" /> CSV
        </button>
        <button type="button" class="tombol tombol-garis" @click="unduh('pdf')">
          <Ikon nama="cetak" ukuran="h-4 w-4" /> PDF
        </button>
        <button
          v-if="boleh_kelola"
          type="button"
          class="tombol tombol-utama"
          @click="bukaBuat"
        >
          <Ikon nama="tambah" ukuran="h-4 w-4" /> Buat Event
        </button>
      </div>
    </template>

    <div class="mb-4 panel p-3">
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="lg:col-span-2">
          <KolomCari
            v-model="filter.cari"
            placeholder="Cari nama event atau catatan…"
            @cari="terapkan"
          />
        </div>
        <Pilihan v-model="filter.status" :opsi="opsiStatus" @update:model-value="terapkan" />

        <RentangTanggal
          v-model:dari="filter.dari"
          v-model:sampai="filter.sampai"
          jajar="kanan"
          @ubah="terapkan"
        />
      </div>

      <button
        v-if="adaFilter"
        type="button"
        class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-redup transition hover:text-utama"
        @click="bersihkanFilter"
      >
        <Ikon nama="tutup" ukuran="h-3.5 w-3.5" /> Bersihkan penyaringan
      </button>
    </div>

    <TabelData :kolom="kolom" :baris="daftar.data" :paginator="daftar" kelas-gulir="tabel-aksi">
      <template #baris="{ isi: event }">
        <td class="px-4 py-3">
          <!--
              Nama dipotong bila perlu supaya lencana tetap sebaris. Ketika
              keduanya dibiarkan membungkus, baris dengan nama panjang
              menjadi lebih tinggi daripada tetangganya dan tabel kehilangan
              irama vertikalnya — nama utuhnya tetap terbaca pada Detail.
            -->
          <span class="flex items-center gap-2">
            <span class="min-w-0 truncate font-medium text-utama" :title="event.nama">
              {{ event.nama }}
            </span>

            <Lencana
              class="shrink-0"
              :warna="event.status === 'aktif' ? 'emerald' : 'slate'"
              :denyut="event.status === 'aktif'"
            >
              {{ event.status_label }}
            </Lencana>
          </span>

          <span v-if="event.catatan" class="mt-0.5 block max-w-xs truncate text-xs text-redup">
            {{ event.catatan }}
          </span>
        </td>
        <td class="whitespace-nowrap px-4 py-3 text-sekunder">
          <span class="flex items-center gap-1.5">
            <Ikon nama="kalender" ukuran="h-3.5 w-3.5" class="text-redup" />
            {{ tanggalPanjang(event.tanggal) }}
          </span>
          <span
            class="mt-0.5 flex items-center gap-1.5 font-display text-xs tabular-nums text-redup"
          >
            <Ikon nama="jam" ukuran="h-3.5 w-3.5" class="text-redup" />
            {{ event.jam_mulai }} · toleransi {{ event.toleransi_menit }} mnt
          </span>
        </td>
        <td class="whitespace-nowrap px-4 py-3 text-right">
          <span class="font-display font-medium tabular-nums text-berhasil-teks">
            {{ event.jumlah_absensi }}
          </span>
          <span class="mt-0.5 block font-display text-xs tabular-nums text-redup">
            {{ event.jumlah_kiosk }} perangkat
          </span>
        </td>
        <td class="whitespace-nowrap px-4 py-3 text-right">
          <TombolAksi ikon="detail" @click="bukaDetail(event)">Detail</TombolAksi>
          <TombolAksi
            v-if="boleh_kelola && event.status === 'aktif'"
            ikon="ubah"
            warna="teal"
            @click="bukaUbah(event)"
          >
            Ubah
          </TombolAksi>
          <TombolAksi
            v-if="boleh_kelola && event.status === 'aktif'"
            ikon="cek"
            warna="navy"
            @click="tutup(event)"
          >
            Tutup
          </TombolAksi>
          <TombolAksi
            v-if="boleh_kelola && event.dapat_dihapus"
            ikon="hapus"
            warna="rose"
            @click="hapus(event)"
          >
            Hapus
          </TombolAksi>
        </td>
      </template>

      <template #kosong>
        <KeadaanKosong
          ikon="absen"
          :judul="adaFilter ? 'Tidak ada event yang cocok' : 'Belum ada event'"
          :keterangan="
            adaFilter
              ? 'Coba longgarkan penyaringan, atau bersihkan seluruhnya.'
              : boleh_kelola
                ? 'Mulai dengan menekan “Buat Event” di kanan atas.'
                : 'Event dibuat oleh Admin Dinas dan berlaku bagi seluruh unit kerja.'
          "
        />
      </template>
    </TabelData>

    <Modal
      :terbuka="detailTerbuka"
      judul="Detail Event"
      lebar="max-w-3xl"
      @tutup="detailTerbuka = false"
    >
      <p
        v-if="detailGagal"
        class="rounded-md bg-peringatan-lembut px-3 py-2 text-sm text-peringatan-teks"
      >
        {{ detailGagal }}
      </p>

      <p v-else-if="!detail" class="flex items-center gap-2 text-sm text-redup">
        <span
          class="h-3 w-3 animate-spin rounded-full border-2 border-teal-600 border-t-transparent"
        ></span>
        Memuat rincian…
      </p>

      <div v-else class="space-y-5">
        <div class="rounded-lg bg-permukaan-2 px-4 py-3">
          <p class="font-medium text-utama">{{ detail.nama }}</p>
          <p class="mt-0.5 text-xs text-redup">
            {{ tanggalPanjang(detail.tanggal) }} · {{ detail.jam_mulai }} · Seluruh unit kerja
          </p>
        </div>

        <div class="grid grid-cols-3 gap-3 text-center">
          <div class="rounded-lg border border-garis px-3 py-3">
            <p class="font-display text-xl font-semibold tabular-nums text-utama">
              {{ detail.kiosk.length }}
            </p>
            <p class="mt-0.5 text-xs text-redup">Perangkat terhubung</p>
          </div>
          <div class="rounded-lg border border-garis px-3 py-3">
            <p class="font-display text-xl font-semibold tabular-nums text-berhasil-teks">
              {{ detail.jumlah_absensi }}
            </p>
            <p class="mt-0.5 text-xs text-redup">Absen masuk</p>
          </div>
          <div class="rounded-lg border border-garis px-3 py-3">
            <p
              class="font-display text-sm font-semibold"
              :class="detail.status === 'aktif' ? 'text-berhasil-teks' : 'text-redup'"
            >
              {{ detail.status_label }}
            </p>
            <p class="mt-0.5 text-xs text-redup">
              {{ detail.ditutup_pada ? waktuSingkat(detail.ditutup_pada) : 'Entry dibuka' }}
            </p>
          </div>
        </div>

        <!--
          Jawaban atas "komputer mana saja yang dipakai pada kegiatan ini, dari
          unit mana, dan dari alamat berapa". Jumlah perangkat per unit tidak
          dibatasi — sebuah UPT boleh memakai tiga mesin hari ini dan empat
          besok — sehingga daftar inilah satu-satunya tempat pertanyaan itu
          terjawab.
        -->
        <div>
          <p class="mb-2 text-xs font-medium uppercase tracking-wider text-redup">
            Perangkat Absen yang Melayani
          </p>

          <KeadaanKosong
            v-if="detail.kiosk.length === 0"
            ikon="perangkat"
            judul="Belum ada perangkat"
            keterangan="Perangkat tercatat di sini begitu membuka layar Absen Event."
          />

          <div v-else class="-mx-1 overflow-x-auto px-1">
            <table class="w-full min-w-[36rem] text-sm">
              <thead class="text-xs uppercase tracking-wider text-redup">
                <tr>
                  <th scope="col" class="whitespace-nowrap py-2 pr-4 text-left font-medium">Titik</th>
                  <th scope="col" class="whitespace-nowrap py-2 pr-4 text-left font-medium">Unit Kerja</th>
                  <th scope="col" class="whitespace-nowrap py-2 pr-4 text-left font-medium">Alamat IP</th>
                  <th scope="col" class="whitespace-nowrap py-2 text-right font-medium">Terakhir Aktif</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-garis">
                <tr v-for="kiosk in detail.kiosk" :key="kiosk.id">
                  <td class="py-2.5 pr-4 align-top text-utama">
                    {{ kiosk.nama_titik }}
                  </td>
                  <td class="py-2.5 pr-4 align-top text-sekunder">
                    {{ kiosk.unit_kerja_nama ?? '—' }}
                    <span
                      v-if="kiosk.unit_kerja_kode"
                      class="ml-1 font-display text-xs tabular-nums text-redup"
                    >
                      {{ kiosk.unit_kerja_kode }}
                    </span>
                  </td>
                  <td class="whitespace-nowrap py-2.5 pr-4 align-top font-display tabular-nums text-sekunder">
                    {{ kiosk.ip_address ?? '—' }}
                  </td>
                  <td class="whitespace-nowrap py-2.5 align-top text-right text-xs text-redup">
                    {{ waktuSingkat(kiosk.terakhir_aktif_pada) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <template #aksi>
        <button
          type="button"
          class="rounded-lg px-4 py-2 text-sm font-medium text-sekunder hover:bg-permukaan-hover"
          @click="detailTerbuka = false"
        >
          Tutup
        </button>
      </template>
    </Modal>

    <Modal :terbuka="formTerbuka" :judul="judulForm" lebar="max-w-2xl" @tutup="tutupForm">
      <div class="space-y-5">
        <div>
          <label for="nama" class="block text-sm font-medium text-utama">Nama Event</label>
          <input
            id="nama"
            v-model="form.nama"
            type="text"
            class="kolom-isian mt-1.5"
            placeholder="mis. Apel Pagi Senin"
          />
          <p v-if="form.errors.nama" class="mt-1 text-xs text-peringatan-teks">
            {{ form.errors.nama }}
          </p>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-[1.5fr_1fr_1fr]">
          <div>
            <label for="tanggal" class="block text-sm font-medium text-utama">Tanggal</label>
            <TanggalIsian
              id="tanggal"
              v-model="form.tanggal"
              class="mt-1.5"
              :bermasalah="Boolean(form.errors.tanggal)"
            />
            <p v-if="form.errors.tanggal" class="mt-1 text-xs text-peringatan-teks">
              {{ form.errors.tanggal }}
            </p>
          </div>
          <div>
            <label for="jam_mulai" class="block text-sm font-medium text-utama">Jam Mulai</label>
            <input id="jam_mulai" v-model="form.jam_mulai" type="time" class="kolom-isian mt-1.5" />
            <p v-if="form.errors.jam_mulai" class="mt-1 text-xs text-peringatan-teks">
              {{ form.errors.jam_mulai }}
            </p>
          </div>
          <div>
            <label for="toleransi" class="block text-sm font-medium text-utama">Toleransi</label>
            <div class="relative mt-1.5">
              <input
                id="toleransi"
                v-model.number="form.toleransi_menit"
                type="number"
                min="0"
                class="kolom-isian pr-16 font-display tabular-nums"
              />
              <span
                class="pointer-events-none absolute inset-y-0 right-3.5 flex items-center text-sm text-redup"
                >menit</span
              >
            </div>
            <p v-if="form.errors.toleransi_menit" class="mt-1 text-xs text-peringatan-teks">
              {{ form.errors.toleransi_menit }}
            </p>
          </div>
        </div>

        <!--
          Cakupan tidak lagi dipilih siapa pun: sejak S49 setiap event berlaku
          bagi seluruh dinas (FR-EVT-01). Yang tersisa adalah menyatakannya,
          supaya admin yang terbiasa mencentang unit tahu ke mana pilihan itu
          pergi — bukan mengira ia lupa mengisinya.
        -->
        <p class="flex items-start gap-2 rounded-md bg-info-lembut px-3 py-2.5 text-xs text-utama">
          <Ikon nama="info" ukuran="h-4 w-4" class="mt-px shrink-0" />
          <span>
            Event ini berlaku bagi
            <strong>seluruh unit kerja</strong> Disnakertrans — termasuk unit yang ditambahkan
            setelah event dibuat. Unit kerja hanya dipakai untuk menyaring dan mengelompokkan rekap
            serta laporannya.
          </span>
        </p>

        <div>
          <label for="catatan" class="block text-sm font-medium text-utama"
            >Catatan (opsional)</label
          >
          <textarea
            id="catatan"
            v-model="form.catatan"
            rows="3"
            class="kolom-isian mt-1.5"
          ></textarea>
          <p v-if="form.errors.catatan" class="mt-1 text-xs text-peringatan-teks">
            {{ form.errors.catatan }}
          </p>
        </div>
      </div>

      <template #aksi>
        <button
          type="button"
          class="rounded-lg px-4 py-2 text-sm font-medium text-sekunder hover:bg-permukaan-hover"
          @click="tutupForm"
        >
          Batal
        </button>
        <TombolProses tipe="button" :proses="form.processing" @click="simpan">
          Simpan Event
        </TombolProses>
      </template>
    </Modal>
  </AdminLayout>
</template>
