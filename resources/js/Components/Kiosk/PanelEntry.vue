<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

/**
 * Panel Capture Foto & Entry Absen (UIUX §4.2.1).
 *
 * Panel ini menampilkan keadaan, tidak memutuskan apa pun: verifikasi wajah
 * dan penyimpanan absen mengendalikannya lewat prop `tahap`.
 *
 * Pratinjau kamera sengaja menjadi elemen terbesar di layar. Orang yang
 * berdiri di depan titik absen perlu melihat wajahnya sendiri untuk
 * memposisikan diri; kotak kecil membuat mereka mencondong dan memperlambat
 * antrean.
 */

const props = defineProps({
  tahap: { type: String, required: true },
  pesan: { type: String, default: null },
  hasil: { type: Object, default: null },
  metode: { type: Object, required: true },
  aktif: { type: Boolean, default: true },
})

const emit = defineEmits(['tap'])

const jenis = defineModel('jenis', { type: String, default: 'datang' })

const kolomId = ref(null)
const video = ref(null)
const kamera = ref(null)
const kameraGagal = ref(null)
const idCard = ref('')

const memindai = computed(() => props.tahap === 'memindai')
const berhasil = computed(() => props.tahap === 'berhasil')
const gagal = computed(() => props.tahap === 'gagal')

const status = computed(() => {
  const daftar = {
    menunggu_event: { teks: 'Menunggu event dibuka', warna: 'text-redup' },
    menunggu_tap: { teks: 'Silakan tap kartu atau ketik NIP', warna: 'text-sekunder' },
    memindai: { teks: 'Memindai wajah…', warna: 'text-aksen-teks' },
    berhasil: props.hasil?.tertunda
      ? {
          teks: 'Absen tersimpan di perangkat, menunggu jaringan pulih',
          warna: 'text-peringatan-teks',
        }
      : { teks: 'Absen berhasil dicatat', warna: 'text-berhasil-teks' },
    gagal: { teks: props.pesan ?? 'Verifikasi gagal, silakan ulangi', warna: 'text-peringatan-teks' },
  }

  return daftar[props.tahap] ?? daftar.menunggu_tap
})

/** Bingkai pratinjau berubah warna mengikuti hasil verifikasi. */
const warnaBingkai = computed(() => {
  if (berhasil.value) return 'border-berhasil'
  if (gagal.value) return 'border-peringatan'
  if (memindai.value) return 'border-aksen'

  return 'border-garis-kuat'
})

async function nyalakanKamera() {
  if (!props.metode.wajah) return

  try {
    kamera.value = await navigator.mediaDevices.getUserMedia({
      video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' },
      audio: false,
    })

    if (video.value) {
      video.value.srcObject = kamera.value
    }
  } catch {
    // Kamera ditolak atau tidak ada: layar tetap dapat dipakai untuk absen
    // manual, jadi kegagalan ini diberitahukan, bukan menghentikan layar.
    kameraGagal.value = 'Kamera tidak dapat diakses. Periksa izin kamera pada peramban perangkat.'
  }
}

function matikanKamera() {
  kamera.value?.getTracks().forEach((jalur) => jalur.stop())
  kamera.value = null
}

/**
 * Reader RFID 13,56 MHz kelas USB/HID mengetikkan UID kartu ke kolom ini,
 * biasanya diakhiri Enter — tetapi tidak semua merek mengirimkannya.
 *
 * Karena itu ada dua pemicu: Enter, dan jeda ketikan. Bila karakter datang
 * secepat mesin lalu berhenti, isinya dikirim sendiri tanpa menunggu Enter.
 * Ketikan manusia jauh lebih lambat sehingga tidak ikut terpicu.
 */
const JEDA_KIRIM_MS = 120
const AMBANG_MESIN_MS = 40
const PANJANG_MIN_OTOMATIS = 6

let jedaOtomatis = null
let ketukanTerakhir = 0
let ketikanMesin = true

function tandaiKetukan() {
  const sekarang = performance.now()
  const selisih = sekarang - ketukanTerakhir

  // Karakter pertama tidak memberi informasi kecepatan apa pun.
  if (ketukanTerakhir !== 0 && selisih > AMBANG_MESIN_MS) {
    ketikanMesin = false
  }

  ketukanTerakhir = sekarang

  clearTimeout(jedaOtomatis)

  jedaOtomatis = setTimeout(() => {
    if (ketikanMesin && idCard.value.trim().length >= PANJANG_MIN_OTOMATIS) {
      kirim()
    }
  }, JEDA_KIRIM_MS)
}

function kirim() {
  clearTimeout(jedaOtomatis)

  const nilai = idCard.value.trim()

  ketukanTerakhir = 0
  ketikanMesin = true

  if (nilai === '' || !props.aktif) return

  emit('tap', { id_card: nilai, jenis: jenis.value })
  idCard.value = ''
}

// Kolom selalu kembali fokus (NFR-08): titik absen dioperasikan tanpa mouse.
function rebutFokus() {
  if (props.aktif) kolomId.value?.focus()
}

watch(() => props.tahap, rebutFokus)

onMounted(() => {
  nyalakanKamera()
  rebutFokus()
})

onBeforeUnmount(() => {
  clearTimeout(jedaOtomatis)
  matikanKamera()
})

/**
 * Ambil satu bingkai dari pratinjau sebagai JPEG terkompresi.
 *
 * Dimensi dan kualitas berasal dari preset Setting Absen (FR-SET-04), dan
 * penyusutan dilakukan di sini — bukan di server — supaya yang melintasi
 * jaringan sudah berukuran akhir.
 */
function ambilFoto(kompresi) {
  const sumber = video.value

  if (!sumber || !sumber.videoWidth) return null

  const skala = Math.min(1, kompresi.dimensi_maks / Math.max(sumber.videoWidth, sumber.videoHeight))
  const kanvas = document.createElement('canvas')

  kanvas.width = Math.round(sumber.videoWidth * skala)
  kanvas.height = Math.round(sumber.videoHeight * skala)
  kanvas.getContext('2d').drawImage(sumber, 0, 0, kanvas.width, kanvas.height)

  return kanvas.toDataURL('image/jpeg', kompresi.kualitas / 100)
}

defineExpose({ rebutFokus, ambilFoto, elemenVideo: () => video.value })
</script>

<template>
  <section class="rounded-xl border border-garis bg-permukaan p-5 bayang">
    <h2 class="font-display text-sm font-semibold uppercase tracking-wider text-redup">
      Capture Foto &amp; Entry Absen
    </h2>

    <!--
      Pratinjau kamera. Rasio 4:3 dan kolom yang lebar membuat luasnya sekitar
      tiga kali lipat tata letak sebelumnya — inilah yang seharusnya pertama
      dilihat orang yang berdiri di depan layar.
    -->
    <div
      v-if="metode.wajah"
      class="relative mt-4 aspect-[4/3] overflow-hidden rounded-xl border-4 bg-navy-900 transition-colors duration-300"
      :class="warnaBingkai"
    >
      <video ref="video" class="h-full w-full object-cover" autoplay muted playsinline></video>

      <div
        v-if="kameraGagal"
        class="absolute inset-0 flex items-center justify-center bg-navy-900/90 px-8 text-center text-sm text-amber-200"
      >
        {{ kameraGagal }}
      </div>

      <span
        v-else
        class="absolute left-4 top-4 inline-flex items-center gap-1.5 rounded-full bg-navy-900/70 px-3 py-1.5 text-xs font-medium text-white backdrop-blur-sm"
      >
        <span class="h-2 w-2 animate-pulse rounded-full bg-red-500"></span>
        LIVE
      </span>

      <!--
        Sudut bidik. Di atas gambar kamera yang isinya tak terduga, putih
        semi-transparan tetap pilihan paling aman; saat verifikasi berjalan
        ia berpindah ke teal terang agar terbaca sebagai keadaan aktif.
      -->
      <template v-if="!kameraGagal">
        <span
          v-for="sudut in [
            'left-6 top-6 border-l-4 border-t-4 rounded-tl-lg',
            'right-6 top-6 border-r-4 border-t-4 rounded-tr-lg',
            'bottom-6 left-6 border-b-4 border-l-4 rounded-bl-lg',
            'bottom-6 right-6 border-b-4 border-r-4 rounded-br-lg',
          ]"
          :key="sudut"
          class="pointer-events-none absolute h-12 w-12 transition-colors duration-300"
          :class="[sudut, memindai ? 'border-teal-300' : 'border-white/60']"
        ></span>
      </template>

      <!-- Garis pemindaian, hanya saat verifikasi berjalan -->
      <span
        v-if="memindai"
        class="pointer-events-none absolute inset-x-0 h-1 animate-[pindai_1.6s_ease-in-out_infinite] bg-teal-300 shadow-[0_0_16px_4px_rgba(94,234,212,0.8)]"
      ></span>

      <!-- Skor kecocokan, tergambar di atas pratinjau saat hasil siap -->
      <span
        v-if="hasil?.skor != null && (berhasil || gagal)"
        class="absolute bottom-4 right-4 rounded-lg px-3 py-1.5 font-display text-sm font-semibold text-white backdrop-blur-sm"
        :class="berhasil ? 'bg-emerald-600/90' : 'bg-amber-600/90'"
      >
        {{ hasil.skor }}% cocok
      </span>
    </div>

    <div
      v-else
      class="mt-4 rounded-xl border border-dashed border-garis-kuat px-4 py-12 text-center text-sm text-redup"
    >
      Verifikasi wajah dinonaktifkan pada Setting Absen.
    </div>

    <!-- Jenis absen -->
    <div class="mt-5">
      <span class="text-xs font-medium uppercase tracking-wider text-redup">Jenis Absen</span>
      <div class="mt-2 grid grid-cols-2 gap-2">
        <label
          v-for="pilihan in [
            { nilai: 'datang', label: 'Datang' },
            { nilai: 'pulang', label: 'Pulang' },
          ]"
          :key="pilihan.nilai"
          class="cursor-pointer rounded-lg border px-4 py-3 text-center text-sm font-medium transition-colors duration-150"
          :class="
            jenis === pilihan.nilai
              ? 'border-aksen bg-aksen-lembut text-aksen-teks'
              : 'border-garis text-sekunder hover:bg-permukaan-hover'
          "
        >
          <input v-model="jenis" type="radio" :value="pilihan.nilai" class="sr-only" />
          {{ pilihan.label }}
        </label>
      </div>
    </div>

    <!-- Kolom scan / ketik -->
    <div class="mt-4">
      <label for="id-card" class="text-xs font-medium uppercase tracking-wider text-redup">
        Scan / Ketik ID Card
      </label>
      <input
        id="id-card"
        ref="kolomId"
        v-model="idCard"
        type="text"
        inputmode="numeric"
        autocomplete="off"
        :disabled="!aktif"
        :placeholder="aktif ? 'Tap kartu atau ketik NIP lalu tekan Enter' : 'Menunggu event dibuka'"
        class="mt-2 block w-full rounded-lg border border-garis bg-permukaan-2 px-4 py-3.5 font-display text-xl tabular-nums text-utama transition-colors duration-150 placeholder:text-sm placeholder:font-sans placeholder:text-redup focus:border-aksen focus:bg-permukaan focus:outline-none focus:ring-1 focus:ring-aksen disabled:opacity-50"
        @keydown="tandaiKetukan"
        @keyup.enter="kirim"
        @blur="rebutFokus"
      />
    </div>

    <!-- Hasil -->
    <dl class="mt-5 grid grid-cols-2 gap-x-4 gap-y-3">
      <div
        v-for="medan in [
          { kunci: 'nip', label: 'NIP' },
          { kunci: 'nama', label: 'Nama' },
          { kunci: 'unit_kerja', label: 'Unit Kerja' },
          { kunci: 'jam', label: 'Jam Absen' },
        ]"
        :key="medan.kunci"
      >
        <dt class="text-xs uppercase tracking-wider text-redup">{{ medan.label }}</dt>
        <dd class="mt-0.5 truncate font-display text-sm font-medium text-utama">
          {{ hasil?.[medan.kunci] ?? '—' }}
        </dd>
      </div>
    </dl>

    <!-- Status -->
    <p
      class="mt-5 flex items-center gap-2 border-t border-garis pt-4 text-sm font-medium"
      :class="status.warna"
    >
      <span
        class="h-2.5 w-2.5 rounded-full"
        :class="{
          'bg-redup/50': tahap === 'menunggu_event',
          'bg-redup': tahap === 'menunggu_tap',
          'animate-pulse bg-aksen': memindai,
          'bg-berhasil': berhasil,
          'bg-peringatan': gagal,
        }"
      ></span>
      {{ status.teks }}
    </p>
  </section>
</template>

<style>
@keyframes pindai {
  0% {
    top: 6%;
  }

  50% {
    top: 94%;
  }

  100% {
    top: 6%;
  }
}
</style>
