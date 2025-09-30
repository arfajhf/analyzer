<?php

// Tampilkan semua error
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Mencoba memuat autoloader Composer...</h1>";

// Memuat file autoloader Composer dengan path absolut
// __DIR__ adalah path ke folder saat ini (C:\project\kalkulator-roi)
require __DIR__ . '/vendor/autoload.php';

echo "<h2>Autoloader berhasil dimuat!</h2>";
echo "<p>Sekarang mencoba menggunakan class 'CountryDataProvider'...</p>";

// Gunakan class dari library
use Umpirsky\Country\CountryDataProvider;

// Coba buat objek dari class tersebut
$dataProvider = new CountryDataProvider();

echo "<h2>SUKSES! Class 'CountryDataProvider' berhasil ditemukan dan digunakan.</h2>";
echo "<p>Setup Composer dan library kamu sudah benar.</p>";

// Tampilkan bukti bahwa objek berhasil dibuat
echo "<pre>";
var_dump($dataProvider);
echo "</pre>";

?>  