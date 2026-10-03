<script setup>
import { computed } from 'vue'
import { CalendarDate, getLocalTimeZone, parseDate, today } from '@internationalized/date'
import Ikon from '@/Components/Ikon.vue'
import { Calendar } from '@/Components/shadcn/calendar'
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/shadcn/popover'

/**
 * Pemilih tanggal untuk FORMULIR, pengganti `<input type="date">`.
 *
 * Bedanya dengan {@see Tanggal.vue} — yang menyaring satu hari pada layar
 * pemantauan — hanya tempat pakainya: yang ini berdiri di dalam formulir,
 * memakai `id` supaya `<label for>` di halaman tetap menunjuk ke sesuatu yang
 * dapat difokuskan.
 *
 * Mengapa `<input type="date">` diganti:
 *
 *   1. Peramban menggambar kalendernya SENDIRI. Di mode gelap ia muncul
 *      sebagai kotak putih asing, persis masalah yang membuat `<select>`
 *      diganti Pilihan.vue.
 *   2. Bentuk tampilannya mengikuti setelan sistem operasi, bukan kebiasaan
 *      Indonesia — di mesin kantor berbahasa Inggris ia terbaca `09/09/2026`
 *      dengan urutan bulan-hari yang membingungkan.
 *   3. Firefox pada Windows lama tidak memberi pemetik sama sekali; yang
 *      tersisa hanya kotak teks tanpa penuntun format.
 *
 * Kalendernya memakai Reka UI (lewat shadcn-vue), bukan gulungan sendiri,
 * karena gridnya benar-benar `role="grid"`: panah untuk berpindah hari,
 * PageUp/PageDown untuk berpindah bulan, Home/End ke ujung pekan, dan hanya
 * SATU perhentian Tab untuk seluruh kalender. Kalender buatan tangan pada
 * Tanggal.vue menaruh 42 tombol berturut-turut di jalur Tab.
 *
 * Tampilannya sengaja disamakan dengan Tanggal.vue supaya tidak lahir dua
 * bahasa visual kalender di satu aplikasi.
 *
 * Nilai model berupa teks ISO `YYYY-MM-DD` — sama persis dengan yang
 * dikirimkan `<input type="date">`, sehingga sisi Laravel tidak berubah.
 */

const props = defineProps({
  id: { type: String, default: undefined },

  /** Tanggal terjauh yang boleh dipilih, ISO `YYYY-MM-DD`. Kosong = tanpa batas. */
  maks: { type: String, default: '' },

  /** Tanggal terawal yang boleh dipilih, ISO `YYYY-MM-DD`. */
  min: { type: String, default: '' },

  nonaktif: { type: Boolean, default: false },
  placeholder: { type: String, default: 'Pilih tanggal' },

  /** Tandai isian bermasalah; menyalakan garis rose dan `aria-invalid`. */
  bermasalah: { type: Boolean, default: false },
})

const model = defineModel({ type: String, default: '' })

const emit = defineEmits(['ubah'])

/**
 * ISO → CalendarDate.
 *
 * Dibungkus try/catch karena nilai formulir bisa datang dari server dalam
 * keadaan setengah jadi (string kosong, atau `2026-9-1` tanpa nol di depan);
 * `parseDate` melempar untuk keduanya, dan formulir tidak boleh ikut mati.
 */
const keTanggal = (teks) => {
  if (!teks) return undefined

  try {
    return parseDate(teks)
  } catch {
    return undefined
  }
}

const nilai = computed({
  get: () => keTanggal(model.value),
  set: (tanggal) => {
    // Reka UI mengirim `undefined` bila tanggal yang sama diklik dua kali.
    model.value = tanggal ? tanggal.toString() : ''
    emit('ubah')
  },
})

const batasMin = computed(() => keTanggal(props.min))
const batasMaks = computed(() => keTanggal(props.maks))

const tampil = computed(() => {
  const tanggal = keTanggal(model.value)

  if (!tanggal) return props.placeholder

  return tanggal
    .toDate(getLocalTimeZone())
    .toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })
})

const hariIni = () => {
  const t = today(getLocalTimeZone())

  return new CalendarDate(t.year, t.month, t.day)
}

const pilihHariIni = () => {
  nilai.value = hariIni()
}
</script>

<template>
  <!-- Pembungkus nyata: PopoverRoot Reka UI tidak menggambar elemen apa pun,
       sehingga kelas dari pemanggil tidak punya tempat mendarat tanpa ini. -->
  <div>
    <Popover>
      <!--
        `as-child`, bukan tombol bawaan PopoverTrigger.

        Reka UI memasang `id`-nya sendiri (`reka-popover-trigger-v-0`) pada
        tombol yang ia gambar, dan id itu MENIMPA milik kita. Akibatnya
        `<label for="tanggal">` di halaman menunjuk ke elemen yang tidak ada:
        klik pada labelnya tidak memfokuskan apa pun, dan pembaca layar
        membacakan tombol tanpa nama. Dengan `as-child` tombolnya kita sendiri
        yang menggambar, sehingga `id` kita bertahan.
      -->
      <PopoverTrigger as-child>
        <button
          :id="id"
          type="button"
          :disabled="nonaktif"
          :aria-invalid="bermasalah || undefined"
          class="group flex w-full items-center gap-2 rounded-lg border bg-permukaan px-3.5 py-2.5 text-left text-sm transition-colors duration-150 focus:outline-none disabled:cursor-not-allowed disabled:opacity-50 data-[state=open]:border-aksen data-[state=open]:ring-1 data-[state=open]:ring-aksen"
          :class="
            bermasalah
              ? 'border-galat hover:border-galat'
              : 'border-garis hover:border-garis-kuat focus:border-aksen focus:ring-1 focus:ring-aksen'
          "
        >
          <Ikon nama="kalender" ukuran="h-4 w-4" class="shrink-0 text-redup" />
          <span class="min-w-0 flex-1 truncate" :class="model ? 'text-utama' : 'text-redup'">
            {{ tampil }}
          </span>
          <Ikon
            nama="bawah"
            ukuran="h-4 w-4"
            class="shrink-0 text-redup transition-transform duration-200 group-data-[state=open]:rotate-180"
          />
        </button>
      </PopoverTrigger>

      <PopoverContent
        align="start"
        class="w-auto rounded-xl border-garis bg-permukaan p-3 shadow-xl"
      >
        <Calendar
          v-model="nilai"
          locale="id-ID"
          :week-starts-on="1"
          :min-value="batasMin"
          :max-value="batasMaks"
          initial-focus
          class="p-0"
        />

        <div class="mt-3 border-t border-garis pt-3">
          <button
            type="button"
            class="w-full rounded-md border border-garis px-3 py-1.5 text-xs text-sekunder transition-colors duration-150 hover:bg-permukaan-hover hover:text-utama"
            @click="pilihHariIni"
          >
            Hari ini
          </button>
        </div>
      </PopoverContent>
    </Popover>
  </div>
</template>
