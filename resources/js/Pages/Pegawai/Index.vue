<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import PendaftaranWajah from '@/Components/PendaftaranWajah.vue'

const props = defineProps({
  pegawai: { type: Object, required: true },
  unit_kerja: { type: Array, required: true },
  filter: { type: Object, required: true },
  dapat_sinkron: { type: Boolean, required: true },
  status_sinkron: { type: Object, required: true },
})

const filter = reactive({ ...props.filter })
const wajahDikelola = ref(null) // pegawai yang sedang didaftarkan wajahnya
const sedangSinkron = ref(false)
const koneksi = ref(null) // null = belum diperiksa

let jedaCari = null

watch(
  () => filter.cari,
  () => {
    clearTimeout(jedaCari)
    jedaCari = setTimeout(terapkanFilter, 350)
  },
)

const terapkanFilter = () => {
  router.get('/admin/pegawai', bersihkan(filter), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

const bersihkan = (nilai) =>
  Object.fromEntries(Object.entries(nilai).filter(([, v]) => v !== '' && v !== null))

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
    return { label: 'Belum dikonfigurasi', warna: 'bg-slate-400', teks: 'text-slate-600' }
  }
  if (koneksi.value === null) {
    return { label: 'Memeriksa koneksi…', warna: 'bg-slate-300', teks: 'text-slate-500' }
  }
  if (koneksi.value === true) {
    return { label: 'Terhubung ke WORKA', warna: 'bg-emerald-600', teks: 'text-emerald-700' }
  }
  return { label: 'Tidak terhubung ke WORKA', warna: 'bg-amber-600', teks: 'text-amber-700' }
})

const waktu = (iso) =>
  iso
    ? new Date(iso).toLocaleString('id-ID', { dateStyle: 'long', timeStyle: 'short' })
    : 'Belum pernah'

const tanggalSingkat = (iso) =>
  iso ? new Date(iso).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
</script>

<template>
  <AdminLayout
    judul="Kelola Pegawai"
    deskripsi="Data pegawai hasil sinkronisasi dari WORKA. Perubahan data induk dilakukan di WORKA, bukan di sini."
  >
    <!-- Kartu status sinkronisasi -->
    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
      <div class="flex flex-wrap items-start justify-between gap-6">
        <div>
          <h2 class="font-display text-base font-semibold text-navy-700">
            Sinkronisasi Data Pegawai dari WORKA
          </h2>

          <dl class="mt-4 grid gap-x-10 gap-y-2 text-sm sm:grid-cols-2">
            <div class="flex gap-2">
              <dt class="text-slate-500">Terakhir sinkron:</dt>
              <dd class="font-medium text-slate-700">{{ waktu(status_sinkron.sinkron_terakhir_at) }}</dd>
            </div>
            <div class="flex gap-2">
              <dt class="text-slate-500">Pegawai aktif tersimpan:</dt>
              <dd class="font-display font-medium tabular-nums text-slate-700">
                {{ status_sinkron.total_pegawai_lokal }}
              </dd>
            </div>
            <div class="flex gap-2">
              <dt class="text-slate-500">Pegawai aktif di WORKA:</dt>
              <dd class="font-display font-medium tabular-nums text-slate-700">
                {{ status_sinkron.total_pegawai_worka || '—' }}
              </dd>
            </div>
            <div class="flex items-center gap-2">
              <dt class="text-slate-500">Status:</dt>
              <dd class="flex items-center gap-1.5 font-medium" :class="statusKoneksi.teks">
                <span class="h-2 w-2 rounded-full" :class="statusKoneksi.warna"></span>
                {{ statusKoneksi.label }}
              </dd>
            </div>
          </dl>
        </div>

        <div v-if="dapat_sinkron" class="flex flex-wrap gap-2">
          <button
            type="button"
            :disabled="sedangSinkron"
            class="rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-60"
            @click="sinkron(false)"
          >
            {{ sedangSinkron ? 'Menyinkronkan…' : 'Sinkron Inkremental' }}
          </button>
          <button
            type="button"
            :disabled="sedangSinkron"
            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
            @click="sinkron(true)"
          >
            Sinkron Penuh
          </button>
          <Link
            href="/admin/setting/worka"
            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
          >
            Setting
          </Link>
        </div>
      </div>
    </div>

    <!-- Filter -->
    <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <div>
        <label for="cari" class="sr-only">Cari nama atau NIP</label>
        <input
          id="cari"
          v-model="filter.cari"
          type="search"
          placeholder="Cari nama atau NIP…"
          class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-teal-600 focus:outline-none focus:ring-1 focus:ring-teal-600"
        />
      </div>
      <select
        v-model="filter.unit_kerja_id"
        aria-label="Filter unit kerja"
        class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-teal-600 focus:outline-none focus:ring-1 focus:ring-teal-600"
        @change="terapkanFilter"
      >
        <option value="">Semua unit kerja</option>
        <option v-for="unit in unit_kerja" :key="unit.id" :value="String(unit.id)">
          {{ unit.nama }}
        </option>
      </select>
      <select
        v-model="filter.status_foto"
        aria-label="Filter status foto wajah"
        class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-teal-600 focus:outline-none focus:ring-1 focus:ring-teal-600"
        @change="terapkanFilter"
      >
        <option value="">Semua status foto</option>
        <option value="terdaftar">Foto terdaftar</option>
        <option value="belum">Foto belum ada</option>
      </select>
      <select
        v-model="filter.status"
        aria-label="Filter status pegawai"
        class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-teal-600 focus:outline-none focus:ring-1 focus:ring-teal-600"
        @change="terapkanFilter"
      >
        <option value="">Aktif dan nonaktif</option>
        <option value="aktif">Hanya aktif</option>
        <option value="nonaktif">Hanya nonaktif</option>
      </select>
    </div>

    <!-- Tabel pegawai -->
    <div class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
              <th scope="col" class="px-4 py-3 font-medium">NIP</th>
              <th scope="col" class="px-4 py-3 font-medium">Nama</th>
              <th scope="col" class="px-4 py-3 font-medium">Unit Kerja</th>
              <th scope="col" class="px-4 py-3 font-medium">Jabatan</th>
              <th scope="col" class="px-4 py-3 font-medium">Foto Wajah</th>
              <th scope="col" class="px-4 py-3 font-medium">Status</th>
              <th scope="col" class="px-4 py-3 font-medium">Sinkron Terakhir</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="orang in pegawai.data" :key="orang.id" :class="{ 'bg-slate-50/60': !orang.aktif }">
              <td class="px-4 py-3 font-display tabular-nums text-slate-600">{{ orang.nip }}</td>
              <td class="px-4 py-3 font-medium text-navy-700">{{ orang.nama }}</td>
              <td class="px-4 py-3 text-slate-600">{{ orang.unit_kerja?.nama ?? '—' }}</td>
              <td class="px-4 py-3 text-slate-600">{{ orang.jabatan ?? '—' }}</td>
              <td class="px-4 py-3">
                <span
                  class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
                  :class="orang.wajah_terdaftar ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'"
                >
                  <span
                    class="h-1.5 w-1.5 rounded-full"
                    :class="orang.wajah_terdaftar ? 'bg-emerald-600' : 'bg-amber-600'"
                  ></span>
                  {{ orang.wajah_terdaftar ? 'Terdaftar' : 'Belum ada' }}
                </span>
              </td>
              <td class="px-4 py-3">
                <span class="text-xs font-medium" :class="orang.aktif ? 'text-slate-600' : 'text-slate-400'">
                  {{ orang.aktif ? 'Aktif' : 'Nonaktif' }}
                </span>
              </td>
              <td class="px-4 py-3 text-xs text-slate-500">
                {{ tanggalSingkat(orang.sumber_sinkron_terakhir) }}
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-right">
                <button
                  type="button"
                  class="rounded-md px-2.5 py-1.5 text-xs font-medium text-teal-700 transition hover:bg-teal-50"
                  @click="wajahDikelola = orang"
                >
                  {{ orang.wajah_terdaftar ? 'Perbarui wajah' : 'Daftarkan wajah' }}
                </button>
              </td>
            </tr>
            <tr v-if="pegawai.data.length === 0">
              <td colspan="8" class="px-6 py-12 text-center text-sm text-slate-500">
                Tidak ada pegawai yang cocok dengan filter ini.
                <span v-if="status_sinkron.total_pegawai_lokal === 0" class="mt-1 block">
                  Jalankan sinkronisasi dari WORKA untuk menarik data pegawai.
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="pegawai.last_page > 1"
        class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-sm"
      >
        <p class="text-slate-500">
          Menampilkan {{ pegawai.from }}–{{ pegawai.to }} dari
          <span class="font-display tabular-nums">{{ pegawai.total }}</span> pegawai
        </p>
        <div class="flex flex-wrap gap-1">
          <component
            :is="tautan.url ? Link : 'span'"
            v-for="tautan in pegawai.links"
            :key="tautan.label"
            :href="tautan.url ?? undefined"
            preserve-scroll
            class="rounded px-3 py-1.5 text-sm transition"
            :class="[
              tautan.active ? 'bg-teal-600 font-medium text-white' : 'text-slate-600 hover:bg-slate-100',
              !tautan.url && 'cursor-default text-slate-300 hover:bg-transparent',
            ]"
            v-html="tautan.label"
          />
        </div>
      </div>
    </div>

    <PendaftaranWajah :pegawai="wajahDikelola" @tutup="wajahDikelola = null" />
  </AdminLayout>
</template>
