<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps({
  api_url: { type: String, required: true },
  token_terisi: { type: Boolean, required: true },
  token_dari_env: { type: Boolean, required: true },
  status_sinkron: { type: Object, required: true },
})

const tampilkanToken = ref(false)
const sedangMenguji = ref(false)
const hasilUji = ref(null)

const form = useForm({
  api_url: props.api_url,
  api_token: '',
})

const simpan = () => {
  form.post('/admin/setting/worka', {
    preserveScroll: true,
    onSuccess: () => form.reset('api_token'),
  })
}

const uji = async () => {
  sedangMenguji.value = true
  hasilUji.value = null

  try {
    const jawaban = await fetch('/admin/setting/worka/test', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(
          document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
        ),
      },
    })
    hasilUji.value = await jawaban.json()
  } catch (galat) {
    hasilUji.value = { sukses: false, pesan: 'Permintaan uji koneksi gagal dikirim.' }
  } finally {
    sedangMenguji.value = false
  }
}

const waktuWorka = (iso) =>
  iso ? new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '—'
</script>

<template>
  <AdminLayout
    judul="Integrasi WORKA"
    deskripsi="Alamat dan token API sistem kepegawaian WORKA, sumber data pegawai dan unit kerja SI-ABSEN."
  >
    <div class="grid gap-6 lg:grid-cols-3">
      <div class="lg:col-span-2">
        <form class="rounded-lg border border-garis bg-permukaan p-6 bayang" @submit.prevent="simpan">
          <div class="space-y-5">
            <div>
              <label for="api_url" class="block text-sm font-medium text-utama">Alamat API WORKA</label>
              <input
                id="api_url"
                v-model="form.api_url"
                type="url"
                required
                placeholder="http://worka.test"
                class="mt-1 block w-full rounded-md border border-garis px-3 py-2 text-sm bayang focus:border-aksen focus:outline-none focus:ring-1 focus:ring-aksen"
              />
              <p v-if="form.errors.api_url" class="mt-1.5 text-sm text-peringatan-teks">{{ form.errors.api_url }}</p>
              <p v-else class="mt-1.5 text-xs text-redup">
                Tanpa garis miring di akhir. SI-ABSEN menambahkan sendiri jalur /api/v1/absen.
              </p>
            </div>

            <div>
              <label for="api_token" class="block text-sm font-medium text-utama">Token API WORKA</label>
              <div class="mt-1 flex gap-2">
                <input
                  id="api_token"
                  v-model="form.api_token"
                  :type="tampilkanToken ? 'text' : 'password'"
                  autocomplete="off"
                  :placeholder="token_terisi ? '•••••••• (biarkan kosong bila tidak diubah)' : 'Tempel token dari WORKA'"
                  class="block w-full rounded-md border border-garis px-3 py-2 font-mono text-sm bayang focus:border-aksen focus:outline-none focus:ring-1 focus:ring-aksen"
                />
                <button
                  type="button"
                  class="shrink-0 rounded-md border border-garis px-3 py-2 text-sm font-medium text-utama transition hover:bg-permukaan-hover"
                  @click="tampilkanToken = !tampilkanToken"
                >
                  {{ tampilkanToken ? 'Sembunyikan' : 'Tampilkan' }}
                </button>
              </div>
              <p v-if="form.errors.api_token" class="mt-1.5 text-sm text-peringatan-teks">{{ form.errors.api_token }}</p>
              <p v-else class="mt-1.5 text-xs text-redup">
                Token disimpan terenkripsi di basis data dan tidak pernah dikirim kembali ke layar ini.
              </p>
            </div>

            <div class="rounded-md border border-garis bg-permukaan-2 px-4 py-3 text-xs text-sekunder">
              <p class="font-medium text-utama">Ability yang dibutuhkan pada token WORKA</p>
              <p class="mt-1 font-mono">
                absen:sync-pegawai · absen:read-pegawai · absen:read-unit · absen:read-foto
              </p>
              <p class="mt-2">
                Token diterbitkan di panel WORKA melalui menu Integrasi → API Token.
              </p>
            </div>
          </div>

          <div class="mt-6 flex flex-wrap gap-3 border-t border-garis pt-5">
            <button
              type="submit"
              :disabled="form.processing"
              class="rounded-md bg-aksen px-4 py-2 text-sm font-semibold text-white bayang transition hover:bg-aksen-kuat disabled:opacity-60"
            >
              {{ form.processing ? 'Menyimpan…' : 'Simpan' }}
            </button>
            <button
              type="button"
              :disabled="sedangMenguji"
              class="rounded-md border border-garis px-4 py-2 text-sm font-medium text-utama transition hover:bg-permukaan-hover disabled:opacity-60"
              @click="uji"
            >
              {{ sedangMenguji ? 'Menguji…' : 'Uji Koneksi' }}
            </button>
          </div>

          <div
            v-if="hasilUji"
            class="mt-5 rounded-md border px-4 py-3 text-sm"
            :class="hasilUji.sukses
              ? 'border-berhasil bg-berhasil-lembut text-berhasil-teks'
              : 'border-peringatan bg-peringatan-lembut text-peringatan-teks'"
          >
            <p class="font-medium">
              {{ hasilUji.sukses ? 'Terhubung' : 'Gagal terhubung' }} — {{ hasilUji.pesan }}
            </p>
            <ul v-if="hasilUji.sukses" class="mt-2 space-y-0.5 text-xs">
              <li>WORKA menjawab dalam {{ hasilUji.durasi_ms }} ms</li>
              <li>Total pegawai aktif tersedia: {{ hasilUji.total_pegawai_aktif }}</li>
              <li>Waktu server WORKA: {{ waktuWorka(hasilUji.server_time) }}</li>
            </ul>
            <p v-else-if="hasilUji.kode" class="mt-1 text-xs">
              Kode galat: {{ hasilUji.kode }}<span v-if="hasilUji.http_status"> (HTTP {{ hasilUji.http_status }})</span>
            </p>
          </div>
        </form>
      </div>

      <aside class="space-y-4">
        <div class="rounded-lg border border-garis bg-permukaan p-5 bayang">
          <h2 class="font-display text-sm font-semibold text-utama">Status Saat Ini</h2>
          <dl class="mt-3 space-y-2 text-sm">
            <div>
              <dt class="text-redup">Token</dt>
              <dd class="font-medium" :class="token_terisi ? 'text-berhasil-teks' : 'text-peringatan-teks'">
                {{ token_terisi ? 'Sudah terisi' : 'Belum diisi' }}
                <span v-if="token_dari_env" class="block text-xs font-normal text-redup">
                  Bersumber dari .env, belum disimpan di basis data.
                </span>
              </dd>
            </div>
            <div>
              <dt class="text-redup">Alamat API aktif</dt>
              <dd class="break-all font-mono text-xs text-utama">{{ status_sinkron.api_url }}</dd>
            </div>
            <div>
              <dt class="text-redup">Pegawai aktif tersimpan</dt>
              <dd class="font-display font-medium tabular-nums text-utama">
                {{ status_sinkron.total_pegawai_lokal }}
              </dd>
            </div>
          </dl>
        </div>

        <div class="rounded-lg border border-garis bg-permukaan p-5 text-sm bayang">
          <h2 class="font-display text-sm font-semibold text-utama">Sinkronisasi Terjadwal</h2>
          <p class="mt-2 text-sekunder">
            Sinkronisasi otomatis berjalan setiap hari pukul 02.00. Sinkronisasi manual
            tersedia pada halaman Kelola Pegawai.
          </p>
          <p class="mt-2 text-xs text-redup">
            Galat integrasi tercatat di <span class="font-mono">storage/logs/worka-api.log</span>.
          </p>
        </div>
      </aside>
    </div>
  </AdminLayout>
</template>
