const fs = require('fs')

/*
 * Rasio kontras WCAG 2.1 AA, dihitung dari tema.css yang sebenarnya.
 *
 * Nilainya dibaca dari berkasnya, bukan disalin ke sini: salinan konstanta
 * menua diam-diam, dan alat ukur yang menua diam-diam lebih buruk daripada
 * tidak ada alat ukur — ia melaporkan lulus untuk warna yang sudah berganti.
 */
const css = fs.readFileSync('resources/css/tema.css', 'utf8')
const terang = css.slice(css.indexOf(':root {'), css.indexOf('@media (prefers-color-scheme: dark)'))

/** Nilai token peran pada blok tema terang. */
function tok(nama) {
  const kunci = '--tema-' + nama + ':'
  const i = terang.indexOf(kunci)

  if (i < 0) return null

  return terang.slice(i + kunci.length, terang.indexOf(';', i)).trim()
}

const hex = (h) => {
  const b = h.replace('#', '')
  return [0, 2, 4].map((i) => parseInt(b.slice(i, i + 2), 16))
}

const lum = (h) => {
  const [r, g, b] = hex(h).map((v) => {
    const s = v / 255
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

const rasio = (a, b) => {
  const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m)
  return (x + 0.05) / (y + 0.05)
}

const P = tok('permukaan')
const K = tok('kertas')
const P2 = tok('permukaan-2')
const PH = tok('permukaan-hover')

const pasangan = [
  ['teks utama / kertas', tok('utama'), K],
  ['teks utama / permukaan', tok('utama'), P],
  ['sekunder / permukaan', tok('sekunder'), P],
  ['redup / kertas', tok('redup'), K],
  ['redup / permukaan', tok('redup'), P],
  ['redup / permukaan-2', tok('redup'), P2],
  ['redup / permukaan-hover', tok('redup'), PH],
  ['aksen-teks / aksen-lembut', tok('aksen-teks'), tok('aksen-lembut')],
  ['berhasil-teks / berhasil-lembut', tok('berhasil-teks'), tok('berhasil-lembut')],
  ['peringatan-teks / peringatan-lembut', tok('peringatan-teks'), tok('peringatan-lembut')],
  ['galat-teks / galat-lembut', tok('galat-teks'), tok('galat-lembut')],
  ['langit-teks / langit-lembut', tok('langit-teks'), tok('langit-lembut')],
  ['info-teks / info-lembut', tok('info-teks'), tok('info-lembut')],
  ['sidebar-teks / sidebar', tok('sidebar-teks'), tok('sidebar')],
  ['sidebar-redup / sidebar', tok('sidebar-redup'), tok('sidebar')],
]

/* Ujung paling terang gradasi tombol utama, dibaca dari aturannya sendiri. */
const iTombol = css.indexOf('.tombol-utama {', css.indexOf('@layer components', css.indexOf('.pelat-kepala')))
const iGrad = css.indexOf('linear-gradient(112deg, ', iTombol)
const ujung = css.slice(iGrad + 'linear-gradient(112deg, '.length, iGrad + 40).slice(0, 7)

pasangan.push(['putih / tombol utama (ujung terterang)', '#ffffff', ujung])

const iPita = css.indexOf('.pita-utama {')
const iPitaGrad = css.indexOf('linear-gradient(112deg, ', iPita)
const ujungPita = css.slice(iPitaGrad + 24, iPitaGrad + 31)

pasangan.push(['putih / pita utama (ujung terterang)', '#ffffff', ujungPita])

let gagal = 0

console.log('pasangan'.padEnd(42), 'rasio  AA-teks')
console.log('-'.repeat(60))

for (const [nama, depan, latar] of pasangan) {
  if (!depan || !latar || depan[0] !== '#' || latar[0] !== '#') {
    console.log(nama.padEnd(42), '  (bukan hex polos, dilewati)')
    continue
  }

  const r = rasio(depan, latar)

  if (r < 4.5) gagal++

  console.log(nama.padEnd(42), r.toFixed(2).padStart(5), r >= 4.5 ? ' LULUS' : ' GAGAL')
}

console.log('-'.repeat(60))
console.log(
  gagal === 0
    ? 'Semua pasangan teks memenuhi WCAG 2.1 AA (4,5:1).'
    : gagal + ' pasangan masih di bawah 4,5:1.',
)
