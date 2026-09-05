<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Ikon from '@/Components/Ikon.vue'
import Lencana from '@/Components/UI/Lencana.vue'
import KeadaanKosong from '@/Components/UI/KeadaanKosong.vue'

/**
 * Dashboard ringkasan kehadiran (FR-DASH-01 s.d. FR-DASH-03).
 *
 * Grafik digambar sebagai SVG inline, bukan lewat pustaka chart: bentuknya
 * sederhana dan tetap, sehingga satu pustaka lagi tidak sepadan — dan
 * warnanya mengikuti palet proyek apa adanya.
 */

const props = defineProps({
  statistik: { type: Object, required: true },
  tren: { type: Array, required: true },
  aktivitas: { type: Array, required: true },
  ketepatan: { type: Object, required: true },
  kesiapan: { type: Object, required: true },
  peringkat_unit: { type: Array, required: true },
  event_berjalan: { type: Array, required: true },
})

const page = usePage()
const pengguna = computed(() => page.props.auth.pengguna)

const cakupan = computed(() =>
  pengguna.value.lintas_unit
    ? 'seluruh unit kerja'
    : (pengguna.value.unit_kerja?.nama ?? 'tanpa unit kerja'),
)

/* Feed disegarkan tiap 20 detik — admin membaca ringkasan, bukan menunggu
   namanya muncul seperti pegawai di perangkat absen. */
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

const kartu = computed(() => [
  {
    label: 'Total Pegawai',
    nilai: props.statistik.total_pegawai,
    keterangan: 'pegawai aktif dalam cakupan Anda',
    ikon: 'pegawai',
    warna: 'text-utama',
    nada: 'info',
  },
  {
    label: 'Perangkat Aktif',
    nilai: props.statistik.kiosk_aktif,
    keterangan: `dari ${props.kesiapan.perangkat} perangkat terdaftar`,
    ikon: 'perangkat',
    warna: 'text-aksen-teks',
    nada: '',
  },
  {
    label: 'Event Berlangsung',
    nilai: props.statistik.event_berlangsung,
    keterangan: 'entry masih dibuka',
    ikon: 'absen',
    warna: 'text-aksen-teks',
    nada: '',
  },
  {
    label: 'Kehadiran Hari Ini',
    nilai: `${props.statistik.persentase_kehadiran}%`,
    keterangan: `${props.statistik.hadir_hari_ini} dari ${props.statistik.total_pegawai} pegawai`,
    ikon: props.statistik.persentase_kehadiran >= 75 ? 'naik' : 'turun',
    warna: props.statistik.persentase_kehadiran >= 75 ? 'text-berhasil-teks' : 'text-peringatan-teks',
    nada:
      props.statistik.persentase_kehadiran >= 75
        ? 'berhasil'
        : 'peringatan',
  },
])

const totalKetepatan = computed(() => props.ketepatan.tepat + props.ketepatan.terlambat)

const bagianTepat = computed(() =>
  totalKetepatan.value === 0 ? 0 : Math.round((props.ketepatan.tepat / totalKetepatan.value) * 100),
)

/* Geometri grafik area. */
const LEBAR = 720
const TINGGI = 190
const PADDING = 30

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

function waktuRelatif(iso) {
  const selisih = Math.round((Date.now() - new Date(iso).getTime()) / 60000)

  if (selisih < 1) return 'baru saja'
  if (selisih < 60) return `${selisih} menit lalu`
  if (selisih < 1440) return `${Math.floor(selisih / 60)} jam lalu`

  return `${Math.floor(selisih / 1440)} hari lalu`
}
</script>

<template>
  <AdminLayout judul="Dashboard" :deskripsi="`Ringkasan kehadiran untuk ${cakupan}.`">
    <!-- FR-DASH-01 -->
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <div v-for="item in kartu" :key="item.label" class="panel p-5">
        <div class="flex items-start justify-between gap-3">
          <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.1em] text-redup">
            {{ item.label }}
          </p>
          <span class="ubin-ikon h-9 w-9 shrink-0" :class="item.nada">
            <Ikon :nama="item.ikon" ukuran="h-[1.125rem] w-[1.125rem]" />
          </span>
        </div>

        <p class="mt-3 font-display text-[2rem] font-semibold leading-none tabular-nums" :class="item.warna">
          {{ item.nilai }}
        </p>

        <p class="mt-2 text-xs text-redup">{{ item.keterangan }}</p>
      </div>
    </div>

    <!-- Event berjalan -->
    <div
      v-if="event_berjalan.length > 0"
      class="mt-6 rounded-lg border border-berhasil bg-berhasil-lembut/50 p-5"
    >
      <div class="flex items-center gap-2">
        <span class="h-2 w-2 animate-pulse rounded-full bg-emerald-600"></span>
        <h2 class="font-display text-sm font-semibold text-utama">Sedang Berlangsung</h2>
      </div>

      <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <Link
          v-for="event in event_berjalan"
          :key="event.id"
          :href="`/admin/kelola-absen/rekap?event_absen_id=${event.id}`"
          class="rounded-lg border border-berhasil bg-permukaan px-4 py-3 transition hover:border-emerald-400 hover:bayang"
        >
          <p class="truncate font-medium text-utama">{{ event.nama }}</p>
          <p class="mt-0.5 truncate text-xs text-redup">
            {{ event.jam_mulai }} · {{ event.cakupan }}
          </p>
          <p class="mt-2 font-display text-lg font-semibold tabular-nums text-berhasil-teks">
            {{ event.hadir }}
            <span class="text-xs font-normal text-redup">sudah absen</span>
          </p>
        </Link>
      </div>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,380px)]">
      <div class="space-y-6">
        <!-- FR-DASH-02 -->
        <div class="panel p-6">
          <div class="flex items-baseline justify-between">
            <h2 class="font-display text-base font-semibold text-utama">Tren Kehadiran</h2>
            <p class="text-xs text-redup">7 hari terakhir</p>
          </div>

          <KeadaanKosong
            v-if="!adaKehadiran"
            ikon="absen"
            judul="Belum ada kehadiran"
            keterangan="Grafik terisi begitu ada absensi tercatat pada rentang ini."
          />

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
            <polyline
              :points="garis"
              fill="none"
              stroke="#0D9488"
              stroke-width="2.5"
              stroke-linejoin="round"
              stroke-linecap="round"
            />

            <g v-for="t in titik" :key="t.tanggal">
              <circle :cx="t.x" :cy="t.y" r="4.5" fill="#fff" stroke="#0D9488" stroke-width="2.5" />
              <text
                :x="t.x"
                :y="t.y - 13"
                text-anchor="middle"
                class="fill-navy-700 font-display text-[13px] tabular-nums"
              >
                {{ t.jumlah }}
              </text>
              <text :x="t.x" :y="TINGGI - 8" text-anchor="middle" class="fill-[var(--tema-redup)] text-[12px]">
                {{ t.label }}
              </text>
            </g>
          </svg>
        </div>

        <!-- Ketepatan & kesiapan -->
        <div class="grid gap-6 md:grid-cols-2">
          <div class="panel p-6">
            <h2 class="font-display text-base font-semibold text-utama">Ketepatan Hari Ini</h2>

            <p v-if="totalKetepatan === 0" class="mt-4 text-sm text-redup">
              Belum ada absen masuk hari ini.
            </p>

            <template v-else>
              <div class="mt-4 flex h-3 overflow-hidden rounded-full bg-permukaan-2">
                <div
                  class="bg-berhasil transition-all duration-500"
                  :style="{ width: `${bagianTepat}%` }"
                ></div>
                <div class="flex-1 bg-peringatan transition-all duration-500"></div>
              </div>

              <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                  <p class="font-display text-2xl font-semibold tabular-nums text-berhasil-teks">
                    {{ ketepatan.tepat }}
                  </p>
                  <p class="text-xs text-redup">tepat waktu ({{ bagianTepat }}%)</p>
                </div>
                <div>
                  <p class="font-display text-2xl font-semibold tabular-nums text-peringatan-teks">
                    {{ ketepatan.terlambat }}
                  </p>
                  <p class="text-xs text-redup">terlambat</p>
                </div>
              </div>
            </template>
          </div>

          <div class="panel p-6">
            <h2 class="font-display text-base font-semibold text-utama">Kesiapan Sistem</h2>
            <p class="mt-1 text-xs text-redup">
              Yang biasanya menjelaskan kegagalan absen di lapangan.
            </p>

            <div class="mt-4 space-y-3">
              <div v-for="baris in [
                { label: 'Wajah terdaftar', nilai: kesiapan.wajah_terdaftar, total: kesiapan.pegawai, persen: kesiapan.wajah_persen },
                { label: 'Kartu RFID terdaftar', nilai: kesiapan.kartu_terdaftar, total: kesiapan.pegawai, persen: kesiapan.kartu_persen },
              ]" :key="baris.label">
                <div class="flex items-baseline justify-between text-xs">
                  <span class="text-sekunder">{{ baris.label }}</span>
                  <span class="font-display tabular-nums text-utama">
                    {{ baris.nilai }}/{{ baris.total }} · {{ baris.persen }}%
                  </span>
                </div>
                <div class="mt-1 h-2 overflow-hidden rounded-full bg-permukaan-2">
                  <div
                    class="h-full rounded-full transition-all duration-500"
                    :class="baris.persen >= 80 ? 'bg-berhasil' : baris.persen >= 40 ? 'bg-aksen-lembut0' : 'bg-peringatan'"
                    :style="{ width: `${Math.max(baris.persen, 2)}%` }"
                  ></div>
                </div>
              </div>

              <div class="flex items-center justify-between border-t border-garis pt-3 text-xs">
                <span class="text-sekunder">Perangkat terpasang</span>
                <Lencana :warna="kesiapan.perangkat_terpasang === kesiapan.perangkat ? 'emerald' : 'amber'">
                  {{ kesiapan.perangkat_terpasang }} dari {{ kesiapan.perangkat }}
                </Lencana>
              </div>
            </div>
          </div>
        </div>

        <!-- Peringkat unit -->
        <div v-if="peringkat_unit.length > 0" class="panel p-6">
          <h2 class="font-display text-base font-semibold text-utama">Kehadiran per Unit Kerja</h2>
          <p class="mt-1 text-xs text-redup">Hari ini, diurutkan menurut persentase kehadiran.</p>

          <div class="mt-4 space-y-3">
            <div v-for="unit in peringkat_unit" :key="unit.kode">
              <div class="flex items-baseline justify-between gap-3 text-sm">
                <span class="truncate text-utama">{{ unit.nama }}</span>
                <span class="shrink-0 font-display text-xs tabular-nums text-redup">
                  {{ unit.hadir }}/{{ unit.pegawai }} · {{ unit.persen }}%
                </span>
              </div>
              <div class="mt-1 h-2 overflow-hidden rounded-full bg-permukaan-2">
                <div
                  class="h-full rounded-full transition-all duration-700"
                  :class="unit.persen >= 75 ? 'bg-berhasil' : unit.persen >= 40 ? 'bg-aksen-lembut0' : 'bg-peringatan'"
                  :style="{ width: `${Math.max(unit.persen, 2)}%` }"
                ></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- FR-DASH-03 -->
      <div class="panel p-6">
        <div class="flex items-baseline justify-between">
          <h2 class="font-display text-base font-semibold text-utama">Aktivitas Terbaru</h2>
          <Lencana warna="emerald" denyut>live</Lencana>
        </div>

        <ol v-if="aktivitasTerkini.length > 0" class="mt-4 space-y-3">
          <li
            v-for="baris in aktivitasTerkini"
            :key="baris.id"
            class="flex items-start gap-3 border-b border-garis pb-3 last:border-0 last:pb-0"
          >
            <span
              class="mt-0.5 rounded-full p-1.5"
              :class="baris.status_ketepatan === 'terlambat'
                ? 'bg-peringatan-lembut text-peringatan'
                : 'bg-berhasil-lembut text-berhasil'"
            >
              <Ikon :nama="baris.metode === 'rfid' ? 'kartu' : 'pegawai'" ukuran="h-3.5 w-3.5" />
            </span>

            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium text-utama">{{ baris.nama }}</p>
              <p class="truncate text-xs text-redup">{{ baris.unit_kerja ?? '—' }}</p>
              <p class="mt-0.5 text-xs text-redup">
                <span class="font-display tabular-nums">{{ baris.jam }}</span>
                · {{ baris.jenis_label }} · {{ baris.metode_label }}
                <span
                  v-if="baris.status_label"
                  :class="baris.status_ketepatan === 'terlambat' ? 'text-peringatan-teks' : 'text-berhasil-teks'"
                >
                  · {{ baris.status_label }}
                </span>
              </p>
            </div>

            <span class="shrink-0 whitespace-nowrap text-xs text-redup">
              {{ waktuRelatif(baris.waktu) }}
            </span>
          </li>
        </ol>

        <KeadaanKosong
          v-else
          ikon="jam"
          judul="Belum ada aktivitas"
          keterangan="Daftar terisi otomatis setiap ada tap berhasil di perangkat absen."
        />
      </div>
    </div>
  </AdminLayout>
</template>
