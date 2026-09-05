<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import { useFaceApi } from '@/Composables/useFaceApi'

/**
 * Pendaftaran/pembaruan foto referensi wajah pegawai (FR-PEG-05).
 *
 * Embedding dihitung di browser ini juga: server tidak pernah memproses wajah,
 * dan yang kelak dikirim ke kiosk hanyalah embedding, bukan fotonya.
 */

const props = defineProps({
  pegawai: { type: Object, default: null },
})

const emit = defineEmits(['tutup'])

const { memuat: memuatModel, hitungEmbedding } = useFaceApi()

const berkas = ref(null)
const pratinjau = ref(null)
const embedding = ref(null)
const galat = ref(null)
const memeriksa = ref(false)
const mengirim = ref(false)

/*
 * Dua cara memasukkan foto, dan keduanya bermuara pada berkas yang sama.
 *
 * Mengunggah berkas cocok ketika fotonya sudah ada — hasil pemotretan massal,
 * atau foto pegawai dari arsip kepegawaian. Mengambil langsung dari kamera
 * cocok ketika pegawainya berdiri di depan meja admin, dan itulah keadaan yang
 * paling sering terjadi saat unit kerja mengejar kelengkapan pendaftaran.
 */
const cara = ref('unggah')
const video = ref(null)
const kamera = ref(null)
const kameraGagal = ref(null)

const terbuka = computed(() => props.pegawai !== null)
const sudahTerdaftar = computed(() => props.pegawai?.wajah_terdaftar === true)

const statusPeriksa = computed(() => {
  if (memuatModel.value) return 'Memuat model pengenalan wajah…'
  if (memeriksa.value) return 'Memeriksa wajah pada foto…'
  return null
})

watch(terbuka, (nilai) => {
  if (!nilai) {
    matikanKamera()

    return
  }

  cara.value = 'unggah'
  bersihkan()
})

watch(cara, (nilai) => {
  bersihkan()

  nilai === 'kamera' ? nyalakanKamera() : matikanKamera()
})

onBeforeUnmount(matikanKamera)

function bersihkan() {
  if (pratinjau.value) URL.revokeObjectURL(pratinjau.value)
  berkas.value = null
  pratinjau.value = null
  embedding.value = null
  galat.value = null
}

function tutup() {
  bersihkan()
  matikanKamera()
  emit('tutup')
}

async function nyalakanKamera() {
  kameraGagal.value = null

  try {
    kamera.value = await navigator.mediaDevices.getUserMedia({
      video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' },
      audio: false,
    })

    if (video.value) {
      video.value.srcObject = kamera.value
    }
  } catch {
    kameraGagal.value =
      'Kamera tidak dapat diakses. Periksa izin kamera pada peramban, atau unggah berkas foto.'
  }
}

function matikanKamera() {
  kamera.value?.getTracks().forEach((jalur) => jalur.stop())
  kamera.value = null
}

/**
 * Ambil satu bingkai dari kamera sebagai berkas JPEG.
 *
 * Tidak dicerminkan, sama seperti pratinjau titik absen: foto referensi adalah
 * dokumen, dan sisi tubuh pada foto harus sama dengan kenyataan supaya
 * pencocokan kelak membandingkan hal yang sama.
 */
async function ambilDariKamera() {
  const elemen = video.value

  if (!elemen || !elemen.videoWidth) {
    galat.value = 'Kamera belum siap. Tunggu sejenak lalu coba lagi.'

    return
  }

  const kanvas = document.createElement('canvas')

  kanvas.width = elemen.videoWidth
  kanvas.height = elemen.videoHeight
  kanvas.getContext('2d').drawImage(elemen, 0, 0)

  const gumpalan = await new Promise((selesai) => kanvas.toBlob(selesai, 'image/jpeg', 0.92))

  if (!gumpalan) {
    galat.value = 'Foto gagal diambil dari kamera. Coba sekali lagi.'

    return
  }

  await terimaBerkas(
    new File([gumpalan], `${props.pegawai.nip}.jpg`, { type: 'image/jpeg' }),
  )
}

async function pilihBerkas(event) {
  await terimaBerkas(event.target.files?.[0] ?? null)
}

/**
 * Satu-satunya jalan masuk foto, dari mana pun asalnya.
 *
 * Pemeriksaan kualitasnya — tepat satu wajah terdeteksi — karena itu tidak
 * dapat dilewati oleh salah satu cara, dan foto yang sama tidak akan diterima
 * lewat kamera bila ditolak lewat unggahan.
 */
async function terimaBerkas(dipilih) {
  if (pratinjau.value) URL.revokeObjectURL(pratinjau.value)

  berkas.value = dipilih
  embedding.value = null
  galat.value = null
  pratinjau.value = dipilih ? URL.createObjectURL(dipilih) : null

  if (!dipilih) return

  memeriksa.value = true

  try {
    const gambar = await muatGambar(pratinjau.value)
    const hasil = await hitungEmbedding(gambar)

    if (hasil.galat) {
      galat.value = hasil.galat
      return
    }

    embedding.value = hasil.embedding
  } catch (e) {
    galat.value = 'Gagal memuat model pengenalan wajah. Periksa koneksi lalu muat ulang halaman.'
  } finally {
    memeriksa.value = false
  }
}

function muatGambar(url) {
  return new Promise((selesai, gagal) => {
    const gambar = new Image()
    gambar.onload = () => selesai(gambar)
    gambar.onerror = () => gagal(new Error('Gambar tidak dapat dibaca.'))
    gambar.src = url
  })
}

function simpan() {
  if (!berkas.value || !embedding.value) return

  mengirim.value = true

  router.post(
    `/admin/pegawai/${props.pegawai.id}/wajah`,
    { foto: berkas.value, embedding: embedding.value },
    {
      preserveScroll: true,
      onSuccess: () => tutup(),
      onError: (kesalahan) => {
        galat.value = kesalahan.foto ?? kesalahan.embedding ?? 'Penyimpanan gagal.'
      },
      onFinish: () => {
        mengirim.value = false
      },
    },
  )
}

function cabut() {
  if (!window.confirm(`Cabut pendaftaran wajah ${props.pegawai.nama}? Foto referensi akan dihapus.`)) {
    return
  }

  mengirim.value = true

  router.delete(`/admin/pegawai/${props.pegawai.id}/wajah`, {
    preserveScroll: true,
    onSuccess: () => tutup(),
    onFinish: () => {
      mengirim.value = false
    },
  })
}
</script>

<template>
  <Modal :terbuka="terbuka" :judul="sudahTerdaftar ? 'Perbarui Foto Referensi Wajah' : 'Daftarkan Foto Referensi Wajah'" @tutup="tutup">
    <div v-if="pegawai" class="space-y-4">
      <div class="rounded-lg bg-permukaan-2 px-4 py-3">
        <p class="font-medium text-utama">{{ pegawai.nama }}</p>
        <p class="font-display text-xs tabular-nums text-redup">{{ pegawai.nip }}</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div v-if="sudahTerdaftar">
          <p class="mb-1.5 text-xs font-medium uppercase tracking-wider text-redup">Terdaftar saat ini</p>
          <img
            :src="`/admin/pegawai/${pegawai.id}/wajah`"
            :alt="`Foto referensi ${pegawai.nama}`"
            class="aspect-square w-full rounded-lg border border-garis object-cover"
          />
        </div>

        <div :class="sudahTerdaftar ? '' : 'sm:col-span-2'">
          <p class="mb-1.5 text-xs font-medium uppercase tracking-wider text-redup">
            {{ sudahTerdaftar ? 'Foto pengganti' : 'Foto referensi' }}
          </p>
          <img
            v-if="pratinjau"
            :src="pratinjau"
            alt="Pratinjau foto referensi"
            class="aspect-square w-full rounded-lg border border-garis object-cover"
          />

          <!--
            Pratinjau kamera menempati bingkai yang sama dengan pratinjau foto,
            sehingga admin melihat persis bidang yang akan tersimpan.
            Dipasang v-show, bukan v-if: elemennya harus tetap terpasang agar
            aliran kameranya tidak putus setiap kali foto diambil dan diulang.
          -->
          <video
            v-show="!pratinjau && cara === 'kamera' && !kameraGagal"
            ref="video"
            autoplay
            playsinline
            muted
            class="aspect-square w-full rounded-lg border border-garis bg-navy-900 object-cover"
          ></video>

          <div
            v-if="!pratinjau && (cara !== 'kamera' || kameraGagal)"
            class="flex aspect-square w-full items-center justify-center rounded-lg border border-dashed border-garis px-4 text-center text-xs text-redup"
          >
            {{ kameraGagal ?? 'Belum ada foto dipilih' }}
          </div>
        </div>
      </div>

      <!--
        Dua cara memasukkan foto. Unggah berkas untuk foto yang sudah ada;
        kamera untuk pegawai yang sedang berdiri di depan meja admin — keadaan
        yang paling sering terjadi saat unit kerja mengejar kelengkapan
        pendaftaran wajah.
      -->
      <div class="flex gap-1 rounded-lg bg-permukaan-2 p-1">
        <button
          v-for="pilihan in [
            { nilai: 'unggah', label: 'Unggah berkas' },
            { nilai: 'kamera', label: 'Ambil dari kamera' },
          ]"
          :key="pilihan.nilai"
          type="button"
          class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors duration-150"
          :class="
            cara === pilihan.nilai
              ? 'bg-permukaan text-utama bayang'
              : 'text-sekunder hover:text-utama'
          "
          @click="cara = pilihan.nilai"
        >
          {{ pilihan.label }}
        </button>
      </div>

      <div v-if="cara === 'unggah'">
        <label class="mb-1.5 block text-sm font-medium text-utama" for="berkas-wajah">Pilih foto</label>
        <input
          id="berkas-wajah"
          type="file"
          accept="image/jpeg,image/png"
          class="block w-full text-sm text-sekunder file:mr-3 file:rounded-md file:border-0 file:bg-navy-700 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-navy-800"
          @change="pilihBerkas"
        />
        <p class="mt-1.5 text-xs text-redup">
          Foto tampak depan, satu orang, pencahayaan cukup. JPG atau PNG, maksimal 5 MB.
        </p>
      </div>

      <div v-else>
        <button
          type="button"
          :disabled="kamera === null || memeriksa"
          class="w-full rounded-lg bg-navy-700 px-4 py-2.5 text-sm font-semibold text-white transition-colors duration-150 hover:bg-navy-800 disabled:cursor-not-allowed disabled:opacity-50 active:scale-[0.99]"
          @click="pratinjau ? bersihkan() : ambilDariKamera()"
        >
          {{ pratinjau ? 'Ambil Ulang' : 'Ambil Foto' }}
        </button>
        <p class="mt-1.5 text-xs text-redup">
          Pegawai menghadap kamera, satu orang dalam bingkai, pencahayaan cukup. Foto tidak
          dicerminkan.
        </p>
      </div>

      <p v-if="statusPeriksa" class="flex items-center gap-2 text-sm text-sekunder">
        <span class="h-3 w-3 animate-spin rounded-full border-2 border-teal-600 border-t-transparent"></span>
        {{ statusPeriksa }}
      </p>

      <p v-if="galat" class="rounded-lg bg-peringatan-lembut px-3 py-2 text-sm text-peringatan-teks">{{ galat }}</p>

      <p v-if="embedding" class="rounded-lg bg-berhasil-lembut px-3 py-2 text-sm text-berhasil-teks">
        Wajah terdeteksi. Foto siap disimpan.
      </p>
    </div>

    <template #aksi>
      <button
        v-if="sudahTerdaftar"
        type="button"
        class="mr-auto rounded-lg px-3 py-2 text-sm font-medium text-peringatan-teks hover:bg-peringatan-lembut disabled:opacity-50"
        :disabled="mengirim"
        @click="cabut"
      >
        Cabut pendaftaran
      </button>
      <button
        type="button"
        class="rounded-lg px-4 py-2 text-sm font-medium text-sekunder hover:bg-permukaan-hover"
        @click="tutup"
      >
        Batal
      </button>
      <button
        type="button"
        class="rounded-lg bg-aksen px-4 py-2 text-sm font-medium text-white hover:bg-aksen-kuat disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="!embedding || mengirim"
        @click="simpan"
      >
        {{ mengirim ? 'Menyimpan…' : 'Simpan' }}
      </button>
    </template>
  </Modal>
</template>
