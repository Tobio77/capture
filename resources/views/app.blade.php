<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'Capture') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|lexend:400,500,600,700" rel="stylesheet">

    {{--
        Tema dipasang sebelum CSS dimuat, bukan setelah Vue hidup.
        Tanpa ini, pengguna mode gelap melihat kilatan putih pada setiap
        perpindahan halaman — halaman sempat tergambar dengan token terang
        sebelum skripnya sempat berjalan.
    --}}
    <script>
        (function () {
            var mode = 'terang';

            try {
                var tersimpan = localStorage.getItem('capture.tema');

                if (tersimpan === 'terang' || tersimpan === 'gelap' || tersimpan === 'sistem') {
                    mode = tersimpan;
                }
            } catch (e) {
                // Penyimpanan lokal diblokir; bawaan Terang tetap dipakai.
            }

            /*
                "Sistem" berarti TANPA atribut, sehingga prefers-color-scheme
                di tema.css yang mengambil alih. Selain itu atributnya dipasang
                — termasuk untuk bawaan Terang, supaya perangkat yang OS-nya
                gelap tidak sempat menggambar halaman dalam mode gelap.
                Bawaannya harus sama dengan TEMA_BAWAAN di useTema.js.
            */
            if (mode !== 'sistem') {
                document.documentElement.setAttribute('data-tema', mode);
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="font-sans antialiased bg-kertas text-utama">
    @inertia
</body>
</html>
