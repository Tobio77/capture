import { afterEach, describe, expect, test } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'

import Modal from '@/Components/Modal.vue'
import TanggalIsian from '@/Components/UI/TanggalIsian.vue'

/**
 * Uji asap komponen struktural yang mesinnya berpindah ke Reka UI.
 *
 * Alasannya sama dengan yang tertulis pada `layar-kiosk.test.js`: uji PHP
 * memeriksa prop, tidak pernah merender Vue-nya. Ketika Modal berpindah dari
 * `Teleport` buatan sendiri ke `DialogPortal`, dan pemilih tanggal berpindah
 * dari `<input type="date">` ke kalender Reka UI, yang bisa patah adalah hal
 * yang hanya muncul saat dirender — impor yang salah, prop yang tidak
 * diteruskan, id yang ditimpa pustaka.
 *
 * Sengaja tidak menguji tampilan, hanya kontraknya: apa yang terbaca pengguna
 * dan apa yang dikirim ke server.
 */

/**
 * Pasang dialog dan tunggu sampai ia benar-benar tergambar.
 *
 * Dua hal yang membuat pemeriksaan langsung sesudah `mount` selalu gagal:
 * isinya diteleportasi ke <body> — jadi tidak pernah ada di dalam wrapper —
 * dan Reka UI baru menyalakan Presence-nya pada `onMounted`, satu tick
 * kemudian. Karena itu yang diperiksa adalah `document.body`, bukan wrapper.
 */
const pasangDialog = async (props, slots) => {
  const bungkus = mount(Modal, { props, slots, attachTo: document.body })
  await nextTick()
  await nextTick()

  return bungkus
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('Modal', () => {
  test('menggambar judul dan isinya saat terbuka', async () => {
    await pasangDialog(
      { terbuka: true, judul: 'Tambah Event' },
      { default: '<p>Isi formulir</p>', aksi: '<button>Simpan</button>' },
    )

    expect(document.body.textContent).toContain('Tambah Event')
    expect(document.body.textContent).toContain('Isi formulir')
    expect(document.body.textContent).toContain('Simpan')
  })

  test('tidak menggambar apa pun saat tertutup', async () => {
    await pasangDialog({ terbuka: false, judul: 'Tambah Event' }, {
      default: '<p>Isi formulir</p>',
    })

    expect(document.body.textContent).not.toContain('Isi formulir')
  })

  test('memakai judul sebagai keterangan tersembunyi bila tidak disebut', async () => {
    await pasangDialog({ terbuka: true, judul: 'Hapus Perangkat' })

    // Reka UI menuntut setiap dialog punya keterangan; tanpa itu dialognya
    // kehilangan aria-describedby dan konsol dipenuhi peringatan.
    const tersembunyi = document.body.querySelector('.sr-only')
    const dialog = document.body.querySelector('[role="dialog"]')

    expect(tersembunyi?.textContent).toBe('Hapus Perangkat')

    // Bukan sekadar ada: keterangannya harus benar-benar TERTAUT ke dialognya.
    expect(dialog?.getAttribute('aria-describedby')).toBe(tersembunyi?.id)
  })

  test('kaki hanya muncul bila ada slot aksi', async () => {
    await pasangDialog({ terbuka: true, judul: 'Detail Event' })

    expect(document.body.querySelectorAll('.border-t')).toHaveLength(0)

    document.body.innerHTML = ''

    await pasangDialog({ terbuka: true, judul: 'Detail Event' }, {
      aksi: '<button>Tutup</button>',
    })

    expect(document.body.querySelectorAll('.border-t')).toHaveLength(1)
  })

  test('tombol tutup mengabarkan lewat emit, bukan mengubah prop sendiri', async () => {
    const bungkus = await pasangDialog({ terbuka: true, judul: 'Ubah Pengguna' })

    await document.body.querySelector('[aria-label="Tutup"]').click()
    await nextTick()

    expect(bungkus.emitted('tutup')).toBeTruthy()
  })
})

describe('TanggalIsian', () => {
  test('menampilkan penuntun saat nilainya kosong', () => {
    const bungkus = mount(TanggalIsian, { props: { modelValue: '' } })

    expect(bungkus.text()).toContain('Pilih tanggal')
  })

  test('menulis tanggal ISO dalam bentuk panjang berbahasa Indonesia', () => {
    const bungkus = mount(TanggalIsian, { props: { modelValue: '2026-09-09' } })

    expect(bungkus.text()).toContain('9 September 2026')
  })

  test('nilai setengah jadi tidak mematikan formulir', () => {
    // `2026-9-1` tanpa nol di depan membuat parseDate melempar; yang harus
    // terjadi adalah kembali ke penuntun, bukan seluruh halaman gagal render.
    const bungkus = mount(TanggalIsian, { props: { modelValue: '2026-9-1' } })

    expect(bungkus.text()).toContain('Pilih tanggal')
  })

  test('id bertahan meski Reka UI memasang id-nya sendiri', () => {
    // Ini yang menjaga `<label for="libur-tanggal">` di halaman tetap
    // menunjuk ke sesuatu. Tanpa `as-child`, Reka UI menimpanya.
    const bungkus = mount(TanggalIsian, {
      props: { modelValue: '', id: 'libur-tanggal' },
    })

    expect(bungkus.find('#libur-tanggal').exists()).toBe(true)
  })

  test('menandai isian bermasalah untuk pembaca layar', () => {
    const bungkus = mount(TanggalIsian, {
      props: { modelValue: '', bermasalah: true },
    })

    expect(bungkus.find('button').attributes('aria-invalid')).toBe('true')
  })
})
