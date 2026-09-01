<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

/**
 * Dashboard ringkasan kehadiran (FR-DASH-01, FR-DASH-02).
 *
 * Grafik tren digambar sebagai SVG inline, bukan lewat pustaka chart: satu
 * grafik area tujuh titik tidak sepadan dengan tambahan berat bundel, dan
 * warnanya jadi mengikuti palet proyek apa adanya.
 */

const props = defineProps({
  statistik: { type: Object, required: true },
  tren: { type: Array, required: true },
  aktivitas: { type: Array, required: true },
})

/*
 * Feed disegarkan setiap 20 detik. Lebih lambat daripada layar kiosk yang
 * 10 detik: admin membaca ringkasan, bukan menunggu namanya muncul.
 */
const JEDA_SEGAR_MS = 20000

const aktivitasTerkini = ref(props.aktivitas)
let jedaSegar = null

onMounted(() => {
  jedaSegar = setInterval(segarkanAktivitas, JEDA_SEGAR_MS)
})

onBeforeUnmount(() => clearInterval(jedaSegar))

async function segarkanAktivitas() {
  try {
    const jawaban = await fetch('/admin/dashboard/aktivitas', {
      headers: { Accept: 'application/json' },
    })

    if (!jawaban.ok) return

    aktivitasTerkini.value = (await jawaban.json()).aktivitas
  } catch {
    // Gangguan sesaat; percobaan berikutnya menyusul sendiri.
  }
}

function waktuRelatif(iso) {
  const selisih = Math.round((Date.now() - new Date(iso).getTime()) / 60000)

  if (selisih < 1) return 'baru saja'
  if (selisih < 60) return `${selisih} menit lalu`
  if (selisih < 1440) return `${Math.floor(selisih / 60)} jam lalu`

  return `${Math.floor(selisih / 1440)} hari lalu`
}

const page = usePage()
const pengguna = computed(() => page.props.auth.pengguna)

const cakupan = computed(() =>
  pengguna.value.lintas_unit
    ? 'seluruh unit kerja'
    : (pengguna.value.unit_kerja?.nama ?? 'tanpa unit kerja'),
)

const kartu = computed(() => [
  {
    label: 'Total Pegawai',
    nilai: props.statistik.total_pegawai,
    keterangan: 'pegawai aktif dalam cakupan Anda',
    warna: 'text-navy-700',
  },
  {
    label: 'Kiosk Aktif',
    nilai: props.statistik.kiosk_aktif,
    keterangan: 'perangkat melayani event hari ini',
    warna: 'text-teal-700',
  },
  {
    label: 'Event Berlangsung',
    nilai: props.statistik.event_berlangsung,
    keterangan: 'entry masih dibuka',
    warna: 'text-teal-700',
  },
  {
    label: 'Kehadiran Hari Ini',
    nilai: `${props.statistik.persentase_kehadiran}%`,
    keterangan: `${props.statistik.hadir_hari_ini} dari ${props.statistik.total_pegawai} pegawai`,
    warna: props.statistik.persentase_kehadiran >= 75 ? 'text-emerald-700' : 'text-amber-700',
  },
])

/* Geometri grafik area. */
const LEBAR = 720
const TINGGI = 200
const PADDING = 28

const puncak = computed(() => Math.max(1, ...props.tren.map((t) => t.jumlah)))

const titik = computed(() =>
  props.tren.map((baris, urutan) => {
    const jarak = props.tren.length > 1 ? (LEBAR - PADDING * 2) / (props.tren.length - 1) : 0

    return {
      ...baris,
      x: PADDING + urutan * jarak,
      y: TINGGI - PADDING - (baris.jumlah / puncak.value) * (TINGGI - PADDING * 2),
    }
  }),
)

const garis = computed(() => titik.value.map((t) => `${t.x},${t.y}`).join(' '))

const area = computed(() => {
  if (titik.value.length === 0) return ''

  const awal = titik.value[0]
  const akhir = titik.value[titik.value.length - 1]

  return `${awal.x},${TINGGI - PADDING} ${garis.value} ${akhir.x},${TINGGI - PADDING}`
})

const adaKehadiran = computed(() => props.tren.some((t) => t.jumlah > 0))
</script>

<template>
  <AdminLayout judul="Dashboard" :deskripsi="`Ringkasan kehadiran untuk ${cakupan}.`">
    <!-- FR-DASH-01 -->
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <div
        v-for="item in kartu"
        :key="item.label"
        class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm"
      >
        <p class="text-xs font-medium uppercase tracking-wider text-slate-500">{{ item.label }}</p>
        <p class="mt-2 font-display text-3xl font-semibold tabular-nums" :class="item.warna">
          {{ item.nilai }}
        </p>
        <p class="mt-1 text-xs text-slate-500">{{ item.keterangan }}</p>
      </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,380px)]">
      <!-- FR-DASH-02 -->
      <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
      <div class="flex items-baseline justify-between">
        <h2 class="font-display text-base font-semibold text-navy-700">Tren Kehadiran</h2>
        <p class="text-xs text-slate-500">7 hari terakhir</p>
      </div>

      <div v-if="!adaKehadiran" class="mt-6 rounded-md border border-dashed border-slate-300 px-4 py-12 text-center text-sm text-slate-500">
        Belum ada kehadiran tercatat pada rentang ini.
      </div>

      <svg
        v-else
        :viewBox="`0 0 ${LEBAR} ${TINGGI}`"
        class="mt-4 w-full"
        role="img"
        aria-label="Grafik tren kehadiran tujuh hari terakhir"
      >
        <defs>
          <linearGradient id="gradienTren" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#0D9488" stop-opacity="0.28" />
            <stop offset="100%" stop-color="#0D9488" stop-opacity="0" />
          </linearGradient>
        </defs>

        <!-- Garis bantu horizontal -->
        <line
          v-for="bagian in [0, 0.5, 1]"
          :key="bagian"
          :x1="PADDING"
          :x2="LEBAR - PADDING"
          :y1="TINGGI - PADDING - bagian * (TINGGI - PADDING * 2)"
          :y2="TINGGI - PADDING - bagian * (TINGGI - PADDING * 2)"
          stroke="#e2e8f0"
          stroke-width="1"
        />

        <polygon :points="area" fill="url(#gradienTren)" />
        <polyline :points="garis" fill="none" stroke="#0D9488" stroke-width="2.5" stroke-linejoin="round" />

        <g v-for="t in titik" :key="t.tanggal">
          <circle :cx="t.x" :cy="t.y" r="4" fill="#0D9488" />
          <text
            :x="t.x"
            :y="t.y - 12"
            text-anchor="middle"
            class="fill-navy-700 font-display text-[13px] tabular-nums"
          >
            {{ t.jumlah }}
          </text>
          <text
            :x="t.x"
            :y="TINGGI - 6"
            text-anchor="middle"
            class="fill-slate-500 text-[12px]"
          >
            {{ t.label }}
          </text>
        </g>
      </svg>
      </div>

      <!-- FR-DASH-03 -->
      <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex items-baseline justify-between">
          <h2 class="font-display text-base font-semibold text-navy-700">Aktivitas Terbaru</h2>
          <span class="flex items-center gap-1.5 text-xs text-slate-500">
            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500"></span>
            live
          </span>
        </div>

        <ol v-if="aktivitasTerkini.length > 0" class="mt-4 space-y-3">
          <li
            v-for="baris in aktivitasTerkini"
            :key="baris.id"
            class="flex items-start gap-3 border-b border-slate-100 pb-3 last:border-0 last:pb-0"
          >
            <span
              class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
              :class="baris.status_ketepatan === 'terlambat' ? 'bg-amber-500' : 'bg-emerald-500'"
            ></span>

            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium text-navy-700">{{ baris.nama }}</p>
              <p class="truncate text-xs text-slate-500">{{ baris.unit_kerja ?? '—' }}</p>
              <p class="mt-0.5 text-xs text-slate-500">
                <span class="font-display tabular-nums">{{ baris.jam }}</span>
                · {{ baris.jenis_label }}
                · {{ baris.metode_label }}
                <span
                  v-if="baris.status_label"
                  :class="baris.status_ketepatan === 'terlambat' ? 'text-amber-700' : 'text-emerald-700'"
                >
                  · {{ baris.status_label }}
                </span>
              </p>
            </div>

            <span class="shrink-0 whitespace-nowrap text-xs text-slate-400">
              {{ waktuRelatif(baris.waktu) }}
            </span>
          </li>
        </ol>

        <p v-else class="mt-6 rounded-md border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500">
          Belum ada aktivitas absen.
        </p>
      </div>
    </div>
  </AdminLayout>
</template>
