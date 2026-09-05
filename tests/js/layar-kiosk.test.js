import { describe, expect, test, beforeEach, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setelPropsBersama } from './inertia-palsu.js'
import prop from './prop-layar.json'

import Beranda from '@/Pages/Beranda.vue'
import Aktivasi from '@/Pages/Kiosk/Aktivasi.vue'
import KioskUtama from '@/Pages/Kiosk/Utama.vue'
import LayarAbsenUmum from '@/Pages/AbsenUmum/Layar.vue'

/**
 * Uji asap layar yang dihadapi pengguna titik absen.
 *
 * Setiap layar dirender dengan prop yang bentuknya mengikuti keluaran
 * controller, lalu diperiksa satu hal saja: ia tidak gagal, dan benar-benar
 * menggambar sesuatu.
 *
 * **Mengapa perlu, padahal uji fitur sudah banyak.** Uji Inertia di sisi PHP
 * memeriksa PROP yang dikirim, tidak pernah merender Vue-nya. Ketika layar
 * tap perangkat menyentuh `props.kiosk` — padahal di halaman itu `kiosk`
 * berasal dari `usePage()`, bukan dari prop halaman — seluruh 456 uji tetap
 * hijau sementara layarnya putih total di peramban. Cacat yang hanya muncul
 * saat perenderan memerlukan uji yang benar-benar merender.
 *
 * Sengaja tidak menguji tampilan. Menegaskan susunan atau kelas CSS di sini
 * akan membuat setiap perbaikan tata letak memerahkan uji tanpa ada yang
 * rusak; yang dijaga adalah layar-layar ini tetap dapat digambar.
 *
 * Galat konsol diperlakukan sebagai kegagalan. Vue menangkap galat pada
 * lifecycle hook dan hanya menuliskannya ke konsol — tanpa aturan ini,
 * komponen yang meledak di `onMounted` tetap lulus.
 */

let galat

beforeEach(() => {
  galat = []
  vi.spyOn(console, 'error').mockImplementation((...isi) => galat.push(isi.join(' ')))
  vi.spyOn(console, 'warn').mockImplementation((...isi) => galat.push(isi.join(' ')))
  setelPropsBersama()
})

afterEach(() => {
  expect(galat, 'layar tidak boleh menuliskan galat atau peringatan Vue').toEqual([])
})

/** Merender, memastikan ada isinya, lalu membongkarnya kembali. */
function render(komponen, props = {}) {
  const layar = mount(komponen, { props })

  expect(layar.html().length).toBeGreaterThan(200)

  return layar
}

describe('Halaman depan titik absen', () => {
  test('perangkat terdaftar yang belum ikut kegiatan', () => {
    const layar = render(Beranda, prop.beranda)

    expect(layar.text()).toContain('Absen Umum')
    expect(layar.text()).toContain('Absen Event')
  })

  test('perangkat belum diaktifkan', () => {
    render(Beranda, { ...prop.beranda, perangkat: null, event_aktif: [] })
  })

  test('perangkat ad-hoc menyebut asal-usulnya tanpa kode unit', () => {
    const layar = render(Beranda, {
      ...prop.beranda,
      perangkat: {
        nama_titik: prop.bersama.kiosk_ad_hoc.nama_titik,
        sumber: 'ad_hoc',
        unit_kerja: { nama: prop.bersama.kiosk_ad_hoc.unit_kerja.nama },
      },
    })

    expect(layar.text()).toContain('Ad-hoc')
    expect(layar.text()).not.toContain('BID-HIJS')
  })

  test('perangkat yang sudah melayani sebuah kegiatan', () => {
    render(Beranda, {
      ...prop.beranda,
      event_diikuti: { id: 12, nama: 'Apel Pagi Senin', jam_mulai: '07:30', toleransi_menit: 15 },
    })
  })

  test('admin yang sedang masuk melihat pintasan panel', () => {
    setelPropsBersama({ auth: { pengguna: prop.bersama.pengguna_superadmin } })

    const layar = render(Beranda, prop.beranda)

    expect(layar.text()).toContain('Panel Admin')
  })
})

describe('Layar aktivasi perangkat', () => {
  test('dengan Mode Terbuka', () => {
    render(Aktivasi, prop.aktivasi)
  })

  test('tanpa Mode Terbuka', () => {
    render(Aktivasi, { ...prop.aktivasi, mode_terbuka: false, unit_kerja: [] })
  })
})

describe('Layar tap pada perangkat absen', () => {
  /*
   * `kiosk` pada halaman ini datang dari prop BERSAMA, bukan prop halaman.
   * Perbedaan itulah yang pernah membuat layarnya putih total, dan itulah
   * sebabnya prop bersama di sini diisi sungguhan alih-alih dibiarkan null.
   */
  test('absen umum pada perangkat terdaftar', () => {
    setelPropsBersama({ kiosk: prop.bersama.kiosk_terdaftar })

    const layar = render(KioskUtama, prop.kiosk_utama)

    expect(layar.text()).toContain('UPT BLK Surabaya')
    expect(layar.text()).toContain('Ahmad Fauzi')
  })

  test('absen umum pada perangkat ad-hoc', () => {
    setelPropsBersama({ kiosk: prop.bersama.kiosk_ad_hoc })

    const layar = render(KioskUtama, prop.kiosk_utama)

    expect(layar.text()).toContain('Bidang Hubungan Industrial dan Jaminan Sosial')
  })

  test('absen kegiatan', () => {
    setelPropsBersama({ kiosk: prop.bersama.kiosk_terdaftar })

    const layar = render(KioskUtama, {
      ...prop.kiosk_utama,
      mode: 'event',
      status_jendela: null,
      event: { ...prop.kiosk_utama.event, nama: 'Apel Pagi Senin' },
    })

    expect(layar.text()).toContain('Apel Pagi Senin')
  })

  test('belum ada entry yang dibuka', () => {
    setelPropsBersama({ kiosk: prop.bersama.kiosk_terdaftar })

    render(KioskUtama, { ...prop.kiosk_utama, event: null, daftar_presensi: [] })
  })

  test('absen umum sedang dimatikan admin', () => {
    setelPropsBersama({ kiosk: prop.bersama.kiosk_terdaftar })

    render(KioskUtama, {
      ...prop.kiosk_utama,
      event: null,
      daftar_presensi: [],
      absen_umum_aktif: false,
    })
  })

  test('verifikasi wajah menyala', () => {
    setelPropsBersama({ kiosk: prop.bersama.kiosk_terdaftar })

    render(KioskUtama, {
      ...prop.kiosk_utama,
      metode: { manual: true, rfid: true, wajah: true },
      daftar_wajah_otomatis: false,
    })
  })

  test('hanya RFID yang dinyalakan admin', () => {
    setelPropsBersama({ kiosk: prop.bersama.kiosk_terdaftar })

    render(KioskUtama, {
      ...prop.kiosk_utama,
      metode: { manual: false, rfid: true, wajah: false },
    })
  })
})

describe('Layar absen umum di peramban admin', () => {
  test('sesi hari ini terbuka', () => {
    setelPropsBersama({ auth: { pengguna: prop.bersama.pengguna_superadmin } })

    const layar = render(LayarAbsenUmum, prop.absen_umum_layar)

    expect(layar.text()).toContain('UPT BLK Surabaya')
  })

  test('absen umum dimatikan admin', () => {
    setelPropsBersama({ auth: { pengguna: prop.bersama.pengguna_superadmin } })

    const layar = render(LayarAbsenUmum, {
      ...prop.absen_umum_layar,
      absen_umum_aktif: false,
      event: null,
      daftar_presensi: [],
      status_jendela: null,
    })

    expect(layar.text()).toContain('Absen umum sedang dimatikan')
  })
})
