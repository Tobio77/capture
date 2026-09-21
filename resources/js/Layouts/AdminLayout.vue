<script setup>
import { computed, onMounted, ref, watch } from 'vue'
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

/* -------------------------------------------------------------- submenu */

/**
 * Kelompok menu yang sedang terbuka (S50).
 *
 * Sebelumnya seluruh anak "Kelola Absen" — tujuh butir — selalu tergambar,
 * sehingga daftar navigasi mencapai dua belas baris dan menuntut gulir pada
 * laptop 13 inci. Kini kelompoknya dapat dilipat, dan yang tertutup menyusut
 * menjadi satu baris.
 *
 * Keadaannya disimpan di localStorage karena AdminLayout **dibuat ulang pada
 * setiap perpindahan halaman** — Inertia di proyek ini tidak memakai
 * persistent layout (lihat resources/js/app.js). Tanpa penyimpanan, kelompok
 * yang baru saja dibuka admin akan menutup sendiri begitu ia menekan salah
 * satu isinya.
 */
const KUNCI_SIMPANAN = 'capture.sidebar.kelompok'

function bacaSimpanan() {
  try {
    const isi = JSON.parse(localStorage.getItem(KUNCI_SIMPANAN) ?? '[]')

    return Array.isArray(isi) ? isi : []
  } catch {
    // Mode privat, kuota penuh, atau nilai rusak — bukan alasan menggagalkan
    // seluruh navigasi. Bawaannya: seluruh kelompok tertutup.
    return []
  }
}

const grupTerbuka = ref(new Set(bacaSimpanan()))

/** Kelompok yang memuat halaman yang sedang dibuka, bila ada. */
const grupAktif = computed(
  () => menu.value.find((item) => item.anak?.some((anak) => aktif(anak.rute)))?.label ?? null,
)

/*
 * Kelompok yang memuat halaman berjalan selalu dibuka saat layar dirakit —
 * sidebar yang tidak menunjukkan DI MANA penggunanya berada berhenti menjadi
 * navigasi. Pembukaan otomatis ini sengaja TIDAK disimpan: yang tersimpan
 * hanya keputusan yang benar-benar diambil admin lewat tombolnya, sehingga
 * sekali berkunjung ke satu halaman tidak membuat kelompoknya menganga
 * selamanya.
 */
onMounted(() => {
  if (grupAktif.value !== null) {
    grupTerbuka.value = new Set(grupTerbuka.value).add(grupAktif.value)
  }
})

const terbuka = (item) => grupTerbuka.value.has(item.label)

function alihkanGrup(item) {
  const berikutnya = new Set(grupTerbuka.value)

  berikutnya.has(item.label) ? berikutnya.delete(item.label) : berikutnya.add(item.label)
  grupTerbuka.value = berikutnya

  try {
    localStorage.setItem(KUNCI_SIMPANAN, JSON.stringify([...berikutnya]))
  } catch {
    // Penyimpanan gagal hanya berarti keadaannya tidak bertahan antar halaman.
  }
}

/*
 * Peringatannya sengaja dipasang di kerangka halaman, bukan di satu layar
 * saja — sakelar ini gampang dimatikan untuk satu kegiatan lalu terlupakan,
 * dan justru itulah yang berbahaya. Sampai audit pra-deploy ia tidak punya
 * peringatan apa pun, padahal ia yang menentukan apakah kehadiran
 * benar-benar dibuktikan wajah.
 *
 * Spanduk "Mode Terbuka" yang dulu berdampingan di sini sudah tidak ada:
 * jalur masuk bawaan perangkat kini selalu menuntut kode unit kerja, sehingga
 * tidak ada lagi keadaan yang perlu diperingatkan (lihat KodeUnitService).
 */
const verifikasiWajahMati = computed(() => page.props.verifikasi_wajah_mati === true)

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

  <div class="min-h-screen bg-kertas lg:flex">
    <!-- Bilah atas; hanya di layar sempit. -->
    <header
      class="sticky top-0 z-30 flex items-center justify-between gap-3 border-b border-sidebar-garis bg-sidebar lapis-sidebar px-4 py-3 text-sidebar-teks lg:hidden print:hidden"
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
        class="fixed inset-0 z-40 bg-navy-900/60 backdrop-blur-[2px] lg:hidden"
        @click="laciTerbuka = false"
      ></div>
    </Transition>

    <!--
      Sidebar. Selalu tergambar; yang berpindah hanya posisinya, sehingga
      lacinya meluncur alih-alih berkedip muncul. Tidak ikut tercetak —
      lembar cetak hanya memuat isinya (FR-REK-03).
    -->
    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-72 flex-col bg-sidebar lapis-sidebar text-sidebar-teks transition-transform duration-200 ease-out lg:sticky lg:top-0 lg:z-auto lg:h-screen lg:shrink-0 lg:translate-x-0 lg:shadow-none print:hidden"
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
          class="-mr-2 rounded-lg p-2 text-sidebar-redup transition-colors duration-150 hover:bg-white/10 hover:text-sidebar-teks lg:hidden"
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

          Gerak di sini sengaja kecil dan searah: ikon bergeser sedikit ke
          kanan saat disentuh, seolah barisnya condong ke arah tujuannya.
          Sidebar dilihat sepanjang hari, dan gerak yang lebih besar dari itu
          berhenti terasa halus setelah pemakaian kelima.
        -->
      <nav class="gulir-halus flex-1 space-y-1 overflow-y-auto px-3 py-4">
        <template v-for="item in menu" :key="item.label">
          <!-- Kelompok yang dapat dilipat -->
          <div v-if="item.anak">
            <button
              type="button"
              class="menu-baris group w-full"
              :class="terbuka(item) ? 'menu-baris-grup-terbuka' : 'menu-baris-diam'"
              :aria-expanded="terbuka(item)"
              :aria-controls="`submenu-${item.label.replace(/\s+/g, '-')}`"
              @click="alihkanGrup(item)"
            >
              <Ikon :nama="item.ikon" ukuran="h-5 w-5" class="menu-ikon" />

              <span class="flex-1 text-left">{{ item.label }}</span>

              <!--
                Titik penanda: kelompok yang sedang tertutup tetapi memuat
                halaman berjalan. Tanpa ini, melipat kelompok berarti kehilangan
                satu-satunya petunjuk di mana pengguna sedang berada.
              -->
              <span
                v-if="!terbuka(item) && grupAktif === item.label"
                class="h-1.5 w-1.5 rounded-full bg-aksen-kuat"
                aria-hidden="true"
              ></span>

              <Ikon
                nama="bawah"
                ukuran="h-4 w-4"
                class="text-sidebar-redup transition-transform duration-300 ease-out group-hover:text-sidebar-teks"
                :class="terbuka(item) && 'rotate-180'"
              />
            </button>

            <!--
              Lipatannya memakai `grid-template-rows: 0fr → 1fr`, bukan
              `max-height` yang ditebak. Tebakan tinggi selalu meleset pada
              salah satu peran — submenu Admin UPT lebih pendek tiga butir
              daripada Superadmin — dan meleset ke atas berarti animasinya
              tersentak di akhir, meleset ke bawah berarti isinya terpotong.
            -->
            <!--
              `inert` saat terlipat. Isinya tetap ada di DOM supaya lipatannya
              dapat dianimasikan, dan tanpa penanda ini tautan yang tidak
              terlihat tetap dapat dijangkau Tab maupun pembaca layar — fokus
              yang berpindah ke tautan setinggi nol piksel adalah fokus yang
              hilang, dan penggunanya tidak punya cara tahu ke mana ia pergi.

              Ditulis `terbuka ? undefined : true`, bukan `!terbuka`: atribut
              `inert` aktif oleh KEBERADAANNYA, sehingga `inert="false"` yang
              lahir dari nilai boolean palsu justru mematikan submenu yang
              sedang terbuka.
            -->
            <div
              :id="`submenu-${item.label.replace(/\s+/g, '-')}`"
              class="grid transition-[grid-template-rows] duration-300 ease-out"
              :class="terbuka(item) ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
              :inert="terbuka(item) ? undefined : true"
            >
              <div class="overflow-hidden">
                <div class="relative mt-1 space-y-0.5 pl-5">
                  <!-- Garis penghubung, menandai kedalaman tanpa indentasi lebar. -->
                  <span
                    class="absolute inset-y-1 left-[1.4rem] w-px bg-sidebar-garis"
                    aria-hidden="true"
                  ></span>

                  <Link
                    v-for="(anak, urutan) in item.anak"
                    :key="anak.rute"
                    :href="anak.url"
                    class="menu-baris menu-anak tautan-aksi group text-[0.8125rem]"
                    :class="[
                      aktif(anak.rute) ? 'menu-baris-aktif' : 'menu-baris-diam',
                      terbuka(item) ? 'menu-anak-masuk' : 'menu-anak-tersembunyi',
                    ]"
                    :style="{ '--tunda': `${terbuka(item) ? urutan * 28 : 0}ms` }"
                  >
                    <Ikon :nama="anak.ikon" ukuran="h-4 w-4" class="menu-ikon" />
                    <span class="flex-1 text-left">{{ anak.label }}</span>
                  </Link>
                </div>
              </div>
            </div>
          </div>

          <!-- Menu tunggal -->
          <Link
            v-else
            :href="item.url"
            class="menu-baris tautan-aksi group"
            :class="aktif(item.rute) ? 'menu-baris-aktif' : 'menu-baris-diam'"
          >
            <Ikon :nama="item.ikon" ukuran="h-5 w-5" class="menu-ikon" />
            <span class="flex-1 text-left">{{ item.label }}</span>
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
        class="sticky top-0 z-20 hidden items-center justify-between gap-3 border-b border-garis bg-permukaan/80 px-6 py-2.5 backdrop-blur-sm lg:flex print:hidden"
      >
        <!--
          Jejak lokasi. Sidebar sudah menandai halaman yang aktif, tetapi pada
          layar lebar bilah ini kosong sama sekali sebelumnya — dan bilah kosong
          selebar halaman adalah ruang yang terbuang, bukan ruang yang lapang.
        -->
        <p class="truncate text-sm text-redup">
          <Link
            href="/admin/dashboard"
            class="rounded transition-colors duration-150 hover:text-sekunder"
          >
            Panel Admin
          </Link>
          <span class="px-1.5 text-garis-kuat">/</span>
          <span class="font-medium text-sekunder">{{ judul }}</span>
        </p>

        <SaklarTema />
      </div>

      <main class="mx-auto max-w-6xl px-4 py-5 sm:px-6 sm:py-6">
        <!--
          Peringatan verifikasi wajah MATI; terlihat di setiap halaman admin.

          Rose, bukan amber: selama sakelar ini mati, kehadiran tidak
          dibuktikan wajah sama sekali — cukup menyebut NIP. Amber berarti
          "berlanjut, tetapi catat"; ini "sistem sedang tidak membuktikan apa
          pun".
        -->
        <div
          v-if="verifikasiWajahMati"
          class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-galat bg-galat-lembut px-4 py-3 print:hidden"
        >
          <p class="flex items-start gap-2 text-sm text-galat-teks">
            <Ikon nama="peringatan" ukuran="h-5 w-5 shrink-0" />
            <span>
              <span class="font-semibold">Verifikasi Wajah Mati</span> — kehadiran dicatat tanpa
              membuktikan wajah, jadi siapa pun yang menyebut NIP orang lain akan diterima. Nyalakan
              kembali segera setelah keperluannya selesai.
            </span>
          </p>
          <Link
            href="/admin/kelola-absen/setting"
            class="tautan-aksi inline-flex shrink-0 items-center gap-1.5 rounded-md border border-galat px-3 py-2 text-xs font-semibold text-galat-teks transition hover:bg-galat-lembut active:scale-95"
          >
            <Ikon nama="filter" ukuran="h-3.5 w-3.5" /> Nyalakan
          </Link>
        </div>

        <!--
          Kepala halaman sebagai PELAT NAVY, mewarisi arah halaman depan
          (S32b). Sebelumnya ia hanya judul di atas garis bawah — benar,
          tetapi tidak menyatakan apa pun; halaman admin jadi terbaca sebagai
          deretan kartu putih tanpa awal yang jelas.

          Sudutnya membulat dan ia berdiri di atas sage, tidak menempel ke tepi
          layar: sidebar sudah navy, dan pelat yang menyentuhnya akan luluh
          menjadi satu bidang gelap raksasa.

          Tombol yang diserahkan halaman lewat slot `aksi` dibalik warnanya
          oleh aturan `.pelat-kepala .tombol-garis` di tema.css — bukan oleh
          setiap halaman mengingatnya sendiri.
        -->
        <div
          class="pelat-kepala px-5 py-5 sm:px-6 print:border print:border-garis print:bg-transparent print:text-utama"
        >
          <!--
            Dua bola cahaya, bukan tiga seperti halaman depan.

            Layar ini dibaca berlama-lama dari kursi, bukan dilirik sambil
            berdiri; cahaya yang bergerak di belakang teks yang sedang dibaca
            lama-lama melelahkan. Keduanya lebih pucat, lebih lambat, dan tidak
            ikut tercetak.
          -->
          <div class="bola bola-kepala-1 print:hidden" aria-hidden="true"></div>
          <div class="bola bola-kepala-2 print:hidden" aria-hidden="true"></div>

          <div class="relative flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
              <h1 class="font-display text-2xl font-semibold sm:text-[1.75rem]">{{ judul }}</h1>
              <p
                v-if="deskripsi"
                class="mt-1.5 max-w-2xl text-sm text-sidebar-redup print:text-sekunder"
              >
                {{ deskripsi }}
              </p>
            </div>
            <slot name="aksi" />
          </div>

          <!--
            Isi yang duduk DI DALAM pelat.

            Dibuka sebagai slot supaya halaman yang punya satu angka pokok —
            Dashboard dengan kehadiran hari ini — dapat menaruhnya di sini,
            persis seperti kartu jam di halaman depan. Pelat yang hanya berisi
            judul adalah bidang gelap yang tidak mengerjakan apa pun.

            Isi apa pun yang diletakkan di sini WAJIB memakai permukaan yang
            menyatakan tintanya sendiri (`.panel`, `.kartu-jam`); lihat aturan
            tinta pada tema.css dan penjaganya, `npm run periksa:tinta`.
          -->
          <div v-if="$slots.pelat" class="relative mt-5">
            <slot name="pelat" />
          </div>

          <!-- Deret garis ukur: motif yang sama dengan halaman depan. -->
          <div
            class="skala-ukur skala-terang absolute inset-x-0 bottom-0 h-2.5 print:hidden"
            aria-hidden="true"
          ></div>
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
