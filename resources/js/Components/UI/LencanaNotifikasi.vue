<script setup>
/**
 * Ikon beranimasi untuk popup dan dialog konfirmasi.
 *
 * Lencana bergradasi berputar masuk, lingkarannya tergambar, lalu tanda di
 * dalamnya tergambar menyusul — centang, silang, seru, atau "i". Dua
 * gelombang denyut memancar di belakangnya selama `berdenyut` menyala.
 *
 * Warnanya mengikuti palet proyek: emerald berhasil, rose gagal/bahaya,
 * amber peringatan, teal informasi.
 */
defineProps({
  /** sukses | gagal | peringatan | info | bahaya */
  jenis: { type: String, default: 'info' },
  berdenyut: { type: Boolean, default: true },
})
</script>

<template>
  <div class="lencana-bungkus relative mx-auto h-20 w-20 sm:h-24 sm:w-24" :class="`lencana-${jenis}`" aria-hidden="true">
    <template v-if="berdenyut">
      <span class="gelombang absolute inset-0 rounded-full"></span>
      <span class="gelombang gelombang-2 absolute inset-0 rounded-full"></span>
    </template>

    <div
      class="lencana relative flex h-full w-full items-center justify-center rounded-full"
      :class="{ 'lencana-goyang': jenis === 'gagal' || jenis === 'bahaya' }"
    >
      <svg viewBox="0 0 52 52" class="h-[66%] w-[66%]">
        <circle class="lingkar" cx="26" cy="26" r="23" fill="none" />

        <path v-if="jenis === 'sukses'" class="goresan" style="--panjang: 40" d="M15 27.5l7 7 15-16" />

        <template v-else-if="jenis === 'gagal'">
          <path class="goresan" style="--panjang: 24" d="M18 18l16 16" />
          <path class="goresan goresan-2" style="--panjang: 24" d="M34 18L18 34" />
        </template>

        <template v-else-if="jenis === 'info'">
          <path class="goresan" style="--panjang: 16" d="M26 23v13" />
          <circle class="titik" cx="26" cy="16.5" r="2.4" />
        </template>

        <!-- peringatan & bahaya: tanda seru -->
        <template v-else>
          <path class="goresan" style="--panjang: 16" d="M26 15v14" />
          <circle class="titik" cx="26" cy="36" r="2.4" />
        </template>
      </svg>
    </div>
  </div>
</template>

<style scoped>
.lencana-sukses {
  --warna-a: #6ee7b7;
  --warna-b: #059669;
  --bayang: rgb(5 150 105 / 0.5);
}
.lencana-gagal,
.lencana-bahaya {
  --warna-a: #fda4af;
  --warna-b: #e11d48;
  --bayang: rgb(225 29 72 / 0.45);
}
.lencana-peringatan {
  --warna-a: #fde68a;
  --warna-b: #f59e0b;
  --bayang: rgb(180 83 9 / 0.5);
}
.lencana-info {
  --warna-a: #5eead4;
  --warna-b: #0d9488;
  --bayang: rgb(13 148 136 / 0.45);
}

.lencana {
  background: radial-gradient(circle at 30% 25%, var(--warna-a), var(--warna-b) 72%);
  box-shadow:
    0 12px 28px -8px var(--bayang),
    inset 0 2px 0 rgb(255 255 255 / 0.35);
  animation: pop 680ms cubic-bezier(0.34, 1.56, 0.64, 1) 80ms both;
}

.lencana-goyang {
  animation:
    pop 680ms cubic-bezier(0.34, 1.56, 0.64, 1) 80ms both,
    goyang 520ms cubic-bezier(0.36, 0.07, 0.19, 0.97) 900ms both;
}

@keyframes pop {
  0% {
    transform: scale(0.3) rotate(-25deg);
    opacity: 0;
  }
  100% {
    transform: scale(1) rotate(0);
    opacity: 1;
  }
}

@keyframes goyang {
  10%,
  90% {
    transform: translateX(-1px);
  }
  20%,
  80% {
    transform: translateX(3px);
  }
  30%,
  50%,
  70% {
    transform: translateX(-5px);
  }
  40%,
  60% {
    transform: translateX(5px);
  }
}

.lingkar {
  stroke: rgb(255 255 255 / 0.9);
  stroke-width: 3;
  stroke-linecap: round;
  stroke-dasharray: 145;
  stroke-dashoffset: 145;
  transform: rotate(-90deg);
  transform-origin: center;
  animation: gambar 650ms cubic-bezier(0.65, 0, 0.35, 1) 320ms forwards;
}

.goresan {
  fill: none;
  stroke: #fff;
  stroke-width: 4.5;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-dasharray: var(--panjang);
  stroke-dashoffset: var(--panjang);
  animation: gambar 380ms cubic-bezier(0.65, 0, 0.35, 1) 780ms forwards;
}
.goresan-2 {
  animation-delay: 960ms;
}

.titik {
  fill: #fff;
  transform-box: fill-box;
  transform-origin: center;
  animation: titik 420ms cubic-bezier(0.34, 1.56, 0.64, 1) 1000ms both;
}

@keyframes gambar {
  to {
    stroke-dashoffset: 0;
  }
}

@keyframes titik {
  from {
    transform: scale(0);
  }
  to {
    transform: scale(1);
  }
}

.gelombang {
  border: 2px solid var(--warna-b);
  opacity: 0;
  animation: gelombang 2.2s cubic-bezier(0.2, 0.6, 0.4, 1) 600ms infinite;
}
.gelombang-2 {
  animation-delay: 1.7s;
}

@keyframes gelombang {
  0% {
    transform: scale(1);
    opacity: 0.5;
  }
  100% {
    transform: scale(1.7);
    opacity: 0;
  }
}

@media (prefers-reduced-motion: reduce) {
  .lencana,
  .lencana-goyang,
  .titik {
    animation: none;
  }
  .gelombang {
    display: none;
  }
  .lingkar,
  .goresan {
    animation: none;
    stroke-dashoffset: 0;
  }
}
</style>
