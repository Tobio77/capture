<script setup>
import { computed, ref, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import Ikon from '@/Components/Ikon.vue'
import SaklarTema from '@/Components/UI/SaklarTema.vue'

defineProps({
  judul: { type: String, required: true },
  deskripsi: { type: String, default: '' },
})

const page = usePage()
const pengguna = computed(() => page.props.auth.pengguna)
const menu = computed(() => page.props.menu)
const ruteSaatIni = computed(() => page.props.rute_saat_ini)
const flash = computed(() => page.props.flash)

const cakupan = computed(() =>
  pengguna.value.lintas_unit
    ? 'Seluruh unit kerja'
    : (pengguna.value.unit_kerja?.nama ?? 'Tanpa unit kerja'),
)

const aktif = (rute) => ruteSaatIni.value === rute

/*
 * FR-SET-06: selama Mode Terbuka menyala, perangkat mana pun dapat masuk
 * tanpa kode. Peringatannya sengaja dipasang di kerangka halaman, bukan di
 * satu layar saja — mode ini gampang dinyalakan untuk satu kegiatan lalu
 * terlupakan, dan justru itulah yang berbahaya.
 */
const modeTerbuka = computed(() => page.props.mode_terbuka === true)

/*
 * Di bawah `md` sidebar menjadi laci yang meluncur dari kiri di atas isi
 * halaman, bukan menumpuk di atasnya: menu proyek ini punya sebelas butir,
 * dan menumpuknya akan mendorong isi halaman jauh ke bawah lipatan.
 */
const laciTerbuka = ref(false)

// Berpindah halaman menutup laci; tanpa ini ia menutupi halaman tujuan.
watch(ruteSaatIni, () => (laciTerbuka.value = false))

watch(laciTerbuka, (terbuka) => {
  document.body.style.overflow = terbuka ? 'hidden' : ''
})

const keluar = () => router.post('/keluar')
</script>

<template>
  <Head :title="judul" />

  <div class="min-h-screen bg-kertas md:flex">
    <!-- Bilah atas; hanya di layar sempit. -->
    <header
      class="sticky top-0 z-30 flex items-center justify-between gap-3 border-b border-sidebar-garis bg-sidebar lapis-sidebar px-4 py-3 text-sidebar-teks md:hidden print:hidden"
    >
      <button
        type="button"
        class="-ml-1 rounded-lg p-2 transition-colors duration-150 hover:bg-white/10"
        :aria-expanded="laciTerbuka"
        aria-label="Buka menu navigasi"
        @click="laciTerbuka = true"
      >
        <Ikon nama="menu" ukuran="h-5 w-5" />
      </button>

      <p class="font-display text-base font-semibold">Capture</p>

      <SaklarTema varian="sidebar" />
    </header>

    <!-- Tirai laci -->
    <Transition
      enter-active-class="transition-opacity duration-200 ease-out"
      enter-from-class="opacity-0"
      leave-active-class="transition-opacity duration-150 ease-in"
      leave-to-class="opacity-0"
    >
      <div
        v-if="laciTerbuka"
        class="fixed inset-0 z-40 bg-navy-900/60 backdrop-blur-[2px] md:hidden"
        @click="laciTerbuka = false"
      ></div>
    </Transition>

    <!--
      Sidebar. Selalu tergambar; yang berpindah hanya posisinya, sehingga
      lacinya meluncur alih-alih berkedip muncul. Tidak ikut tercetak —
      lembar cetak hanya memuat isinya (FR-REK-03).
    -->
    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-72 flex-col bg-sidebar lapis-sidebar text-sidebar-teks transition-transform duration-200 ease-out md:sticky md:top-0 md:z-auto md:h-screen md:shrink-0 md:translate-x-0 md:shadow-none print:hidden"
      :class="laciTerbuka ? 'translate-x-0 shadow-2xl' : '-translate-x-full'"
    >
        <div class="flex items-start justify-between border-b border-sidebar-garis px-5 py-5">
          <Link href="/" class="flex items-center gap-3 rounded-xl">
            <span class="ubin-merek h-10 w-10 shrink-0">
              <Ikon nama="absen" ukuran="h-5 w-5" />
            </span>
            <span class="min-w-0 leading-tight">
              <span class="block font-display text-base font-semibold">Capture</span>
              <span class="mt-0.5 block text-xs text-sidebar-redup">Absensi Kegiatan</span>
            </span>
          </Link>

          <button
            type="button"
            class="-mr-2 rounded-lg p-2 text-sidebar-redup transition-colors duration-150 hover:bg-white/10 hover:text-sidebar-teks md:hidden"
            aria-label="Tutup menu navigasi"
            @click="laciTerbuka = false"
          >
            <Ikon nama="tutup" ukuran="h-5 w-5" />
          </button>
        </div>

        <!--
          Keadaan aktif dibuat sebagai bidang teal yang benar-benar terangkat —
          sorotan tipis di tepi atas ditambah pendar berwarna di bawahnya —
          bukan sekadar isian rata. Pada latar navy, isian rata terbaca sebagai
          "baris yang kebetulan diberi warna"; yang dicari di sini adalah
          "tombol yang sedang ditekan".
        -->
        <nav class="gulir-halus flex-1 space-y-0.5 overflow-y-auto px-3 py-4">
          <template v-for="item in menu" :key="item.label">
            <!-- Menu induk dengan submenu -->
            <div v-if="item.anak" class="mt-4 border-t border-sidebar-garis pt-4 first:mt-0 first:border-t-0 first:pt-0">
              <p
                class="flex items-center gap-2 px-3 pb-1.5 text-[0.6875rem] font-semibold uppercase tracking-[0.1em] text-sidebar-redup"
              >
                <Ikon :nama="item.ikon" ukuran="h-3.5 w-3.5" />
                {{ item.label }}
              </p>

              <Link
                v-for="anak in item.anak"
                :key="anak.rute"
                :href="anak.url"
                class="tautan-aksi relative flex items-center rounded-xl py-2 pl-9 pr-3 text-sm transition-all duration-150"
                :class="
                  aktif(anak.rute)
                    ? 'bg-aksen font-medium text-white shadow-[inset_0_1px_0_rgb(255_255_255/0.2),0_2px_10px_rgb(13_148_136/0.35)]'
                    : 'text-sidebar-teks/80 hover:bg-white/[0.07] hover:text-sidebar-teks'
                "
              >
                <span
                  class="absolute left-3.5 h-1.5 w-1.5 rounded-full transition-colors duration-150"
                  :class="aktif(anak.rute) ? 'bg-white' : 'bg-sidebar-redup/40'"
                ></span>
                {{ anak.label }}
              </Link>
            </div>

            <!-- Menu tunggal -->
            <Link
              v-else
              :href="item.url"
              class="tautan-aksi flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition-all duration-150"
              :class="
                aktif(item.rute)
                  ? 'bg-aksen font-medium text-white shadow-[inset_0_1px_0_rgb(255_255_255/0.2),0_2px_10px_rgb(13_148_136/0.35)]'
                  : 'text-sidebar-teks/80 hover:bg-white/[0.07] hover:text-sidebar-teks'
              "
            >
              <Ikon :nama="item.ikon" ukuran="h-5 w-5 shrink-0" />
              {{ item.label }}
            </Link>
          </template>
        </nav>

        <!-- Indikator peran & cakupan unit kerja -->
        <div class="border-t border-sidebar-garis p-3">
          <div class="rounded-xl bg-white/[0.06] p-3">
            <div class="flex items-center gap-3">
              <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-aksen font-display text-sm font-semibold text-white ring-2 ring-white/15"
              >
                {{ pengguna.nama.charAt(0).toUpperCase() }}
              </span>
              <div class="min-w-0">
                <p class="truncate text-sm font-medium">{{ pengguna.nama }}</p>
                <p class="truncate text-xs text-aksen-kuat">{{ pengguna.role_label }}</p>
              </div>
            </div>

            <p class="mt-2.5 truncate text-xs text-sidebar-redup" :title="cakupan">{{ cakupan }}</p>
          </div>

          <!-- Saklar tema tinggal di bilah atas; di sini cukup tombol keluar. -->
          <button
            type="button"
            class="mt-2 inline-flex w-full items-center justify-center gap-1.5 rounded-xl px-3 py-2.5 text-xs font-medium text-sidebar-redup transition-colors duration-150 hover:bg-white/10 hover:text-sidebar-teks"
            @click="keluar"
          >
            <Ikon nama="keluar" ukuran="h-4 w-4" /> Keluar
          </button>
        </div>
    </aside>

    <!-- Konten -->
    <div class="min-w-0 flex-1">
      <!--
        Bilah atas layar lebar: tempat saklar tema, supaya tidak terkubur di
        dalam menu samping.
      -->
      <div
        class="sticky top-0 z-20 hidden items-center justify-between gap-3 border-b border-garis bg-permukaan/80 px-6 py-2.5 backdrop-blur-sm md:flex print:hidden"
      >
        <!--
          Jejak lokasi. Sidebar sudah menandai halaman yang aktif, tetapi pada
          layar lebar bilah ini kosong sama sekali sebelumnya — dan bilah kosong
          selebar halaman adalah ruang yang terbuang, bukan ruang yang lapang.
        -->
        <p class="truncate text-sm text-redup">
          <Link href="/admin/dashboard" class="rounded transition-colors duration-150 hover:text-sekunder">
            Panel Admin
          </Link>
          <span class="px-1.5 text-garis-kuat">/</span>
          <span class="font-medium text-sekunder">{{ judul }}</span>
        </p>

        <SaklarTema />
      </div>

      <main class="mx-auto max-w-6xl px-4 py-5 sm:px-6 sm:py-6">
        <!-- Peringatan Mode Terbuka; terlihat di setiap halaman admin. -->
        <div
          v-if="modeTerbuka"
          class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-peringatan bg-peringatan-lembut px-4 py-3 print:hidden"
        >
          <p class="flex items-start gap-2 text-sm text-peringatan-teks">
            <Ikon nama="peringatan" ukuran="h-5 w-5 shrink-0" />
            <span>
              <span class="font-semibold">Mode Terbuka Aktif</span> — perangkat tidak perlu
              registrasi dan dapat mengabsen tanpa kode aktivasi. Gunakan hanya untuk kebutuhan
              darurat, dan jangan lupa nonaktifkan kembali.
            </span>
          </p>
          <Link
            href="/admin/kelola-absen/setting"
            class="tautan-aksi inline-flex shrink-0 items-center gap-1.5 rounded-md border border-peringatan px-3 py-2 text-xs font-semibold text-peringatan-teks transition hover:bg-peringatan-lembut active:scale-95"
          >
            <Ikon nama="filter" ukuran="h-3.5 w-3.5" /> Nonaktifkan
          </Link>
        </div>

        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-garis pb-4">
          <div class="min-w-0">
            <h1 class="font-display text-2xl font-semibold text-utama sm:text-[1.75rem]">{{ judul }}</h1>
            <p v-if="deskripsi" class="mt-1.5 max-w-2xl text-sm text-sekunder">{{ deskripsi }}</p>
          </div>
          <slot name="aksi" />
        </div>

        <Transition
          enter-active-class="transition duration-200 ease-out"
          enter-from-class="-translate-y-2 opacity-0"
          enter-to-class="translate-y-0 opacity-100"
        >
          <div
            v-if="flash.sukses"
            class="mt-6 flex items-start gap-2 rounded-lg border border-garis bg-berhasil-lembut px-4 py-3 text-sm text-berhasil-teks print:hidden"
          >
            <Ikon nama="cek" ukuran="h-4 w-4 shrink-0 mt-0.5" />
            <span>{{ flash.sukses }}</span>
          </div>
        </Transition>
        <Transition
          enter-active-class="transition duration-200 ease-out"
          enter-from-class="-translate-y-2 opacity-0"
          enter-to-class="translate-y-0 opacity-100"
        >
          <div
            v-if="flash.gagal"
            class="mt-6 flex items-start gap-2 rounded-lg border border-garis bg-peringatan-lembut px-4 py-3 text-sm text-peringatan-teks print:hidden"
          >
            <Ikon nama="peringatan" ukuran="h-4 w-4 shrink-0 mt-0.5" />
            <span>{{ flash.gagal }}</span>
          </div>
        </Transition>

        <!--
          Transisi antar halaman: isi lama memudar keluar, isi baru masuk
          naik sedikit. `:key` pada rute yang membuatnya berjalan; tanpa itu
          Vue menggunakan ulang simpul yang sama dan tidak ada yang beralih.
        -->
        <Transition
          mode="out-in"
          enter-active-class="transition duration-200 ease-out"
          enter-from-class="translate-y-2 opacity-0"
          enter-to-class="translate-y-0 opacity-100"
          leave-active-class="transition duration-100 ease-in"
          leave-from-class="opacity-100"
          leave-to-class="opacity-0"
        >
          <div :key="ruteSaatIni" class="mt-5">
            <slot />
          </div>
        </Transition>
      </main>
    </div>
  </div>
</template>
