<script setup>
import { computed, reactive, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Modal from '@/Components/Modal.vue'
import Ikon from '@/Components/Ikon.vue'
import Paginasi from '@/Components/UI/Paginasi.vue'
import KolomCari from '@/Components/UI/KolomCari.vue'
import Lencana from '@/Components/UI/Lencana.vue'
import KeadaanKosong from '@/Components/UI/KeadaanKosong.vue'
import TombolAksi from '@/Components/UI/TombolAksi.vue'

/**
 * Daftar Event (FR-EVT-01 s.d. FR-EVT-05).
 */

const props = defineProps({
  daftar: { type: Object, required: true },
  filter: { type: Object, required: true },
  status_pilihan: { type: Array, required: true },
  unit_kerja: { type: Array, required: true },
  nilai_awal: { type: Object, required: true },
  boleh_semua_unit: { type: Boolean, required: true },
  cakupan_semua_unit: { type: String, required: true },
})

const filter = reactive({ ...props.filter })

const formTerbuka = ref(false)
const sedangDiubah = ref(null)
const detailTerbuka = ref(false)
const detail = ref(null)
const detailGagal = ref(null)

const form = useForm({
  nama: '',
  tanggal: new Date().toISOString().slice(0, 10),
  jam_mulai: '07:30',
  toleransi_menit: props.nilai_awal.toleransi_menit,
  cakupan: 'unit',
  unit_kerja_id: [],
  catatan: '',
})

const semuaUnit = computed(() => form.cakupan === props.cakupan_semua_unit)
const judulForm = computed(() => (sedangDiubah.value ? 'Ubah Event' : 'Buat Event Baru'))
const unitTunggal = computed(() => props.unit_kerja.length === 1)

const adaFilter = computed(() => Object.values(filter).some((n) => n !== '' && n !== null))

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
  form.unit_kerja_id = unitTunggal.value ? [props.unit_kerja[0].id] : []
  formTerbuka.value = true
}

function bukaUbah(event) {
  sedangDiubah.value = event
  form.clearErrors()
  form.nama = event.nama
  form.tanggal = event.tanggal
  form.jam_mulai = event.jam_mulai
  form.toleransi_menit = event.toleransi_menit
  form.cakupan = event.cakupan
  form.unit_kerja_id = event.unit_kerja.map((u) => u.id)
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

async function bukaDetail(event) {
  detailTerbuka.value = true
  detail.value = null
  detailGagal.value = null

  try {
    const jawaban = await fetch(`/admin/kelola-absen/event/${event.id}/detail`, {
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
  if (window.confirm(`Hapus event "${event.nama}" secara permanen? Tindakan ini tidak dapat dibatalkan.`)) {
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
</script>

<template>
  <AdminLayout
    judul="Daftar Event"
    deskripsi="Event absensi beserta cakupan unit kerjanya. Perangkat absen hanya melayani tap untuk event yang masih aktif."
  >
    <template #aksi>
      <div class="flex flex-wrap items-center gap-2">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 active:scale-95"
          @click="unduh('csv')"
        >
          <Ikon nama="unduh" ukuran="h-4 w-4" /> CSV
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 active:scale-95"
          @click="unduh('pdf')"
        >
          <Ikon nama="cetak" ukuran="h-4 w-4" /> PDF
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 active:scale-95"
          @click="bukaBuat"
        >
          <Ikon nama="tambah" ukuran="h-4 w-4" /> Buat Event
        </button>
      </div>
    </template>

    <div class="mb-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div class="lg:col-span-2">
          <KolomCari v-model="filter.cari" placeholder="Cari nama event atau catatan…" @cari="terapkan" />
        </div>
        <select
          v-model="filter.status"
          aria-label="Saring status"
          class="rounded-md border-slate-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
          @change="terapkan"
        >
          <option value="">Semua status</option>
          <option v-for="s in status_pilihan" :key="s.nilai" :value="s.nilai">{{ s.label }}</option>
        </select>
        <select
          v-model="filter.unit_kerja_id"
          aria-label="Saring unit kerja"
          class="rounded-md border-slate-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
          @change="terapkan"
        >
          <option value="">Semua unit kerja</option>
          <option v-for="unit in unit_kerja" :key="unit.id" :value="unit.id">{{ unit.nama }}</option>
        </select>
        <div class="flex items-center gap-2">
          <input
            v-model="filter.dari"
            type="date"
            aria-label="Tanggal awal"
            class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
            @change="terapkan"
          />
          <span class="text-xs text-slate-400">—</span>
          <input
            v-model="filter.sampai"
            type="date"
            aria-label="Tanggal akhir"
            class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
            @change="terapkan"
          />
        </div>
      </div>

      <button
        v-if="adaFilter"
        type="button"
        class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 transition hover:text-slate-700"
        @click="bersihkanFilter"
      >
        <Ikon nama="tutup" ukuran="h-3.5 w-3.5" /> Bersihkan penyaringan
      </button>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
              <th scope="col" class="px-4 py-3 text-left font-medium">Nama Event</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Cakupan</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Jadwal</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Toleransi</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Perangkat</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Masuk</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Status</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="event in daftar.data" :key="event.id" class="transition-colors hover:bg-slate-50/70">
              <td class="px-4 py-3">
                <span class="font-medium text-navy-700">{{ event.nama }}</span>
                <span v-if="event.catatan" class="mt-0.5 block max-w-xs truncate text-xs text-slate-500">
                  {{ event.catatan }}
                </span>
              </td>
              <td class="px-4 py-3">
                <Lencana v-if="event.cakupan === cakupan_semua_unit" warna="navy" :titik="false">
                  Semua Unit
                </Lencana>
                <span v-else class="text-xs text-slate-600">
                  {{ event.unit_kerja.map((u) => u.kode).join(', ') || '—' }}
                </span>
              </td>
              <td class="px-4 py-3 text-slate-600">
                <span class="flex items-center gap-1.5">
                  <Ikon nama="kalender" ukuran="h-3.5 w-3.5" class="text-slate-400" />
                  {{ tanggalPanjang(event.tanggal) }}
                </span>
                <span class="mt-0.5 flex items-center gap-1.5 font-display text-xs tabular-nums text-slate-500">
                  <Ikon nama="jam" ukuran="h-3.5 w-3.5" class="text-slate-400" />
                  {{ event.jam_mulai }}
                </span>
              </td>
              <td class="px-4 py-3 text-right font-display tabular-nums text-slate-600">
                {{ event.toleransi_menit }} mnt
              </td>
              <td class="px-4 py-3 text-right font-display tabular-nums text-slate-600">
                {{ event.jumlah_kiosk }}
              </td>
              <td class="px-4 py-3 text-right font-display font-medium tabular-nums text-emerald-700">
                {{ event.jumlah_absensi }}
              </td>
              <td class="px-4 py-3">
                <Lencana
                  :warna="event.status === 'aktif' ? 'emerald' : 'slate'"
                  :denyut="event.status === 'aktif'"
                >
                  {{ event.status_label }}
                </Lencana>
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-right">
                <TombolAksi ikon="detail" @click="bukaDetail(event)">Detail</TombolAksi>
                <TombolAksi v-if="event.status === 'aktif'" ikon="ubah" warna="teal" @click="bukaUbah(event)">
                  Ubah
                </TombolAksi>
                <TombolAksi v-if="event.status === 'aktif'" ikon="cek" warna="navy" @click="tutup(event)">
                  Tutup
                </TombolAksi>
                <TombolAksi v-if="event.dapat_dihapus" ikon="hapus" warna="amber" @click="hapus(event)">
                  Hapus
                </TombolAksi>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <KeadaanKosong
        v-if="daftar.data.length === 0"
        ikon="absen"
        :judul="adaFilter ? 'Tidak ada event yang cocok' : 'Belum ada event'"
        :keterangan="
          adaFilter
            ? 'Coba longgarkan penyaringan, atau bersihkan seluruhnya.'
            : 'Mulai dengan menekan “Buat Event” di kanan atas.'
        "
      />

      <Paginasi :data="daftar" />
    </div>

    <Modal :terbuka="detailTerbuka" judul="Detail Event" @tutup="detailTerbuka = false">
      <p v-if="detailGagal" class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
        {{ detailGagal }}
      </p>

      <p v-else-if="!detail" class="flex items-center gap-2 text-sm text-slate-500">
        <span class="h-3 w-3 animate-spin rounded-full border-2 border-teal-600 border-t-transparent"></span>
        Memuat rincian…
      </p>

      <div v-else class="space-y-5">
        <div class="rounded-lg bg-slate-50 px-4 py-3">
          <p class="font-medium text-navy-700">{{ detail.nama }}</p>
          <p class="mt-0.5 text-xs text-slate-500">
            {{ tanggalPanjang(detail.tanggal) }} · {{ detail.jam_mulai }} · {{ detail.cakupan_label }}
          </p>
        </div>

        <div class="grid grid-cols-3 gap-3 text-center">
          <div class="rounded-lg border border-slate-200 px-3 py-3">
            <p class="font-display text-xl font-semibold tabular-nums text-navy-700">
              {{ detail.kiosk.length }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500">Perangkat terhubung</p>
          </div>
          <div class="rounded-lg border border-slate-200 px-3 py-3">
            <p class="font-display text-xl font-semibold tabular-nums text-emerald-700">
              {{ detail.jumlah_absensi }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500">Absen masuk</p>
          </div>
          <div class="rounded-lg border border-slate-200 px-3 py-3">
            <p
              class="font-display text-sm font-semibold"
              :class="detail.status === 'aktif' ? 'text-emerald-700' : 'text-slate-500'"
            >
              {{ detail.status_label }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500">
              {{ detail.ditutup_pada ? waktuSingkat(detail.ditutup_pada) : 'Entry dibuka' }}
            </p>
          </div>
        </div>

        <div>
          <p class="mb-2 text-xs font-medium uppercase tracking-wider text-slate-500">
            Perangkat Absen Terhubung
          </p>

          <KeadaanKosong
            v-if="detail.kiosk.length === 0"
            ikon="perangkat"
            judul="Belum ada perangkat"
            keterangan="Perangkat tercatat begitu layarnya dibuka pada event ini."
          />

          <table v-else class="min-w-full text-sm">
            <thead class="text-xs uppercase tracking-wider text-slate-500">
              <tr>
                <th scope="col" class="py-2 text-left font-medium">Titik</th>
                <th scope="col" class="py-2 text-left font-medium">Alamat IP</th>
                <th scope="col" class="py-2 text-right font-medium">Terakhir Aktif</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="kiosk in detail.kiosk" :key="kiosk.id">
                <td class="py-2 text-slate-700">
                  {{ kiosk.nama_titik }}
                  <span class="ml-1 font-display text-xs tabular-nums text-slate-400">
                    {{ kiosk.unit_kerja_kode }}
                  </span>
                </td>
                <td class="py-2 font-display tabular-nums text-slate-600">{{ kiosk.ip_address ?? '—' }}</td>
                <td class="py-2 text-right text-xs text-slate-500">
                  {{ waktuSingkat(kiosk.terakhir_aktif_pada) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <template #aksi>
        <button
          type="button"
          class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
          @click="detailTerbuka = false"
        >
          Tutup
        </button>
      </template>
    </Modal>

    <Modal :terbuka="formTerbuka" :judul="judulForm" @tutup="tutupForm">
      <div class="space-y-4">
        <div>
          <label for="nama" class="block text-sm font-medium text-slate-700">Nama Event</label>
          <input
            id="nama"
            v-model="form.nama"
            type="text"
            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
            placeholder="mis. Apel Pagi Senin"
          />
          <p v-if="form.errors.nama" class="mt-1 text-xs text-amber-700">{{ form.errors.nama }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
          <div>
            <label for="tanggal" class="block text-sm font-medium text-slate-700">Tanggal</label>
            <input
              id="tanggal"
              v-model="form.tanggal"
              type="date"
              class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
            />
            <p v-if="form.errors.tanggal" class="mt-1 text-xs text-amber-700">{{ form.errors.tanggal }}</p>
          </div>
          <div>
            <label for="jam_mulai" class="block text-sm font-medium text-slate-700">Jam Mulai</label>
            <input
              id="jam_mulai"
              v-model="form.jam_mulai"
              type="time"
              class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
            />
            <p v-if="form.errors.jam_mulai" class="mt-1 text-xs text-amber-700">{{ form.errors.jam_mulai }}</p>
          </div>
          <div>
            <label for="toleransi" class="block text-sm font-medium text-slate-700">Toleransi</label>
            <div class="mt-1 flex items-center gap-2">
              <input
                id="toleransi"
                v-model.number="form.toleransi_menit"
                type="number"
                min="0"
                class="block w-full rounded-md border-slate-300 font-display tabular-nums shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
              />
              <span class="text-sm text-slate-500">mnt</span>
            </div>
            <p v-if="form.errors.toleransi_menit" class="mt-1 text-xs text-amber-700">
              {{ form.errors.toleransi_menit }}
            </p>
          </div>
        </div>

        <div>
          <span class="block text-sm font-medium text-slate-700">Cakupan Unit Kerja</span>

          <div v-if="boleh_semua_unit" class="mt-2 flex gap-4">
            <label class="flex items-center gap-2 text-sm text-slate-700">
              <input v-model="form.cakupan" type="radio" value="unit" class="text-teal-600 focus:ring-teal-500" />
              Unit terpilih
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-700">
              <input
                v-model="form.cakupan"
                type="radio"
                :value="cakupan_semua_unit"
                class="text-teal-600 focus:ring-teal-500"
              />
              Semua unit
            </label>
          </div>

          <div
            v-if="!semuaUnit"
            class="mt-2 max-h-48 space-y-1.5 overflow-y-auto rounded-md border border-slate-200 p-3"
          >
            <label
              v-for="unit in unit_kerja"
              :key="unit.id"
              class="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-sm text-slate-700 transition hover:bg-slate-50"
            >
              <input
                v-model="form.unit_kerja_id"
                type="checkbox"
                :value="unit.id"
                class="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
              />
              <span class="font-display text-xs tabular-nums text-slate-500">{{ unit.kode }}</span>
              <span>{{ unit.nama }}</span>
            </label>
            <p v-if="unit_kerja.length === 0" class="px-1.5 py-1 text-xs text-slate-500">
              Belum ada unit kerja aktif yang dapat dipilih.
            </p>
          </div>

          <p v-else class="mt-2 flex items-start gap-2 rounded-md bg-navy-50 px-3 py-2 text-xs text-navy-700">
            <Ikon nama="info" ukuran="h-4 w-4" class="mt-px shrink-0" />
            Event berlaku untuk seluruh unit kerja, termasuk unit yang ditambahkan setelah event ini dibuat.
          </p>

          <p v-if="form.errors.cakupan" class="mt-1 text-xs text-amber-700">{{ form.errors.cakupan }}</p>
          <p v-if="form.errors.unit_kerja_id" class="mt-1 text-xs text-amber-700">
            {{ form.errors.unit_kerja_id }}
          </p>
        </div>

        <div>
          <label for="catatan" class="block text-sm font-medium text-slate-700">Catatan (opsional)</label>
          <textarea
            id="catatan"
            v-model="form.catatan"
            rows="2"
            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
          ></textarea>
          <p v-if="form.errors.catatan" class="mt-1 text-xs text-amber-700">{{ form.errors.catatan }}</p>
        </div>
      </div>

      <template #aksi>
        <button
          type="button"
          class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
          @click="tutupForm"
        >
          Batal
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-teal-700 active:scale-95 disabled:opacity-50"
          :disabled="form.processing"
          @click="simpan"
        >
          <Ikon v-if="!form.processing" nama="cek" ukuran="h-4 w-4" />
          {{ form.processing ? 'Menyimpan…' : 'Simpan Event' }}
        </button>
      </template>
    </Modal>
  </AdminLayout>
</template>
