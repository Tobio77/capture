const puppeteer = require('puppeteer-core')

/**
 * Penjaga warisan tinta.
 *
 * Setiap permukaan TERANG di aplikasi ini harus tetap bertinta gelap, di mana
 * pun ia diletakkan. Yang dijaga adalah kelas cacat, bukan satu kejadian:
 * begitu sebuah permukaan terang diletakkan di dalam bidang gelap — pelat navy
 * halaman depan, pelat kepala Panel Admin — ia mewarisi warna teks terang
 * milik bidang itu dan isinya nyaris lenyap.
 *
 * Sudah terjadi sekali (S37): kartu jam kehilangan angka jamnya begitu
 * dipindahkan ke dalam pelat, dan tidak ada satu pun uji yang menangkapnya
 * karena uji Inertia memeriksa prop dan uji asap hanya memeriksa "tidak
 * gagal". Yang menangkapnya adalah mata, pada percobaan kedua.
 *
 * Alat ini membaca warna SUNGGUHAN dari halaman yang sudah dirender, bukan
 * dari berkas CSS — satu-satunya cara memeriksa pewarisan.
 *
 *   node tools/periksa-tinta.cjs
 *
 * Butuh Chrome terpasang dan situs berjalan di https://capture.test.
 */

const CHROME =
  process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe'

const AKUN = {
  email: process.env.UJI_EMAIL ?? 'tangkap.layar@capture.test',
  sandi: process.env.UJI_SANDI ?? 'tangkap-layar-sementara',
}

/** Permukaan terang yang tintanya wajib gelap. */
const PERMUKAAN = ['.panel', '.kartu-jam', '.kartu-angkat']

/** Halaman publik, lalu halaman yang perlu sesi admin. */
const PUBLIK = ['/']

const ADMIN = [
  '/admin/dashboard',
  '/admin/kelola-absen/event',
  '/admin/kelola-absen/rekap',
  '/admin/pegawai',
  '/admin/perangkat',
  '/admin/laporan',
]

const tidur = (n) => new Promise((r) => setTimeout(r, n))

/** Luminansi relatif dari "rgb(r, g, b)". */
function terang(warna) {
  const [r, g, b] = warna.match(/\d+/g).slice(0, 3).map(Number)
  const k = [r, g, b].map((v) => {
    const s = v / 255
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  })

  return 0.2126 * k[0] + 0.7152 * k[1] + 0.0722 * k[2]
}

async function periksa(halaman, alamat) {
  await halaman.goto('https://capture.test' + alamat, { waitUntil: 'networkidle2' })
  await tidur(900)

  return halaman.evaluate((pilih) => {
    const hasil = []

    for (const p of pilih) {
      for (const e of document.querySelectorAll(p)) {
        const gaya = getComputedStyle(e)

        hasil.push({
          pilih: p,
          tinta: gaya.color,
          latar: gaya.backgroundColor,
          cuplikan: e.textContent.trim().replace(/\s+/g, ' ').slice(0, 34),
        })
      }
    }

    return hasil
  }, pilih)
}

let pilih = PERMUKAAN

;(async () => {
  const peramban = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    ignoreHTTPSErrors: true,
    args: ['--ignore-certificate-errors', '--hide-scrollbars'],
  })

  const halaman = await peramban.newPage()
  await halaman.setViewport({ width: 1366, height: 900 })

  const cacat = []
  let diperiksa = 0

  const nilai = (alamat, baris) => {
    for (const b of baris) {
      diperiksa++

      /*
       * Ambang 0,45: tinta yang lebih terang dari itu di atas permukaan terang
       * berarti ia mewarisi warna milik bidang gelap di sekelilingnya. Tinta
       * sah aplikasi ini (#0f2a43, #445f76, #556978) semuanya jauh di bawah.
       */
      if (terang(b.tinta) > 0.45) {
        cacat.push({ alamat, ...b })
      }
    }
  }

  for (const alamat of PUBLIK) nilai(alamat, await periksa(halaman, alamat))

  // Masuk sebagai admin, lengkap dengan jawaban hitungannya.
  await halaman.goto('https://capture.test/masuk', { waitUntil: 'networkidle2' })
  await halaman.type('#email', AKUN.email)
  await halaman.type('#password', AKUN.sandi)
  await halaman.evaluate(() => {
    const kolom = document.querySelector('#jawaban-captcha')
    const soal = document.querySelector('[aria-label^="Berapa"]').getAttribute('aria-label')
    const bagian = soal.match(/(\d+)\s*([+\u2212-])\s*(\d+)/)

    kolom.value = String(
      bagian[2] === '+' ? +bagian[1] + +bagian[3] : +bagian[1] - +bagian[3],
    )
    kolom.dispatchEvent(new Event('input', { bubbles: true }))
  })
  await halaman.click('button[type="submit"]')
  await halaman.waitForFunction(() => location.pathname.startsWith('/admin'), { timeout: 20000 })

  for (const alamat of ADMIN) nilai(alamat, await periksa(halaman, alamat))

  await peramban.close()

  console.log(`Permukaan diperiksa: ${diperiksa}`)

  if (cacat.length === 0) {
    console.log('Tidak ada permukaan terang yang mewarisi tinta terang.')

    return
  }

  console.log(`\n${cacat.length} permukaan mewarisi tinta yang salah:\n`)

  for (const c of cacat) {
    console.log(`  ${c.alamat}  ${c.pilih}  tinta=${c.tinta}  "${c.cuplikan}"`)
  }

  process.exitCode = 1
})().catch((galat) => {
  console.error('GAGAL:', galat.message)
  process.exitCode = 1
})
