<script setup>
import Ikon from '@/Components/Ikon.vue'

/**
 * Tombol aksi utama yang menyatakan dirinya sedang bekerja.
 *
 * Sebelumnya ada lima salinan tulisan tangan dari tombol yang sama —
 * Simpan Event, Simpan Akun, Simpan Perangkat, Simpan Setting, Simpan Unit
 * Kerja — dan kelimanya berbeda tipis: dua memakai pemintal, satu menukar
 * ikon centang, dua tidak menampilkan apa-apa selain teks; kelasnya pun
 * bercampur antara `.tombol-utama` dan `bg-aksen` mentah, sehingga gradasi
 * aksi utama yang dipasang pada S32b tidak pernah sampai ke sebagian
 * besarnya.
 *
 * Perbedaan sekecil itu tidak pernah dilaporkan siapa pun — ia hanya membuat
 * panel admin terasa dirakit oleh beberapa orang yang tidak saling bicara.
 *
 * Umpan baliknya tiga lapis sekaligus, dan itu disengaja: tombolnya dimatikan
 * (mencegah kiriman ganda), teksnya berganti (menyatakan apa yang sedang
 * terjadi), dan pemintalnya berputar (menyatakan bahwa sistemnya masih hidup).
 * Ketika pengguna meminta gerak minimal, pemintalnya membeku — tetapi dua
 * lapis lainnya tetap bekerja, sehingga tidak ada keterangan yang hilang.
 */

defineProps({
  /** Sedang mengirim; biasanya `form.processing` dari Inertia. */
  proses: { type: Boolean, default: false },

  /** Alasan lain tombol dimatikan, mis. formulir belum sah. */
  nonaktif: { type: Boolean, default: false },

  /** Ikon saat diam; kosongkan bila tombolnya cukup dengan teks. */
  ikon: { type: String, default: 'cek' },

  teksProses: { type: String, default: 'Menyimpan…' },

  tipe: { type: String, default: 'submit' },

  /** Atribut `form=` untuk tombol yang berada di luar formulirnya. */
  form: { type: String, default: undefined },
})
</script>

<template>
  <button :type="tipe" :form="form" :disabled="proses || nonaktif" class="tombol tombol-utama">
    <Ikon v-if="proses" nama="segarkan" ukuran="h-4 w-4 animate-spin" />
    <Ikon v-else-if="ikon" :nama="ikon" ukuran="h-4 w-4" />

    <template v-if="proses">{{ teksProses }}</template>
    <slot v-else />
  </button>
</template>
