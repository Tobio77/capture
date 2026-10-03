<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import Ikon from '@/Components/Ikon.vue'

/**
 * Notifikasi absen ganda (FR-TAP-05, revisi S28a) — dialog di tengah layar.
 *
 * Sebelumnya penolakan "sudah absen" hanya mengganti satu baris status di
 * panel kiri, yang luput dibaca orang yang berdiri satu langkah dari layar
 * dan sudah berbalik pergi. Dialog di tengah, dengan jam yang tercatat
 * tertulis besar, menjawab pertanyaan yang sebenarnya ia punya: "berarti
 * saya sudah aman, kan?"
 *
 * Warnanya amber (peringatan), bukan merah: kehadirannya justru sudah
 * tercatat. Yang diperingatkan hanyalah bahwa tap kedua ini tidak diperlukan.
 *
 * FOKUS SENGAJA TIDAK DIREBUT. Pembaca RFID mengetikkan UID ke kolom tap yang
 * sedang berfokus; dialog yang merebut fokus akan menelan tap orang
 * berikutnya. Karena itu ia diumumkan lewat `role="alert"` saja, dan tap
 * baru — yang memindahkan tahap layar — menutupnya dengan sendirinya.
 */

const props = defineProps({
  terbuka: { type: Boolean, default: false },

  /** 'datang' atau 'pulang'. */
  jenis: { type: String, default: 'datang' },

  /** Jam yang sudah tercatat, HH:MM. */
  jam: { type: String, default: null },

  nama: { type: String, default: null },
  nip: { type: String, default: null },
  unitKerja: { type: String, default: null },

  /** "hari ini" pada Absen Umum, atau "pada kegiatan …" pada Absen Event. */
  konteks: { type: String, default: 'hari ini' },

  /** Lama tampil sebelum menutup sendiri, dalam milidetik. */
  durasi: { type: Number, default: 5000 },
})

const emit = defineEmits(['tutup'])

const labelJenis = computed(() => (props.jenis === 'pulang' ? 'Pulang' : 'Datang'))
const jamTertulis = computed(() => (props.jam ?? '—').replace(':', '.'))

/*
 * Hitung mundur yang dapat dijeda. Diarahkan kursor atau disentuh berarti
 * sedang dibaca — menutupnya di tengah kalimat terasa merampas.
 */
const sisa = ref(props.durasi)
const terjeda = ref(false)
let pewaktu = null
let terakhir = 0

function detak(sekarang) {
  if (!terjeda.value) {
    sisa.value = Math.max(0, sisa.value - (sekarang - terakhir))
  }

  terakhir = sekarang

  if (sisa.value === 0) {
    emit('tutup')

    return
  }

  pewaktu = requestAnimationFrame(detak)
}

function mulai() {
  berhenti()
  sisa.value = props.durasi
  terakhir = performance.now()
  pewaktu = requestAnimationFrame(detak)
}

function berhenti() {
  if (pewaktu !== null) cancelAnimationFrame(pewaktu)
  pewaktu = null
}

const progres = computed(() => (props.durasi > 0 ? sisa.value / props.durasi : 0))

function tekanTombol(e) {
  if (e.key === 'Escape') emit('tutup')
}

watch(
  () => props.terbuka,
  (terbuka) => {
    if (terbuka) {
      mulai()
      window.addEventListener('keydown', tekanTombol)
    } else {
      berhenti()
      window.removeEventListener('keydown', tekanTombol)
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  berhenti()
  window.removeEventListener('keydown', tekanTombol)
})
</script>

<template>
  <Teleport to="body">
    <Transition name="notif">
      <div
        v-if="terbuka"
        class="notif-tirai fixed inset-0 z-[90] flex items-center justify-center p-4"
        @click.self="emit('tutup')"
      >
        <div
          role="alert"
          aria-live="assertive"
          class="notif-kartu relative w-full max-w-md overflow-hidden rounded-3xl bg-permukaan text-center"
          @mouseenter="terjeda = true"
          @mouseleave="terjeda = false"
          @touchstart.passive="terjeda = true"
          @touchend.passive="terjeda = false"
        >
          <!-- Cahaya lembut di belakang ikon. -->
          <div class="notif-cahaya pointer-events-none absolute inset-x-0 top-0 h-40" aria-hidden="true"></div>

          <div class="relative px-7 pb-6 pt-9 sm:px-9">
            <!-- Ikon: dua gelombang denyut, lingkaran yang tergambar, dan centang. -->
            <div class="relative mx-auto h-24 w-24" aria-hidden="true">
              <span class="notif-gelombang absolute inset-0 rounded-full"></span>
              <span class="notif-gelombang notif-gelombang-2 absolute inset-0 rounded-full"></span>

              <div class="notif-lencana relative flex h-24 w-24 items-center justify-center rounded-full">
                <svg viewBox="0 0 52 52" class="h-16 w-16">
                  <circle class="notif-lingkar" cx="26" cy="26" r="23" fill="none" />
                  <path class="notif-centang" fill="none" d="M15 27.5l7 7 15-16" />
                </svg>
              </div>

              <span
                class="notif-jam absolute -bottom-1 -right-1 flex h-9 w-9 items-center justify-center rounded-full border-4 border-permukaan"
              >
                <Ikon nama="jam" ukuran="h-4 w-4" />
              </span>
            </div>

            <p class="notif-muncul mt-6 text-xs font-semibold uppercase tracking-[0.2em] text-peringatan-teks" style="--urut: 1">
              Tidak perlu absen lagi
            </p>

            <h2 class="notif-muncul mt-1.5 font-display text-2xl font-semibold text-utama sm:text-[1.7rem]" style="--urut: 2">
              Sudah Absen {{ labelJenis }}
            </h2>

            <p class="notif-muncul mt-2 text-sm leading-relaxed text-sekunder" style="--urut: 3">
              Kehadiran {{ jenis }} Anda sudah tercatat {{ konteks }}. Tap kedua ini tidak disimpan.
            </p>

            <!-- Jam yang tercatat: angka yang paling dicari, jadi paling besar. -->
            <div class="notif-muncul mt-5 inline-flex items-baseline gap-2 rounded-2xl px-5 py-2.5 notif-pil" style="--urut: 4">
              <span class="text-xs font-medium text-peringatan-teks">Tercatat pukul</span>
              <span class="font-display text-3xl font-semibold tabular-nums text-utama">{{ jamTertulis }}</span>
            </div>

            <div
              v-if="nama"
              class="notif-muncul mt-5 rounded-2xl border border-garis bg-permukaan-2 px-4 py-3 text-left"
              style="--urut: 5"
            >
              <p class="truncate font-medium text-utama">{{ nama }}</p>
              <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-redup">
                <span v-if="nip" class="font-display tabular-nums">{{ nip }}</span>
                <span v-if="nip && unitKerja" aria-hidden="true">·</span>
                <span v-if="unitKerja" class="truncate">{{ unitKerja }}</span>
              </p>
            </div>

            <button
              type="button"
              class="notif-muncul tombol tombol-utama mt-6 w-full justify-center py-3 text-base"
              style="--urut: 6"
              @click="emit('tutup')"
            >
              Mengerti
            </button>
          </div>

          <!-- Hitung mundur penutupan otomatis. -->
          <div class="h-1.5 w-full bg-permukaan-hover" aria-hidden="true">
            <div
              class="notif-progres h-full origin-left"
              :style="{ transform: `scaleX(${progres})` }"
            ></div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
/* ---------- Tirai ---------- */
.notif-tirai {
  background: rgb(8 25 42 / 0.55);
  backdrop-filter: blur(6px) saturate(120%);
}

/* ---------- Kartu ---------- */
.notif-kartu {
  box-shadow:
    0 30px 80px -20px rgb(8 25 42 / 0.55),
    0 0 0 1px var(--tema-garis);
}

.notif-cahaya {
  background: radial-gradient(60% 100% at 50% 0%, var(--tema-peringatan-lembut), transparent 75%);
}

/* ---------- Masuk & keluar ---------- */
.notif-enter-active {
  transition: opacity 260ms ease, backdrop-filter 260ms ease;
}
.notif-leave-active {
  transition: opacity 220ms ease 60ms, backdrop-filter 220ms ease;
}
.notif-enter-from,
.notif-leave-to {
  opacity: 0;
  backdrop-filter: blur(0);
}

.notif-enter-active .notif-kartu {
  animation: kartu-masuk 620ms cubic-bezier(0.34, 1.56, 0.64, 1) both;
}
.notif-leave-active .notif-kartu {
  animation: kartu-keluar 240ms cubic-bezier(0.4, 0, 1, 1) both;
}

@keyframes kartu-masuk {
  0% {
    opacity: 0;
    transform: translateY(28px) scale(0.86);
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
    transform: translateY(12px) scale(0.94);
  }
}

/* ---------- Ikon ---------- */
.notif-lencana {
  background: radial-gradient(circle at 30% 25%, #fde68a, #f59e0b 70%);
  color: #fff;
  box-shadow:
    0 12px 28px -8px rgb(180 83 9 / 0.55),
    inset 0 2px 0 rgb(255 255 255 / 0.35);
  animation: lencana-pop 700ms cubic-bezier(0.34, 1.56, 0.64, 1) 120ms both;
}

@keyframes lencana-pop {
  0% {
    transform: scale(0.3) rotate(-25deg);
    opacity: 0;
  }
  100% {
    transform: scale(1) rotate(0);
    opacity: 1;
  }
}

.notif-lingkar {
  stroke: rgb(255 255 255 / 0.9);
  stroke-width: 3;
  stroke-linecap: round;
  stroke-dasharray: 145;
  stroke-dashoffset: 145;
  transform: rotate(-90deg);
  transform-origin: center;
  animation: gambar 700ms cubic-bezier(0.65, 0, 0.35, 1) 380ms forwards;
}

.notif-centang {
  stroke: #fff;
  stroke-width: 4.5;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-dasharray: 40;
  stroke-dashoffset: 40;
  animation: gambar 420ms cubic-bezier(0.65, 0, 0.35, 1) 900ms forwards;
}

@keyframes gambar {
  to {
    stroke-dashoffset: 0;
  }
}

.notif-gelombang {
  border: 2px solid var(--tema-peringatan);
  opacity: 0;
  animation: gelombang 2.2s cubic-bezier(0.2, 0.6, 0.4, 1) 600ms infinite;
}
.notif-gelombang-2 {
  animation-delay: 1.7s;
}

@keyframes gelombang {
  0% {
    transform: scale(1);
    opacity: 0.55;
  }
  100% {
    transform: scale(1.75);
    opacity: 0;
  }
}

.notif-jam {
  background: var(--tema-utama);
  color: #fff;
  animation: jam-masuk 520ms cubic-bezier(0.34, 1.56, 0.64, 1) 1.1s both;
}

@keyframes jam-masuk {
  0% {
    transform: scale(0) rotate(-120deg);
  }
  100% {
    transform: scale(1) rotate(0);
  }
}

/* ---------- Isi bertahap ---------- */
.notif-muncul {
  animation: muncul 480ms cubic-bezier(0.22, 1, 0.36, 1) both;
  animation-delay: calc(260ms + var(--urut, 0) * 70ms);
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

.notif-pil {
  background: var(--tema-peringatan-lembut);
}

/* ---------- Hitung mundur ---------- */
.notif-progres {
  background: linear-gradient(90deg, #f59e0b, var(--tema-peringatan));
  will-change: transform;
}

/* Gerak dikurangi: cukup memudar, tanpa pegas, denyut, atau goresan. */
@media (prefers-reduced-motion: reduce) {
  .notif-enter-active .notif-kartu,
  .notif-leave-active .notif-kartu,
  .notif-lencana,
  .notif-jam,
  .notif-muncul {
    animation: none;
  }
  .notif-gelombang {
    display: none;
  }
  .notif-lingkar,
  .notif-centang {
    animation: none;
    stroke-dashoffset: 0;
  }
}
</style>
