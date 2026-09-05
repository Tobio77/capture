<script setup>
import { computed } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import Ikon from '@/Components/Ikon.vue'

/**
 * Layar masuk Panel Admin.
 *
 * Sejak S30 latarnya mengikuti tema pastel aplikasi, bukan lagi bidang navy
 * rata: layar ini adalah kesan pertama pengelola atas aplikasinya, dan bidang
 * navy polos dengan satu kotak putih di tengahnya tidak menjanjikan apa pun
 * tentang halaman-halaman di baliknya.
 *
 * Navy tetap ada, tetapi sebagai aksen — lencana merek dan tautan kembali —
 * bukan sebagai seluruh layar.
 */

const page = usePage()
const flash = computed(() => page.props.flash)

const form = useForm({
  email: '',
  password: '',
  ingat_saya: false,
})

const kirim = () => {
  form.post('/masuk', {
    onFinish: () => form.reset('password'),
  })
}
</script>

<template>
  <Head title="Masuk" />

  <div class="latar-pastel flex min-h-screen flex-col bg-kertas px-4 py-10 text-utama">
    <div class="mx-auto flex w-full max-w-[26rem] flex-1 flex-col justify-center">
      <!-- Merek -->
      <div class="flex flex-col items-center text-center">
        <span class="ubin-merek h-12 w-12">
          <Ikon nama="absen" ukuran="h-6 w-6" />
        </span>

        <h1 class="mt-4 font-display text-2xl font-semibold">Capture</h1>
        <p class="mt-1.5 text-sm text-sekunder">
          Sistem Informasi Absensi Kegiatan<br />
          Disnakertrans Provinsi Jawa Timur
        </p>
      </div>

      <div class="panel mt-8 p-7">
        <h2 class="font-display text-lg font-semibold">Masuk Panel Admin</h2>
        <p class="mt-1 text-sm text-redup">
          Perangkat absen memakai alur aktivasi terpisah.
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
            <label for="email" class="mb-1.5 block text-sm font-medium">Alamat Surel</label>

            <!--
              Ikon di dalam kolom, bukan label bergambar di sebelahnya: pada
              formulir sependek ini ia satu-satunya hiasan yang menambah
              keterangan alih-alih sekadar mengisi ruang.
            -->
            <div class="relative">
              <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-redup">
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
            <label for="password" class="mb-1.5 block text-sm font-medium">Kata Sandi</label>

            <div class="relative">
              <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-redup">
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

          <label class="flex cursor-pointer items-center gap-2.5 text-sm text-sekunder">
            <input
              v-model="form.ingat_saya"
              type="checkbox"
              class="h-4 w-4 rounded border-garis text-aksen focus:ring-aksen"
            />
            Ingat saya pada perangkat ini
          </label>

          <button type="submit" :disabled="form.processing" class="tombol tombol-utama w-full py-3">
            {{ form.processing ? 'Memproses…' : 'Masuk' }}
          </button>
        </form>
      </div>

      <div class="mt-6 flex flex-col items-center gap-3">
        <Link
          href="/"
          class="tautan-aksi inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-sekunder transition-colors duration-150 hover:bg-permukaan-hover hover:text-utama"
        >
          <Ikon nama="kiri" ukuran="h-4 w-4" /> Kembali ke halaman absen
        </Link>

        <p class="text-xs text-redup">Kesulitan masuk? Hubungi Superadmin Capture.</p>
      </div>
    </div>
  </div>
</template>
