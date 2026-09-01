<script setup>
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Modal from '@/Components/Modal.vue'

/**
 * Daftar Event (FR-EVT-01, FR-EVT-02).
 *
 * Aksi Tutup dan Detail menyusul pada S11 dan S12.
 */

const props = defineProps({
  daftar: { type: Array, required: true },
  unit_kerja: { type: Array, required: true },
  nilai_awal: { type: Object, required: true },
  boleh_semua_unit: { type: Boolean, required: true },
  cakupan_semua_unit: { type: String, required: true },
})

const formTerbuka = ref(false)
const sedangDiubah = ref(null)

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

// Admin UPT yang hanya menaungi satu unit tidak perlu memilih apa pun.
const unitTunggal = computed(() => props.unit_kerja.length === 1)

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

function hapus(event) {
  const pesan = `Hapus event "${event.nama}" secara permanen? Tindakan ini tidak dapat dibatalkan.`

  if (window.confirm(pesan)) {
    form.delete(`/admin/kelola-absen/event/${event.id}`, { preserveScroll: true })
  }
}

function tanggalPanjang(iso) {
  return new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}
</script>

<template>
  <AdminLayout
    judul="Daftar Event"
    deskripsi="Event absensi beserta cakupan unit kerjanya. Kiosk hanya melayani tap untuk event yang masih aktif."
  >
    <div class="mb-4 flex justify-end">
      <button
        type="button"
        class="rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700"
        @click="bukaBuat"
      >
        Buat Event Baru
      </button>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
              <th scope="col" class="px-4 py-3 font-medium">Nama Event</th>
              <th scope="col" class="px-4 py-3 font-medium">Cakupan</th>
              <th scope="col" class="px-4 py-3 font-medium">Tanggal &amp; Jam</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Toleransi</th>
              <th scope="col" class="px-4 py-3 font-medium">Status</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="event in daftar" :key="event.id">
              <td class="px-4 py-3">
                <span class="font-medium text-navy-700">{{ event.nama }}</span>
                <span v-if="event.catatan" class="mt-0.5 block text-xs text-slate-500">{{ event.catatan }}</span>
              </td>
              <td class="px-4 py-3">
                <span
                  v-if="event.cakupan === cakupan_semua_unit"
                  class="rounded-full bg-navy-50 px-2.5 py-0.5 text-xs font-medium text-navy-700"
                >
                  Semua Unit
                </span>
                <span v-else class="text-xs text-slate-600">
                  {{ event.unit_kerja.map((u) => u.kode).join(', ') || '—' }}
                </span>
              </td>
              <td class="px-4 py-3 text-slate-600">
                {{ tanggalPanjang(event.tanggal) }}
                <span class="font-display tabular-nums text-slate-500">· {{ event.jam_mulai }}</span>
              </td>
              <td class="px-4 py-3 text-right font-display tabular-nums text-slate-600">
                {{ event.toleransi_menit }} mnt
              </td>
              <td class="px-4 py-3">
                <span
                  class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
                  :class="event.status === 'aktif' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'"
                >
                  <span
                    class="h-1.5 w-1.5 rounded-full"
                    :class="event.status === 'aktif' ? 'bg-emerald-600' : 'bg-slate-400'"
                  ></span>
                  {{ event.status_label }}
                </span>
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-right">
                <button
                  v-if="event.status === 'aktif'"
                  type="button"
                  class="rounded-md px-2.5 py-1.5 text-xs font-medium text-teal-700 transition hover:bg-teal-50"
                  @click="bukaUbah(event)"
                >
                  Ubah
                </button>
                <button
                  v-if="event.dapat_dihapus"
                  type="button"
                  class="rounded-md px-2.5 py-1.5 text-xs font-medium text-amber-700 transition hover:bg-amber-50"
                  @click="hapus(event)"
                >
                  Hapus
                </button>
                <span
                  v-else-if="event.status !== 'aktif'"
                  class="text-xs text-slate-400"
                  :title="`${event.jumlah_absensi} absensi tercatat`"
                >
                  Terkunci
                </span>
              </td>
            </tr>
            <tr v-if="daftar.length === 0">
              <td colspan="6" class="px-6 py-12 text-center text-sm text-slate-500">
                Belum ada event. Mulai dengan menekan “Buat Event Baru”.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

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

          <div v-if="!semuaUnit" class="mt-2 max-h-48 space-y-1.5 overflow-y-auto rounded-md border border-slate-200 p-3">
            <label
              v-for="unit in unit_kerja"
              :key="unit.id"
              class="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-sm text-slate-700 hover:bg-slate-50"
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

          <p v-else class="mt-2 rounded-md bg-navy-50 px-3 py-2 text-xs text-navy-700">
            Event berlaku untuk seluruh unit kerja, termasuk unit yang ditambahkan setelah event ini dibuat.
          </p>

          <p v-if="form.errors.cakupan" class="mt-1 text-xs text-amber-700">{{ form.errors.cakupan }}</p>
          <p v-if="form.errors.unit_kerja_id" class="mt-1 text-xs text-amber-700">{{ form.errors.unit_kerja_id }}</p>
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
          class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 disabled:opacity-50"
          :disabled="form.processing"
          @click="simpan"
        >
          {{ form.processing ? 'Menyimpan…' : 'Simpan Event' }}
        </button>
      </template>
    </Modal>
  </AdminLayout>
</template>
