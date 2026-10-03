<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { FocusScope } from 'reka-ui'
import LencanaNotifikasi from '@/Components/UI/LencanaNotifikasi.vue'
import { notifikasi, tutupPopup, useNotifikasi } from '@/Composables/useNotifikasi'

/**
 * Penggambar tunggal seluruh notifikasi aplikasi, dipasang sekali di akar
 * (lihat app.js) sehingga berlaku di panel admin, layar masuk, halaman depan,
 * dan layar perangkat sekaligus.
 *
 * Tiga sumber kabar dialirkan ke sini:
 *
 *   1. Flash `sukses`/`gagal` dari server — setiap aksi CRUD yang berakhir
 *      dengan `back()->with('sukses', …)`. Dibaca dari setiap kunjungan
 *      Inertia yang berhasil, ditambah halaman pertama yang dimuat.
 *   2. Galat HTTP yang bukan jawaban Inertia (403, 419, 429, 500, …) —
 *      sebelumnya muncul sebagai halaman galat mentah di dalam bingkai.
 *   3. Panggilan langsung `notifikasi.*()` dan `konfirmasi()` dari halaman.
 */

const props = defineProps({
  /** Flash pada halaman pertama, sebelum ada kunjungan Inertia apa pun. */
  flashAwal: { type: Object, default: null },
})

const { keadaan } = useNotifikasi()

/* ---------------------------------------------------------------------
 * 1. Flash dari server
 * ------------------------------------------------------------------- */

/*
 * Objek flash yang terakhir ditampilkan. Muat ulang parsial
 * (`router.reload({ only: [...] })`) MENGGABUNGKAN prop lama dengan yang
 * baru, sehingga objek flash yang sama — rujukan yang sama persis — ikut
 * terbawa. Membandingkan rujukannya mencegah kabar lama muncul lagi pada
 * setiap muat ulang parsial, sementara jawaban penuh selalu membawa objek
 * baru walau teksnya kebetulan sama.
 */
let flashTerakhir = null

function bacaFlash(flash) {
  if (!flash || flash === flashTerakhir) return
  flashTerakhir = flash

  if (flash.sukses) notifikasi.sukses(flash.sukses)
  if (flash.gagal) notifikasi.gagal(flash.gagal)
}

/* ---------------------------------------------------------------------
 * 2. Galat HTTP
 * ------------------------------------------------------------------- */

const PESAN_STATUS = {
  403: 'Anda tidak memiliki akses untuk tindakan ini.',
  404: 'Data yang dituju tidak ditemukan. Mungkin sudah dihapus.',
  419: 'Sesi Anda telah berakhir. Muat ulang halaman lalu coba lagi.',
  429: 'Terlalu banyak permintaan dalam waktu singkat. Tunggu sebentar lalu coba lagi.',
  500: 'Terjadi kesalahan pada server. Coba lagi beberapa saat lagi.',
  503: 'Layanan sedang dalam pemeliharaan. Coba lagi beberapa saat lagi.',
}

/**
 * Pesan galat yang layak dibaca. `abort(403, 'Event yang sudah ditutup …')`
 * membawa kalimatnya sendiri — di JSON sebagai `message`, di HTML Laravel
 * sebagai judul halaman galat — dan kalimat itu lebih berguna daripada pesan
 * umum per status.
 */
function pesanGalat(response) {
  const data = response?.data
  const status = response?.status ?? 0

  if (data && typeof data === 'object' && typeof data.message === 'string' && data.message.trim()) {
    return data.message
  }

  if (typeof data === 'string' && status === 403) {
    const cocok = data.match(/<div[^>]*class="[^"]*text-lg[^"]*"[^>]*>\s*([^<]{3,200}?)\s*<\/div>/i)
    if (cocok && !/^forbidden$/i.test(cocok[1].trim())) return cocok[1].trim()
  }

  return PESAN_STATUS[status] ?? (status >= 500 ? PESAN_STATUS[500] : `Permintaan gagal diproses (kode ${status}).`)
}

const lepas = []

onMounted(() => {
  bacaFlash(props.flashAwal)

  lepas.push(router.on('success', (event) => bacaFlash(event.detail.page?.props?.flash)))

  lepas.push(
    router.on('httpException', (event) => {
      event.preventDefault()
      const status = event.detail.response?.status
      const jenis = status === 419 || status === 429 ? 'peringatan' : 'gagal'

      notifikasi[jenis](pesanGalat(event.detail.response), {
        judul: status === 419 ? 'Sesi Berakhir' : status === 403 ? 'Akses Ditolak' : undefined,
      })
    }),
  )

  lepas.push(
    router.on('networkError', (event) => {
      event.preventDefault()
      notifikasi.gagal('Tidak dapat menghubungi server. Periksa koneksi jaringan lalu coba lagi.', {
        judul: 'Koneksi Terputus',
      })
    }),
  )
})

/* ---------------------------------------------------------------------
 * Popup: hitung mundur yang dapat dijeda
 * ------------------------------------------------------------------- */

const popup = computed(() => keadaan.popup)
const sisa = ref(0)
const terjeda = ref(false)
let bingkai = null
let terakhir = 0

function detak(sekarang) {
  if (!terjeda.value) sisa.value = Math.max(0, sisa.value - (sekarang - terakhir))
  terakhir = sekarang

  if (sisa.value === 0) {
    tutupPopup()

    return
  }

  bingkai = requestAnimationFrame(detak)
}

function hentikanHitung() {
  if (bingkai !== null) cancelAnimationFrame(bingkai)
  bingkai = null
}

watch(
  () => popup.value?.id,
  () => {
    hentikanHitung()
    terjeda.value = false

    if (popup.value && popup.value.durasi > 0) {
      sisa.value = popup.value.durasi
      terakhir = performance.now()
      bingkai = requestAnimationFrame(detak)
    }
  },
)

const progres = computed(() => (popup.value?.durasi ? sisa.value / popup.value.durasi : 0))

/* ---------------------------------------------------------------------
 * Dialog konfirmasi: fokus, Escape, dan pengembalian fokus
 * ------------------------------------------------------------------- */

const dialog = computed(() => keadaan.konfirmasi)
const tombolYa = ref(null)
const tombolTidak = ref(null)
let fokusSebelum = null

watch(
  () => dialog.value?.id,
  async (id, idLama) => {
    if (id && !idLama) fokusSebelum = document.activeElement

    if (id) {
      await nextTick()
      // Tindakan berbahaya: fokus awal pada Batal, bukan pada tombol eksekusi.
      ;(dialog.value?.nada === 'bahaya' ? tombolTidak : tombolYa).value?.focus()
    } else if (fokusSebelum instanceof HTMLElement) {
      fokusSebelum.focus()
      fokusSebelum = null
    }
  },
)

function jawab(nilai) {
  dialog.value?.selesai(nilai)
}

/*
 * Didengarkan pada fase CAPTURE di window dan rambatannya dihentikan.
 * Dialog Reka (Modal.vue) mendengarkan Escape di window pada fase bubble;
 * tanpa ini, Escape yang menutup konfirmasi di atas sebuah formulir ikut
 * menutup formulirnya.
 */
function tekanTombol(e) {
  if (dialog.value) {
    if (e.key === 'Escape') {
      e.preventDefault()
      e.stopPropagation()
      jawab(false)
    }

    return
  }

  if (popup.value && (e.key === 'Escape' || e.key === 'Enter')) {
    // Enter juga menutup, sama seperti tombol "Oke" pada SweetAlert — tetapi
    // hanya bila fokus tidak sedang berada di kolom isian.
    if (e.key === 'Enter' && /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName ?? '')) return
    e.stopPropagation()
    tutupPopup()
  }
}

onMounted(() => window.addEventListener('keydown', tekanTombol, true))

onBeforeUnmount(() => {
  hentikanHitung()
  window.removeEventListener('keydown', tekanTombol, true)
  lepas.forEach((fn) => fn())
})

const warnaTombolYa = computed(() =>
  dialog.value?.nada === 'bahaya' ? 'tombol-bahaya' : 'tombol tombol-utama',
)
</script>

<template>
  <Teleport to="body">
    <!-- ================= Popup ================= -->
    <Transition name="pn">
      <div
        v-if="popup && !dialog"
        :key="popup.id"
        class="pn-tirai fixed inset-0 z-[95] flex items-center justify-center p-4"
        @pointerdown.stop
        @focusin.stop
        @click.self="tutupPopup"
      >
        <div
          :role="popup.jenis === 'gagal' ? 'alertdialog' : 'status'"
          aria-live="assertive"
          :aria-label="popup.judul"
          class="pn-kartu relative w-full max-w-sm overflow-hidden rounded-3xl bg-permukaan text-center"
          @pointerenter="(e) => { if (e.pointerType === 'mouse') terjeda = true }"
          @pointerleave="terjeda = false"
          @touchstart.passive="terjeda = true"
          @touchend.passive="terjeda = false"
        >
          <div class="pn-cahaya pointer-events-none absolute inset-x-0 top-0 h-36" :class="`pn-cahaya-${popup.jenis}`" aria-hidden="true"></div>

          <div class="relative px-6 pb-6 pt-8 sm:px-8">
            <LencanaNotifikasi :jenis="popup.jenis" :berdenyut="popup.jenis !== 'gagal'" />

            <h2 class="pn-muncul mt-5 font-display text-xl font-semibold text-utama sm:text-2xl" style="--urut: 1">
              {{ popup.judul }}
            </h2>

            <p
              v-if="popup.teks"
              class="pn-muncul mx-auto mt-2 max-w-xs text-sm leading-relaxed text-sekunder"
              style="--urut: 2"
            >
              {{ popup.teks }}
            </p>

            <button
              type="button"
              class="pn-muncul mt-6 w-full justify-center py-2.5"
              :class="popup.jenis === 'gagal' ? 'tombol-bahaya' : 'tombol tombol-utama'"
              style="--urut: 3"
              @click="tutupPopup"
            >
              {{ popup.tombol }}
            </button>
          </div>

          <div v-if="popup.durasi > 0" class="h-1 w-full bg-permukaan-hover" aria-hidden="true">
            <div
              class="pn-progres h-full origin-left"
              :class="`pn-progres-${popup.jenis}`"
              :style="{ transform: `scaleX(${progres})` }"
            ></div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- ================= Konfirmasi ================= -->
    <Transition name="pn">
      <div
        v-if="dialog"
        :key="dialog.id"
        class="pn-tirai fixed inset-0 z-[96] flex items-center justify-center p-4"
        @pointerdown.stop
        @focusin.stop
        @click.self="jawab(false)"
      >
        <FocusScope trapped loop as-child>

        <div
          role="alertdialog"
          aria-modal="true"
          :aria-labelledby="`pn-judul-${dialog.id}`"
          :aria-describedby="dialog.teks ? `pn-teks-${dialog.id}` : undefined"
          class="pn-kartu relative w-full max-w-md overflow-hidden rounded-3xl bg-permukaan text-center"
        >
          <div class="pn-cahaya pointer-events-none absolute inset-x-0 top-0 h-36" :class="`pn-cahaya-${dialog.nada}`" aria-hidden="true"></div>

          <div class="relative px-6 pb-6 pt-8 sm:px-8">
            <LencanaNotifikasi :jenis="dialog.nada" :berdenyut="false" />

            <h2
              :id="`pn-judul-${dialog.id}`"
              class="pn-muncul mt-5 font-display text-xl font-semibold text-utama sm:text-2xl"
              style="--urut: 1"
            >
              {{ dialog.judul }}
            </h2>

            <p
              v-if="dialog.teks"
              :id="`pn-teks-${dialog.id}`"
              class="pn-muncul mx-auto mt-2 max-w-sm whitespace-pre-line text-sm leading-relaxed text-sekunder"
              style="--urut: 2"
            >
              {{ dialog.teks }}
            </p>

            <div class="pn-muncul mt-6 flex flex-col-reverse gap-2.5 sm:flex-row" style="--urut: 3">
              <button
                ref="tombolTidak"
                type="button"
                class="tombol-sekunder flex-1 justify-center py-2.5"
                @click="jawab(false)"
              >
                {{ dialog.tombolTidak }}
              </button>
              <button
                ref="tombolYa"
                type="button"
                class="flex-1 justify-center py-2.5"
                :class="warnaTombolYa"
                @click="jawab(true)"
              >
                {{ dialog.tombolYa }}
              </button>
            </div>
          </div>
        </div>
        </FocusScope>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.pn-tirai {
  /* Dialog Reka mematikan klik pada body selama terbuka; tirai ini harus
     tetap dapat diklik saat muncul di atas sebuah formulir. */
  pointer-events: auto;
  background: rgb(8 25 42 / 0.5);
  backdrop-filter: blur(5px) saturate(120%);
}

.pn-kartu {
  box-shadow:
    0 30px 80px -20px rgb(8 25 42 / 0.55),
    0 0 0 1px var(--tema-garis);
}

.pn-cahaya-sukses {
  background: radial-gradient(60% 100% at 50% 0%, var(--tema-berhasil-lembut), transparent 75%);
}
.pn-cahaya-gagal,
.pn-cahaya-bahaya {
  background: radial-gradient(60% 100% at 50% 0%, var(--tema-galat-lembut), transparent 75%);
}
.pn-cahaya-peringatan {
  background: radial-gradient(60% 100% at 50% 0%, var(--tema-peringatan-lembut), transparent 75%);
}
.pn-cahaya-info {
  background: radial-gradient(60% 100% at 50% 0%, var(--tema-aksen-lembut), transparent 75%);
}

/* Tombol merah untuk gagal & tindakan berbahaya — melengkapi .tombol-utama. */
.tombol-bahaya {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  border-radius: var(--radius-lg);
  background-image: linear-gradient(145deg, #f43f5e, #be123c);
  color: #fff;
  font-size: 0.875rem;
  font-weight: 600;
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / 0.25),
    0 2px 8px rgb(225 29 72 / 0.3);
  transition:
    transform 150ms ease,
    filter 150ms ease;
}
.tombol-bahaya:hover {
  filter: brightness(1.06);
}
.tombol-bahaya:active {
  transform: scale(0.97);
}

.tombol-sekunder {
  display: inline-flex;
  align-items: center;
  border-radius: var(--radius-lg);
  border: 1px solid var(--tema-garis);
  background: var(--tema-permukaan);
  color: var(--tema-sekunder);
  font-size: 0.875rem;
  font-weight: 500;
  transition:
    background-color 150ms ease,
    transform 150ms ease;
}
.tombol-sekunder:hover {
  background: var(--tema-permukaan-hover);
}
.tombol-sekunder:active {
  transform: scale(0.97);
}

.tombol-bahaya:focus-visible,
.tombol-sekunder:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--tema-aksen-lembut);
}

/* ---------- Masuk & keluar ---------- */
.pn-enter-active {
  transition:
    opacity 240ms ease,
    backdrop-filter 240ms ease;
}
.pn-leave-active {
  transition:
    opacity 200ms ease 40ms,
    backdrop-filter 200ms ease;
}
.pn-enter-from,
.pn-leave-to {
  opacity: 0;
  backdrop-filter: blur(0);
}

.pn-enter-active .pn-kartu {
  animation: kartu-masuk 560ms cubic-bezier(0.34, 1.56, 0.64, 1) both;
}
.pn-leave-active .pn-kartu {
  animation: kartu-keluar 220ms cubic-bezier(0.4, 0, 1, 1) both;
}

@keyframes kartu-masuk {
  0% {
    opacity: 0;
    transform: translateY(24px) scale(0.88);
  }
  60% {
    opacity: 1;
  }
  100% {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

@keyframes kartu-keluar {
  to {
    opacity: 0;
    transform: translateY(10px) scale(0.94);
  }
}

.pn-muncul {
  animation: muncul 460ms cubic-bezier(0.22, 1, 0.36, 1) both;
  animation-delay: calc(240ms + var(--urut, 0) * 70ms);
}

@keyframes muncul {
  from {
    opacity: 0;
    transform: translateY(10px);
    filter: blur(3px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
    filter: blur(0);
  }
}

.pn-progres {
  will-change: transform;
}
.pn-progres-sukses {
  background: linear-gradient(90deg, #34d399, #059669);
}
.pn-progres-gagal {
  background: linear-gradient(90deg, #fb7185, #e11d48);
}
.pn-progres-peringatan {
  background: linear-gradient(90deg, #fbbf24, #b45309);
}
.pn-progres-info {
  background: linear-gradient(90deg, #2dd4bf, #0d9488);
}

@media (prefers-reduced-motion: reduce) {
  .pn-enter-active .pn-kartu,
  .pn-leave-active .pn-kartu,
  .pn-muncul {
    animation: none;
  }
}
</style>
