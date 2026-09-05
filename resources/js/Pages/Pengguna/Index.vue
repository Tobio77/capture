<script setup>
import { computed, reactive, ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Modal from '@/Components/Modal.vue'
import Ikon from '@/Components/Ikon.vue'
import Paginasi from '@/Components/UI/Paginasi.vue'
import KolomCari from '@/Components/UI/KolomCari.vue'
import Lencana from '@/Components/UI/Lencana.vue'
import KeadaanKosong from '@/Components/UI/KeadaanKosong.vue'
import TombolAksi from '@/Components/UI/TombolAksi.vue'
import Pilihan from '@/Components/UI/Pilihan.vue'

/**
 * Kelola akun admin (FR-USR-01).
 */

const props = defineProps({
  daftar: { type: Object, required: true },
  filter: { type: Object, required: true },
  unit_kerja: { type: Array, required: true },
  peran: { type: Array, required: true },
})

const page = usePage()
const sayaId = computed(() => page.props.auth.pengguna.id)
const sandiSementara = computed(() => page.props.flash.sandi_sementara ?? null)

/* ------------------------------------------------------------------ filter */

const filter = reactive({ ...props.filter })

const opsiPeran = computed(() =>
  props.peran.map((p) => ({ nilai: p.nilai, label: p.label })),
)

const opsiPeranFilter = computed(() => [
  { nilai: '', label: 'Semua peran' },
  ...opsiPeran.value,
])

const opsiUnitForm = computed(() =>
  props.unit_kerja.map((u) => ({ nilai: u.id, label: u.nama, keterangan: u.kode })),
)

const kueri = computed(() =>
  Object.fromEntries(Object.entries(filter).filter(([, nilai]) => nilai !== '' && nilai !== null)),
)

const adaPenyaring = computed(() => Object.keys(kueri.value).length > 0)

function terapkan() {
  router.get('/admin/pengguna', kueri.value, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

function bersihkan() {
  filter.cari = ''
  filter.role = ''
  filter.status = ''
  terapkan()
}

/* -------------------------------------------------------------------- form */

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

const tersalin = ref(false)

function salin(teks) {
  navigator.clipboard?.writeText(teks)
  tersalin.value = true
  setTimeout(() => (tersalin.value = false), 2000)
}

const warnaPeran = (role) => (role === 'superadmin' ? 'navy' : role === 'admin_dinas' ? 'teal' : 'slate')
</script>

<template>
  <AdminLayout
    judul="Kelola User / Role"
    deskripsi="Akun admin beserta cakupan unit kerjanya. Akun tidak pernah dihapus, hanya dinonaktifkan."
  >
    <template #aksi>
      <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded-md bg-aksen px-4 py-2 text-sm font-semibold text-white bayang transition hover:bg-aksen-kuat active:scale-95"
        @click="bukaTambah"
      >
        <Ikon nama="tambah" ukuran="h-4 w-4" /> Tambah Akun Admin
      </button>
    </template>

    <!-- Kata sandi sementara hanya tampil sekali, tepat setelah diterbitkan. -->
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="-translate-y-2 opacity-0"
      enter-to-class="translate-y-0 opacity-100"
    >
      <div
        v-if="sandiSementara"
        class="mb-5 rounded-lg border border-peringatan bg-peringatan-lembut px-5 py-4"
      >
        <p class="flex items-center gap-1.5 text-sm font-medium text-peringatan-teks">
          <Ikon nama="kunci" ukuran="h-4 w-4" /> Kata sandi sementara
        </p>
        <p class="mt-1 text-xs text-peringatan-teks">
          Catat sekarang — kata sandi ini tidak dapat ditampilkan lagi setelah halaman berpindah.
        </p>
        <div class="mt-3 flex flex-wrap items-center gap-3">
          <code class="rounded bg-permukaan px-3 py-1.5 font-display text-sm text-utama">
            {{ sandiSementara.email }}
          </code>
          <code
            class="rounded bg-permukaan px-3 py-1.5 font-display text-sm font-semibold text-utama"
          >
            {{ sandiSementara.sandi }}
          </code>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border border-peringatan px-3 py-1.5 text-xs font-medium text-peringatan-teks transition hover:bg-peringatan-lembut active:scale-95"
            @click="salin(sandiSementara.sandi)"
          >
            <Ikon :nama="tersalin ? 'cek' : 'detail'" ukuran="h-3.5 w-3.5" />
            {{ tersalin ? 'Tersalin' : 'Salin' }}
          </button>
        </div>
      </div>
    </Transition>

    <div class="mb-5 panel p-4">
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <span class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-redup">
            Cari Akun
          </span>
          <KolomCari v-model="filter.cari" placeholder="Nama atau surel…" @cari="terapkan" />
        </div>
        <div>
          <label
            for="peran"
            class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-redup"
          >
            Peran
          </label>
          <Pilihan
            id="peran"
            v-model="filter.role"
            :opsi="opsiPeranFilter"
            @update:model-value="terapkan"
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
              { nilai: '', label: 'Semua status' },
              { nilai: 'aktif', label: 'Aktif' },
              { nilai: 'nonaktif', label: 'Nonaktif' },
            ]"
            @update:model-value="terapkan"
          />
        </div>
        <div class="flex items-end">
          <button
            v-if="adaPenyaring"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border border-garis px-3 py-2 text-sm font-medium text-sekunder transition hover:bg-permukaan-hover active:scale-95"
            @click="bersihkan"
          >
            <Ikon nama="tutup" ukuran="h-4 w-4" /> Bersihkan filter
          </button>
        </div>
      </div>
    </div>

    <div class="overflow-hidden panel">
      <div class="tabel-gulir tabel-aksi gulir-halus">
        <table class="min-w-full divide-y divide-garis text-sm">
          <thead
            class="border-b border-garis bg-permukaan-2 text-xs uppercase tracking-wider text-redup"
          >
            <tr>
              <th scope="col" class="px-4 py-3 text-left font-medium">Nama</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Alamat Surel</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Peran</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Cakupan</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Status</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-garis">
            <tr
              v-for="item in daftar.data"
              :key="item.id"
              class="transition-colors hover:bg-permukaan-hover"
              :class="{ 'baris-redup bg-permukaan-2/60': !item.aktif }"
            >
              <td class="px-4 py-3 font-medium text-utama">
                {{ item.nama }}
                <span v-if="item.id === sayaId" class="ml-1.5 text-xs font-normal text-redup">
                  (Anda)
                </span>
              </td>
              <td class="px-4 py-3 text-sekunder">{{ item.email }}</td>
              <td class="px-4 py-3">
                <Lencana :warna="warnaPeran(item.role)" :titik="false">
                  {{ item.role_label }}
                </Lencana>
              </td>
              <td
                class="max-w-[14rem] truncate px-4 py-3 text-sekunder"
                :title="item.unit_kerja?.nama ?? 'Seluruh unit kerja'"
              >
                {{ item.unit_kerja?.nama ?? 'Seluruh unit kerja' }}
              </td>
              <td class="px-4 py-3">
                <Lencana :warna="item.aktif ? 'emerald' : 'slate'">
                  {{ item.aktif ? 'Aktif' : 'Nonaktif' }}
                </Lencana>
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-right">
                <TombolAksi ikon="ubah" warna="teal" @click="bukaUbah(item)">Ubah</TombolAksi>
                <TombolAksi ikon="kunci" warna="navy" @click="resetSandi(item)">
                  Reset Sandi
                </TombolAksi>
                <TombolAksi
                  v-if="item.id !== sayaId"
                  :ikon="item.aktif ? 'cabut' : 'cek'"
                  :warna="item.aktif ? 'amber' : 'emerald'"
                  @click="ubahStatus(item)"
                >
                  {{ item.aktif ? 'Nonaktifkan' : 'Aktifkan' }}
                </TombolAksi>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <KeadaanKosong
        v-if="daftar.data.length === 0"
        ikon="pengguna"
        :judul="adaPenyaring ? 'Tidak ada akun yang cocok' : 'Belum ada akun admin'"
        :keterangan="
          adaPenyaring
            ? 'Ubah kata kunci, peran, atau status pada penyaring di atas.'
            : 'Tambahkan akun admin pertama melalui tombol di kanan atas.'
        "
      >
        <button
          v-if="adaPenyaring"
          type="button"
          class="inline-flex items-center gap-1.5 rounded-md border border-garis px-3 py-1.5 text-xs font-medium text-sekunder transition hover:bg-permukaan-hover"
          @click="bersihkan"
        >
          <Ikon nama="tutup" ukuran="h-3.5 w-3.5" /> Bersihkan filter
        </button>
      </KeadaanKosong>

      <Paginasi :data="daftar" />
    </div>

    <Modal :terbuka="modalTerbuka" :judul="judulForm" @tutup="tutup">
      <div class="space-y-4">
        <div>
          <label for="nama" class="block text-sm font-medium text-utama">Nama</label>
          <input
            id="nama"
            v-model="form.nama"
            type="text"
            class="mt-1 block w-full rounded-md border-garis bayang transition focus:border-aksen focus:ring-aksen sm:text-sm"
          />
          <p v-if="form.errors.nama" class="mt-1.5 text-xs text-peringatan-teks">{{ form.errors.nama }}</p>
        </div>

        <div>
          <label for="email" class="block text-sm font-medium text-utama">Alamat Surel</label>
          <input
            id="email"
            v-model="form.email"
            type="email"
            class="mt-1 block w-full rounded-md border-garis bayang transition focus:border-aksen focus:ring-aksen sm:text-sm"
          />
          <p v-if="form.errors.email" class="mt-1.5 text-xs text-peringatan-teks">
            {{ form.errors.email }}
          </p>
        </div>

        <div>
          <label for="role" class="block text-sm font-medium text-utama">Peran</label>
          <Pilihan id="role" v-model="form.role" :opsi="opsiPeran" class="mt-1" />
          <p v-if="form.errors.role" class="mt-1.5 text-xs text-peringatan-teks">{{ form.errors.role }}</p>
        </div>

        <div v-if="perluUnit">
          <label for="unit" class="block text-sm font-medium text-utama">Unit Kerja</label>
          <Pilihan
            id="unit"
            v-model="form.unit_kerja_id"
            :opsi="opsiUnitForm"
            placeholder="Pilih unit kerja…"
            class="mt-1"
          />
          <p class="mt-1.5 text-xs text-redup">
            Cakupannya meliputi unit ini beserta seluruh seksi/subbag di bawahnya.
          </p>
          <p v-if="form.errors.unit_kerja_id" class="mt-1.5 text-xs text-peringatan-teks">
            {{ form.errors.unit_kerja_id }}
          </p>
        </div>

        <p
          v-else
          class="flex items-start gap-2 rounded-md bg-info-lembut px-3 py-2 text-xs text-utama"
        >
          <Ikon nama="info" ukuran="h-4 w-4 shrink-0" />
          Peran ini mencakup seluruh unit kerja, sehingga tidak terikat pada satu unit.
        </p>

        <p
          v-if="!sedangDiubah"
          class="flex items-start gap-2 rounded-md bg-permukaan-2 px-3 py-2 text-xs text-sekunder"
        >
          <Ikon nama="kunci" ukuran="h-4 w-4 shrink-0" />
          Kata sandi sementara diterbitkan otomatis dan ditampilkan sekali setelah akun tersimpan.
        </p>
      </div>

      <template #aksi>
        <button
          type="button"
          class="rounded-lg px-4 py-2 text-sm font-medium text-sekunder transition hover:bg-permukaan-hover active:scale-95"
          @click="tutup"
        >
          Batal
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-lg bg-aksen px-4 py-2 text-sm font-medium text-white transition hover:bg-aksen-kuat active:scale-95 disabled:opacity-50"
          :disabled="form.processing"
          @click="simpan"
        >
          <Ikon v-if="form.processing" nama="segarkan" ukuran="h-4 w-4 animate-spin" />
          {{ form.processing ? 'Menyimpan…' : 'Simpan Akun' }}
        </button>
      </template>
    </Modal>
  </AdminLayout>
</template>
