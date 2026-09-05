<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Ikon from '@/Components/Ikon.vue'
import KolomCari from '@/Components/UI/KolomCari.vue'
import Pilihan from '@/Components/UI/Pilihan.vue'
import Tanggal from '@/Components/UI/Tanggal.vue'
import Lencana from '@/Components/UI/Lencana.vue'
import KeadaanKosong from '@/Components/UI/KeadaanKosong.vue'
import RingkasanRekap from '@/Components/Rekap/RingkasanRekap.vue'
import TabelRekap from '@/Components/Rekap/TabelRekap.vue'

/**
 * Rekap Absen (FR-REK-01 s.d. FR-REK-03), dua tab.
 *
 * "Rekap Event" merekap satu kegiatan dan menyegarkan dirinya selama entry
 * masih dibuka. "Rekap Umum" merekap kehadiran harian per unit kerja dan
 * tanggal — pekerjaan yang sudah dilakukan halaman Absen Umum, dan karena itu
 * memakai sumber baris serta komponen tabel yang sama, bukan salinannya.
 *
 * Bedanya letak pekerjaan menyaring, dan itu memang mengikuti letak datanya:
 * satu kegiatan sudah utuh di layar dan disegarkan berkala, jadi pencariannya
 * di peramban; rekap harian dibaca ulang per tanggal, jadi pencariannya ikut
 * ke server bersama tanggal dan unitnya.
 */

const props = defineProps({
  tab: { type: String, default: 'event' },

  // Tab kegiatan
  daftar_event: { type: Array, default: () => [] },
  event: { type: Object, default: null },
  rekap: { type: Array, default: () => [] },

  ringkasan: { type: Object, required: true },

  /** Tab harian: `{ unit_kerja, filter, sesi, baris }`; null pada tab event. */
  umum: { type: Object, default: null },
})

const page = usePage()
const pengguna = computed(() => page.props.auth.pengguna)

const ALAMAT = '/admin/kelola-absen/rekap'
const JEDA_SEGAR_MS = 15000

const adalahUmum = computed(() => props.tab === 'umum')

const tab = [
  { nilai: 'event', label: 'Rekap Event', ikon: 'absen' },
  { nilai: 'umum', label: 'Rekap Umum', ikon: 'kalender' },
]

/* -----------------------------------------------------------------------
 * Tab kegiatan
 * --------------------------------------------------------------------- */

const barisEvent = ref(props.rekap)
const angka = ref(props.ringkasan)
const statusEvent = ref(props.event?.status ?? null)
const eventTerpilih = ref(props.event?.id ?? '')
const cariEvent = ref('')

const masihDibuka = computed(() => statusEvent.value === 'aktif')

const opsiEvent = computed(() =>
  props.daftar_event.map((item) => ({
    nilai: item.id,
    label: item.nama,
    keterangan: `${item.tanggal} · ${item.status_label}`,
  })),
)

/*
 * Pencarian tab kegiatan dilakukan di peramban: satu event paling banyak
 * berisi ratusan baris yang sudah ada di layar, sehingga menyaringnya tidak
 * perlu perjalanan ke server dan tetap terasa seketika.
 */
const barisTampilEvent = computed(() => {
  const kunci = cariEvent.value.trim().toLowerCase()

  if (kunci === '') return barisEvent.value

  return barisEvent.value.filter(
    (b) =>
      b.nama.toLowerCase().includes(kunci) ||
      b.nip.includes(kunci) ||
      (b.unit_kerja ?? '').toLowerCase().includes(kunci),
  )
})

watch(
  () => props.rekap,
  (nilai) => {
    barisEvent.value = nilai
    angka.value = props.ringkasan
    statusEvent.value = props.event?.status ?? null
    eventTerpilih.value = props.event?.id ?? ''
  },
)

function pilihEvent() {
  router.get(
    ALAMAT,
    { event_absen_id: eventTerpilih.value },
    { preserveState: true, preserveScroll: true, replace: true },
  )
}

/* -----------------------------------------------------------------------
 * Tab harian
 * --------------------------------------------------------------------- */

const filter = reactive({ ...(props.umum?.filter ?? {}) })
const barisUmum = ref(props.umum?.baris ?? [])

const opsiUnit = computed(() =>
  (props.umum?.unit_kerja ?? []).map((u) => ({ nilai: u.id, label: u.nama, keterangan: u.kode })),
)

const kueriUmum = computed(() => ({
  tab: 'umum',
  ...Object.fromEntries(
    Object.entries(filter).filter(([, nilai]) => nilai !== '' && nilai !== null),
  ),
}))

function terapkanUmum() {
  router.get(ALAMAT, kueriUmum.value, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onSuccess: (halaman) => {
      barisUmum.value = halaman.props.umum.baris
      angka.value = halaman.props.ringkasan
    },
  })
}

watch(
  () => props.umum,
  (nilai) => {
    if (!nilai) return

    barisUmum.value = nilai.baris
    angka.value = props.ringkasan
  },
)

/* -----------------------------------------------------------------------
 * Bersama
 * --------------------------------------------------------------------- */

let jedaSegar = null

/*
 * Hanya rekap yang masih bergerak yang perlu disegarkan: event yang entry-nya
 * dibuka, dan sesi harian hari ini. Rekap kemarin tidak akan berubah lagi.
 */
const hariIni = computed(() => filter.tanggal === new Date().toISOString().slice(0, 10))

onMounted(() => {
  jedaSegar = setInterval(segarkan, JEDA_SEGAR_MS)
})

onBeforeUnmount(() => clearInterval(jedaSegar))

async function segarkan() {
  const alamat = adalahUmum.value
    ? props.umum?.sesi && hariIni.value
      ? '/admin/kelola-absen/absen-umum/data?' + new URLSearchParams(kueriUmum.value).toString()
      : null
    : props.event && masihDibuka.value
      ? `${ALAMAT}/${props.event.id}/data`
      : null

  if (alamat === null) return

  try {
    const jawaban = await fetch(alamat, { headers: { Accept: 'application/json' } })

    if (!jawaban.ok) return

    const isi = await jawaban.json()

    if (adalahUmum.value) {
      barisUmum.value = isi.baris
    } else {
      barisEvent.value = isi.rekap
      statusEvent.value = isi.status
    }

    angka.value = isi.ringkasan
  } catch {
    // Gangguan sesaat; percobaan berikutnya menyusul sendiri.
  }
}

/*
 * Ekspor tab harian memanggil endpoint Absen Umum apa adanya — berkasnya
 * memang berkas yang sama, dan menyalinnya ke sini hanya akan melahirkan
 * lampiran kedua yang berbeda tipis.
 */
function unduh(format) {
  if (adalahUmum.value) {
    if (!props.umum?.sesi) return

    const kueri = { ...kueriUmum.value, format }

    delete kueri.tab
    window.location.href =
      '/admin/kelola-absen/absen-umum/ekspor?' + new URLSearchParams(kueri).toString()

    return
  }

  if (!props.event) return

  window.location.href = `${ALAMAT}/${props.event.id}/ekspor?format=${format}`
}

const dapatDiunduh = computed(() =>
  adalahUmum.value ? Boolean(props.umum?.sesi) : Boolean(props.event),
)

const cetak = () => window.print()

function tanggalPanjang(iso) {
  if (!iso) return '—'

  return new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}

const kartu = computed(() => [
  {
    label: 'Hadir',
    nilai: angka.value.hadir,
    warna: 'text-utama',
    ikon: 'pegawai',
    latar: 'bg-info-lembut text-info-teks',
  },
  {
    label: 'Tepat Waktu',
    nilai: angka.value.tepat,
    warna: 'text-berhasil-teks',
    ikon: 'cek',
    latar: 'bg-berhasil-lembut text-berhasil',
  },
  {
    label: 'Terlambat',
    nilai: angka.value.terlambat,
    warna: 'text-peringatan-teks',
    ikon: 'jam',
    latar: 'bg-peringatan-lembut text-peringatan',
  },
  adalahUmum.value
    ? {
        label: 'Belum Absen',
        nilai: angka.value.belum_absen ?? 0,
        warna: 'text-sekunder',
        ikon: 'kosong',
        latar: 'bg-permukaan-2 text-redup',
      }
    : {
        label: 'Sudah Pulang',
        nilai: angka.value.sudah_pulang,
        warna: 'text-sekunder',
        ikon: 'keluar',
        latar: 'bg-permukaan-2 text-redup',
      },
])
</script>

<template>
  <AdminLayout
    judul="Rekap Absen"
    :deskripsi="
      adalahUmum
        ? 'Kehadiran harian per unit kerja. Rekap hari ini diperbarui sendiri selama sesinya berjalan.'
        : 'Daftar e-presensi per event. Tabel diperbarui sendiri selama event masih dibuka.'
    "
  >
    <template #aksi>
      <div class="flex flex-wrap items-center gap-2 print:hidden">
        <button type="button" class="tombol tombol-garis" @click="cetak">
          <Ikon nama="cetak" ukuran="h-4 w-4" /> Cetak
        </button>
        <button
          type="button"
          :disabled="!dapatDiunduh"
          class="tombol tombol-garis disabled:opacity-50"
          @click="unduh('csv')"
        >
          <Ikon nama="unduh" ukuran="h-4 w-4" /> CSV
        </button>
        <button
          type="button"
          :disabled="!dapatDiunduh"
          class="tombol tombol-utama disabled:opacity-50"
          @click="unduh('pdf')"
        >
          <Ikon nama="unduh" ukuran="h-4 w-4" /> Unduh PDF
        </button>
      </div>
    </template>

    <!--
      Dua tab, bukan dua menu. Kehadiran harian dan kehadiran kegiatan adalah
      dua pertanyaan yang ditanyakan orang yang sama di tempat yang sama;
      memisahkannya ke menu berbeda membuat salah satunya seolah tidak pernah
      direkap.
    -->
    <div class="mb-5 flex gap-1 border-b border-garis print:hidden" role="tablist">
      <Link
        v-for="pilihan in tab"
        :key="pilihan.nilai"
        :href="pilihan.nilai === 'umum' ? `${ALAMAT}?tab=umum` : ALAMAT"
        role="tab"
        :aria-selected="props.tab === pilihan.nilai"
        preserve-scroll
        class="-mb-px inline-flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors duration-150"
        :class="
          props.tab === pilihan.nilai
            ? 'border-aksen text-aksen-teks'
            : 'border-transparent text-sekunder hover:border-garis hover:text-utama'
        "
      >
        <Ikon :nama="pilihan.ikon" ukuran="h-4 w-4" />
        {{ pilihan.label }}
      </Link>
    </div>

    <!-- ============================ Tab harian ========================= -->
    <template v-if="adalahUmum">
      <div class="mb-5 panel p-4 print:hidden">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label for="unit" class="sr-only">Unit Kerja</label>
            <Pilihan
              id="unit"
              v-model="filter.unit_kerja_id"
              :opsi="opsiUnit"
              @update:model-value="terapkanUmum"
            />
          </div>
          <div>
            <label for="tanggal" class="sr-only">Tanggal</label>
            <Tanggal v-model="filter.tanggal" @ubah="terapkanUmum" />
          </div>
          <div class="lg:col-span-2">
            <span class="sr-only">Cari Pegawai</span>
            <KolomCari v-model="filter.cari" placeholder="Nama atau NIP…" @cari="terapkanUmum" />
          </div>
        </div>
      </div>

      <div class="panel p-6 print:border-0 print:p-0 print:shadow-none">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 class="font-display text-lg font-semibold text-utama">
              {{ umum?.sesi?.nama ?? 'Belum ada sesi absen umum' }}
            </h2>
            <p class="mt-1 text-sm text-sekunder">
              {{ tanggalPanjang(filter.tanggal) }}
              <template v-if="umum?.sesi">
                · mulai {{ umum.sesi.jam_mulai }} · toleransi {{ umum.sesi.toleransi_menit }} menit
              </template>
            </p>
            <p class="mt-0.5 text-xs text-redup">
              Cakupan tampilan:
              {{ pengguna.lintas_unit ? 'seluruh unit kerja' : pengguna.unit_kerja?.nama }}
            </p>
          </div>

          <Lencana
            v-if="umum?.sesi"
            :warna="umum.sesi.aktif ? 'emerald' : 'slate'"
            :denyut="umum.sesi.aktif"
            class="print:hidden"
          >
            {{ umum.sesi.aktif ? 'Sesi berjalan' : 'Sesi ditutup' }}
          </Lencana>
        </div>

        <RingkasanRekap :kartu="kartu" class="mt-5" />

        <p class="mt-3 text-xs text-redup">
          {{ angka.pegawai ?? 0 }} pegawai aktif dalam cakupan unit ini.
        </p>
      </div>

      <TabelRekap
        class="mt-6"
        :baris="barisUmum"
        :cari="filter.cari ?? ''"
        judul-kosong="Belum ada kehadiran pada tanggal ini"
        keterangan-kosong="Sesi harian dibuka sendiri pada tap pertama. Coba pilih tanggal lain, atau buka sesinya dari menu Absen Umum."
      />
    </template>

    <!-- =========================== Tab kegiatan ======================== -->
    <template v-else>
      <div class="mb-5 grid gap-3 sm:grid-cols-2 print:hidden">
        <div>
          <label for="event" class="sr-only">Event</label>
          <Pilihan
            id="event"
            v-model="eventTerpilih"
            :opsi="opsiEvent"
            placeholder="Pilih event…"
            @update:model-value="pilihEvent"
          />
        </div>

        <div v-if="event">
          <span class="sr-only">Cari Peserta</span>
          <KolomCari v-model="cariEvent" placeholder="Nama, NIP, atau unit kerja…" :jeda="0" />
        </div>
      </div>

      <KeadaanKosong
        v-if="!event"
        ikon="absen"
        judul="Belum ada event yang dapat direkap"
        keterangan="Buat event pada menu Daftar Event untuk mulai mencatat kehadiran."
      />

      <template v-else>
        <div class="panel p-6 print:border-0 print:p-0 print:shadow-none">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h2 class="font-display text-lg font-semibold text-utama">{{ event.nama }}</h2>
              <p class="mt-1 text-sm text-sekunder">
                {{ tanggalPanjang(event.tanggal) }} · mulai {{ event.jam_mulai }} · toleransi
                {{ event.toleransi_menit }} menit
              </p>
              <p class="mt-0.5 text-xs text-redup">
                Cakupan tampilan:
                {{ pengguna.lintas_unit ? 'seluruh unit kerja' : pengguna.unit_kerja?.nama }}
              </p>
            </div>

            <Lencana
              :warna="masihDibuka ? 'emerald' : 'slate'"
              :denyut="masihDibuka"
              class="print:hidden"
            >
              {{ masihDibuka ? 'Entry dibuka — diperbarui otomatis' : 'Entry ditutup' }}
            </Lencana>
          </div>

          <RingkasanRekap :kartu="kartu" class="mt-5" />
        </div>

        <!-- FR-REK-01 -->
        <TabelRekap
          class="mt-6"
          foto
          :baris="barisTampilEvent"
          :total-asli="barisEvent.length"
          :cari="cariEvent"
        />
      </template>
    </template>
  </AdminLayout>
</template>

<style>
/*
 * FR-REK-03. Sidebar, penyaring, tombol, dan kolom foto disembunyikan saat
 * mencetak supaya lembar rekap berisi tabelnya saja; foto tidak ikut karena
 * rekap cetak dipakai sebagai lampiran administratif, bukan bukti visual.
 */
@media print {
  @page {
    margin: 14mm;
  }

  body {
    background: #fff;
  }
}
</style>
