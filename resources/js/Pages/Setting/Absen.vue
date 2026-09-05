<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Ikon from '@/Components/Ikon.vue'

/**
 * Setting Absen — pengaturan global sistem (FR-SET-01 s.d. FR-SET-06).
 */

const props = defineProps({
  setting: { type: Object, required: true },
  preset_kompresi: { type: Array, required: true },
  batas: { type: Object, required: true },
})

const form = useForm({ ...props.setting })

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
                  class="w-28 rounded-md border-garis font-display tabular-nums bayang focus:border-aksen focus:ring-aksen sm:text-sm"
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

          <label class="mt-4 flex cursor-pointer items-start gap-3">
            <input
              v-model="form.absen_umum_aktif"
              type="checkbox"
              class="mt-0.5 h-4 w-4 rounded border-garis text-aksen focus:ring-aksen"
            />
            <span>
              <span class="block text-sm font-medium text-utama">
                Nyalakan absen umum harian
              </span>
              <span class="mt-0.5 block text-xs text-redup">
                Bila dimatikan, perangkat absen hanya melayani event kegiatan dan menolak tap di
                luar itu.
              </span>
            </span>
          </label>

          <div class="mt-5">
            <label for="jam_masuk" class="block text-sm font-medium text-utama">
              Jam Masuk Harian
            </label>
            <p class="mt-0.5 text-xs text-redup">
              Batas tepat waktu bagi sesi absen umum, ditambah toleransi keterlambatan di atas.
            </p>
            <input
              id="jam_masuk"
              v-model="form.jam_masuk_umum"
              type="time"
              class="mt-2 w-32 rounded-md border-garis font-display tabular-nums bayang focus:border-aksen focus:ring-aksen sm:text-sm"
            />
            <p v-if="form.errors.jam_masuk_umum" class="mt-1.5 text-xs text-peringatan-teks">
              {{ form.errors.jam_masuk_umum }}
            </p>
          </div>
        </section>

        <!-- FR-SET-06 -->
        <section class="panel p-6">
          <h2 class="font-display text-sm font-semibold text-utama">Registrasi Perangkat Absen</h2>
          <p class="mt-1 text-xs text-redup">
            Secara bawaan, perangkat harus didaftarkan admin lebih dahulu dan menukarkan kode
            aktivasi sebelum dapat melayani tap.
          </p>

          <label class="mt-4 flex cursor-pointer items-start gap-3">
            <input
              v-model="form.wajib_kode_aktivasi"
              type="checkbox"
              class="mt-0.5 h-4 w-4 rounded border-garis text-aksen focus:ring-aksen"
            />
            <span>
              <span class="block text-sm font-medium text-utama">
                Wajib kode aktivasi perangkat
              </span>
              <span class="mt-0.5 block text-xs text-redup">
                Biarkan menyala pada operasi normal.
              </span>
            </span>
          </label>

          <div
            v-if="!form.wajib_kode_aktivasi"
            class="mt-4 rounded-lg border border-peringatan bg-peringatan-lembut p-4"
          >
            <p class="flex items-center gap-1.5 text-sm font-semibold text-peringatan-teks">
              <Ikon nama="peringatan" ukuran="h-4 w-4" /> Mode Terbuka
            </p>
            <p class="mt-1 text-xs text-peringatan-teks">
              Perangkat mana pun yang dapat menjangkau alamat aplikasi ini boleh masuk tanpa kode
              aktivasi. Ia dibuatkan entri sendiri bertanda <strong>Ad-hoc</strong>, alamat IP-nya
              tetap dicatat, dan absen yang dilayaninya tercatat pada unit kerja yang dipilih
              petugas di layar. Gunakan hanya untuk kebutuhan darurat, dan nonaktifkan kembali
              sesudahnya.
            </p>
          </div>
        </section>

        <!-- FR-SET-04 -->
        <section class="panel p-6">
          <h2 class="font-display text-sm font-semibold text-utama">Kompresi Foto Absen</h2>
          <p class="mt-1 text-xs text-redup">
            Foto absen disusutkan di kiosk sebelum dikirim, agar ruang penyimpanan server terkendali.
          </p>

          <div class="mt-4 grid gap-3 sm:grid-cols-3">
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
      </div>

      <aside class="space-y-4">
        <div class="panel p-5">
          <h2 class="font-display text-sm font-semibold text-utama">Simpan Perubahan</h2>
          <p class="mt-1 text-xs text-redup">
            Pengaturan ini berlaku global dan langsung dipakai kiosk pada sesi berikutnya.
          </p>

          <button
            type="submit"
            class="mt-4 w-full rounded-md bg-aksen px-4 py-2 text-sm font-semibold text-white bayang transition hover:bg-aksen-kuat disabled:opacity-60"
            :disabled="form.processing || !adaMetodeAktif"
          >
            {{ form.processing ? 'Menyimpan…' : 'Simpan Setting' }}
          </button>

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
  </AdminLayout>
</template>
