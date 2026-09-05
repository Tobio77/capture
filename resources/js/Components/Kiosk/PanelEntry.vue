<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

/**
 * Panel Capture Foto & Entry Absen (UIUX §4.2.1).
 *
 * Panel ini menampilkan keadaan, tidak memutuskan apa pun: verifikasi wajah
 * dan penyimpanan absen mengendalikannya lewat prop `tahap`.
 *
 * Kamera selalu menyala selama perangkat punya akses ke sana, termasuk ketika
 * verifikasi wajah dimatikan pada Setting Absen — yang dimatikan hanya langkah
 * pencocokan embedding, sedangkan fotonya tetap diambil sebagai bukti
 * kehadiran (revisi FR-SET-01, S28a).
 *
 * Pratinjaunya tidak dicerminkan. Foto absen adalah dokumen: nama pada tanda
 * pengenal, arah rambut, dan sisi tubuh harus sama dengan kenyataan, bukan
 * terbalik seperti cermin.
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
const sudah = computed(() => props.tahap === 'sudah')

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
    sudah: { teks: props.pesan ?? 'Kehadiran sudah tercatat', warna: 'text-info-teks' },
  }

  return daftar[props.tahap] ?? daftar.menunggu_tap
})

/*
 * Nada warna keadaan. Satu nilai yang mewarnai bingkai pratinjau, blok hasil,
 * dan baris status sekaligus — sehingga seluruh panel berubah warna serempak
 * alih-alih satu elemen berkedip sendirian.
 *
 * "Sudah absen" sengaja tidak memakai amber seperti kegagalan: bagi orang yang
 * berdiri di depan layar, ia bukan masalah yang harus diperbaiki melainkan
 * kepastian bahwa dirinya sudah aman.
 */
const nadaTahap = computed(() => {
  const daftar = {
    memindai: 'nada-teal',
    berhasil: 'nada-emerald',
    gagal: 'nada-amber',
    sudah: 'nada-langit',
  }

  return daftar[props.tahap] ?? 'nada-biru'
})

/** Bingkai pratinjau berubah warna mengikuti hasil verifikasi. */
const warnaBingkai = computed(() => {
  if (berhasil.value) return 'border-berhasil'
  if (gagal.value) return 'border-peringatan'
  if (sudah.value) return 'border-aksen-kuat'
  if (memindai.value) return 'border-aksen'

  return 'border-garis-kuat'
})

async function nyalakanKamera() {
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
  <section class="panel p-5">
    <h2 class="font-display text-sm font-semibold uppercase tracking-wider text-redup">
      Capture Foto &amp; Entry Absen
    </h2>

    <!--
      Pratinjau kamera sengaja KECIL (revisi S30): ~17rem pada rasio 4:3,
      dipusatkan, bukan lagi 16:9 selebar kolom.

      Perannya hanya konfirmasi "wajah saya sudah di dalam bingkai" — bukan
      cermin rias. Pratinjau selebar kolom mendorong kolom tap dan tombol jenis
      absen jauh ke bawah lipatan pada layar titik absen yang umumnya kecil,
      sementara rasio 4:3 memuat kepala dan bahu lebih rapat daripada 16:9 yang
      separuhnya terisi dinding.

      Pratinjaunya TIDAK dicerminkan. Foto absen adalah dokumen: nama pada
      tanda pengenal, arah rambut, dan sisi tubuh harus sama dengan kenyataan,
      bukan terbalik seperti cermin — dan foto yang kelak dipromosikan menjadi
      foto referensi (FR-PEG-05) harus menghadap arah yang sama dengan foto
      pembandingnya.

      Kamera tetap menyala walau verifikasi wajah dimatikan: fotonya tetap
      diambil dan disimpan sebagai bukti kehadiran (revisi FR-SET-01, S28a).
    -->
    <div
      class="relative mx-auto mt-4 aspect-[4/3] w-full max-w-[17rem] overflow-hidden rounded-2xl border-2 bg-navy-900 transition-colors duration-300"
      :class="warnaBingkai"
    >
      <video ref="video" class="h-full w-full object-cover" autoplay muted playsinline></video>

      <div
        v-if="kameraGagal"
        class="absolute inset-0 flex items-center justify-center bg-navy-900/90 px-5 text-center text-xs text-amber-200"
      >
        {{ kameraGagal }}
      </div>

      <span
        v-else
        class="absolute left-2.5 top-2.5 inline-flex items-center gap-1.5 rounded-full bg-navy-900/70 px-2 py-0.5 text-[0.6875rem] font-medium text-white backdrop-blur-sm"
      >
        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-red-500"></span>
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
            'left-3 top-3 border-l-2 border-t-2 rounded-tl-md',
            'right-3 top-3 border-r-2 border-t-2 rounded-tr-md',
            'bottom-3 left-3 border-b-2 border-l-2 rounded-bl-md',
            'bottom-3 right-3 border-b-2 border-r-2 rounded-br-md',
          ]"
          :key="sudut"
          class="pointer-events-none absolute h-6 w-6 transition-colors duration-300"
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
        class="absolute bottom-3 right-3 rounded-lg px-2.5 py-1 font-display text-xs font-semibold text-white backdrop-blur-sm"
        :class="berhasil ? 'bg-emerald-600/90' : 'bg-amber-600/90'"
      >
        {{ hasil.skor }}% cocok
      </span>
    </div>

    <!--
      Keterangan ini turun ke bawah pratinjau sejak pratinjaunya dikecilkan:
      sebagai lencana mengambang ia menutupi hampir separuh bingkai.
    -->
    <p
      v-if="!kameraGagal && !metode.wajah"
      class="mt-2 text-center text-xs text-redup"
    >
      Foto bukti kehadiran · tanpa pencocokan wajah
    </p>

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
        class="mt-2 block w-full panel-2 px-4 py-3.5 font-display text-xl tabular-nums text-utama transition-colors duration-150 placeholder:text-sm placeholder:font-sans placeholder:text-redup focus:border-aksen focus:bg-permukaan focus:outline-none focus:ring-1 focus:ring-aksen disabled:opacity-50"
        @keydown="tandaiKetukan"
        @keyup.enter="kirim"
        @blur="rebutFokus"
      />

      <!--
        Reader RFID mengirim Enter sendiri, tetapi pegawai yang mengetik NIP
        pada layar sentuh tidak punya papan ketik fisik untuk menekannya.
      -->
      <button
        type="button"
        :disabled="!aktif || idCard.trim() === ''"
        class="mt-3 w-full rounded-lg bg-aksen px-4 py-3 text-sm font-semibold text-white transition-colors duration-150 hover:bg-aksen-kuat active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-40"
        @click="kirim"
      >
        Absen {{ jenis === 'datang' ? 'Datang' : 'Pulang' }}
      </button>
    </div>

    <!--
      Hasil. Seluruh bloknya berlatar nada keadaan, bukan hanya baris statusnya
      — pada layar yang dibaca dari jarak berdiri, bidang berwarna terbaca jauh
      lebih cepat daripada satu kata berwarna.
    -->
    <dl
      class="mt-5 grid grid-cols-2 gap-x-4 gap-y-3 rounded-xl p-4 transition-colors duration-300"
      :class="nadaTahap"
      :style="{ backgroundColor: 'var(--nada-lembut)' }"
    >
      <div
        v-for="medan in [
          { kunci: 'nip', label: 'NIP' },
          { kunci: 'nama', label: 'Nama' },
          { kunci: 'unit_kerja', label: 'Unit Kerja' },
          { kunci: 'jam', label: 'Jam Absen' },
        ]"
        :key="medan.kunci"
      >
        <dt class="text-[0.6875rem] font-medium uppercase tracking-wider opacity-70" :style="{ color: 'var(--nada-teks)' }">
          {{ medan.label }}
        </dt>
        <dd class="mt-0.5 truncate font-display text-sm font-semibold text-utama">
          {{ hasil?.[medan.kunci] ?? '—' }}
        </dd>
      </div>
    </dl>

    <!--
      FR-PEG-05 (revisi S29): foto tap barusan menjadi foto referensi wajah
      pegawai ini. Diberitahukan supaya ia tidak lagi merasa perlu mendatangi
      admin untuk sesi pendaftaran.
    -->
    <p
      v-if="hasil?.wajah_didaftarkan"
      class="mt-4 rounded-lg bg-berhasil-lembut px-3 py-2 text-xs text-berhasil-teks"
    >
      Foto ini sekaligus terdaftar sebagai foto referensi wajah Anda.
    </p>

    <!-- Status -->
    <p
      class="mt-4 flex items-center gap-2.5 border-t border-garis pt-4 text-sm font-medium"
      :class="status.warna"
    >
      <span class="relative flex h-2.5 w-2.5 shrink-0" :class="nadaTahap">
        <span
          v-if="memindai || berhasil"
          class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-70"
          :style="{ backgroundColor: 'var(--nada-kuat)' }"
        ></span>
        <span
          class="relative inline-flex h-2.5 w-2.5 rounded-full transition-colors duration-300"
          :style="{ backgroundColor: tahap.startsWith('menunggu') ? 'var(--tema-redup)' : 'var(--nada-kuat)' }"
        ></span>
      </span>
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
