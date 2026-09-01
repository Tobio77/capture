<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

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

  <div class="flex min-h-screen items-center justify-center bg-navy-700 px-4 py-12">
    <div class="w-full max-w-md">
      <div class="text-center">
        <h1 class="font-display text-3xl font-semibold text-white">Capture</h1>
        <p class="mt-1 text-sm text-navy-200">
          Sistem Informasi Absensi Kegiatan<br />
          Disnakertrans Provinsi Jawa Timur
        </p>
      </div>

      <div class="mt-8 rounded-lg bg-white p-8 shadow-lg">
        <h2 class="font-display text-lg font-semibold text-navy-700">Masuk Panel Admin</h2>
        <p class="mt-1 text-sm text-slate-500">
          Gunakan akun admin Anda. Perangkat absen memakai alur aktivasi terpisah.
        </p>

        <div
          v-if="flash.gagal"
          class="mt-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700"
        >
          {{ flash.gagal }}
        </div>
        <div
          v-if="flash.sukses"
          class="mt-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"
        >
          {{ flash.sukses }}
        </div>

        <form class="mt-6 space-y-5" @submit.prevent="kirim">
          <div>
            <label for="email" class="block text-sm font-medium text-slate-700">Alamat Surel</label>
            <input
              id="email"
              v-model="form.email"
              type="email"
              autocomplete="username"
              autofocus
              required
              class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-teal-600 focus:outline-none focus:ring-1 focus:ring-teal-600"
            />
            <p v-if="form.errors.email" class="mt-1.5 text-sm text-amber-700">{{ form.errors.email }}</p>
          </div>

          <div>
            <label for="password" class="block text-sm font-medium text-slate-700">Kata Sandi</label>
            <input
              id="password"
              v-model="form.password"
              type="password"
              autocomplete="current-password"
              required
              class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-teal-600 focus:outline-none focus:ring-1 focus:ring-teal-600"
            />
            <p v-if="form.errors.password" class="mt-1.5 text-sm text-amber-700">{{ form.errors.password }}</p>
          </div>

          <label class="flex items-center gap-2 text-sm text-slate-600">
            <input
              v-model="form.ingat_saya"
              type="checkbox"
              class="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-600"
            />
            Ingat saya pada perangkat ini
          </label>

          <button
            type="submit"
            :disabled="form.processing"
            class="w-full rounded-md bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 disabled:opacity-60"
          >
            {{ form.processing ? 'Memproses…' : 'Masuk' }}
          </button>
        </form>
      </div>

      <p class="mt-6 text-center text-xs text-navy-300">
        Kesulitan masuk? Hubungi Superadmin Capture.
      </p>
    </div>
  </div>
</template>
