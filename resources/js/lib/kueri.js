/**
 * Bangun query string dari objek yang boleh berisi larik (mis. `kolom`),
 * bentuk yang tidak ditangani `URLSearchParams` bawaan.
 *
 * `new URLSearchParams({ kolom: ['a', 'b'] })` menghasilkan `kolom=a%2Cb` —
 * satu nilai gabungan koma — sementara Laravel mengharapkan `kolom[]=a&kolom[]=b`
 * untuk membacanya sebagai larik lewat `$request->input('kolom')`. Dipakai
 * layar mana pun yang mengirim penyaring lewat GET (unduhan CSV/Excel dengan
 * checklist kolom), bukan cuma satu halaman.
 *
 * Nilai `''`/`null`/`undefined` dilewati, sama seperti komputed `kueri` yang
 * sudah ada di tiap halaman — parameter kosong tidak perlu ikut terkirim.
 */
export function keQueryString(param) {
  const bagian = new URLSearchParams()

  for (const [kunci, nilai] of Object.entries(param)) {
    if (nilai === '' || nilai === null || nilai === undefined) continue

    if (Array.isArray(nilai)) {
      nilai.forEach((satu) => bagian.append(`${kunci}[]`, satu))
      continue
    }

    bagian.append(kunci, nilai)
  }

  return bagian.toString()
}
