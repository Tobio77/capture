<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

/**
 * Setting Absen — pengaturan global sistem (FR-SET-01 s.d. FR-SET-04).
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
        <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
          <h2 class="font-display text-sm font-semibold text-navy-700">Metode Absensi Aktif</h2>
          <p class="mt-1 text-xs text-slate-500">Metode yang dimatikan tidak akan muncul pada layar kiosk.</p>

          <div class="mt-4 space-y-3">
            <label
              v-for="item in metode"
              :key="item.kunci"
              class="flex cursor-pointer items-start gap-3 rounded-md border border-slate-200 px-4 py-3 transition hover:bg-slate-50"
            >
              <input v-model="form[item.kunci]" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
              <span>
                <span class="block text-sm font-medium text-slate-700">{{ item.judul }}</span>
                <span class="mt-0.5 block text-xs text-slate-500">{{ item.keterangan }}</span>
              </span>
            </label>
          </div>

          <p v-if="!adaMetodeAktif" class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Minimal satu metode absen harus aktif — tanpa itu tidak ada cara mengabsen sama sekali.
          </p>
          <p v-if="form.errors.metode_manual_aktif" class="mt-2 text-xs text-amber-700">
            {{ form.errors.metode_manual_aktif }}
          </p>
        </section>

        <!-- FR-SET-02 & FR-SET-03 -->
        <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
          <div class="space-y-6">
            <div>
              <label for="toleransi" class="block text-sm font-medium text-slate-700">
                Toleransi Keterlambatan Default
              </label>
              <p class="mt-0.5 text-xs text-slate-500">
                Nilai awal untuk event baru; masih dapat diubah per event saat pembuatannya.
              </p>
              <div class="mt-2 flex items-center gap-2">
                <input
                  id="toleransi"
                  v-model.number="form.toleransi_default_menit"
                  type="number"
                  min="0"
                  :max="batas.toleransi_maks"
                  class="w-28 rounded-md border-slate-300 font-display tabular-nums shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
                />
                <span class="text-sm text-slate-600">menit</span>
              </div>
              <p v-if="form.errors.toleransi_default_menit" class="mt-1.5 text-xs text-amber-700">
                {{ form.errors.toleransi_default_menit }}
              </p>
            </div>

            <div>
              <label for="ambang" class="block text-sm font-medium text-slate-700">Ambang Kecocokan Wajah</label>
              <p class="mt-0.5 text-xs text-slate-500">
                Semakin tinggi, semakin ketat pencocokan — dan semakin sering wajah sah ikut tertolak.
              </p>
              <div class="mt-3 flex items-center gap-4">
                <input
                  id="ambang"
                  v-model.number="form.ambang_kecocokan_wajah"
                  type="range"
                  :min="batas.ambang_min"
                  :max="batas.ambang_maks"
                  class="h-2 flex-1 cursor-pointer appearance-none rounded-full bg-slate-200 accent-teal-600"
                />
                <span class="w-16 text-right font-display text-lg font-semibold tabular-nums text-navy-700">
                  {{ form.ambang_kecocokan_wajah }}%
                </span>
              </div>
              <div class="mt-1 flex justify-between text-xs text-slate-400">
                <span>{{ batas.ambang_min }}% — longgar</span>
                <span>{{ batas.ambang_maks }}% — ketat</span>
              </div>
              <p v-if="form.errors.ambang_kecocokan_wajah" class="mt-1.5 text-xs text-amber-700">
                {{ form.errors.ambang_kecocokan_wajah }}
              </p>
            </div>
          </div>
        </section>

        <!-- Absen umum: absensi harian tanpa event kegiatan -->
        <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
          <h2 class="font-display text-sm font-semibold text-navy-700">Absen Umum Harian</h2>
          <p class="mt-1 text-xs text-slate-500">
            Sesi absen harian yang dibuka sistem sendiri ketika tidak ada event kegiatan yang
            berjalan, sehingga pegawai tetap dapat mencatat kehadiran rutinnya. Kegiatan selalu
            didahulukan bila keduanya berlaku bersamaan.
          </p>

          <label class="mt-4 flex cursor-pointer items-start gap-3">
            <input
              v-model="form.absen_umum_aktif"
              type="checkbox"
              class="mt-0.5 h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
            />
            <span>
              <span class="block text-sm font-medium text-slate-700">
                Nyalakan absen umum harian
              </span>
              <span class="mt-0.5 block text-xs text-slate-500">
                Bila dimatikan, perangkat absen hanya melayani event kegiatan dan menolak tap di
                luar itu.
              </span>
            </span>
          </label>

          <div class="mt-5">
            <label for="jam_masuk" class="block text-sm font-medium text-slate-700">
              Jam Masuk Harian
            </label>
            <p class="mt-0.5 text-xs text-slate-500">
              Batas tepat waktu bagi sesi absen umum, ditambah toleransi keterlambatan di atas.
            </p>
            <input
              id="jam_masuk"
              v-model="form.jam_masuk_umum"
              type="time"
              class="mt-2 w-32 rounded-md border-slate-300 font-display tabular-nums shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
            />
            <p v-if="form.errors.jam_masuk_umum" class="mt-1.5 text-xs text-amber-700">
              {{ form.errors.jam_masuk_umum }}
            </p>
          </div>
        </section>

        <!-- FR-SET-04 -->
        <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
          <h2 class="font-display text-sm font-semibold text-navy-700">Kompresi Foto Absen</h2>
          <p class="mt-1 text-xs text-slate-500">
            Foto absen disusutkan di kiosk sebelum dikirim, agar ruang penyimpanan server terkendali.
          </p>

          <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <label
              v-for="preset in preset_kompresi"
              :key="preset.nilai"
              class="cursor-pointer rounded-md border px-4 py-3 transition"
              :class="form.kompresi_foto === preset.nilai
                ? 'border-teal-600 bg-teal-50 ring-1 ring-teal-600'
                : 'border-slate-200 hover:bg-slate-50'"
            >
              <input v-model="form.kompresi_foto" type="radio" :value="preset.nilai" class="sr-only" />
              <span class="block text-sm font-medium text-slate-700">{{ preset.label }}</span>
              <span class="mt-1 block font-display text-xs tabular-nums text-slate-500">
                {{ preset.dimensi_maks }} px · kualitas {{ preset.kualitas }}
              </span>
              <span class="mt-1 block text-xs text-slate-500">{{ preset.estimasi }}</span>
            </label>
          </div>

          <p v-if="presetTerpilih" class="mt-3 text-xs text-slate-500">{{ presetTerpilih.keterangan }}</p>
          <p v-if="form.errors.kompresi_foto" class="mt-2 text-xs text-amber-700">{{ form.errors.kompresi_foto }}</p>
        </section>
      </div>

      <aside class="space-y-4">
        <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
          <h2 class="font-display text-sm font-semibold text-navy-700">Simpan Perubahan</h2>
          <p class="mt-1 text-xs text-slate-500">
            Pengaturan ini berlaku global dan langsung dipakai kiosk pada sesi berikutnya.
          </p>

          <button
            type="submit"
            class="mt-4 w-full rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-60"
            :disabled="form.processing || !adaMetodeAktif"
          >
            {{ form.processing ? 'Menyimpan…' : 'Simpan Setting' }}
          </button>

          <p v-if="form.recentlySuccessful" class="mt-3 rounded-md bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
            Setting Absen tersimpan.
          </p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5 text-sm shadow-sm">
          <h2 class="font-display text-sm font-semibold text-navy-700">Catatan</h2>
          <ul class="mt-2 space-y-2 text-xs text-slate-600">
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
