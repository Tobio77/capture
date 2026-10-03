<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Ikon from '@/Components/Ikon.vue'
import TombolProses from '@/Components/UI/TombolProses.vue'
import HariLibur from '@/Pages/Setting/HariLibur.vue'

/**
 * Setting Absen — pengaturan global sistem (FR-SET-01 s.d. FR-SET-06).
 *
 * Hari Libur (FR-SET-08) ada di berkas sendiri ({@see HariLibur.vue}):
 * formulir, penyimpanan, dan kegagalannya berdiri lepas dari setting absen
 * di sini, jadi tidak ada gunanya ikut memanjangkan berkas ini.
 */

const props = defineProps({
  setting: { type: Object, required: true },
  preset_kompresi: { type: Array, required: true },
  batas: { type: Object, required: true },

  /** Hari libur bertanggal yang masih relevan (FR-SET-08) — diteruskan apa adanya ke HariLibur.vue. */
  hari_libur: { type: Array, default: () => [] },
  unit_kerja_libur: { type: Array, default: () => [] },
  boleh_libur_nasional: { type: Boolean, default: false },
})

const form = useForm({ ...props.setting })

/*
 * Urutan tetap Senin..Minggu, selaras dengan urutan `hari` (ISO 1..7) yang
 * dikirim SettingAbsenService::jadwalMingguan() — indeks array form dan
 * indeks label ini SELALU sejajar, tidak perlu dicocokkan lewat `hari`.
 */
const HARI_LABEL = ['Senin', 'Selasa', 'Rabu', 'Kamis', "Jum'at", 'Sabtu', 'Minggu']

/**
 * Menyalin jam satu baris ke keenam baris lainnya — tanpa ini, kasus paling
 * umum ("cuma satu hari yang beda") berarti mengisi ulang 30 kolom jam
 * manual satu per satu.
 */
function samakanKeSemuaHari(indeks) {
  const acuan = form.jadwal_mingguan[indeks]

  form.jadwal_mingguan = form.jadwal_mingguan.map((baris) => ({
    ...baris,
    jam_masuk: acuan.jam_masuk,
    jam_buka_datang: acuan.jam_buka_datang,
    jam_tutup_datang: acuan.jam_tutup_datang,
    jam_buka_pulang: acuan.jam_buka_pulang,
    jam_tutup_pulang: acuan.jam_tutup_pulang,
  }))
}

// Galat per-sel ("jadwal_mingguan.2.jam_masuk", dst.) dirangkum satu baris —
// 35 slot galat kecil di bawah tiap kolom sel akan lebih ramai daripada
// membantu.
const errorJadwalMingguan = computed(() => {
  const kunci = Object.keys(form.errors).find((k) => k.startsWith('jadwal_mingguan'))

  return kunci ? 'Ada jam yang belum valid pada tabel di bawah — periksa kembali.' : null
})

const fitur = [
  {
    kunci: 'absen_umum_aktif',
    judul: 'Absen Umum',
    keterangan:
      'Absen datang dan pulang harian untuk seluruh dinas. Bila dinonaktifkan, sesi hari ini tidak dihapus dan berlanjut begitu fiturnya dinyalakan kembali.',
  },
  {
    kunci: 'absen_event_aktif',
    judul: 'Absen Event',
    keterangan:
      'Absen kegiatan yang dibuka admin. Bila dinonaktifkan, event tetap dapat dikelola tetapi perangkat tidak dapat melayaninya.',
  },
]

const metode = [
  {
    kunci: 'metode_manual_aktif',
    judul: 'Input Manual',
    keterangan: 'Pegawai mengetik NIP pada kiosk. Sebaiknya tetap aktif sebagai jalur cadangan.',
  },
  {
    kunci: 'metode_rfid_aktif',
    judul: 'Tap RFID',
    keterangan: 'Kartu pegawai dibaca perangkat RFID yang terpasang pada kiosk.',
  },
  {
    kunci: 'metode_wajah_aktif',
    judul: 'Verifikasi Wajah',
    keterangan: 'Kamera kiosk mencocokkan wajah dengan foto referensi yang sudah terdaftar.',
  },
]

const adaMetodeAktif = computed(() => metode.some((m) => form[m.kunci]))

const presetTerpilih = computed(
  () => props.preset_kompresi.find((p) => p.nilai === form.kompresi_foto) ?? null,
)

const simpan = () => {
  form.post('/admin/kelola-absen/setting', { preserveScroll: true })
}
</script>

<template>
  <AdminLayout
    judul="Setting Absen"
    deskripsi="Metode absen, toleransi keterlambatan, ambang kecocokan wajah, dan kompresi foto — berlaku untuk seluruh unit kerja."
  >
    <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="simpan">
      <div class="space-y-6 lg:col-span-2">
        <!--
          Sakelar fitur. Yang dimatikan tertutup sepenuhnya: pilihannya di
          halaman depan terkunci, layarnya tidak dapat dibuka, dan setiap tap
          ditolak server (PastikanFiturAbsenAktif). Data yang sudah tercatat
          tetap dapat dibaca di Rekap dan Laporan.
        -->
        <section class="panel p-6">
          <h2 class="font-display text-sm font-semibold text-utama">Fitur Absensi</h2>
          <p class="mt-1 text-xs text-redup">
            Fitur yang dinonaktifkan tidak dapat diakses dari perangkat absen maupun layar absen
            admin. Data yang sudah tercatat tetap tampil pada Rekap dan Laporan.
          </p>

          <div class="mt-4 space-y-3">
            <label
              v-for="item in fitur"
              :key="item.kunci"
              class="flex cursor-pointer items-start gap-3 rounded-md border border-garis px-4 py-3 transition hover:bg-permukaan-hover"
            >
              <input v-model="form[item.kunci]" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-garis text-aksen focus:ring-aksen" />
              <span class="min-w-0 flex-1">
                <span class="flex items-center gap-2 text-sm font-medium text-utama">
                  {{ item.judul }}
                  <span
                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                    :class="form[item.kunci] ? 'bg-berhasil-lembut text-berhasil-teks' : 'bg-peringatan-lembut text-peringatan-teks'"
                  >
                    {{ form[item.kunci] ? 'Aktif' : 'Nonaktif' }}
                  </span>
                </span>
                <span class="mt-0.5 block text-xs text-redup">{{ item.keterangan }}</span>
              </span>
            </label>
          </div>
        </section>

        <!-- FR-SET-01 -->
        <section class="panel p-6">
          <h2 class="font-display text-sm font-semibold text-utama">Metode Absensi Aktif</h2>
          <p class="mt-1 text-xs text-redup">Metode yang dimatikan tidak akan muncul pada layar kiosk.</p>

          <div class="mt-4 space-y-3">
            <label
              v-for="item in metode"
              :key="item.kunci"
              class="flex cursor-pointer items-start gap-3 rounded-md border border-garis px-4 py-3 transition hover:bg-permukaan-hover"
            >
              <input v-model="form[item.kunci]" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-garis text-aksen focus:ring-aksen" />
              <span>
                <span class="block text-sm font-medium text-utama">{{ item.judul }}</span>
                <span class="mt-0.5 block text-xs text-redup">{{ item.keterangan }}</span>
              </span>
            </label>
          </div>

          <p v-if="!adaMetodeAktif" class="mt-3 rounded-md bg-peringatan-lembut px-3 py-2 text-xs text-peringatan-teks">
            Minimal satu metode absen harus aktif — tanpa itu tidak ada cara mengabsen sama sekali.
          </p>
          <p v-if="form.errors.metode_manual_aktif" class="mt-2 text-xs text-peringatan-teks">
            {{ form.errors.metode_manual_aktif }}
          </p>
        </section>

        <!-- FR-SET-02 & FR-SET-03 -->
        <section class="panel p-6">
          <div class="space-y-6">
            <div>
              <label for="toleransi" class="block text-sm font-medium text-utama">
                Toleransi Keterlambatan Default
              </label>
              <p class="mt-0.5 text-xs text-redup">
                Nilai awal untuk event baru; masih dapat diubah per event saat pembuatannya.
              </p>
              <div class="mt-2 flex items-center gap-2">
                <input
                  id="toleransi"
                  v-model.number="form.toleransi_default_menit"
                  type="number"
                  min="0"
                  :max="batas.toleransi_maks"
                  class="kolom-isian w-28 font-display tabular-nums"
                />
                <span class="text-sm text-sekunder">menit</span>
              </div>
              <p v-if="form.errors.toleransi_default_menit" class="mt-1.5 text-xs text-peringatan-teks">
                {{ form.errors.toleransi_default_menit }}
              </p>
            </div>

            <div>
              <label for="ambang" class="block text-sm font-medium text-utama">Ambang Kecocokan Wajah</label>
              <p class="mt-0.5 text-xs text-redup">
                Semakin tinggi, semakin ketat pencocokan — dan semakin sering wajah sah ikut tertolak.
              </p>
              <div class="mt-3 flex items-center gap-4">
                <input
                  id="ambang"
                  v-model.number="form.ambang_kecocokan_wajah"
                  type="range"
                  :min="batas.ambang_min"
                  :max="batas.ambang_maks"
                  class="h-2 flex-1 cursor-pointer appearance-none rounded-full bg-permukaan-2 accent-[var(--tema-aksen)]"
                />
                <span class="w-16 text-right font-display text-lg font-semibold tabular-nums text-utama">
                  {{ form.ambang_kecocokan_wajah }}%
                </span>
              </div>
              <div class="mt-1 flex justify-between text-xs text-redup">
                <span>{{ batas.ambang_min }}% — longgar</span>
                <span>{{ batas.ambang_maks }}% — ketat</span>
              </div>
              <p v-if="form.errors.ambang_kecocokan_wajah" class="mt-1.5 text-xs text-peringatan-teks">
                {{ form.errors.ambang_kecocokan_wajah }}
              </p>
            </div>
          </div>
        </section>

        <!-- Absen umum: absensi harian tanpa event kegiatan -->
        <section class="panel p-6">
          <h2 class="font-display text-sm font-semibold text-utama">Absen Umum Harian</h2>
          <p class="mt-1 text-xs text-redup">
            Sesi absen harian yang dibuka sistem sendiri ketika tidak ada event kegiatan yang
            berjalan, sehingga pegawai tetap dapat mencatat kehadiran rutinnya. Kegiatan selalu
            didahulukan bila keduanya berlaku bersamaan.
          </p>

          <p
            v-if="!form.absen_umum_aktif"
            class="mt-4 rounded-md bg-peringatan-lembut px-3 py-2 text-xs text-peringatan-teks"
          >
            Fitur Absen Umum sedang dinonaktifkan pada bagian Fitur Absensi di atas. Jadwal di
            bawah tetap tersimpan dan berlaku kembali begitu fiturnya dinyalakan.
          </p>

          <!--
            FR-SET-07 (revisi jadwal per hari). Jam masuk dan jendela
            operasional dulu satu angka untuk seluruh pekan; kini tiap hari
            punya barisnya sendiri — kasus nyata: UPT yang hari Rabu masuk
            lebih pagi karena ada senam bersama dulu.

            Sakelar "hari ini libur total" BUKAN di sini: itu sudah diatur
            per unit kerja lewat Unit Kerja → Hari Kerja (dengan pewarisan ke
            seksi di bawahnya), dan tetap berlaku apa pun jam yang diisi di
            bawah — hari yang bukan hari kerja unit tetap tertutup penuh.
          -->
          <div class="mt-6 border-t border-garis pt-5">
            <p class="text-sm font-medium text-utama">Jadwal Jam per Hari</p>
            <p class="mt-0.5 text-xs text-redup">
              <strong class="font-medium text-sekunder">Jam Masuk</strong> menentukan tepat/terlambat
              (ditambah toleransi keterlambatan di atas).
              <strong class="font-medium text-sekunder">Buka–Tutup Datang/Pulang</strong> menentukan
              kapan tap diterima sama sekali — di luar jam itu perangkat menolak tap, apa pun kata
              Jam Masuk.
            </p>

            <div class="mt-4 overflow-x-auto">
              <table class="w-full min-w-[42rem] text-sm">
                <thead>
                  <tr class="border-b border-garis text-left text-xs font-semibold uppercase tracking-wider text-redup">
                    <th class="py-2 pr-3">Hari</th>
                    <th class="px-2 py-2">Jam Masuk</th>
                    <th class="px-2 py-2" colspan="2">Datang (buka–tutup)</th>
                    <th class="px-2 py-2" colspan="2">Pulang (buka–tutup)</th>
                    <th class="py-2 pl-2"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="(baris, indeks) in form.jadwal_mingguan"
                    :key="baris.hari"
                    class="border-b border-garis last:border-0"
                  >
                    <td class="whitespace-nowrap py-2 pr-3 font-medium text-utama">
                      {{ HARI_LABEL[indeks] }}
                    </td>
                    <td class="px-2 py-2">
                      <input
                        v-model="baris.jam_masuk"
                        type="time"
                        :aria-label="`Jam masuk ${HARI_LABEL[indeks]}`"
                        class="kolom-isian w-28 font-display tabular-nums"
                      />
                    </td>
                    <td class="py-2 pl-2 pr-1">
                      <input
                        v-model="baris.jam_buka_datang"
                        type="time"
                        :aria-label="`Jam buka datang ${HARI_LABEL[indeks]}`"
                        class="kolom-isian w-28 font-display tabular-nums"
                      />
                    </td>
                    <td class="px-1 py-2">
                      <input
                        v-model="baris.jam_tutup_datang"
                        type="time"
                        :aria-label="`Jam tutup datang ${HARI_LABEL[indeks]}`"
                        class="kolom-isian w-28 font-display tabular-nums"
                      />
                    </td>
                    <td class="py-2 pl-2 pr-1">
                      <input
                        v-model="baris.jam_buka_pulang"
                        type="time"
                        :aria-label="`Jam buka pulang ${HARI_LABEL[indeks]}`"
                        class="kolom-isian w-28 font-display tabular-nums"
                      />
                    </td>
                    <td class="px-1 py-2">
                      <input
                        v-model="baris.jam_tutup_pulang"
                        type="time"
                        :aria-label="`Jam tutup pulang ${HARI_LABEL[indeks]}`"
                        class="kolom-isian w-28 font-display tabular-nums"
                      />
                    </td>
                    <td class="py-2 pl-2 text-right">
                      <button
                        type="button"
                        class="whitespace-nowrap text-xs font-medium text-aksen-teks hover:underline"
                        @click="samakanKeSemuaHari(indeks)"
                      >
                        Samakan ke semua hari
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <p v-if="errorJadwalMingguan" class="mt-2 text-xs text-peringatan-teks">
              {{ errorJadwalMingguan }}
            </p>

            <p class="mt-4 text-xs text-redup">
              Kasus khusus untuk SATU hari saja — apel dadakan sore hari, atau penutupan lebih awal
              — ditangani lewat tombol buka/tutup paksa pada halaman Absen Umum. Override itu
              berlaku hari itu saja dan tidak mengubah jadwal di atas.
            </p>
          </div>
        </section>

        <!-- FR-SET-06 -->
        <section class="panel p-6">
          <h2 class="font-display text-sm font-semibold text-utama">Mode Pendaftaran Perangkat</h2>
          <p class="mt-1 text-xs text-redup">
            Secara bawaan mode ini <strong>dimatikan</strong>: perangkat masuk dengan mengetikkan
            kode unit kerjanya, tanpa perlu didaftarkan lebih dahulu. Kode itu dibaca dan diganti
            di menu Setting → Unit Kerja.
          </p>

          <label class="mt-4 flex cursor-pointer items-start gap-3">
            <input
              v-model="form.pendaftaran_perangkat_aktif"
              type="checkbox"
              class="mt-0.5 h-4 w-4 rounded border-garis text-aksen focus:ring-aksen"
            />
            <span>
              <span class="block text-sm font-medium text-utama">
                Wajibkan pendaftaran perangkat
              </span>
              <span class="mt-0.5 block text-xs text-redup">
                Biarkan mati pada operasi normal.
              </span>
            </span>
          </label>

          <div
            v-if="form.pendaftaran_perangkat_aktif"
            class="mt-4 rounded-lg border border-peringatan bg-peringatan-lembut p-4"
          >
            <p class="flex items-center gap-1.5 text-sm font-semibold text-peringatan-teks">
              <Ikon nama="peringatan" ukuran="h-4 w-4" /> Kode unit kerja tidak lagi diterima
            </p>
            <p class="mt-1 text-xs text-peringatan-teks">
              Selama mode ini menyala, setiap komputer harus didaftarkan lebih dahulu di menu
              <strong>Perangkat Absen</strong> dan menukarkan kode aktivasi sekali pakai miliknya
              sendiri. Perangkat yang sudah terhubung tetap berjalan; yang belum tidak akan dapat
              masuk sampai admin mendaftarkannya.
            </p>
          </div>
        </section>

        <!-- FR-SET-04 -->
        <section class="panel p-6">
          <h2 class="font-display text-sm font-semibold text-utama">Kompresi Foto Absen</h2>
          <p class="mt-1 text-xs text-redup">
            Foto absen disusutkan di kiosk sebelum dikirim, agar ruang penyimpanan server terkendali.
          </p>

          <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label
              v-for="preset in preset_kompresi"
              :key="preset.nilai"
              class="cursor-pointer rounded-md border px-4 py-3 transition"
              :class="form.kompresi_foto === preset.nilai
                ? 'border-teal-600 bg-aksen-lembut ring-1 ring-teal-600'
                : 'border-garis hover:bg-permukaan-hover'"
            >
              <input v-model="form.kompresi_foto" type="radio" :value="preset.nilai" class="sr-only" />
              <span class="block text-sm font-medium text-utama">{{ preset.label }}</span>
              <span class="mt-1 block font-display text-xs tabular-nums text-redup">
                {{ preset.dimensi_maks }} px · kualitas {{ preset.kualitas }}
              </span>
              <span class="mt-1 block text-xs text-redup">{{ preset.estimasi }}</span>
            </label>
          </div>

          <p v-if="presetTerpilih" class="mt-3 text-xs text-redup">{{ presetTerpilih.keterangan }}</p>
          <p v-if="form.errors.kompresi_foto" class="mt-2 text-xs text-peringatan-teks">{{ form.errors.kompresi_foto }}</p>
        </section>

        <!-- FR-LAP-04 -->
        <section class="panel p-6">
          <h2 class="font-display text-sm font-semibold text-utama">Ambang Batas Laporan</h2>
          <p class="mt-1 text-xs text-redup">
            Menyalakan bagian Rekomendasi pada Laporan Resmi: unit yang melampaui salah satu ambang
            ini disebutkan namanya beserta saran tindak lanjut.
          </p>

          <div class="mt-4 space-y-6">
            <div>
              <label for="ambang-kehadiran" class="block text-sm font-medium text-utama">
                Kehadiran Minimum
              </label>
              <p class="mt-0.5 text-xs text-redup">
                Unit dengan tingkat kehadiran di bawah ini direkomendasikan untuk dievaluasi.
              </p>
              <div class="mt-3 flex items-center gap-4">
                <input
                  id="ambang-kehadiran"
                  v-model.number="form.ambang_kehadiran_minimum"
                  type="range"
                  :min="batas.ambang_kehadiran_min"
                  :max="batas.ambang_kehadiran_maks"
                  class="h-2 flex-1 cursor-pointer appearance-none rounded-full bg-permukaan-2 accent-[var(--tema-aksen)]"
                />
                <span class="w-16 text-right font-display text-lg font-semibold tabular-nums text-utama">
                  {{ form.ambang_kehadiran_minimum }}%
                </span>
              </div>
              <div class="mt-1 flex justify-between text-xs text-redup">
                <span>{{ batas.ambang_kehadiran_min }}%</span>
                <span>{{ batas.ambang_kehadiran_maks }}%</span>
              </div>
              <p v-if="form.errors.ambang_kehadiran_minimum" class="mt-1.5 text-xs text-peringatan-teks">
                {{ form.errors.ambang_kehadiran_minimum }}
              </p>
            </div>

            <div>
              <label for="ambang-keterlambatan" class="block text-sm font-medium text-utama">
                Keterlambatan Maksimum
              </label>
              <p class="mt-0.5 text-xs text-redup">
                Unit dengan tingkat keterlambatan di atas ini — dari total kehadirannya sendiri —
                direkomendasikan untuk ditinjau kedisiplinan waktunya.
              </p>
              <div class="mt-3 flex items-center gap-4">
                <input
                  id="ambang-keterlambatan"
                  v-model.number="form.ambang_keterlambatan_maksimum"
                  type="range"
                  :min="batas.ambang_keterlambatan_min"
                  :max="batas.ambang_keterlambatan_maks"
                  class="h-2 flex-1 cursor-pointer appearance-none rounded-full bg-permukaan-2 accent-[var(--tema-aksen)]"
                />
                <span class="w-16 text-right font-display text-lg font-semibold tabular-nums text-utama">
                  {{ form.ambang_keterlambatan_maksimum }}%
                </span>
              </div>
              <div class="mt-1 flex justify-between text-xs text-redup">
                <span>{{ batas.ambang_keterlambatan_min }}%</span>
                <span>{{ batas.ambang_keterlambatan_maks }}%</span>
              </div>
              <p v-if="form.errors.ambang_keterlambatan_maksimum" class="mt-1.5 text-xs text-peringatan-teks">
                {{ form.errors.ambang_keterlambatan_maksimum }}
              </p>
            </div>
          </div>
        </section>
      </div>

      <aside class="space-y-4">
        <div class="panel p-5">
          <h2 class="font-display text-sm font-semibold text-utama">Simpan Perubahan</h2>
          <p class="mt-1 text-xs text-redup">
            Pengaturan ini berlaku global dan langsung dipakai kiosk pada sesi berikutnya.
          </p>

          <TombolProses
            class="mt-4 w-full"
            :proses="form.processing"
            :nonaktif="!adaMetodeAktif"
          >
            Simpan Setting
          </TombolProses>

          <p v-if="form.recentlySuccessful" class="mt-3 rounded-md bg-berhasil-lembut px-3 py-2 text-xs text-berhasil-teks">
            Setting Absen tersimpan.
          </p>
        </div>

        <div class="panel p-5 text-sm">
          <h2 class="font-display text-sm font-semibold text-utama">Catatan</h2>
          <ul class="mt-2 space-y-2 text-xs text-sekunder">
            <li>
              Verifikasi wajah hanya berjalan untuk pegawai yang foto referensinya sudah terdaftar di
              Kelola Pegawai.
            </li>
            <li>Menurunkan ambang mempermudah pencocokan, tetapi memperbesar peluang wajah keliru diterima.</li>
            <li>Setiap perubahan pada halaman ini tercatat pada audit trail.</li>
          </ul>
        </div>
      </aside>
    </form>

      <HariLibur
        :hari_libur="hari_libur"
        :unit_kerja_libur="unit_kerja_libur"
        :boleh_libur_nasional="boleh_libur_nasional"
      />
  </AdminLayout>
</template>
