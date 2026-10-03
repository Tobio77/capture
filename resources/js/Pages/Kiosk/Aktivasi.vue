<script setup>
import { computed } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import Ikon from '@/Components/Ikon.vue'

const props = defineProps({
  /*
   * FR-SET-06. Menentukan kode mana yang diminta layar ini:
   *   false (bawaan) → kode unit kerja, dan perangkatnya dikenali sendiri
   *   true           → kode aktivasi sekali pakai milik perangkat terdaftar
   */
  mode_pendaftaran: { type: Boolean, default: false },
  panjang_kode: { type: Number, default: 8 },
})


const form = useForm({ kode: '' })

const medan = computed(() => (props.mode_pendaftaran ? 'kode_aktivasi' : 'kode'))
const galat = computed(() => form.errors[medan.value])

// Tampilkan sebagai XXXX-XXXX; server menormalkan lagi sebelum dicocokkan.
const rapikan = (event) => {
  const bersih = event.target.value
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, '')
    .slice(0, props.panjang_kode)

  form.kode = bersih.length > 4 ? `${bersih.slice(0, 4)}-${bersih.slice(4)}` : bersih
}

/*
 * Dua alamat, dua nama medan — dan keduanya dikirim dari satu kolom isian.
 * Yang membedakan hanya mode pendaftaran; petugas di depan layar cukup
 * mengetikkan kode yang ada di tangannya.
 */
const kirim = () => {
  if (props.mode_pendaftaran) {
    form.transform((data) => ({ kode_aktivasi: data.kode })).post('/kiosk/aktivasi')
    return
  }

  form.transform((data) => data).post('/kiosk/aktivasi/unit')
}
</script>

<template>
  <Head title="Hubungkan Perangkat" />

  <div class="latar-pastel flex min-h-screen items-center justify-center bg-kertas px-4 py-12 text-utama">
    <div class="w-full max-w-lg">
      <div class="flex flex-col items-center text-center">
        <span class="ubin-merek h-12 w-12">
          <Ikon nama="perangkat" ukuran="h-6 w-6" />
        </span>
        <h1 class="mt-4 font-display text-2xl font-semibold">Capture</h1>
        <p class="mt-1.5 text-sm text-sekunder">Perangkat Titik Absen</p>
      </div>

      <div class="panel mt-8 p-7">
        <h2 class="font-display text-lg font-semibold text-utama">
          {{ mode_pendaftaran ? 'Aktivasi Perangkat' : 'Hubungkan Perangkat' }}
        </h2>
        <p class="mt-1 text-sm text-redup">
          <template v-if="mode_pendaftaran">
            Masukkan kode aktivasi yang diberikan admin untuk titik absen ini.
            Perangkat cukup diaktifkan satu kali.
          </template>
          <template v-else>
            Masukkan kode unit kerja tempat perangkat ini berada. Seluruh absen
            yang dilayaninya akan tercatat atas nama unit tersebut. Perangkat
            cukup dihubungkan satu kali.
          </template>
        </p>

        <form class="mt-6 space-y-5" @submit.prevent="kirim">
          <div>
            <label for="kode" class="block text-sm font-medium text-utama">
              {{ mode_pendaftaran ? 'Kode Aktivasi' : 'Kode Unit Kerja' }}
              <span class="ml-0.5 text-galat-teks" aria-hidden="true">*</span>
            </label>
            <input
              id="kode"
              :value="form.kode"
              type="text"
              inputmode="latin"
              autocomplete="off"
              autofocus
              required
              placeholder="XXXX-XXXX"
              class="kolom-isian mt-1 px-4 py-3.5 text-center font-display text-2xl uppercase tracking-[0.3em]"
              @input="rapikan"
            />
            <p v-if="galat" class="mt-1.5 text-sm text-peringatan-teks">
              {{ galat }}
            </p>
          </div>

          <button
            type="submit"
            :disabled="form.processing"
            class="tombol tombol-utama w-full py-3"
          >
            {{ form.processing ? 'Memproses…' : mode_pendaftaran ? 'Aktifkan Perangkat' : 'Hubungkan Perangkat' }}
          </button>
        </form>

        <p class="mt-6 border-t border-garis pt-4 text-xs text-redup">
          <template v-if="mode_pendaftaran">
            Kode aktivasi diterbitkan admin melalui menu Perangkat Absen dan berlaku 24 jam.
          </template>
          <template v-else>
            Kode unit kerja diterbitkan sekali dan tetap berlaku. Mintakan kepada admin
            dinas bila belum memilikinya.
          </template>
          Alamat IP perangkat ini tercatat otomatis dan muncul pada rekap absensi.
        </p>
      </div>
    </div>
  </div>
</template>
