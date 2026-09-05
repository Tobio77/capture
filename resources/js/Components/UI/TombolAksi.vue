<script setup>
import { useSlots } from 'vue'
import Ikon from '@/Components/Ikon.vue'

/**
 * Tombol aksi baris tabel: ikon + label, dengan warna sesuai sifat aksinya.
 *
 * **Labelnya menyembunyikan diri pada kolom isi yang sempit** (revisi S31). Empat tombol
 * beserta labelnya menghabiskan 415px pada kolom aksi Perangkat Absen, dan
 * karena kolom itu lengket di tepi kanan, kolom-kolom di tengah — unit kerja,
 * pemasangan, status — terdorong ke baliknya dan hilang sama sekali pada
 * laptop kantor 1366px. Yang tersisa di layar hanya nama titik absen dan
 * deretan tombol.
 *
 * Ambangnya `2xl`, bukan `xl`: titik henti Tailwind mengukur LEBAR VIEWPORT,
 * sedangkan yang menentukan di sini lebar KOLOM ISI — pada viewport 1366px,
 * sidebar 288px menyisakan hanya ~1028px, yang sudah di bawah `xl`.
 *
 * Pada lebar itu tombolnya menjadi ikon saja, dengan `title` dan `aria-label`
 * yang tetap membawa labelnya utuh — sehingga tidak ada keterangan yang hilang
 * bagi tetikus maupun pembaca layar.
 */

defineProps({
  ikon: { type: String, required: true },
  warna: { type: String, default: 'slate' },

  /** Teks lengkap aksinya, untuk tooltip dan pembaca layar saat label tersembunyi. */
  label: { type: String, default: '' },

  /**
   * Sembunyikan label pada kolom isi yang sempit.
   *
   * Sengaja opt-in per tabel, bukan berlaku menyeluruh: hanya Perangkat Absen
   * yang benar-benar kehabisan lebar. Daftar Event masih menyisakan ratusan
   * piksel kosong, dan menyembunyikan labelnya di sana hanya membuat aksinya
   * lebih sukar dibaca tanpa menyelamatkan satu kolom pun.
   */
  ringkas: { type: Boolean, default: false },
})

const slots = useSlots()

/** Isi slot sebagai teks datar; dipakai bila `label` tidak disebut pemanggil. */
const teksSlot = () =>
  (slots.default?.() ?? [])
    .map((simpul) => (typeof simpul.children === 'string' ? simpul.children : ''))
    .join('')
    .trim()

const palet = {
  teal: 'text-aksen-teks hover:bg-aksen-lembut',
  navy: 'text-utama hover:bg-info-lembut',
  amber: 'text-peringatan-teks hover:bg-peringatan-lembut',
  emerald: 'text-berhasil-teks hover:bg-berhasil-lembut',
  slate: 'text-sekunder hover:bg-permukaan-hover',
}
</script>

<template>
  <button
    type="button"
    class="inline-flex items-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-medium transition active:scale-95 2xl:px-2.5"
    :class="palet[warna] ?? palet.slate"
    :title="label || teksSlot()"
    :aria-label="label || teksSlot()"
  >
    <Ikon :nama="ikon" ukuran="h-3.5 w-3.5 shrink-0" />
    <span :class="ringkas && 'hidden 2xl:inline'"><slot /></span>
  </button>
</template>
