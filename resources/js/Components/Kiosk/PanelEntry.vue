<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

/**
 * Panel Capture Foto & Entry Absen (UIUX §4.2.1).
 *
 * Panel ini menampilkan keadaan, tidak memutuskan apa pun: verifikasi wajah
 * (S15) dan penyimpanan absen (S16) mengendalikannya lewat prop `tahap`.
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
    menunggu_event: { teks: 'Menunggu event dibuka', warna: 'text-slate-400' },
    menunggu_tap: { teks: 'Silakan tap kartu atau ketik NIP', warna: 'text-slate-300' },
    memindai: { teks: 'Memindai wajah…', warna: 'text-teal-300' },
    berhasil: { teks: 'Absen berhasil dicatat', warna: 'text-emerald-400' },
    gagal: { teks: props.pesan ?? 'Verifikasi gagal, silakan ulangi', warna: 'text-amber-400' },
  }

  return daftar[props.tahap] ?? daftar.menunggu_tap
})

/** Bingkai pratinjau berubah warna mengikuti hasil verifikasi. */
const warnaBingkai = computed(() => {
  if (berhasil.value) return 'border-emerald-500'
  if (gagal.value) return 'border-amber-500'
  if (memindai.value) return 'border-teal-400'

  return 'border-white/15'
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
    kameraGagal.value = 'Kamera tidak dapat diakses. Periksa izin kamera pada peramban kiosk.'
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

// Kolom selalu kembali fokus (NFR-08): kiosk dioperasikan tanpa mouse.
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
 * jaringan sudah berukuran akhir. Dipakai S16 saat menyimpan absen.
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
  <section class="rounded-lg border border-white/10 bg-white/5 p-5">
    <h2 class="font-display text-sm font-semibold uppercase tracking-wider text-slate-400">
      Capture Foto &amp; Entry Absen
    </h2>

    <!-- Pratinjau kamera -->
    <div
      v-if="metode.wajah"
      class="relative mt-4 aspect-video overflow-hidden rounded-lg border-2 bg-slate-950 transition-colors"
      :class="warnaBingkai"
    >
      <video ref="video" class="h-full w-full object-cover" autoplay muted playsinline></video>

      <div v-if="kameraGagal" class="absolute inset-0 flex items-center justify-center bg-slate-950/85 px-6 text-center text-xs text-amber-300">
        {{ kameraGagal }}
      </div>

      <span
        v-else
        class="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded-full bg-slate-950/70 px-2.5 py-1 text-xs font-medium text-slate-200"
      >
        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-rose-500"></span>
        LIVE
      </span>

      <!-- Sudut bidik -->
      <template v-if="!kameraGagal">
        <span class="pointer-events-none absolute left-6 top-6 h-8 w-8 border-l-2 border-t-2 border-white/50"></span>
        <span class="pointer-events-none absolute right-6 top-6 h-8 w-8 border-r-2 border-t-2 border-white/50"></span>
        <span class="pointer-events-none absolute bottom-6 left-6 h-8 w-8 border-b-2 border-l-2 border-white/50"></span>
        <span class="pointer-events-none absolute bottom-6 right-6 h-8 w-8 border-b-2 border-r-2 border-white/50"></span>
      </template>

      <!-- Garis pemindaian, hanya saat verifikasi berjalan -->
      <span
        v-if="memindai"
        class="pointer-events-none absolute inset-x-0 h-0.5 animate-[pindai_1.6s_ease-in-out_infinite] bg-teal-400/80 shadow-[0_0_12px_2px_rgba(45,212,191,0.7)]"
      ></span>
    </div>

    <div
      v-else
      class="mt-4 rounded-lg border border-dashed border-white/15 px-4 py-8 text-center text-xs text-slate-400"
    >
      Verifikasi wajah dinonaktifkan pada Setting Absen.
    </div>

    <!-- Jenis absen -->
    <div class="mt-5">
      <span class="text-xs font-medium uppercase tracking-wider text-slate-400">Jenis Absen</span>
      <div class="mt-2 grid grid-cols-2 gap-2">
        <label
          v-for="pilihan in [{ nilai: 'datang', label: 'Datang' }, { nilai: 'pulang', label: 'Pulang' }]"
          :key="pilihan.nilai"
          class="cursor-pointer rounded-md border px-4 py-2.5 text-center text-sm font-medium transition"
          :class="jenis === pilihan.nilai
            ? 'border-teal-500 bg-teal-500/15 text-teal-300'
            : 'border-white/15 text-slate-300 hover:bg-white/5'"
        >
          <input v-model="jenis" type="radio" :value="pilihan.nilai" class="sr-only" />
          {{ pilihan.label }}
        </label>
      </div>
    </div>

    <!-- Kolom scan / ketik -->
    <div class="mt-4">
      <label for="id-card" class="text-xs font-medium uppercase tracking-wider text-slate-400">
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
        class="mt-2 block w-full rounded-md border border-white/15 bg-slate-950/60 px-4 py-3 font-display text-lg tabular-nums text-white placeholder:text-sm placeholder:font-sans placeholder:text-slate-500 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500 disabled:opacity-50"
        @keydown="tandaiKetukan"
        @keyup.enter="kirim"
        @blur="rebutFokus"
      />
    </div>

    <!-- Hasil -->
    <dl class="mt-5 grid grid-cols-2 gap-x-4 gap-y-3">
      <div v-for="medan in [
        { kunci: 'nip', label: 'NIP' },
        { kunci: 'nama', label: 'Nama' },
        { kunci: 'unit_kerja', label: 'Unit Kerja' },
        { kunci: 'jam', label: 'Jam Absen' },
      ]" :key="medan.kunci">
        <dt class="text-xs uppercase tracking-wider text-slate-500">{{ medan.label }}</dt>
        <dd class="mt-0.5 truncate font-display text-sm text-white">{{ hasil?.[medan.kunci] ?? '—' }}</dd>
      </div>
    </dl>

    <!-- Status -->
    <p class="mt-5 flex items-center gap-2 border-t border-white/10 pt-4 text-sm" :class="status.warna">
      <span
        class="h-2 w-2 rounded-full"
        :class="{
          'bg-slate-500': tahap === 'menunggu_event',
          'bg-slate-400': tahap === 'menunggu_tap',
          'animate-pulse bg-teal-400': memindai,
          'bg-emerald-500': berhasil,
          'bg-amber-500': gagal,
        }"
      ></span>
      {{ status.teks }}
    </p>
  </section>
</template>

<style>
@keyframes pindai {
  0% { top: 8%; }
  50% { top: 92%; }
  100% { top: 8%; }
}
</style>
