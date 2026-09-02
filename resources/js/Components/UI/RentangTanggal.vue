<script setup>
import { computed, ref, watch } from 'vue'
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/vue'
import Ikon from '@/Components/Ikon.vue'

/**
 * Pemilih rentang tanggal bergaya kalender popover.
 *
 * Menggantikan sepasang `<input type="date">`, yang di luar Chrome tampil
 * sebagai kotak "dd/mm/yyyy" polos tanpa kalender dan tidak dapat ditata
 * mengikuti tema. Di sini bulan digambar sendiri, sehingga rentang terpilih
 * dapat disorot sebagai satu kesatuan — hal yang justru paling ingin dilihat
 * admin ketika menyaring laporan.
 *
 * Klik pertama menetapkan awal, klik kedua menetapkan akhir. Klik kedua yang
 * jatuh sebelum awal diperlakukan sebagai awal baru, bukan ditolak: itu yang
 * dilakukan orang ketika sadar salah pilih.
 */

const props = defineProps({
  label: { type: String, default: '' },

  // Batas bawah/atas opsional, dalam bentuk 'YYYY-MM-DD'.
  min: { type: String, default: '' },
  maks: { type: String, default: '' },

  /*
   * Sisi mana panel kalender bersandar. Penyaring yang berdiri di tepi kanan
   * halaman harus bersandar ke kanan; kalau tidak, panelnya terpotong keluar
   * viewport dan dua kolom terakhir kalender tidak pernah terlihat.
   */
  jajar: { type: String, default: 'kiri' },
})

const dari = defineModel('dari', { type: String, default: '' })
const sampai = defineModel('sampai', { type: String, default: '' })

const emit = defineEmits(['ubah'])

const HARI = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min']

const BULAN = [
  'Januari',
  'Februari',
  'Maret',
  'April',
  'Mei',
  'Juni',
  'Juli',
  'Agustus',
  'September',
  'Oktober',
  'November',
  'Desember',
]

/* ------------------------------------------------------------- utilitas */

const iso = (tanggal) => {
  const pad = (n) => String(n).padStart(2, '0')

  return `${tanggal.getFullYear()}-${pad(tanggal.getMonth() + 1)}-${pad(tanggal.getDate())}`
}

const dariIso = (teks) => {
  if (!teks) return null

  const [tahun, bulan, hari] = teks.split('-').map(Number)

  return new Date(tahun, bulan - 1, hari)
}

const tampil = (teks) => {
  const tanggal = dariIso(teks)

  return tanggal === null
    ? ''
    : tanggal.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
}

/* --------------------------------------------------------------- kursor */

const awalKursor = dariIso(dari.value) ?? new Date()
const kursor = ref(new Date(awalKursor.getFullYear(), awalKursor.getMonth(), 1))

// Tanggal yang sedang dihover, untuk pratinjau rentang sebelum klik kedua.
const bayangan = ref(null)

// Menunggu klik kedua? Klik pertama mengosongkan `sampai` sampai ada akhirnya.
const menungguAkhir = ref(false)

watch(dari, (baru) => {
  const tanggal = dariIso(baru)

  if (tanggal) kursor.value = new Date(tanggal.getFullYear(), tanggal.getMonth(), 1)
})

function geserBulan(langkah) {
  kursor.value = new Date(kursor.value.getFullYear(), kursor.value.getMonth() + langkah, 1)
}

/**
 * Sel kalender satu bulan, termasuk sisa bulan sebelum/sesudah supaya
 * kisinya selalu genap tujuh kolom.
 */
const sel = computed(() => {
  const tahun = kursor.value.getFullYear()
  const bulan = kursor.value.getMonth()

  const pertama = new Date(tahun, bulan, 1)

  // Pekan dimulai Senin, mengikuti kebiasaan kalender dinas.
  const geser = (pertama.getDay() + 6) % 7

  const mulai = new Date(tahun, bulan, 1 - geser)
  const hasil = []

  for (let i = 0; i < 42; i += 1) {
    const tanggal = new Date(mulai.getFullYear(), mulai.getMonth(), mulai.getDate() + i)

    hasil.push({
      iso: iso(tanggal),
      angka: tanggal.getDate(),
      bulanIni: tanggal.getMonth() === bulan,
    })
  }

  return hasil
})

const hariIni = iso(new Date())

const akhirEfektif = computed(() =>
  menungguAkhir.value && bayangan.value ? bayangan.value : sampai.value,
)

const dalamRentang = (nilai) => {
  const a = dari.value
  const b = akhirEfektif.value

  if (!a || !b) return false

  return nilai > (a < b ? a : b) && nilai < (a < b ? b : a)
}

const diluarBatas = (nilai) =>
  (props.min !== '' && nilai < props.min) || (props.maks !== '' && nilai > props.maks)

function pilih(nilai) {
  if (diluarBatas(nilai)) return

  // Klik pertama, atau klik yang mundur ke sebelum awal: mulai rentang baru.
  if (!menungguAkhir.value || nilai < dari.value) {
    dari.value = nilai
    sampai.value = ''
    menungguAkhir.value = true

    return
  }

  sampai.value = nilai
  menungguAkhir.value = false
  bayangan.value = null

  emit('ubah')
}

/** Pintasan rentang yang paling sering dipakai admin. */
const pintasan = [
  { label: 'Hari ini', hitung: () => [hariIni, hariIni] },
  {
    label: '7 hari terakhir',
    hitung: () => {
      const akhir = new Date()
      const mulai = new Date()

      mulai.setDate(mulai.getDate() - 6)

      return [iso(mulai), iso(akhir)]
    },
  },
  {
    label: 'Bulan ini',
    hitung: () => {
      const kini = new Date()

      return [
        iso(new Date(kini.getFullYear(), kini.getMonth(), 1)),
        iso(new Date(kini.getFullYear(), kini.getMonth() + 1, 0)),
      ]
    },
  },
  {
    label: 'Bulan lalu',
    hitung: () => {
      const kini = new Date()

      return [
        iso(new Date(kini.getFullYear(), kini.getMonth() - 1, 1)),
        iso(new Date(kini.getFullYear(), kini.getMonth(), 0)),
      ]
    },
  },
]

function pakaiPintasan(item) {
  const [a, b] = item.hitung()

  dari.value = a
  sampai.value = b
  menungguAkhir.value = false
  emit('ubah')
}

function bersihkan() {
  dari.value = ''
  sampai.value = ''
  menungguAkhir.value = false
  emit('ubah')
}

const ringkas = computed(() => {
  if (!dari.value && !sampai.value) return ''
  if (dari.value && !sampai.value) return `${tampil(dari.value)} — pilih akhir`

  return `${tampil(dari.value)} — ${tampil(sampai.value)}`
})
</script>

<template>
  <div>
    <span
      v-if="label"
      class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-redup"
    >
      {{ label }}
    </span>

    <Popover v-slot="{ open, close }" class="relative">
      <PopoverButton
        class="flex w-full items-center gap-2 rounded-lg border bg-permukaan px-3 py-2 text-left text-sm transition-colors duration-150 focus:outline-none"
        :class="
          open
            ? 'border-aksen ring-1 ring-aksen'
            : 'border-garis hover:border-garis-kuat focus:border-aksen focus:ring-1 focus:ring-aksen'
        "
      >
        <Ikon nama="kalender" ukuran="h-4 w-4" class="shrink-0 text-redup" />
        <span class="min-w-0 flex-1 truncate" :class="ringkas ? 'text-utama' : 'text-redup'">
          {{ ringkas || 'Semua tanggal' }}
        </span>
        <Ikon
          nama="bawah"
          ukuran="h-4 w-4"
          class="shrink-0 text-redup transition-transform duration-200"
          :class="open && 'rotate-180'"
        />
      </PopoverButton>

      <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="-translate-y-1 scale-[0.98] opacity-0"
        enter-to-class="translate-y-0 scale-100 opacity-100"
        leave-active-class="transition duration-100 ease-in"
        leave-from-class="translate-y-0 scale-100 opacity-100"
        leave-to-class="-translate-y-1 scale-[0.98] opacity-0"
      >
        <PopoverPanel
          class="absolute z-50 mt-2 w-[19rem] rounded-xl border border-garis bg-permukaan p-3 shadow-xl focus:outline-none"
          :class="jajar === 'kanan' ? 'right-0 origin-top-right' : 'left-0 origin-top-left'"
        >
          <!-- Kepala bulan -->
          <div class="flex items-center justify-between">
            <button
              type="button"
              class="rounded-md p-1.5 text-sekunder transition-colors duration-150 hover:bg-permukaan-hover hover:text-utama"
              aria-label="Bulan sebelumnya"
              @click="geserBulan(-1)"
            >
              <Ikon nama="kiri" ukuran="h-4 w-4" />
            </button>

            <p class="font-display text-sm font-semibold text-utama">
              {{ BULAN[kursor.getMonth()] }} {{ kursor.getFullYear() }}
            </p>

            <button
              type="button"
              class="rounded-md p-1.5 text-sekunder transition-colors duration-150 hover:bg-permukaan-hover hover:text-utama"
              aria-label="Bulan berikutnya"
              @click="geserBulan(1)"
            >
              <Ikon nama="kanan" ukuran="h-4 w-4" />
            </button>
          </div>

          <!-- Kisi tanggal -->
          <div class="mt-3 grid grid-cols-7 gap-px text-center">
            <span v-for="hari in HARI" :key="hari" class="pb-1.5 text-[0.65rem] font-medium uppercase tracking-wide text-redup">
              {{ hari }}
            </span>

            <button
              v-for="item in sel"
              :key="item.iso"
              type="button"
              :disabled="diluarBatas(item.iso)"
              class="relative h-9 text-sm transition-colors duration-100 disabled:cursor-not-allowed disabled:opacity-30"
              :class="[
                item.bulanIni ? 'text-utama' : 'text-redup/60',
                dalamRentang(item.iso) && 'bg-aksen-lembut',
                item.iso === dari && (sampai || akhirEfektif) ? 'rounded-l-md bg-aksen-lembut' : '',
                item.iso === akhirEfektif && dari ? 'rounded-r-md bg-aksen-lembut' : '',
              ]"
              @click="pilih(item.iso)"
              @mouseenter="bayangan = item.iso"
            >
              <span
                class="absolute inset-0 m-auto flex h-8 w-8 items-center justify-center rounded-md transition-colors duration-100"
                :class="[
                  item.iso === dari || item.iso === sampai
                    ? 'bg-aksen font-semibold text-white'
                    : 'hover:bg-permukaan-hover',
                  item.iso === hariIni && item.iso !== dari && item.iso !== sampai
                    ? 'ring-1 ring-inset ring-aksen'
                    : '',
                ]"
              >
                {{ item.angka }}
              </span>
            </button>
          </div>

          <!-- Pintasan -->
          <div class="mt-3 grid grid-cols-2 gap-1.5 border-t border-garis pt-3">
            <button
              v-for="item in pintasan"
              :key="item.label"
              type="button"
              class="rounded-md border border-garis px-2.5 py-1.5 text-xs text-sekunder transition-colors duration-150 hover:bg-permukaan-hover hover:text-utama"
              @click="
                () => {
                  pakaiPintasan(item)
                  close()
                }
              "
            >
              {{ item.label }}
            </button>
          </div>

          <div class="mt-2 flex items-center justify-between">
            <button
              type="button"
              class="rounded-md px-2.5 py-1.5 text-xs text-redup transition-colors duration-150 hover:bg-permukaan-hover hover:text-utama"
              @click="
                () => {
                  bersihkan()
                  close()
                }
              "
            >
              Bersihkan
            </button>
            <button
              type="button"
              class="rounded-md bg-aksen px-3 py-1.5 text-xs font-semibold text-white transition-colors duration-150 hover:bg-aksen-kuat"
              @click="
                () => {
                  emit('ubah')
                  close()
                }
              "
            >
              Terapkan
            </button>
          </div>
        </PopoverPanel>
      </Transition>
    </Popover>
  </div>
</template>
