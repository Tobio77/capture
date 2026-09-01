<script setup>
import { computed, ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Modal from '@/Components/Modal.vue'

/**
 * Kelola perangkat absen (FR-USR-02, FR-USR-03).
 */

defineProps({
  daftar: { type: Array, required: true },
  unit_kerja: { type: Array, required: true },
})

const page = usePage()
const kodeAktivasi = computed(() => page.props.flash.kode_aktivasi ?? null)

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

function salin(teks) {
  navigator.clipboard?.writeText(teks)
}

function waktu(iso) {
  if (!iso) return '—'

  return new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <AdminLayout
    judul="Perangkat Absen"
    deskripsi="Titik absen yang terdaftar beserta status aktivasi dan jejak koneksinya."
  >
    <template #aksi>
      <button
        type="button"
        class="rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700"
        @click="bukaTambah"
      >
        Daftarkan Perangkat
      </button>
    </template>

    <!-- Kode aktivasi hanya tampil sekali, tepat setelah diterbitkan. -->
    <div v-if="kodeAktivasi" class="mb-5 rounded-lg border border-amber-300 bg-amber-50 px-5 py-4">
      <p class="text-sm font-medium text-amber-900">
        Kode aktivasi — {{ kodeAktivasi.nama_titik }}
      </p>
      <p class="mt-1 text-xs text-amber-800">
        Berlaku 24 jam. Masukkan kode ini pada layar aktivasi perangkat di lokasi.
      </p>
      <div class="mt-3 flex flex-wrap items-center gap-3">
        <code class="rounded bg-white px-4 py-2 font-display text-lg font-semibold tracking-widest text-navy-700">
          {{ kodeAktivasi.kode }}
        </code>
        <button
          type="button"
          class="rounded-md border border-amber-400 px-3 py-1.5 text-xs font-medium text-amber-900 transition hover:bg-amber-100"
          @click="salin(kodeAktivasi.kode)"
        >
          Salin
        </button>
      </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
              <th scope="col" class="px-4 py-3 text-left font-medium">Titik Absen</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Unit Kerja</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Pemasangan</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Alamat IP</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Terakhir Aktif</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Status</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="item in daftar" :key="item.id" :class="{ 'bg-slate-50/60': !item.aktif }">
              <td class="px-4 py-3 font-medium text-navy-700">{{ item.nama_titik }}</td>
              <td class="px-4 py-3 text-slate-600">{{ item.unit_kerja?.nama ?? '—' }}</td>
              <td class="px-4 py-3">
                <span
                  v-if="item.terpasang"
                  class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700"
                >
                  Terpasang
                </span>
                <span
                  v-else-if="item.kode_aktivasi_berlaku"
                  class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700"
                >
                  Menunggu aktivasi
                </span>
                <span v-else class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                  Belum diaktifkan
                </span>
              </td>
              <td class="px-4 py-3 font-display tabular-nums text-slate-600">{{ item.ip_terakhir ?? '—' }}</td>
              <td class="px-4 py-3 text-xs text-slate-500">{{ waktu(item.login_terakhir_at) }}</td>
              <td class="px-4 py-3">
                <span
                  class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
                  :class="item.aktif ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                >
                  <span class="h-1.5 w-1.5 rounded-full" :class="item.aktif ? 'bg-emerald-600' : 'bg-slate-400'"></span>
                  {{ item.aktif ? 'Aktif' : 'Nonaktif' }}
                </span>
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-right">
                <button
                  type="button"
                  class="rounded-md px-2.5 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-100"
                  @click="bukaRiwayat(item)"
                >
                  Riwayat
                </button>
                <button
                  type="button"
                  class="rounded-md px-2.5 py-1.5 text-xs font-medium text-teal-700 transition hover:bg-teal-50"
                  @click="bukaUbah(item)"
                >
                  Ubah
                </button>
                <button
                  v-if="!item.terpasang && item.aktif"
                  type="button"
                  class="rounded-md px-2.5 py-1.5 text-xs font-medium text-navy-700 transition hover:bg-navy-50"
                  @click="terbitkanKode(item)"
                >
                  Kode Aktivasi
                </button>
                <button
                  v-if="item.terpasang"
                  type="button"
                  class="rounded-md px-2.5 py-1.5 text-xs font-medium text-amber-700 transition hover:bg-amber-50"
                  @click="cabutToken(item)"
                >
                  Cabut Akses
                </button>
                <button
                  type="button"
                  class="rounded-md px-2.5 py-1.5 text-xs font-medium transition"
                  :class="item.aktif ? 'text-amber-700 hover:bg-amber-50' : 'text-emerald-700 hover:bg-emerald-50'"
                  @click="ubahStatus(item)"
                >
                  {{ item.aktif ? 'Nonaktifkan' : 'Aktifkan' }}
                </button>
              </td>
            </tr>

            <tr v-if="daftar.length === 0">
              <td colspan="7" class="px-6 py-14 text-center text-sm text-slate-500">
                Belum ada perangkat absen terdaftar.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Formulir -->
    <Modal :terbuka="modalTerbuka" :judul="judulForm" @tutup="tutup">
      <div class="space-y-4">
        <div>
          <label for="nama_titik" class="block text-sm font-medium text-slate-700">Nama Titik Absen</label>
          <input
            id="nama_titik"
            v-model="form.nama_titik"
            type="text"
            placeholder="mis. Aula Utama BLK Singosari"
            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
          />
          <p v-if="form.errors.nama_titik" class="mt-1.5 text-xs text-amber-700">{{ form.errors.nama_titik }}</p>
        </div>

        <div>
          <label for="unit" class="block text-sm font-medium text-slate-700">Unit Kerja</label>
          <select
            id="unit"
            v-model="form.unit_kerja_id"
            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
          >
            <option value="" disabled>Pilih unit kerja…</option>
            <option v-for="unit in unit_kerja" :key="unit.id" :value="unit.id">{{ unit.nama }}</option>
          </select>
          <p v-if="form.errors.unit_kerja_id" class="mt-1.5 text-xs text-amber-700">
            {{ form.errors.unit_kerja_id }}
          </p>
        </div>

        <p v-if="!sedangDiubah" class="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
          Kode aktivasi diterbitkan otomatis setelah perangkat tersimpan, dan berlaku 24 jam.
        </p>
      </div>

      <template #aksi>
        <button
          type="button"
          class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
          @click="tutup"
        >
          Batal
        </button>
        <button
          type="button"
          class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 disabled:opacity-50"
          :disabled="form.processing"
          @click="simpan"
        >
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
      <p v-if="riwayat === null" class="text-sm text-slate-500">Memuat riwayat…</p>

      <ol v-else-if="riwayat.length > 0" class="space-y-3">
        <li
          v-for="baris in riwayat"
          :key="baris.id"
          class="border-b border-slate-100 pb-3 text-sm last:border-0 last:pb-0"
        >
          <p class="font-medium text-navy-700">{{ baris.aksi }}</p>
          <p class="mt-0.5 text-xs text-slate-600">{{ baris.deskripsi }}</p>
          <p class="mt-1 text-xs text-slate-500">
            {{ waktu(baris.waktu) }}
            <span v-if="baris.ip"> · IP {{ baris.ip }}</span>
            <span v-if="baris.oleh"> · oleh {{ baris.oleh }}</span>
          </p>
        </li>
      </ol>

      <p v-else class="rounded-md border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500">
        Belum ada riwayat untuk perangkat ini.
      </p>

      <template #aksi>
        <button
          type="button"
          class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
          @click="riwayatTerbuka = false"
        >
          Tutup
        </button>
      </template>
    </Modal>
  </AdminLayout>
</template>
