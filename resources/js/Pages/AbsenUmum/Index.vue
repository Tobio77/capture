<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Ikon from '@/Components/Ikon.vue'
import Lencana from '@/Components/UI/Lencana.vue'
import KolomCari from '@/Components/UI/KolomCari.vue'
import Pilihan from '@/Components/UI/Pilihan.vue'
import Tanggal from '@/Components/UI/Tanggal.vue'
import RingkasanRekap from '@/Components/Rekap/RingkasanRekap.vue'
import TabelRekap from '@/Components/Rekap/TabelRekap.vue'

/**
 * Absen Umum — pemantauan sesi absensi harian tanpa event kegiatan.
 */

const props = defineProps({
  sesi: { type: Object, default: null },
  baris: { type: Array, required: true },
  ringkasan: { type: Object, required: true },
  riwayat: { type: Array, required: true },
  unit_kerja: { type: Array, required: true },
  filter: { type: Object, required: true },
  absen_umum_aktif: { type: Boolean, required: true },
  jam_masuk: { type: String, required: true },

  /** Status efektif per jenis absen beserta sumbernya (FR-SET-07). */
  status_jendela: { type: Object, default: () => ({}) },
})

/** Salah satu jenis sedang dipaksa buka/tutup admin. */
const adaOverride = computed(() =>
  Object.values(props.status_jendela).some((status) => status.sumber === 'override'),
)

function aturOverride(aksi) {
  const tanya = {
    buka: 'Buka paksa absen umum hari ini, mengabaikan jadwal? Berlaku sampai hari berganti.',
    tutup: 'Tutup paksa absen umum hari ini? Perangkat akan menolak tap sampai hari berganti.',
    cabut: 'Kembalikan ke jadwal bawaan?',
  }

  if (!window.confirm(tanya[aksi])) return

  router.post(
    '/admin/kelola-absen/absen-umum/override',
    { aksi, unit_kerja_id: filter.unit_kerja_id },
    { preserveScroll: true },
  )
}

const filter = reactive({ ...props.filter })

const opsiUnit = computed(() =>
  props.unit_kerja.map((u) => ({ nilai: u.id, label: u.nama, keterangan: u.kode })),
)
const baris = ref(props.baris)
const ringkasan = ref(props.ringkasan)

const kueri = computed(() =>
  Object.fromEntries(Object.entries(filter).filter(([, nilai]) => nilai !== '' && nilai !== null)),
)

function terapkan() {
  router.get('/admin/kelola-absen/absen-umum', kueri.value, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onSuccess: (halaman) => {
      baris.value = halaman.props.baris
      ringkasan.value = halaman.props.ringkasan
    },
  })
}

function bukaSesi() {
  router.post(
    '/admin/kelola-absen/absen-umum/buka',
    { unit_kerja_id: filter.unit_kerja_id },
    { preserveScroll: true },
  )
}

function unduh(format) {
  window.location.href =
    '/admin/kelola-absen/absen-umum/ekspor?' +
    new URLSearchParams({ ...kueri.value, format }).toString()
}

const cetak = () => window.print()

/*
 * Sesi hari ini masih berjalan, jadi tabelnya menyegarkan dirinya sendiri —
 * admin yang memantau dari mejanya tidak perlu menekan muat ulang.
 */
const JEDA_SEGARKAN_MS = 15000
let jeda = null

const hariIni = computed(() => filter.tanggal === new Date().toISOString().slice(0, 10))

onMounted(() => {
  jeda = setInterval(segarkan, JEDA_SEGARKAN_MS)
})

onBeforeUnmount(() => clearInterval(jeda))

async function segarkan() {
  if (!hariIni.value || props.sesi === null) return

  try {
    const jawaban = await fetch(
      '/admin/kelola-absen/absen-umum/data?' + new URLSearchParams(kueri.value).toString(),
      { headers: { Accept: 'application/json' } },
    )

    if (!jawaban.ok) return

    const isi = await jawaban.json()

    baris.value = isi.baris
    ringkasan.value = isi.ringkasan
  } catch {
    // Penyegaran berikutnya menyusul sendiri.
  }
}

const kartu = computed(() => [
  {
    label: 'Hadir',
    nilai: ringkasan.value.hadir,
    ikon: 'cek',
    latar: 'bg-berhasil-lembut text-berhasil',
    warna: 'text-berhasil-teks',
  },
  {
    label: 'Tepat Waktu',
    nilai: ringkasan.value.tepat,
    ikon: 'jam',
    latar: 'bg-aksen-lembut text-aksen',
    warna: 'text-aksen-teks',
  },
  {
    label: 'Terlambat',
    nilai: ringkasan.value.terlambat,
    ikon: 'peringatan',
    latar: 'bg-peringatan-lembut text-peringatan',
    warna: 'text-peringatan-teks',
  },
  {
    label: 'Belum Absen',
    nilai: ringkasan.value.belum_absen,
    ikon: 'pegawai',
    latar: 'bg-permukaan-2 text-redup',
    warna: 'text-sekunder',
  },
])

const tanggalPanjang = (iso) =>
  new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
</script>

<template>
  <AdminLayout
    judul="Absen Umum"
    deskripsi="Absensi harian tanpa event kegiatan. Sesi hariannya dibuka sistem saat tidak ada kegiatan yang berjalan."
  >
    <template #aksi>
      <div class="flex flex-wrap items-center gap-2 print:hidden">
        <button type="button" class="tombol tombol-garis" @click="cetak">
          <Ikon nama="cetak" ukuran="h-4 w-4" /> Cetak
        </button>
        <button
          type="button"
          :disabled="sesi === null"
          class="tombol tombol-garis disabled:opacity-50"
          @click="unduh('csv')"
        >
          <Ikon nama="unduh" ukuran="h-4 w-4" /> CSV
        </button>
        <button
          type="button"
          :disabled="sesi === null"
          class="tombol tombol-garis disabled:opacity-50"
          @click="unduh('pdf')"
        >
          <Ikon nama="unduh" ukuran="h-4 w-4" /> PDF
        </button>
        <Link
          :href="`/admin/kelola-absen/absen-umum/layar${filter.unit_kerja_id ? `?unit_kerja_id=${filter.unit_kerja_id}` : ''}`"
          class="inline-flex items-center gap-1.5 rounded-md bg-aksen px-4 py-2 text-sm font-semibold text-white bayang transition hover:bg-aksen-kuat active:scale-95"
        >
          <Ikon nama="wajah" ukuran="h-4 w-4" /> Buka Layar Absen
        </Link>
      </div>
    </template>

    <!-- Absen umum dimatikan: menu tetap terbuka, tetapi tanpa sesi harian. -->
    <div
      v-if="!absen_umum_aktif"
      class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-peringatan bg-peringatan-lembut px-5 py-4 print:hidden"
    >
      <p class="flex items-start gap-2 text-sm text-peringatan-teks">
        <Ikon nama="peringatan" ukuran="h-4 w-4 shrink-0 mt-0.5" />
        <span>
          Absen umum sedang dimatikan pada Setting Absen. Sesi harian tidak dibuka, dan perangkat
          absen hanya melayani event kegiatan.
        </span>
      </p>
      <Link
        href="/admin/kelola-absen/setting"
        class="inline-flex items-center gap-1.5 rounded-md border border-peringatan px-3 py-1.5 text-xs font-medium text-peringatan-teks transition hover:bg-peringatan-lembut active:scale-95"
      >
        <Ikon nama="filter" ukuran="h-3.5 w-3.5" /> Setting Absen
      </Link>
    </div>

    <!-- Penyaring -->
    <div class="mb-5 panel p-4 print:hidden">
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
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
          <label for="tanggal" class="sr-only"> Tanggal </label>
          <Tanggal v-model="filter.tanggal" @ubah="terapkan" />
        </div>
        <div class="lg:col-span-2">
          <span class="sr-only"> Cari Pegawai </span>
          <KolomCari v-model="filter.cari" placeholder="Nama atau NIP…" @cari="terapkan" />
        </div>
      </div>
    </div>

    <!-- Kepala sesi; ikut tercetak -->
    <div class="panel p-6 print:border-0 print:p-0 print:shadow-none">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 class="font-display text-lg font-semibold text-utama">
            {{ sesi?.nama ?? 'Belum ada sesi absen umum' }}
          </h2>
          <p class="mt-1 text-sm text-sekunder">
            {{ tanggalPanjang(filter.tanggal) }}
            <template v-if="sesi">
              · mulai {{ sesi.jam_mulai }} · toleransi {{ sesi.toleransi_menit }} menit
            </template>
            <template v-else> · jam masuk harian {{ jam_masuk }} </template>
          </p>
        </div>

        <div class="flex items-center gap-3 print:hidden">
          <Lencana v-if="sesi" :warna="sesi.aktif ? 'emerald' : 'slate'" :denyut="sesi.aktif">
            {{ sesi.aktif ? 'Sesi berjalan' : 'Sesi ditutup' }}
          </Lencana>
          <button
            v-else-if="absen_umum_aktif && hariIni"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border border-garis px-3 py-1.5 text-sm font-medium text-utama transition hover:bg-permukaan-hover active:scale-95"
            @click="bukaSesi"
          >
            <Ikon nama="tambah" ukuran="h-4 w-4" /> Buka Sesi Hari Ini
          </button>
        </div>
      </div>

      <!--
        Status jendela buka/tutup (FR-SET-07).

        Menyebut SUMBER statusnya, bukan hanya keadaannya: "Tertutup" saja
        membuat admin memeriksa jam kantor, padahal penyebabnya bisa saja
        override yang tertinggal dari kemarin. Karena override menempel pada
        sesi harian, ia memang tidak mungkin terbawa — tetapi admin yang
        memasangnya pagi ini tetap perlu tahu bahwa yang berlaku sekarang
        keputusannya, bukan jadwal.
      -->
      <div v-if="hariIni" class="mt-5 flex flex-wrap items-center gap-3 border-t border-garis pt-5">
        <div
          v-for="status in status_jendela"
          :key="status.jenis"
          class="flex min-w-0 items-center gap-2.5 rounded-xl px-3 py-2"
          :class="status.terbuka ? 'nada-emerald' : 'nada-amber'"
          :style="{ backgroundColor: 'var(--nada-lembut)' }"
        >
          <span
            class="h-2 w-2 shrink-0 rounded-full"
            :style="{ backgroundColor: 'var(--nada-kuat)' }"
          ></span>

          <div class="min-w-0 leading-tight">
            <p class="text-sm font-semibold" :style="{ color: 'var(--nada-teks)' }">
              {{ status.jenis === 'datang' ? 'Datang' : 'Pulang' }} ·
              {{ status.terbuka ? 'terbuka' : 'tertutup' }}
            </p>
            <p class="truncate text-xs text-sekunder">{{ status.keterangan }}</p>
          </div>
        </div>

        <div class="ml-auto flex flex-wrap items-center gap-2 print:hidden">
          <span v-if="adaOverride" class="keping nada-biru">
            <Ikon nama="peringatan" ukuran="h-3.5 w-3.5" /> Override manual aktif
          </span>

          <button
            v-if="adaOverride"
            type="button"
            class="tombol tombol-garis px-3 py-2 text-xs"
            @click="aturOverride('cabut')"
          >
            Kembalikan ke jadwal
          </button>

          <template v-else>
            <button
              type="button"
              class="tombol tombol-garis px-3 py-2 text-xs"
              @click="aturOverride('buka')"
            >
              <Ikon nama="kunci" ukuran="h-3.5 w-3.5" /> Buka paksa
            </button>
            <button
              type="button"
              class="tombol tombol-garis px-3 py-2 text-xs"
              @click="aturOverride('tutup')"
            >
              <Ikon nama="tutup" ukuran="h-3.5 w-3.5" /> Tutup paksa
            </button>
          </template>
        </div>
      </div>

      <RingkasanRekap :kartu="kartu" class="mt-5" />

      <p class="mt-3 text-xs text-redup">
        {{ ringkasan.pegawai }} pegawai aktif dalam cakupan unit ini.
      </p>
    </div>

    <!--
      Daftar kehadiran. Tabelnya milik bersama dengan Rekap Absen — bentuk yang
      sama untuk baris yang memang sama.
    -->
    <TabelRekap
      class="mt-6"
      :baris="baris"
      :cari="filter.cari ?? ''"
      :judul-kosong="sesi === null ? 'Belum ada sesi pada tanggal ini' : 'Belum ada yang mengabsen'"
      :keterangan-kosong="
        sesi === null
          ? 'Sesi harian dibuka sendiri pada tap pertama, atau lewat tombol Buka Sesi Hari Ini.'
          : 'Kehadiran akan muncul di sini begitu pegawai pertama men-tap.'
      "
    />

    <!-- Riwayat sesi -->
    <div v-if="riwayat.length > 0" class="mt-6 print:hidden">
      <h3 class="font-display text-sm font-semibold text-utama">Sesi Terakhir</h3>
      <div class="mt-3 flex flex-wrap gap-2">
        <button
          v-for="item in riwayat"
          :key="item.id"
          type="button"
          class="rounded-md border px-3 py-2 text-left text-xs transition hover:bg-permukaan-hover active:scale-95"
          :class="
            item.tanggal === filter.tanggal
              ? 'border-aksen bg-aksen-lembut text-aksen-teks'
              : 'border-garis bg-permukaan text-sekunder'
          "
          @click="
            () => {
              filter.tanggal = item.tanggal
              terapkan()
            }
          "
        >
          <span class="block font-display font-medium">{{ item.tanggal }}</span>
          <span class="mt-0.5 block tabular-nums">{{ item.jumlah_absen }} kehadiran</span>
        </button>
      </div>
    </div>
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
