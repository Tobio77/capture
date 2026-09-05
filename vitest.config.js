import { defineConfig } from 'vitest/config'
import { fileURLToPath, URL } from 'node:url'
import vue from '@vitejs/plugin-vue'

/**
 * Konfigurasi uji sisi klien.
 *
 * Terpisah dari `vite.config.js` dan sengaja tidak memuat plugin Laravel
 * maupun Tailwind: keduanya membaca manifest dan memindai kelas, dua hal yang
 * tidak ada gunanya bagi uji yang hanya merender komponen di memori.
 */
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
    },
  },
  test: {
    environment: 'jsdom',

    /*
     * Kolam 'forks' bawaan tidak dapat memulai worker di lingkungan Windows
     * ini (worker tidak pernah menjawab, lalu kehabisan waktu 60 detik).
     * Utas biasa lebih ringan dan cukup: uji ini merender komponen di memori,
     * tidak memerlukan isolasi proses.
     */
    pool: 'threads',
    include: ['tests/js/**/*.test.js'],
    setupFiles: ['tests/js/persiapan.js'],
    restoreMocks: true,
  },
})
