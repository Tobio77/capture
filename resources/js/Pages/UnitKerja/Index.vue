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

const props = defineProps({
  daftar: { type: Object, required: true },
  filter: { type: Object, required: true },
  dapat_mengubah: { type: Boolean, required: true },
})

/* ------------------------------------------------------------------ filter */

const filter = reactive({ ...props.filter })

const kueri = computed(() =>
  Object.fromEntries(Object.entries(filter).filter(([, nilai]) => nilai !== '' && nilai !== null)),
)

const adaPenyaring = computed(() => Object.keys(kueri.value).length > 0)

function terapkan() {
  router.get('/kelola-absen/unit-kerja', kueri.value, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

function bersihkan() {
  filter.cari = ''
  filter.status = ''
  terapkan()
}

/* -------------------------------------------------------------------- form */

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
      ? `\n\nUnit ini masih menaungi ${unit.jumlah_pegawai} pegawai dan ${unit.jumlah_kiosk} perangkat absen. Data lama tetap tersimpan.`
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
    deskripsi="Unit kerja yang berpartisipasi dalam Capture beserta jumlah pegawai dan perangkat absen terdaftar."
  >
    <template v-if="dapat_mengubah" #aksi>
      <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 active:scale-95"
        @click="bukaTambah"
      >
        <Ikon nama="tambah" ukuran="h-4 w-4" /> Tambah Unit Kerja
      </button>
    </template>

    <div class="mb-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="lg:col-span-2">
          <span class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-slate-500">
            Cari Unit Kerja
          </span>
          <KolomCari v-model="filter.cari" placeholder="Kode atau nama unit…" @cari="terapkan" />
        </div>
        <div>
          <label
            for="status"
            class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-slate-500"
          >
            Status
          </label>
          <select
            id="status"
            v-model="filter.status"
            class="block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
            @change="terapkan"
          >
            <option value="">Semua status</option>
            <option value="aktif">Aktif</option>
            <option value="nonaktif">Nonaktif</option>
          </select>
        </div>
        <div class="flex items-end">
          <button
            v-if="adaPenyaring"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 active:scale-95"
            @click="bersihkan"
          >
            <Ikon nama="tutup" ukuran="h-4 w-4" /> Bersihkan filter
          </button>
        </div>
      </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead
            class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500"
          >
            <tr>
              <th scope="col" class="px-6 py-3 font-medium">Kode</th>
              <th scope="col" class="px-6 py-3 font-medium">Nama Unit Kerja</th>
              <th scope="col" class="px-6 py-3 text-right font-medium">Pegawai</th>
              <th scope="col" class="px-6 py-3 text-right font-medium">Perangkat</th>
              <th scope="col" class="px-6 py-3 font-medium">Status</th>
              <th v-if="dapat_mengubah" scope="col" class="px-6 py-3 text-right font-medium">
                Aksi
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr
              v-for="unit in daftar.data"
              :key="unit.id"
              class="transition-colors hover:bg-slate-50/70"
              :class="{ 'bg-slate-50/60': !unit.aktif }"
            >
              <td class="px-6 py-3 font-display font-medium text-navy-700">{{ unit.kode }}</td>
              <td class="px-6 py-3 text-slate-700">
                {{ unit.nama }}
                <span
                  v-if="unit.jumlah_unit_turunan > 0"
                  class="ml-2 whitespace-nowrap text-xs text-slate-500"
                >
                  membawahi {{ unit.jumlah_unit_turunan }} unit
                </span>
              </td>
              <td class="px-6 py-3 text-right font-display tabular-nums text-slate-600">
                {{ unit.jumlah_pegawai }}
              </td>
              <td class="px-6 py-3 text-right font-display tabular-nums text-slate-600">
                {{ unit.jumlah_kiosk }}
              </td>
              <td class="px-6 py-3">
                <Lencana :warna="unit.aktif ? 'emerald' : 'slate'">
                  {{ unit.aktif ? 'Aktif' : 'Nonaktif' }}
                </Lencana>
              </td>
              <td v-if="dapat_mengubah" class="whitespace-nowrap px-6 py-3 text-right">
                <TombolAksi ikon="ubah" warna="teal" @click="bukaUbah(unit)">Ubah</TombolAksi>
                <TombolAksi
                  :ikon="unit.aktif ? 'cabut' : 'cek'"
                  :warna="unit.aktif ? 'amber' : 'emerald'"
                  @click="ubahStatus(unit)"
                >
                  {{ unit.aktif ? 'Nonaktifkan' : 'Aktifkan' }}
                </TombolAksi>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <KeadaanKosong
        v-if="daftar.data.length === 0"
        ikon="pegawai"
        :judul="adaPenyaring ? 'Tidak ada unit yang cocok' : 'Belum ada unit kerja terdaftar'"
        :keterangan="
          adaPenyaring
            ? 'Ubah kata kunci atau status pada penyaring di atas.'
            : 'Unit kerja terisi otomatis saat sinkronisasi pegawai dari WORKA.'
        "
      >
        <button
          v-if="adaPenyaring"
          type="button"
          class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50"
          @click="bersihkan"
        >
          <Ikon nama="tutup" ukuran="h-3.5 w-3.5" /> Bersihkan filter
        </button>
      </KeadaanKosong>

      <Paginasi :data="daftar" />
    </div>

    <p v-if="!dapat_mengubah" class="mt-4 flex items-center gap-1.5 text-sm text-slate-500">
      <Ikon nama="info" ukuran="h-4 w-4" />
      Peran Anda dapat melihat daftar unit kerja, tetapi perubahannya dilakukan oleh Superadmin atau
      Admin Dinas.
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
            class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm uppercase shadow-sm transition focus:border-teal-600 focus:outline-none focus:ring-1 focus:ring-teal-600"
          />
          <p v-if="form.errors.kode" class="mt-1.5 text-sm text-amber-700">{{ form.errors.kode }}</p>
          <p v-else class="mt-1.5 text-xs text-slate-500">
            Huruf, angka, dan tanda hubung. Contoh: BLK-SBY.
          </p>
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
            class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm transition focus:border-teal-600 focus:outline-none focus:ring-1 focus:ring-teal-600"
          />
          <p v-if="form.errors.nama" class="mt-1.5 text-sm text-amber-700">{{ form.errors.nama }}</p>
        </div>
      </form>

      <template #aksi>
        <button
          type="button"
          class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 active:scale-95"
          @click="tutup"
        >
          Batal
        </button>
        <button
          type="submit"
          form="form-unit-kerja"
          :disabled="form.processing"
          class="inline-flex items-center gap-1.5 rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 active:scale-95 disabled:opacity-60"
        >
          <Ikon v-if="form.processing" nama="segarkan" ukuran="h-4 w-4 animate-spin" />
          {{ form.processing ? 'Menyimpan…' : 'Simpan' }}
        </button>
      </template>
    </Modal>
  </AdminLayout>
</template>
