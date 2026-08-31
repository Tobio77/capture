<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Modal from '@/Components/Modal.vue'

const props = defineProps({
  daftar: { type: Array, required: true },
  dapat_mengubah: { type: Boolean, required: true },
})

const modalTerbuka = ref(false)
const sedangDiubah = ref(null)

const form = useForm({
  kode: '',
  nama: '',
})

const bukaTambah = () => {
  sedangDiubah.value = null
  form.reset()
  form.clearErrors()
  modalTerbuka.value = true
}

const bukaUbah = (unit) => {
  sedangDiubah.value = unit
  form.clearErrors()
  form.kode = unit.kode
  form.nama = unit.nama
  modalTerbuka.value = true
}

const tutup = () => {
  modalTerbuka.value = false
}

const simpan = () => {
  const opsi = { preserveScroll: true, onSuccess: tutup }

  if (sedangDiubah.value) {
    form.patch(`/kelola-absen/unit-kerja/${sedangDiubah.value.id}`, opsi)
  } else {
    form.post('/kelola-absen/unit-kerja', opsi)
  }
}

const ubahStatus = (unit) => {
  const aksi = unit.aktif ? 'menonaktifkan' : 'mengaktifkan'
  const peringatan =
    unit.aktif && (unit.jumlah_pegawai > 0 || unit.jumlah_kiosk > 0)
      ? `\n\nUnit ini masih menaungi ${unit.jumlah_pegawai} pegawai dan ${unit.jumlah_kiosk} kiosk. Data lama tetap tersimpan.`
      : ''

  if (window.confirm(`Yakin ${aksi} unit kerja ${unit.kode}?${peringatan}`)) {
    router.patch(
      `/kelola-absen/unit-kerja/${unit.id}/status`,
      { aktif: !unit.aktif },
      { preserveScroll: true },
    )
  }
}
</script>

<template>
  <AdminLayout
    judul="Setting Unit Kerja"
    deskripsi="Unit kerja yang berpartisipasi dalam SI-ABSEN beserta jumlah pegawai dan kiosk terdaftar."
  >
    <template v-if="dapat_mengubah" #aksi>
      <button
        type="button"
        class="rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700"
        @click="bukaTambah"
      >
        Tambah Unit Kerja
      </button>
    </template>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
              <th scope="col" class="px-6 py-3 font-medium">Kode</th>
              <th scope="col" class="px-6 py-3 font-medium">Nama Unit Kerja</th>
              <th scope="col" class="px-6 py-3 text-right font-medium">Pegawai</th>
              <th scope="col" class="px-6 py-3 text-right font-medium">Kiosk</th>
              <th scope="col" class="px-6 py-3 font-medium">Status</th>
              <th v-if="dapat_mengubah" scope="col" class="px-6 py-3 text-right font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="unit in daftar" :key="unit.id" :class="{ 'bg-slate-50/60': !unit.aktif }">
              <td class="px-6 py-3 font-display font-medium text-navy-700">{{ unit.kode }}</td>
              <td class="px-6 py-3 text-slate-700">{{ unit.nama }}</td>
              <td class="px-6 py-3 text-right font-display tabular-nums text-slate-600">{{ unit.jumlah_pegawai }}</td>
              <td class="px-6 py-3 text-right font-display tabular-nums text-slate-600">{{ unit.jumlah_kiosk }}</td>
              <td class="px-6 py-3">
                <span
                  class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
                  :class="unit.aktif ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                >
                  <span class="h-1.5 w-1.5 rounded-full" :class="unit.aktif ? 'bg-emerald-600' : 'bg-slate-400'"></span>
                  {{ unit.aktif ? 'Aktif' : 'Nonaktif' }}
                </span>
              </td>
              <td v-if="dapat_mengubah" class="whitespace-nowrap px-6 py-3 text-right">
                <button
                  type="button"
                  class="rounded px-2 py-1 text-sm font-medium text-teal-700 transition hover:bg-teal-50"
                  @click="bukaUbah(unit)"
                >
                  Ubah
                </button>
                <button
                  type="button"
                  class="ml-1 rounded px-2 py-1 text-sm font-medium transition"
                  :class="unit.aktif ? 'text-amber-700 hover:bg-amber-50' : 'text-emerald-700 hover:bg-emerald-50'"
                  @click="ubahStatus(unit)"
                >
                  {{ unit.aktif ? 'Nonaktifkan' : 'Aktifkan' }}
                </button>
              </td>
            </tr>
            <tr v-if="daftar.length === 0">
              <td :colspan="dapat_mengubah ? 6 : 5" class="px-6 py-10 text-center text-sm text-slate-500">
                Belum ada unit kerja terdaftar.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <p v-if="!dapat_mengubah" class="mt-4 text-sm text-slate-500">
      Peran Anda dapat melihat daftar unit kerja, tetapi perubahannya dilakukan oleh Superadmin atau Admin Dinas.
    </p>

    <Modal
      :terbuka="modalTerbuka"
      :judul="sedangDiubah ? `Ubah Unit Kerja ${sedangDiubah.kode}` : 'Tambah Unit Kerja'"
      @tutup="tutup"
    >
      <form id="form-unit-kerja" class="space-y-5" @submit.prevent="simpan">
        <div>
          <label for="kode" class="block text-sm font-medium text-slate-700">Kode Unit Kerja</label>
          <input
            id="kode"
            v-model="form.kode"
            type="text"
            required
            maxlength="20"
            placeholder="BLK-SBY"
            class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm uppercase shadow-sm focus:border-teal-600 focus:outline-none focus:ring-1 focus:ring-teal-600"
          />
          <p v-if="form.errors.kode" class="mt-1.5 text-sm text-amber-700">{{ form.errors.kode }}</p>
          <p v-else class="mt-1.5 text-xs text-slate-500">Huruf, angka, dan tanda hubung. Contoh: BLK-SBY.</p>
        </div>

        <div>
          <label for="nama" class="block text-sm font-medium text-slate-700">Nama Unit Kerja</label>
          <input
            id="nama"
            v-model="form.nama"
            type="text"
            required
            maxlength="150"
            placeholder="UPT Balai Latihan Kerja Surabaya"
            class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-teal-600 focus:outline-none focus:ring-1 focus:ring-teal-600"
          />
          <p v-if="form.errors.nama" class="mt-1.5 text-sm text-amber-700">{{ form.errors.nama }}</p>
        </div>
      </form>

      <template #aksi>
        <button
          type="button"
          class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
          @click="tutup"
        >
          Batal
        </button>
        <button
          type="submit"
          form="form-unit-kerja"
          :disabled="form.processing"
          class="rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-60"
        >
          {{ form.processing ? 'Menyimpan…' : 'Simpan' }}
        </button>
      </template>
    </Modal>
  </AdminLayout>
</template>
