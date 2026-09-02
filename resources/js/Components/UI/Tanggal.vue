<script setup>
import { computed, ref, watch } from 'vue'
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/vue'
import Ikon from '@/Components/Ikon.vue'

/**
 * Pemilih satu tanggal, sekeluarga dengan {@see RentangTanggal.vue}.
 *
 * Dipakai layar yang menyaring satu hari saja — pemantauan Absen Umum —
 * sehingga kalendernya sama, hanya tanpa keadaan "menunggu klik kedua".
 */

const props = defineProps({
  label: { type: String, default: '' },
  maks: { type: String, default: '' },

  // Lihat catatan pada RentangTanggal.vue.
  jajar: { type: String, default: 'kiri' },
})

const model = defineModel({ type: String, default: '' })

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

const iso = (tanggal) => {
  const pad = (n) => String(n).padStart(2, '0')

  return `${tanggal.getFullYear()}-${pad(tanggal.getMonth() + 1)}-${pad(tanggal.getDate())}`
}

const dariIso = (teks) => {
  if (!teks) return null

  const [tahun, bulan, hari] = teks.split('-').map(Number)

  return new Date(tahun, bulan - 1, hari)
}

const awal = dariIso(model.value) ?? new Date()
const kursor = ref(new Date(awal.getFullYear(), awal.getMonth(), 1))

watch(model, (baru) => {
  const tanggal = dariIso(baru)

  if (tanggal) kursor.value = new Date(tanggal.getFullYear(), tanggal.getMonth(), 1)
})

function geserBulan(langkah) {
  kursor.value = new Date(kursor.value.getFullYear(), kursor.value.getMonth() + langkah, 1)
}

const sel = computed(() => {
  const tahun = kursor.value.getFullYear()
  const bulan = kursor.value.getMonth()
  const geser = (new Date(tahun, bulan, 1).getDay() + 6) % 7
  const mulai = new Date(tahun, bulan, 1 - geser)

  return Array.from({ length: 42 }, (_, i) => {
    const tanggal = new Date(mulai.getFullYear(), mulai.getMonth(), mulai.getDate() + i)

    return {
      iso: iso(tanggal),
      angka: tanggal.getDate(),
      bulanIni: tanggal.getMonth() === bulan,
    }
  })
})

const hariIni = iso(new Date())

const diluarBatas = (nilai) => props.maks !== '' && nilai > props.maks

const tampil = computed(() => {
  const tanggal = dariIso(model.value)

  return tanggal === null
    ? 'Pilih tanggal'
    : tanggal.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })
})
</script>

<template>
  <div>
    <span v-if="label" class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-redup">
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
        <span class="min-w-0 flex-1 truncate text-utama">{{ tampil }}</span>
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

          <div class="mt-3 grid grid-cols-7 gap-px text-center">
            <span
              v-for="hari in HARI"
              :key="hari"
              class="pb-1.5 text-[0.65rem] font-medium uppercase tracking-wide text-redup"
            >
              {{ hari }}
            </span>

            <button
              v-for="item in sel"
              :key="item.iso"
              type="button"
              :disabled="diluarBatas(item.iso)"
              class="flex h-9 items-center justify-center text-sm disabled:cursor-not-allowed disabled:opacity-30"
              :class="item.bulanIni ? 'text-utama' : 'text-redup/60'"
              @click="
                () => {
                  model = item.iso
                  emit('ubah')
                  close()
                }
              "
            >
              <span
                class="flex h-8 w-8 items-center justify-center rounded-md transition-colors duration-100"
                :class="[
                  item.iso === model
                    ? 'bg-aksen font-semibold text-white'
                    : 'hover:bg-permukaan-hover',
                  item.iso === hariIni && item.iso !== model ? 'ring-1 ring-inset ring-aksen' : '',
                ]"
              >
                {{ item.angka }}
              </span>
            </button>
          </div>

          <div class="mt-3 border-t border-garis pt-3">
            <button
              type="button"
              class="w-full rounded-md border border-garis px-3 py-1.5 text-xs text-sekunder transition-colors duration-150 hover:bg-permukaan-hover hover:text-utama"
              @click="
                () => {
                  model = hariIni
                  emit('ubah')
                  close()
                }
              "
            >
              Hari ini
            </button>
          </div>
        </PopoverPanel>
      </Transition>
    </Popover>
  </div>
</template>
