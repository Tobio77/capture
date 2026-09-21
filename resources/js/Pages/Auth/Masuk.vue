<script setup>
import { computed } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import Ikon from '@/Components/Ikon.vue'

/**
 * Layar masuk Panel Admin — dua panel (S50).
 *
 * Sampai S49 layar ini satu kolom di tengah layar: benar, tetapi pada monitor
 * kantor 24 inci kartu selebar 26rem itu mengambang di tengah bidang pastel
 * yang kosong sama sekali. Ruang yang tidak dipakai bukan ruang yang lapang.
 *
 * Kini bidang itu diisi PELAT NAVY, mewarisi arah "Pelat Bengkel" dari halaman
 * depan (S32): identitas dinas, motif garis ukur, dan tiga keterangan tentang
 * apa yang dikerjakan aplikasi ini. Formulirnya pindah ke kanan dengan lebar
 * yang tetap terkendali — yang melebar adalah pelatnya, bukan kolom isiannya,
 * sebab kolom isian selebar layar justru lebih sukar dibaca.
 *
 * Di bawah `lg` pelatnya menghilang seluruhnya dan yang tersisa persis layar
 * lama: satu kolom di tengah. Pelat setinggi layar pada ponsel hanya akan
 * mendorong formulirnya ke bawah lipatan.
 */

defineProps({
  /** Soal hitungan; dibuat baru server setiap kali halaman ini digambar. */
  soal_captcha: { type: String, default: null },
})

const page = usePage()
const flash = computed(() => page.props.flash)

/*
 * Tiga keterangan pada pelat. Warnanya disebut EKSPLISIT (emerald/teal/langit
 * pada tingkat 400–200), bukan lewat sistem `.nada-*`: nada membaca token tema
 * yang berbalik gelap-terang mengikuti mode tampilan, sedangkan pelat ini
 * selalu navy. Keping berwarna pastel di atas navy gelap akan hilang begitu
 * pengguna menyalakan mode gelap — persis yang dihindari halaman depan dengan
 * cara yang sama.
 */
const sorotan = [
  {
    ikon: 'wajah',
    judul: 'Verifikasi wajah',
    isi: 'Kehadiran dibuktikan wajah, dicocokkan di server.',
    ubin: 'bg-teal-400/25 text-teal-200',
  },
  {
    ikon: 'kartu',
    judul: 'Tap kartu atau NIP',
    isi: 'Kartu RFID maupun ketik manual, keduanya satu alur.',
    ubin: 'bg-sky-400/25 text-sky-200',
  },
  {
    ikon: 'laporan',
    judul: 'Rekap siap unduh',
    isi: 'Jam masuk dan pulang tercatat, lengkap per unit kerja.',
    ubin: 'bg-emerald-400/25 text-emerald-200',
  },
]

const form = useForm({
  email: '',
  password: '',
  ingat_saya: false,
  jawaban_captcha: '',
})

const kirim = () => {
  form.post('/masuk', {
    onFinish: () => form.reset('password', 'jawaban_captcha'),
  })
}
</script>

<template>
  <Head title="Masuk" />

  <div class="min-h-screen bg-kertas text-utama lg:grid lg:grid-cols-[1.05fr_1fr] xl:grid-cols-[1.2fr_1fr]">
    <!--
      PELAT NAVY — hanya `lg` ke atas. Bukan sekadar hiasan: ia yang membuat
      layar ini terbaca sebagai milik dinas sejak detik pertama, sebelum satu
      kolom pun diisi.
    -->
    <aside
      class="pelat-navy tahap tahap-pelat relative hidden overflow-hidden lg:flex lg:flex-col lg:justify-between lg:p-12 xl:p-16"
      style="--lama: 520ms"
    >
      <!--
        Tiga bola cahaya yang mengapung pelan; seluruhnya CSS dan hanya
        `transform` yang beranimasi, sehingga peramban merasterkannya sekali
        lalu memindahkannya di GPU (lihat catatan pada .bola).
      -->
      <div class="bola bola-1" aria-hidden="true"></div>
      <div class="bola bola-2" aria-hidden="true"></div>
      <div class="bola bola-3" aria-hidden="true"></div>

      <!-- Identitas -->
      <div class="tahap tahap-redup relative" style="--tunda: 200ms">
        <span class="ubin-merek h-11 w-11">
          <Ikon nama="absen" ukuran="h-5 w-5" />
        </span>

        <p class="mt-5 font-display text-2xl font-semibold text-sidebar-teks">Capture</p>
        <p class="mt-1 text-sm text-sidebar-redup">
          Dinas Tenaga Kerja dan Transmigrasi<br />Provinsi Jawa Timur
        </p>
      </div>

      <!-- Inti pesan, dibaca dari jarak duduk. -->
      <div class="relative max-w-md">
        <h2
          class="tahap tahap-kartu font-display text-3xl font-semibold leading-tight text-sidebar-teks xl:text-[2.5rem]"
          style="--tunda: 300ms"
        >
          Absensi kegiatan,<br />satu pintu untuk seluruh dinas.
        </h2>

        <ul class="mt-9 space-y-5">
          <li
            v-for="(butir, urutan) in sorotan"
            :key="butir.judul"
            class="tahap tahap-pita flex items-start gap-4"
            :style="{ '--tunda': `${420 + urutan * 90}ms` }"
          >
            <span
              class="ubin-sorot flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
              :class="butir.ubin"
            >
              <Ikon :nama="butir.ikon" ukuran="h-[1.375rem] w-[1.375rem]" />
            </span>

            <span class="min-w-0 leading-snug">
              <span class="block text-sm font-medium text-sidebar-teks">{{ butir.judul }}</span>
              <span class="mt-0.5 block text-sm text-sidebar-redup">{{ butir.isi }}</span>
            </span>
          </li>
        </ul>
      </div>

      <p class="tahap tahap-redup relative text-xs text-sidebar-redup" style="--tunda: 760ms">
        Perangkat titik absen memakai alur masuk tersendiri — kode unit kerja,
        bukan akun admin.
      </p>

      <!--
        Deret garis ukur di tepi bawah: motif yang sama dengan halaman depan,
        diambil dari irisan tiga kejuruan BLK (meteran penjahit, mistar las,
        sigmat otomotif).
      -->
      <div
        class="skala-ukur skala-terang tahap tahap-skala absolute inset-x-0 bottom-0 h-3"
        style="--tunda: 260ms; --lama: 600ms"
        aria-hidden="true"
      ></div>
    </aside>

    <!--
      KOLOM FORMULIR. Lebarnya tetap dibatasi walau kolomnya melebar: baris
      isian selebar 40rem lebih sukar dibaca daripada yang 26rem, dan yang
      dicari layar ini adalah kecepatan mengisi, bukan kemegahan.
    -->
    <main
      class="latar-pastel flex min-h-screen flex-col justify-center px-4 py-10 sm:px-8 lg:min-h-0 lg:px-10 xl:px-16"
    >
      <div class="mx-auto w-full max-w-[26rem]">
        <!-- Merek ringkas; hanya ketika pelatnya tidak tampil. -->
        <div class="tahap tahap-redup flex flex-col items-center text-center lg:hidden">
          <span class="ubin-merek h-12 w-12">
            <Ikon nama="absen" ukuran="h-6 w-6" />
          </span>

          <h1 class="mt-4 font-display text-2xl font-semibold">Capture</h1>
          <p class="mt-1.5 text-sm text-sekunder">
            Sistem Informasi Absensi Kegiatan<br />
            Disnakertrans Provinsi Jawa Timur
          </p>
        </div>

        <div class="panel tahap tahap-kartu mt-8 p-7 lg:mt-0" style="--tunda: 120ms">
          <h2 class="font-display text-xl font-semibold">Masuk Panel Admin</h2>
          <p class="mt-1 text-sm text-redup">
            Gunakan akun yang diterbitkan Superadmin Capture.
          </p>

          <div
            v-if="flash.gagal"
            class="mt-5 flex items-start gap-2 rounded-lg bg-peringatan-lembut px-3.5 py-3 text-sm text-peringatan-teks"
          >
            <Ikon nama="peringatan" ukuran="h-4 w-4 mt-0.5 shrink-0" />
            <span>{{ flash.gagal }}</span>
          </div>

          <div
            v-if="flash.sukses"
            class="mt-5 flex items-start gap-2 rounded-lg bg-berhasil-lembut px-3.5 py-3 text-sm text-berhasil-teks"
          >
            <Ikon nama="cek" ukuran="h-4 w-4 mt-0.5 shrink-0" />
            <span>{{ flash.sukses }}</span>
          </div>

          <form class="mt-6 flex flex-col gap-5" @submit.prevent="kirim">
            <div>
              <label for="email" class="mb-1.5 block text-sm font-medium">Alamat Surel<span class="ml-0.5 text-galat-teks" aria-hidden="true">*</span></label>

              <!--
                Ikon di dalam kolom, bukan label bergambar di sebelahnya: pada
                formulir sependek ini ia satu-satunya hiasan yang menambah
                keterangan alih-alih sekadar mengisi ruang.

                Warnanya berpindah dari redup ke aksen saat kolomnya difokus —
                penanda "di sinilah kursor berada" yang terbaca bahkan oleh
                mata yang tidak melihat garis fokusnya.
              -->
              <div class="kolom-berikon group">
                <span class="kolom-ikon">
                  <Ikon nama="pengguna" ukuran="h-4 w-4" />
                </span>
                <input
                  id="email"
                  v-model="form.email"
                  type="email"
                  autocomplete="username"
                  autofocus
                  required
                  placeholder="nama@jatimprov.go.id"
                  class="kolom-isian pl-10"
                />
              </div>

              <p v-if="form.errors.email" class="mt-1.5 text-sm text-peringatan-teks">
                {{ form.errors.email }}
              </p>
            </div>

            <div>
              <label for="password" class="mb-1.5 block text-sm font-medium">Kata Sandi<span class="ml-0.5 text-galat-teks" aria-hidden="true">*</span></label>

              <div class="kolom-berikon group">
                <span class="kolom-ikon">
                  <Ikon nama="kunci" ukuran="h-4 w-4" />
                </span>
                <input
                  id="password"
                  v-model="form.password"
                  type="password"
                  autocomplete="current-password"
                  required
                  placeholder="••••••••"
                  class="kolom-isian pl-10"
                />
              </div>

              <p v-if="form.errors.password" class="mt-1.5 text-sm text-peringatan-teks">
                {{ form.errors.password }}
              </p>
            </div>

            <!--
              CAPTCHA hitungan (FR-AUTH-03), diminta sejak percobaan pertama.

              Rancangan progresif sebelumnya dibatalkan: bot yang mencoba satu
              kombinasi pada satu akun lalu berpindah sasaran tidak pernah
              menyentuh ambang apa pun, sehingga penghalangnya justru tidak
              pernah terpasang pada pola serangan yang paling umum.

              Soalnya berupa TEKS, bukan gambar berhuruf-miring. Gambar menuntut
              `alt` yang menjelaskan isinya bagi pembaca layar, dan begitu
              `alt`-nya benar ia berhenti menjadi penghalang; yang tersisa
              hanyalah admin tunanetra yang terkunci di luar sistemnya sendiri.
            -->
            <div>
              <label for="jawaban-captcha" class="mb-1.5 block text-sm font-medium">
                Verifikasi<span class="ml-0.5 text-galat-teks" aria-hidden="true">*</span>
              </label>

              <div class="flex items-center gap-3">
                <p
                  class="kolom-isian flex w-28 shrink-0 items-center justify-center bg-permukaan-2 font-display text-lg font-semibold tabular-nums"
                  aria-hidden="true"
                >
                  {{ soal_captcha }} =
                </p>

                <input
                  id="jawaban-captcha"
                  v-model="form.jawaban_captcha"
                  type="text"
                  inputmode="numeric"
                  autocomplete="off"
                  required
                  :aria-label="`Berapa hasil ${soal_captcha}?`"
                  placeholder="Jawaban"
                  class="kolom-isian"
                />
              </div>

              <p class="mt-1.5 text-xs text-redup">
                Verifikasi sederhana untuk menahan percobaan masuk otomatis.
              </p>

              <p v-if="form.errors.jawaban_captcha" class="mt-1.5 text-sm text-peringatan-teks">
                {{ form.errors.jawaban_captcha }}
              </p>
            </div>

            <label class="flex cursor-pointer items-center gap-2.5 text-sm text-sekunder">
              <input
                v-model="form.ingat_saya"
                type="checkbox"
                class="h-4 w-4 rounded border-garis text-aksen focus:ring-aksen"
              />
              Ingat saya pada perangkat ini
            </label>

            <button
              type="submit"
              :disabled="form.processing"
              class="tombol tombol-utama kilau w-full py-3"
            >
              <span
                v-if="form.processing"
                class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"
                aria-hidden="true"
              ></span>
              {{ form.processing ? 'Memproses…' : 'Masuk' }}
            </button>
          </form>
        </div>

        <div class="tahap tahap-redup mt-6 flex flex-col items-center gap-3" style="--tunda: 260ms">
          <Link
            href="/"
            class="tautan-aksi group inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-sekunder transition-colors duration-150 hover:bg-permukaan-hover hover:text-utama"
          >
            <Ikon
              nama="kiri"
              ukuran="h-4 w-4"
              class="transition-transform duration-200 group-hover:-translate-x-0.5"
            />
            Kembali ke halaman absen
          </Link>

          <p class="text-xs text-redup">Kesulitan masuk? Hubungi Superadmin Capture.</p>
        </div>
      </div>
    </main>
  </div>
</template>
