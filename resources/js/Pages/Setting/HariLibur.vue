<script setup>
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import TombolProses from '@/Components/UI/TombolProses.vue'
import TombolAksi from '@/Components/UI/TombolAksi.vue'
import Lencana from '@/Components/UI/Lencana.vue'
import Pilihan from '@/Components/UI/Pilihan.vue'
import TanggalIsian from '@/Components/UI/TanggalIsian.vue'

/**
 * Hari Libur bertanggal (FR-SET-08) — dipisah dari `Setting/Absen.vue`
 * karena berdiri sendiri sepenuhnya: formulirnya sendiri (`formLibur`,
 * bukan `form` milik setting), menyimpan ke endpoint sendiri, dan gagal
 * dengan cara sendiri — menyatukannya di satu berkas besar dengan setting
 * lain hanya menambah panjang tanpa menambah keterkaitan.
 *
 * Menentukan dua hal sekaligus sejak revisi kalender kerja: Absen Umum
 * tertutup otomatis pada tanggal ini (kecuali admin membuka paksa lewat
 * override — lihat menu Absen Umum), dan tap yang tetap diterima lewat
 * override itu tercatat dengan penanda "di luar hari kerja" pada rekap.
 *
 * Diisi tangan, tidak ditarik dari layanan luar: jaringan dinas kerap
 * berada di belakang proxy yang menyaring keluar, dan kalender yang gagal
 * diam-diam membuat seluruh rekap salah tanpa ada yang tahu sebabnya.
 */

const props = defineProps({
  hari_libur: { type: Array, default: () => [] },
  unit_kerja_libur: { type: Array, default: () => [] },
  boleh_libur_nasional: { type: Boolean, default: false },
})

const formLibur = useForm({
  tanggal: '',
  keterangan: '',
  unit_kerja_id: null,
})

const opsiCakupanLibur = computed(() => [
  ...(props.boleh_libur_nasional
    ? [{ nilai: null, label: 'Seluruh unit kerja (nasional)' }]
    : []),
  ...props.unit_kerja_libur,
])

const tambahLibur = () =>
  formLibur.post('/admin/kelola-absen/setting/hari-libur', {
    preserveScroll: true,
    onSuccess: () => formLibur.reset(),
  })

const hapusLibur = (libur) => {
  if (! window.confirm(`Hapus hari libur "${libur.keterangan}" pada ${libur.tanggal_panjang}?`)) {
    return
  }

  formLibur.delete(`/admin/kelola-absen/setting/hari-libur/${libur.id}`, { preserveScroll: true })
}

/*
 * Impor banyak hari libur sekaligus.
 *
 * Bukan `useForm` biasa: jawabannya berupa laporan per baris (ditambahkan /
 * dilewati beserta alasannya), bukan sekadar sukses-atau-gagal, sehingga satu
 * baris yang salah ketik tidak boleh menggagalkan baris lain di sekitarnya —
 * "kalender yang gagal diam-diam lebih buruk daripada kalender yang diisi
 * tangan" (catatan migrasinya sendiri). `fetch` mentah dipakai mengikuti pola
 * yang sama dengan uji koneksi WORKA (Setting/Worka.vue), bukan kunjungan
 * Inertia, supaya modalnya tetap terbuka menampilkan laporan itu.
 */
const modalImporLiburTerbuka = ref(false)
const teksImporLibur = ref('')
const unitImporLibur = ref(null)
const sedangMengimpor = ref(false)
const hasilImpor = ref(null)

const tutupModalImpor = () => {
  modalImporLiburTerbuka.value = false
  teksImporLibur.value = ''
  unitImporLibur.value = null
  hasilImpor.value = null
}

const jumlahBarisImpor = computed(
  () => teksImporLibur.value.split(/\r\n|\r|\n/).filter((baris) => baris.trim() !== '').length,
)

const imporLibur = async () => {
  sedangMengimpor.value = true
  hasilImpor.value = null

  try {
    const jawaban = await fetch('/admin/kelola-absen/setting/hari-libur/impor', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(
          document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
        ),
      },
      body: JSON.stringify({ teks: teksImporLibur.value, unit_kerja_id: unitImporLibur.value }),
    })

    if (! jawaban.ok) {
      const galat = await jawaban.json().catch(() => null)
      hasilImpor.value = {
        ditambahkan: [],
        dilewati: [],
        galat: galat?.message ?? 'Impor gagal dikirim. Periksa kembali cakupan yang dipilih.',
      }

      return
    }

    hasilImpor.value = await jawaban.json()

    // Daftar hari libur di bawah formulir dimuat ulang; fetch mentah tidak
    // ikut membawa props Inertia yang baru seperti kunjungan halaman biasa.
    if (hasilImpor.value.ditambahkan.length > 0) {
      router.reload({ only: ['hari_libur'] })
    }
  } catch {
    hasilImpor.value = { ditambahkan: [], dilewati: [], galat: 'Permintaan impor gagal dikirim.' }
  } finally {
    sedangMengimpor.value = false
  }
}
</script>

<template>
  <div class="panel mt-6 p-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="font-display text-base font-semibold text-utama">Hari Libur</h2>
        <p class="mt-1 text-sm text-sekunder">
          Tanggal merah dan cuti bersama. Absen Umum tertutup otomatis pada tanggal ini,
          kecuali dibuka paksa lewat override pada menu Absen Umum.
        </p>
      </div>

      <TombolAksi ikon="tambah" warna="teal" @click="modalImporLiburTerbuka = true">
        Impor Banyak Sekaligus
      </TombolAksi>
    </div>

    <form class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="tambahLibur">
      <div>
        <label for="libur-tanggal" class="mb-1.5 block text-sm font-medium text-utama">
          Tanggal<span class="ml-0.5 text-galat-teks" aria-hidden="true">*</span>
        </label>
        <!--
          `required` bawaan ikut lepas bersama input native, tetapi tidak
          ada lubang yang terbuka: tombol simpan sudah nonaktif selama
          tanggal atau keterangan kosong (lihat `:nonaktif` di bawah), dan
          sisi server tetap memvalidasinya.
        -->
        <TanggalIsian
          id="libur-tanggal"
          v-model="formLibur.tanggal"
          :bermasalah="Boolean(formLibur.errors.tanggal)"
        />
      </div>

      <div class="lg:col-span-2">
        <label for="libur-keterangan" class="mb-1.5 block text-sm font-medium text-utama">
          Keterangan<span class="ml-0.5 text-galat-teks" aria-hidden="true">*</span>
        </label>
        <input
          id="libur-keterangan"
          v-model="formLibur.keterangan"
          type="text"
          required
          maxlength="150"
          placeholder="mis. Hari Raya Idulfitri"
          class="kolom-isian"
        />
      </div>

      <div>
        <label for="libur-unit" class="mb-1.5 block text-sm font-medium text-utama">
          Berlaku untuk
        </label>
        <Pilihan
          id="libur-unit"
          v-model="formLibur.unit_kerja_id"
          :opsi="opsiCakupanLibur"
          placeholder="Pilih cakupan…"
        />
      </div>

      <div class="sm:col-span-2 lg:col-span-4">
        <TombolProses
          :proses="formLibur.processing"
          :nonaktif="!formLibur.tanggal || !formLibur.keterangan"
          ikon="tambah"
          teks-proses="Menambahkan…"
        >
          Tambah Hari Libur
        </TombolProses>

        <p v-if="formLibur.errors.tanggal" class="mt-2 text-sm text-galat-teks">
          {{ formLibur.errors.tanggal }}
        </p>
      </div>
    </form>

    <ul v-if="hari_libur.length > 0" class="mt-5 flex flex-col gap-2">
      <li
        v-for="libur in hari_libur"
        :key="libur.id"
        class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-garis bg-permukaan-2 px-4 py-2.5"
        :class="libur.lampau && 'opacity-60'"
      >
        <div class="min-w-0">
          <p class="truncate text-sm font-medium text-utama">
            {{ libur.keterangan }}
            <Lencana v-if="libur.nasional" warna="langit" :titik="false" class="ml-1.5">
              Nasional
            </Lencana>
          </p>
          <p class="mt-0.5 truncate text-xs text-redup">
            {{ libur.tanggal_panjang }} · {{ libur.cakupan }}
          </p>
        </div>

        <TombolAksi ikon="hapus" warna="rose" @click="hapusLibur(libur)">Hapus</TombolAksi>
      </li>
    </ul>

    <p v-else class="mt-5 rounded-xl bg-permukaan-2 px-4 py-5 text-center text-sm text-redup">
      Belum ada hari libur terdaftar. Akhir pekan sudah ditangani lewat hari kerja tiap unit
      pada Setting Unit Kerja; daftar ini untuk tanggal merah dan cuti bersama.
    </p>
  </div>

  <Modal
    :terbuka="modalImporLiburTerbuka"
    judul="Impor Banyak Hari Libur"
    keterangan="Satu baris per tanggal. Admin biasanya mengisi belasan tanggal sekaligus tiap tahun — satu per satu terlalu lambat untuk itu."
    lebar="max-w-2xl"
    @tutup="tutupModalImpor"
  >
    <div class="flex flex-col gap-4">
      <div>
        <label for="impor-teks" class="mb-1.5 block text-sm font-medium text-utama">
          Daftar tanggal<span class="ml-0.5 text-galat-teks" aria-hidden="true">*</span>
        </label>
        <textarea
          id="impor-teks"
          v-model="teksImporLibur"
          rows="10"
          placeholder="2026-01-01;Tahun Baru Masehi
2026-03-19;Hari Raya Nyepi
2026-03-30;Hari Raya Idulfitri
2026-03-31;Hari Raya Idulfitri
2026-04-01;Cuti Bersama Idulfitri"
          class="kolom-isian font-mono text-xs leading-relaxed"
        />
        <p class="mt-1.5 text-xs text-redup">
          Format <code class="rounded bg-permukaan-2 px-1 py-0.5">YYYY-MM-DD;Keterangan</code> —
          pemisah titik koma, satu tanggal per baris.
          <span v-if="jumlahBarisImpor > 0">{{ jumlahBarisImpor }} baris terbaca.</span>
        </p>
      </div>

      <div>
        <label for="impor-unit" class="mb-1.5 block text-sm font-medium text-utama">
          Berlaku untuk
        </label>
        <Pilihan
          id="impor-unit"
          v-model="unitImporLibur"
          :opsi="opsiCakupanLibur"
          placeholder="Pilih cakupan…"
        />
      </div>

      <!--
        Laporan per baris, bukan sekadar sukses/gagal — baris yang salah
        ketik tidak boleh menggagalkan baris lain, dan admin harus tahu
        PERSIS baris mana yang perlu diperbaiki, bukan menebak dari selisih
        jumlah baris yang dikirim dan yang tersimpan.
      -->
      <div
        v-if="hasilImpor"
        class="rounded-xl border p-4 text-sm"
        :class="hasilImpor.galat ? 'border-galat bg-galat-lembut' : 'border-garis bg-permukaan-2'"
      >
        <p v-if="hasilImpor.galat" class="text-galat-teks">{{ hasilImpor.galat }}</p>

        <template v-else>
          <p class="font-medium text-utama">
            {{ hasilImpor.ditambahkan.length }} hari libur ditambahkan{{
              hasilImpor.dilewati.length > 0 ? `, ${hasilImpor.dilewati.length} dilewati` : ''
            }}.
          </p>

          <ul v-if="hasilImpor.dilewati.length > 0" class="mt-2 flex flex-col gap-1">
            <li
              v-for="butir in hasilImpor.dilewati"
              :key="butir.baris"
              class="text-xs text-peringatan-teks"
            >
              Baris {{ butir.baris }}: {{ butir.alasan }}
            </li>
          </ul>
        </template>
      </div>
    </div>

    <template #aksi>
      <TombolAksi warna="slate" @click="tutupModalImpor">Tutup</TombolAksi>
      <TombolProses
        tipe="button"
        :proses="sedangMengimpor"
        :nonaktif="jumlahBarisImpor === 0"
        ikon="tambah"
        teks-proses="Mengimpor…"
        @click="imporLibur"
      >
        Impor {{ jumlahBarisImpor > 0 ? jumlahBarisImpor : '' }} Tanggal
      </TombolProses>
    </template>
  </Modal>
</template>
