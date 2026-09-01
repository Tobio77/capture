<script setup>
import { computed, ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Modal from '@/Components/Modal.vue'

/**
 * Kelola akun admin (FR-USR-01).
 */

const props = defineProps({
  daftar: { type: Array, required: true },
  unit_kerja: { type: Array, required: true },
  peran: { type: Array, required: true },
})

const page = usePage()
const sayaId = computed(() => page.props.auth.pengguna.id)
const sandiSementara = computed(() => page.props.flash.sandi_sementara ?? null)

const modalTerbuka = ref(false)
const sedangDiubah = ref(null)

const form = useForm({
  nama: '',
  email: '',
  role: 'admin_upt',
  unit_kerja_id: '',
})

const perluUnit = computed(
  () => props.peran.find((p) => p.nilai === form.role)?.lintas_unit === false,
)

const judulForm = computed(() => (sedangDiubah.value ? 'Ubah Akun Admin' : 'Tambah Akun Admin'))

function bukaTambah() {
  sedangDiubah.value = null
  form.reset()
  form.clearErrors()
  modalTerbuka.value = true
}

function bukaUbah(pengguna) {
  sedangDiubah.value = pengguna
  form.clearErrors()
  form.nama = pengguna.nama
  form.email = pengguna.email
  form.role = pengguna.role
  form.unit_kerja_id = pengguna.unit_kerja?.id ?? ''
  modalTerbuka.value = true
}

function tutup() {
  modalTerbuka.value = false
  sedangDiubah.value = null
}

function simpan() {
  const opsi = { preserveScroll: true, onSuccess: () => tutup() }

  if (sedangDiubah.value) {
    form.patch(`/admin/pengguna/${sedangDiubah.value.id}`, opsi)
  } else {
    form.post('/admin/pengguna', opsi)
  }
}

function ubahStatus(pengguna) {
  const aksi = pengguna.aktif ? 'menonaktifkan' : 'mengaktifkan'
  const peringatan = pengguna.aktif
    ? '\n\nAkun yang dinonaktifkan langsung kehilangan akses, termasuk sesi yang sedang berjalan.'
    : ''

  if (!window.confirm(`Yakin ${aksi} akun ${pengguna.nama}?${peringatan}`)) return

  router.patch(
    `/admin/pengguna/${pengguna.id}/status`,
    { aktif: !pengguna.aktif },
    { preserveScroll: true },
  )
}

function resetSandi(pengguna) {
  const pesan =
    `Terbitkan kata sandi baru untuk ${pengguna.nama}?\n\n` +
    'Kata sandi lama langsung tidak berlaku dan sesi yang sedang berjalan ikut gugur.'

  if (!window.confirm(pesan)) return

  router.post(`/admin/pengguna/${pengguna.id}/reset-sandi`, {}, { preserveScroll: true })
}

function salin(teks) {
  navigator.clipboard?.writeText(teks)
}
</script>

<template>
  <AdminLayout
    judul="Kelola User / Role"
    deskripsi="Akun admin beserta cakupan unit kerjanya. Akun tidak pernah dihapus, hanya dinonaktifkan."
  >
    <template #aksi>
      <button
        type="button"
        class="rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700"
        @click="bukaTambah"
      >
        Tambah Akun Admin
      </button>
    </template>

    <!-- Kata sandi sementara hanya tampil sekali, tepat setelah diterbitkan. -->
    <div
      v-if="sandiSementara"
      class="mb-5 rounded-lg border border-amber-300 bg-amber-50 px-5 py-4"
    >
      <p class="text-sm font-medium text-amber-900">Kata sandi sementara</p>
      <p class="mt-1 text-xs text-amber-800">
        Catat sekarang — kata sandi ini tidak dapat ditampilkan lagi setelah halaman berpindah.
      </p>
      <div class="mt-3 flex flex-wrap items-center gap-3">
        <code class="rounded bg-white px-3 py-1.5 font-display text-sm text-navy-700">
          {{ sandiSementara.email }}
        </code>
        <code class="rounded bg-white px-3 py-1.5 font-display text-sm font-semibold text-navy-700">
          {{ sandiSementara.sandi }}
        </code>
        <button
          type="button"
          class="rounded-md border border-amber-400 px-3 py-1.5 text-xs font-medium text-amber-900 transition hover:bg-amber-100"
          @click="salin(sandiSementara.sandi)"
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
              <th scope="col" class="px-4 py-3 text-left font-medium">Nama</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Alamat Surel</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Peran</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Cakupan</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Status</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="item in daftar" :key="item.id" :class="{ 'bg-slate-50/60': !item.aktif }">
              <td class="px-4 py-3 font-medium text-navy-700">
                {{ item.nama }}
                <span v-if="item.id === sayaId" class="ml-1.5 text-xs font-normal text-slate-500">(Anda)</span>
              </td>
              <td class="px-4 py-3 text-slate-600">{{ item.email }}</td>
              <td class="px-4 py-3 text-slate-600">{{ item.role_label }}</td>
              <td class="px-4 py-3 text-slate-600">
                {{ item.unit_kerja?.nama ?? 'Seluruh unit kerja' }}
              </td>
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
                  class="rounded-md px-2.5 py-1.5 text-xs font-medium text-teal-700 transition hover:bg-teal-50"
                  @click="bukaUbah(item)"
                >
                  Ubah
                </button>
                <button
                  type="button"
                  class="rounded-md px-2.5 py-1.5 text-xs font-medium text-navy-700 transition hover:bg-navy-50"
                  @click="resetSandi(item)"
                >
                  Reset Sandi
                </button>
                <button
                  v-if="item.id !== sayaId"
                  type="button"
                  class="rounded-md px-2.5 py-1.5 text-xs font-medium transition"
                  :class="item.aktif ? 'text-amber-700 hover:bg-amber-50' : 'text-emerald-700 hover:bg-emerald-50'"
                  @click="ubahStatus(item)"
                >
                  {{ item.aktif ? 'Nonaktifkan' : 'Aktifkan' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Modal :terbuka="modalTerbuka" :judul="judulForm" @tutup="tutup">
      <div class="space-y-4">
        <div>
          <label for="nama" class="block text-sm font-medium text-slate-700">Nama</label>
          <input
            id="nama"
            v-model="form.nama"
            type="text"
            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
          />
          <p v-if="form.errors.nama" class="mt-1.5 text-xs text-amber-700">{{ form.errors.nama }}</p>
        </div>

        <div>
          <label for="email" class="block text-sm font-medium text-slate-700">Alamat Surel</label>
          <input
            id="email"
            v-model="form.email"
            type="email"
            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
          />
          <p v-if="form.errors.email" class="mt-1.5 text-xs text-amber-700">{{ form.errors.email }}</p>
        </div>

        <div>
          <label for="role" class="block text-sm font-medium text-slate-700">Peran</label>
          <select
            id="role"
            v-model="form.role"
            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
          >
            <option v-for="item in peran" :key="item.nilai" :value="item.nilai">{{ item.label }}</option>
          </select>
          <p v-if="form.errors.role" class="mt-1.5 text-xs text-amber-700">{{ form.errors.role }}</p>
        </div>

        <div v-if="perluUnit">
          <label for="unit" class="block text-sm font-medium text-slate-700">Unit Kerja</label>
          <select
            id="unit"
            v-model="form.unit_kerja_id"
            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
          >
            <option value="" disabled>Pilih unit kerja…</option>
            <option v-for="unit in unit_kerja" :key="unit.id" :value="unit.id">{{ unit.nama }}</option>
          </select>
          <p class="mt-1.5 text-xs text-slate-500">
            Cakupannya meliputi unit ini beserta seluruh seksi/subbag di bawahnya.
          </p>
          <p v-if="form.errors.unit_kerja_id" class="mt-1.5 text-xs text-amber-700">
            {{ form.errors.unit_kerja_id }}
          </p>
        </div>

        <p v-else class="rounded-md bg-navy-50 px-3 py-2 text-xs text-navy-700">
          Peran ini mencakup seluruh unit kerja, sehingga tidak terikat pada satu unit.
        </p>

        <p v-if="!sedangDiubah" class="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
          Kata sandi sementara diterbitkan otomatis dan ditampilkan sekali setelah akun tersimpan.
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
          {{ form.processing ? 'Menyimpan…' : 'Simpan Akun' }}
        </button>
      </template>
    </Modal>
  </AdminLayout>
</template>
