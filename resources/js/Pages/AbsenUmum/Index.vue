<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Ikon from '@/Components/Ikon.vue'
import Lencana from '@/Components/UI/Lencana.vue'
import KolomCari from '@/Components/UI/KolomCari.vue'
import KeadaanKosong from '@/Components/UI/KeadaanKosong.vue'
import Pilihan from '@/Components/UI/Pilihan.vue'
import Tanggal from '@/Components/UI/Tanggal.vue'

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
})

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
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-md border border-garis bg-permukaan px-3 py-2 text-sm font-medium text-utama transition hover:bg-permukaan-hover active:scale-95"
          @click="cetak"
        >
          <Ikon nama="cetak" ukuran="h-4 w-4" /> Cetak
        </button>
        <button
          type="button"
          :disabled="sesi === null"
          class="inline-flex items-center gap-1.5 rounded-md border border-garis bg-permukaan px-3 py-2 text-sm font-medium text-utama transition hover:bg-permukaan-hover active:scale-95 disabled:opacity-50"
          @click="unduh('csv')"
        >
          <Ikon nama="unduh" ukuran="h-4 w-4" /> CSV
        </button>
        <button
          type="button"
          :disabled="sesi === null"
          class="inline-flex items-center gap-1.5 rounded-md border border-garis bg-permukaan px-3 py-2 text-sm font-medium text-utama transition hover:bg-permukaan-hover active:scale-95 disabled:opacity-50"
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
    <div class="mb-5 rounded-lg border border-garis bg-permukaan p-4 bayang print:hidden">
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <label
            for="unit"
            class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-redup"
          >
            Unit Kerja
          </label>
          <Pilihan
            id="unit"
            v-model="filter.unit_kerja_id"
            :opsi="opsiUnit"
            @update:model-value="terapkan"
          />
        </div>
        <div>
          <label
            for="tanggal"
            class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-redup"
          >
            Tanggal
          </label>
          <Tanggal v-model="filter.tanggal" @ubah="terapkan" />
        </div>
        <div class="lg:col-span-2">
          <span class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-redup">
            Cari Pegawai
          </span>
          <KolomCari v-model="filter.cari" placeholder="Nama atau NIP…" @cari="terapkan" />
        </div>
      </div>
    </div>

    <!-- Kepala sesi; ikut tercetak -->
    <div
      class="rounded-lg border border-garis bg-permukaan p-6 bayang print:border-0 print:p-0 print:shadow-none"
    >
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

      <dl class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div v-for="item in kartu" :key="item.label" class="rounded-md border border-garis px-4 py-3">
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

      <p class="mt-3 text-xs text-redup">
        {{ ringkasan.pegawai }} pegawai aktif dalam cakupan unit ini.
      </p>
    </div>

    <!-- Daftar kehadiran -->
    <div
      class="mt-6 overflow-hidden rounded-lg border border-garis bg-permukaan bayang print:border-0 print:shadow-none"
    >
      <div class="tabel-gulir gulir-halus">
        <table class="min-w-full divide-y divide-garis text-sm">
          <thead
            class="border-b border-garis bg-permukaan-2 text-xs uppercase tracking-wider text-redup"
          >
            <tr>
              <th scope="col" class="px-4 py-3 text-left font-medium">No</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">NIP</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Nama</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Unit Kerja</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Masuk</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Pulang</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Metode</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-garis">
            <tr
              v-for="(isi, urutan) in baris"
              :key="isi.pegawai_id ?? isi.nip"
              class="transition-colors hover:bg-permukaan-hover"
            >
              <td class="px-4 py-2.5 font-display tabular-nums text-redup">{{ urutan + 1 }}</td>
              <td class="px-4 py-2.5 font-display tabular-nums text-sekunder">{{ isi.nip }}</td>
              <td class="whitespace-nowrap px-4 py-2.5 font-medium text-utama">{{ isi.nama }}</td>
              <td class="max-w-[14rem] truncate px-4 py-2.5 text-sekunder" :title="isi.unit_kerja">
                {{ isi.unit_kerja ?? '—' }}
              </td>
              <td class="px-4 py-2.5 font-display tabular-nums text-utama">
                {{ isi.jam_masuk ?? '—' }}
              </td>
              <td class="px-4 py-2.5 font-display tabular-nums text-utama">
                {{ isi.jam_pulang ?? '—' }}
              </td>
              <td class="px-4 py-2.5 text-sekunder">{{ isi.metode }}</td>
              <td class="px-4 py-2.5">
                <Lencana
                  v-if="isi.status_ketepatan"
                  :warna="isi.status_ketepatan === 'tepat' ? 'emerald' : 'amber'"
                >
                  {{ isi.status_label }}
                </Lencana>
                <span v-else class="text-xs text-redup">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <KeadaanKosong
        v-if="baris.length === 0"
        ikon="absen"
        :judul="sesi === null ? 'Belum ada sesi pada tanggal ini' : 'Belum ada yang mengabsen'"
        :keterangan="
          sesi === null
            ? 'Sesi harian dibuka sendiri pada tap pertama, atau lewat tombol Buka Sesi Hari Ini.'
            : 'Kehadiran akan muncul di sini begitu pegawai pertama men-tap.'
        "
      />
    </div>

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
