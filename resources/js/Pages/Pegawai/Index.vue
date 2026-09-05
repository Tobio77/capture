<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import PendaftaranWajah from '@/Components/PendaftaranWajah.vue'
import PendaftaranKartu from '@/Components/PendaftaranKartu.vue'
import Ikon from '@/Components/Ikon.vue'
import Paginasi from '@/Components/UI/Paginasi.vue'
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
</script>

<template>
  <AdminLayout
    judul="Kelola Pegawai"
    deskripsi="Data pegawai hasil sinkronisasi dari WORKA. Perubahan data induk dilakukan di WORKA, bukan di sini."
  >
    <!-- Kartu status sinkronisasi -->
    <div class="panel p-6">
      <div class="flex flex-wrap items-start justify-between gap-6">
        <div>
          <h2 class="flex items-center gap-2 font-display text-base font-semibold text-utama">
            <span class="rounded-md bg-aksen-lembut p-1.5 text-aksen">
              <Ikon nama="segarkan" ukuran="h-4 w-4" />
            </span>
            Sinkronisasi Data Pegawai dari WORKA
          </h2>

          <dl class="mt-4 grid gap-x-10 gap-y-2 text-sm sm:grid-cols-2">
            <div class="flex gap-2">
              <dt class="text-redup">Terakhir sinkron:</dt>
              <dd class="font-medium text-utama">
                {{ waktu(status_sinkron.sinkron_terakhir_at) }}
              </dd>
            </div>
            <div class="flex gap-2">
              <dt class="text-redup">Pegawai aktif tersimpan:</dt>
              <dd class="font-display font-medium tabular-nums text-utama">
                {{ status_sinkron.total_pegawai_lokal }}
              </dd>
            </div>
            <div class="flex gap-2">
              <dt class="text-redup">Pegawai aktif di WORKA:</dt>
              <dd class="font-display font-medium tabular-nums text-utama">
                {{ status_sinkron.total_pegawai_worka || '—' }}
              </dd>
            </div>
            <div class="flex items-center gap-2">
              <dt class="text-redup">Status:</dt>
              <dd>
                <Lencana :warna="statusKoneksi.warna" :denyut="statusKoneksi.denyut">
                  {{ statusKoneksi.label }}
                </Lencana>
              </dd>
            </div>
          </dl>
        </div>

        <div v-if="dapat_sinkron" class="flex flex-wrap gap-2">
          <button
            type="button"
            :disabled="sedangSinkron"
            class="inline-flex items-center gap-1.5 rounded-md bg-aksen px-4 py-2 text-sm font-semibold text-white bayang transition hover:bg-aksen-kuat active:scale-95 disabled:opacity-60"
            @click="sinkron(false)"
          >
            <Ikon nama="segarkan" ukuran="h-4 w-4" :class="sedangSinkron && 'animate-spin'" />
            {{ sedangSinkron ? 'Menyinkronkan…' : 'Sinkron Inkremental' }}
          </button>
          <button
            type="button"
            :disabled="sedangSinkron"
            class="inline-flex items-center gap-1.5 rounded-md border border-garis px-4 py-2 text-sm font-medium text-utama transition hover:bg-permukaan-hover active:scale-95 disabled:opacity-60"
            @click="sinkron(true)"
          >
            <Ikon nama="unduh" ukuran="h-4 w-4" /> Sinkron Penuh
          </button>
          <Link
            href="/admin/setting/worka"
            class="inline-flex items-center gap-1.5 rounded-md border border-garis px-4 py-2 text-sm font-medium text-utama transition hover:bg-permukaan-hover active:scale-95"
          >
            <Ikon nama="filter" ukuran="h-4 w-4" /> Setting
          </Link>
        </div>
      </div>
    </div>

    <!-- Filter -->
    <div class="mt-6 panel p-4">
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div class="lg:col-span-2">
          <span class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-redup">
            Cari Pegawai
          </span>
          <KolomCari v-model="filter.cari" placeholder="Nama atau NIP…" @cari="terapkanFilter" />
        </div>
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
            @update:model-value="terapkanFilter"
          />
        </div>
        <div>
          <label
            for="status_foto"
            class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-redup"
          >
            Foto Wajah
          </label>
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
          <label
            for="status"
            class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-redup"
          >
            Status
          </label>
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

    <!-- Tabel pegawai -->
    <div class="mt-4 overflow-hidden panel">
      <div class="tabel-gulir tabel-aksi gulir-halus">
        <table class="w-full text-left text-sm">
          <thead
            class="border-b border-garis bg-permukaan-2 text-xs uppercase tracking-wider text-redup"
          >
            <tr>
              <th scope="col" class="px-4 py-3 font-medium">NIP</th>
              <th scope="col" class="px-4 py-3 font-medium">Nama</th>
              <th scope="col" class="px-4 py-3 font-medium">Unit Kerja</th>
              <th scope="col" class="hidden px-4 py-3 font-medium 2xl:table-cell">Jabatan</th>
              <th scope="col" class="px-4 py-3 font-medium">Foto Wajah</th>
              <th scope="col" class="px-4 py-3 font-medium">Kartu</th>
              <th scope="col" class="px-4 py-3 font-medium">Status</th>
              <th scope="col" class="hidden whitespace-nowrap px-4 py-3 font-medium 2xl:table-cell">
                Sinkron
              </th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-garis">
            <tr
              v-for="orang in pegawai.data"
              :key="orang.id"
              class="transition-colors hover:bg-permukaan-hover"
              :class="{ 'baris-redup bg-permukaan-2/60': !orang.aktif }"
            >
              <td class="whitespace-nowrap px-4 py-3 font-display text-xs tabular-nums text-sekunder">
                {{ orang.nip }}
              </td>
              <td class="whitespace-nowrap px-4 py-3 font-medium text-utama">{{ orang.nama }}</td>
              <td
                class="max-w-[11rem] truncate px-4 py-3 text-sekunder"
                :title="orang.unit_kerja?.nama"
              >
                {{ orang.unit_kerja?.nama ?? '—' }}
              </td>
              <td
                class="hidden max-w-[11rem] truncate px-4 py-3 text-sekunder 2xl:table-cell"
                :title="orang.jabatan"
              >
                {{ orang.jabatan ?? '—' }}
              </td>
              <td class="px-4 py-3">
                <Lencana :warna="orang.wajah_terdaftar ? 'emerald' : 'amber'">
                  {{ orang.wajah_terdaftar ? 'Terdaftar' : 'Belum ada' }}
                </Lencana>
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
              <td class="px-4 py-3">
                <span
                  class="text-xs font-medium"
                  :class="orang.aktif ? 'text-sekunder' : 'text-redup'"
                >
                  {{ orang.aktif ? 'Aktif' : 'Nonaktif' }}
                </span>
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
            </tr>
          </tbody>
        </table>
      </div>

      <KeadaanKosong
        v-if="pegawai.data.length === 0"
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

      <Paginasi :data="pegawai" />
    </div>

    <PendaftaranWajah :pegawai="wajahDikelola" @tutup="wajahDikelola = null" />
    <PendaftaranKartu :pegawai="kartuDikelola" @tutup="kartuDikelola = null" />
  </AdminLayout>
</template>
