import { getLocalTimeZone, today } from '@internationalized/date'

/**
 * Tanggal hari ini, ISO `YYYY-MM-DD`, menurut zona waktu LOKAL peramban.
 *
 * BUKAN `new Date().toISOString().slice(0, 10)` — itu memotong jam UTC,
 * yang sepanjang WIB masih di belakang tengah malam UTC (00.00–06.59 WIB)
 * memberi tanggal HARI SEBELUMNYA, meleset dari "hari ini" milik server
 * (`APP_TIMEZONE=Asia/Jakarta`) maupun milik penggunanya sendiri. Dipakai
 * `@internationalized/date` yang sama dengan `TanggalIsian.vue`, supaya
 * seluruh aplikasi punya satu definisi "hari ini".
 */
export function hariIniIso() {
  return today(getLocalTimeZone()).toString()
}
