# Xitercsx Panel Website

Website PHP untuk katalog, order, cek pembayaran, riwayat sesi, dan permintaan detail order melalui API reseller.

## Persyaratan
- PHP 8.1+ (disarankan 8.2+)
- Ekstensi PHP standar: json, openssl
- HTTPS aktif
- API reseller yang mendukung endpoint di bawah

## Instalasi
1. Upload semua file dan folder ke document root website.
2. Pastikan folder `data/` bisa ditulis oleh user PHP (permission yang aman, misalnya owner webserver saja).
3. Edit `config.php`, isi `panel_api_url` dan `panel_api_key`; lebih baik isi key lewat environment `YANNPANEL_API_KEY`.
4. Pastikan domain memakai HTTPS.
5. Buka `index.php` dan tes daftar produk sebelum menerima transaksi.

## Endpoint API yang dipakai
- `GET /api/v1/products`
- `POST /api/v1/create-order` dengan `productId`, `username`, `password`, `email`
- `GET /api/v1/check-payment/{invoice}`
- `GET /api/v1/order-detail/{invoice}`

Website ini sengaja tidak mengimplementasikan endpoint renewal/server check karena potongan handler yang diberikan tidak memuat kontrak API untuk fitur tersebut. Jangan menebak endpoint API reseller.

## Penting sebelum produksi
- Riwayat pada starter ini terikat ke sesi browser, bukan akun pelanggan permanen. Pelanggan yang menghapus cookie/browser atau pindah perangkat tidak akan melihat order lama. Untuk produksi, tambahkan registrasi/login dan database SQL.
- `data/orders.json` menyimpan invoice, status, dan metadata order tanpa password panel. Pastikan folder data tidak dapat diakses publik.
- Cek pembayaran/detail memakai API reseller. Verifikasi kepemilikan invoice dari catatan lokal sebelum menghubungi endpoint.
- API key hanya berada di server. Jangan kirim ke HTML/JavaScript atau repositori publik.
- Uji dengan produk murah/test mode jika API reseller menyediakan sandbox. Jangan mengulang order bila API timeout setelah request terkirim; cek invoice/API terlebih dahulu.
- Jika server API tidak mengizinkan permintaan dari hosting, periksa firewall dan konfigurasi TLS.
- Ini starter website, bukan audit keamanan atau sistem produksi lengkap. Sebelum menerima pembayaran sungguhan, implementasikan login, rate limit, logging aman, backup, dan pengujian.
