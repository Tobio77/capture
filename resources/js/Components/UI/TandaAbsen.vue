<script setup>
/**
 * Dua tanda untuk pilihan absen di halaman depan (S32).
 *
 * Percobaan pertama gagal dan dibuang: piringan berjarum untuk Absen Umum dan
 * plakat berkisi untuk Absen Event ternyata terbaca persis sebagai ikon jam
 * dan ikon kalender — dua ikon generik yang justru diminta ditinggalkan.
 * Menambahkan tik ukur di atasnya tidak menolong; bentuk pokoknya sudah
 * telanjur milik orang lain.
 *
 * Yang dipakai sekarang mengambil benda yang benar-benar ada di dunia ini:
 *
 * `umum`  — AMBANG PINTU kantor dengan dua panah berlawanan arah dan deret
 *           tik ukur di lantainya. Absen harian memang peristiwa melewati
 *           pintu: datang pagi, pulang sore. Ia tidak mungkin tertukar
 *           dengan jam.
 * `event` — SPANDUK KEGIATAN beserta dua tali gantungnya. Tidak ada kantor
 *           dinas di Jawa Timur yang menyelenggarakan kegiatan tanpa
 *           memasangnya, dan tanpa kisi tanggal ia tidak mungkin tertukar
 *           dengan kalender.
 *
 * Keduanya dibangun dari primitif yang sama dengan motif halamannya — garis
 * rambut dan deret tik ukur — dengan tebal goresan seragam, supaya terbaca
 * sebagai satu keluarga dan bukan dua ikon yang kebetulan bersebelahan.
 *
 * Batasnya tegas: tanda ini boleh ekspresif, tidak boleh mengalahkan
 * keterbacaan. Pada layar yang dioperasikan sambil berdiri, ikon yang perlu
 * ditafsirkan adalah ikon yang gagal.
 */

defineProps({
  jenis: { type: String, required: true },
  ukuran: { type: String, default: 'h-9 w-9' },
})
</script>

<template>
  <svg
    v-if="jenis === 'umum'"
    :class="ukuran"
    viewBox="0 0 48 48"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <!-- Ambang pintu: dua tiang dan ambang atas, terbuka ke bawah. -->
    <path d="M12 40V14a4 4 0 0 1 4-4h16a4 4 0 0 1 4 4v26" />

    <!--
      Dua panah berlawanan arah: datang dan pulang. Satu panah ke bawah saja
      terbaca sebagai ikon unduh — kotak dengan panah masuk ke baki.
    -->
    <path d="M19.5 17v13" />
    <path d="M16 26.5 19.5 30l3.5-3.5" />
    <path d="M28.5 33V20" />
    <path d="M25 23.5 28.5 20l3.5 3.5" />

    <!-- Lantai berdeter tik ukur — motif halaman ini, dan ambang yang dilewati. -->
    <path d="M6 40h36" />
    <g opacity="0.55" stroke-width="1.75">
      <path d="M9 43.5V40M15 43.5V40M21 43.5V40M27 43.5V40M33 43.5V40M39 43.5V40" />
    </g>
  </svg>

  <svg
    v-else
    :class="ukuran"
    viewBox="0 0 48 48"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <!-- Dua tali gantung: spanduk selalu diikat di dua sudut. -->
    <path d="M11 6v5M37 6v5" opacity="0.7" />

    <!--
      Spanduk kegiatan. Tepi bawahnya berlekuk seperti kain yang menggantung —
      itulah yang membedakannya dari kotak kalender.
    -->
    <path d="M7 11h34v20c-5.5 3-11.5-3-17 0s-11.5 3-17 0V11z" />

    <!-- Dua baris tulisan spanduk, bukan kisi tanggal. -->
    <g opacity="0.6" stroke-width="1.75">
      <path d="M14 19h20M14 25h13" />
    </g>
  </svg>
</template>
