<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Ikon from '@/Components/Ikon.vue'
import Lencana from '@/Components/UI/Lencana.vue'
import KeadaanKosong from '@/Components/UI/KeadaanKosong.vue'
import KartuStatistik from '@/Components/UI/KartuStatistik.vue'
import KartuKehadiran from '@/Components/UI/KartuKehadiran.vue'

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

/*
 * Tiga kartu ringkas. Yang keempat — kehadiran hari ini — naik menjadi kartu
 * utama tersendiri, karena ia satu-satunya yang layak menjadi pusat perhatian
 * halaman ini.
 *
 * Indikator visual hanya dipasang pada yang punya PENYEBUT NYATA. "Perangkat
 * aktif 0 dari 5" punya penyebut, jadi ia memperoleh lima pip. "666 pegawai"
 * dan "7 event" tidak — keduanya bukan bagian dari apa pun, dan bar di
 * bawahnya hanya akan menjadi jalur kosong yang menyesatkan.
 */
const kartu = computed(() => [
  {
    label: 'Total Pegawai',
    nilai: props.statistik.total_pegawai,
    keterangan: `${props.kesiapan.wajah_terdaftar} wajah · ${props.kesiapan.kartu_terdaftar} kartu RFID terdaftar`,
    ikon: 'pegawai',
    nada: 'biru',
  },
  {
    label: 'Perangkat Aktif',
    nilai: props.statistik.kiosk_aktif,
    keterangan: `dari ${props.kesiapan.perangkat} perangkat terdaftar`,
    ikon: 'perangkat',
    nada: 'langit',
    pip: { terisi: props.statistik.kiosk_aktif, total: props.kesiapan.perangkat },
  },
  {
    label: 'Event Berlangsung',
    nilai: props.statistik.event_berlangsung,
    keterangan: 'entry masih dibuka untuk menerima tap',
    ikon: 'absen',
    nada: 'teal',
  },
])

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

/*
 * Panjang garis, dihitung sebagai jumlah panjang tiap ruasnya.
 *
 * Dipakai animasi "garis tergambar": `stroke-dasharray` disetel sepanjang
 * garisnya lalu `stroke-dashoffset` dijalankan dari panjang itu ke nol,
 * sehingga grafiknya seolah digambar dari kiri ke kanan. Menghitungnya di
 * sini jauh lebih murah daripada memanggil `getTotalLength()` pada elemen,
 * yang menuntut simpulnya sudah terpasang lebih dahulu.
 */
const panjangGaris = computed(() =>
  titik.value.reduce((jumlah, t, urutan) => {
    if (urutan === 0) return 0

    const lalu = titik.value[urutan - 1]

    return jumlah + Math.hypot(t.x - lalu.x, t.y - lalu.y)
  }, 0),
)

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
    <!--
      FR-DASH-01. Satu kartu utama dan tiga kartu ringkas, bukan empat kartu
      sejajar: empat hal berukuran sama berarti tidak ada satu pun yang menjadi
      pusat perhatian, dan mata akhirnya memilih yang paling kiri.
    -->
    <div class="flex flex-col gap-4">
      <KartuKehadiran
        :hadir="statistik.hadir_hari_ini"
        :total="statistik.total_pegawai"
        :tepat="ketepatan.tepat"
        :terlambat="ketepatan.terlambat"
      />

      <!--
        Tiga kartu ringkas mendapat lebar penuh masing-masing sepertiga. Ketika
        keempatnya dijejer sebaris bersama kartu utama, labelnya terpaksa
        membungkus dua baris dan tinggi kartunya berbeda-beda.
      -->
      <div class="grid gap-4 sm:grid-cols-3">
        <KartuStatistik
          v-for="(item, urutan) in kartu"
          :key="item.label"
          v-bind="item"
          :tunda="urutan * 70"
        />
      </div>
    </div>

    <!-- Event berjalan -->
    <!--
      Sesi yang sedang menerima tap. Sebelumnya berlatar hijau selebar halaman,
      dan bidang berwarna sebesar itu menyaingi kartu kehadiran di atasnya —
      padahal isinya daftar rutin yang dibaca sekilas, bukan angka yang perlu
      direnungkan. Kini panel biasa; yang menandai "hidup" cukup titik berdenyut
      pada judulnya.
    -->
    <div v-if="event_berjalan.length > 0" class="panel mt-4 p-5">
      <div class="flex items-center gap-2">
        <span class="relative flex h-2 w-2">
          <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-berhasil opacity-60"></span>
          <span class="relative inline-flex h-2 w-2 rounded-full bg-berhasil"></span>
        </span>
        <h2 class="font-display text-sm font-semibold text-utama">Sedang Berlangsung</h2>
        <span class="keping nada-emerald ml-1 px-2 py-0 text-[0.6875rem]">
          {{ event_berjalan.length }}
        </span>
      </div>

      <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <Link
          v-for="event in event_berjalan"
          :key="event.id"
          :href="`/admin/kelola-absen/rekap?event_absen_id=${event.id}`"
          class="rounded-xl border border-garis bg-permukaan-2 px-4 py-3 transition-colors duration-150 hover:border-aksen hover:bg-permukaan"
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
              <!--
                Warna gradien dan garis diambil dari token tema, bukan dari
                heksadesimal tetap: grafiknya harus ikut berpindah saat mode
                gelap dinyalakan, sama seperti sisa halaman.
              -->
              <linearGradient id="gradienTren" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="var(--tema-aksen)" stop-opacity="0.3" />
                <stop offset="55%" stop-color="var(--tema-aksen)" stop-opacity="0.08" />
                <stop offset="100%" stop-color="var(--tema-aksen)" stop-opacity="0" />
              </linearGradient>
            </defs>

            <line
              v-for="bagian in [0, 0.5, 1]"
              :key="bagian"
              :x1="PADDING"
              :x2="LEBAR - PADDING"
              :y1="TINGGI - PADDING - bagian * (TINGGI - PADDING * 2)"
              :y2="TINGGI - PADDING - bagian * (TINGGI - PADDING * 2)"
              stroke="var(--tema-garis)"
              stroke-width="1"
            />

            <polygon :points="area" fill="url(#gradienTren)" class="masuk" />

            <polyline
              :points="garis"
              fill="none"
              stroke="var(--tema-aksen)"
              stroke-width="2.5"
              stroke-linejoin="round"
              stroke-linecap="round"
              class="garis-tergambar"
              :style="{ '--panjang-garis': panjangGaris }"
            />

            <g
              v-for="(t, urutan) in titik"
              :key="t.tanggal"
              class="masuk"
              :style="{ '--tunda': `${380 + urutan * 60}ms` }"
            >
              <circle
                :cx="t.x"
                :cy="t.y"
                r="4.5"
                fill="var(--tema-permukaan)"
                stroke="var(--tema-aksen)"
                stroke-width="2.5"
              />
              <text
                :x="t.x"
                :y="t.y - 13"
                text-anchor="middle"
                fill="var(--tema-utama)"
                class="font-display text-[13px] tabular-nums"
              >
                {{ t.jumlah }}
              </text>
              <text :x="t.x" :y="TINGGI - 8" text-anchor="middle" fill="var(--tema-redup)" class="text-[12px]">
                {{ t.label }}
              </text>
            </g>
          </svg>
        </div>

        <!-- Kesiapan sistem -->
        <div class="panel p-6">
          <h2 class="font-display text-base font-semibold text-utama">Kesiapan Sistem</h2>
            <p class="mt-1 text-xs text-redup">
              Yang biasanya menjelaskan kegagalan absen di lapangan.
            </p>

            <div class="mt-4 space-y-3">
              <div v-for="(baris, urutan) in [
                { label: 'Wajah terdaftar', nilai: kesiapan.wajah_terdaftar, total: kesiapan.pegawai, persen: kesiapan.wajah_persen },
                { label: 'Kartu RFID terdaftar', nilai: kesiapan.kartu_terdaftar, total: kesiapan.pegawai, persen: kesiapan.kartu_persen },
              ]" :key="baris.label">
                <div class="flex items-baseline justify-between text-xs">
                  <span class="text-sekunder">{{ baris.label }}</span>
                  <span class="font-display tabular-nums text-utama">
                    {{ baris.nilai }}/{{ baris.total }} · {{ baris.persen }}%
                  </span>
                </div>
                <!--
                  Warnanya mengikuti seberapa siap, bukan sekadar mengisi:
                  hijau bila hampir lengkap, teal bila sedang berjalan, amber
                  bila masih tertinggal jauh.
                -->
                <div
                  class="bar-jalur mt-1.5 h-2"
                  :class="baris.persen >= 80 ? 'nada-emerald' : baris.persen >= 40 ? 'nada-teal' : 'nada-amber'"
                >
                  <span
                    class="bar-isi"
                    :style="{ width: `${Math.max(baris.persen, 2)}%`, '--tunda': `${240 + urutan * 120}ms` }"
                  ></span>
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

        <!-- Peringkat unit -->
        <div v-if="peringkat_unit.length > 0" class="panel p-6">
          <h2 class="font-display text-base font-semibold text-utama">Kehadiran per Unit Kerja</h2>
          <p class="mt-1 text-xs text-redup">Hari ini, diurutkan menurut persentase kehadiran.</p>

          <!--
            Tiga teratas diberi nomor peringkat; sisanya tidak. Menomori seluruh
            daftar membuat nomornya kehilangan arti — yang ingin diketahui
            pimpinan adalah siapa yang memimpin, bukan urutan lengkap sampai
            unit terakhir.
          -->
          <ol class="mt-4 space-y-3.5">
            <li
              v-for="(unit, urutan) in peringkat_unit"
              :key="unit.kode"
              class="masuk"
              :style="{ '--tunda': `${urutan * 55}ms` }"
              :class="unit.persen >= 75 ? 'nada-emerald' : unit.persen >= 40 ? 'nada-teal' : 'nada-amber'"
            >
              <div class="flex items-baseline justify-between gap-3 text-sm">
                <span class="flex min-w-0 items-baseline gap-2">
                  <span
                    v-if="urutan < 3"
                    class="keping shrink-0 px-1.5 font-display text-[0.625rem] tabular-nums"
                  >
                    {{ urutan + 1 }}
                  </span>
                  <span class="truncate text-utama">{{ unit.nama }}</span>
                </span>

                <span class="shrink-0 font-display text-xs tabular-nums">
                  <span class="font-semibold" :style="{ color: 'var(--nada-teks)' }">
                    {{ unit.persen }}%
                  </span>
                  <span class="text-redup"> · {{ unit.hadir }}/{{ unit.pegawai }}</span>
                </span>
              </div>

              <div class="bar-jalur mt-1.5 h-2">
                <span
                  class="bar-isi"
                  :style="{ width: `${Math.max(unit.persen, 2)}%`, '--tunda': `${180 + urutan * 55}ms` }"
                ></span>
              </div>
            </li>
          </ol>
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
