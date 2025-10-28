<?php
// Mulai session
session_start();
// Paksa PHP untuk menampilkan error agar mudah di-debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Wajib ada setelah install Composer
require 'vendor/autoload.php';

// Gunakan class dari library
// Tidak perlu 'use' untuk umpirsky karena akses langsung
use PhpOffice\PhpSpreadsheet\IOFactory;

// --- FUNGSI NORMALISASI OTOMATIS ---
function normalizeCountryName($countryName) {
    static $englishCountries = null, $indonesianLookup = null, $cache = [];
    if ($englishCountries === null) {
        // Cek jika file library ada
        if (file_exists(__DIR__ . '/vendor/umpirsky/country-list/data/en/country.php')) {
            $englishCountries = require __DIR__ . '/vendor/umpirsky/country-list/data/en/country.php';
            $indonesianCountries = require __DIR__ . '/vendor/umpirsky/country-list/data/id/country.php';
            $indonesianLookup = array_flip($indonesianCountries);
        } else {
            // Fallback jika library tidak ditemukan
            trigger_error("Data library umpirsky/country-list tidak ditemukan.", E_USER_WARNING);
            return $countryName;
        }
    }
    $name = trim($countryName);
    $upperName = strtoupper($name);
    if (isset($cache[$name])) { return $cache[$name]; }
    if (isset($englishCountries[$upperName])) { $cache[$name] = $englishCountries[$upperName]; return $cache[$name]; }
    if (isset($indonesianLookup[$name])) { $countryCode = $indonesianLookup[$name]; $cache[$name] = $englishCountries[$countryCode] ?? $name; return $cache[$name]; }
    $countryCode = array_search($name, $englishCountries);
    if ($countryCode !== false) { $cache[$name] = $name; return $name; }
    $bestMatch = '';
    $shortestDistance = -1;
    foreach ($englishCountries as $standardName) {
        $distance = levenshtein(strtolower($name), strtolower($standardName));
        if ($shortestDistance < 0 || $distance < $shortestDistance) { $shortestDistance = $distance; $bestMatch = $standardName; }
    }
    if ($shortestDistance >= 0 && $shortestDistance <= 3) { $cache[$name] = $bestMatch; return $bestMatch; } // Tambah cek >= 0
    $cache[$name] = $name;
    return $name;
}

// --- FUNGSI PEMBACA FILE ---
function readDataFile($filePath, &$header) {
    try {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $data = []; $headerFound = false; $headerRowIndex = -1;

        foreach ($worksheet->getRowIterator() as $rowIndex => $row) {
            $cellIterator = $row->getCellIterator(); $cellIterator->setIterateOnlyExistingCells(FALSE);
            $rowData = [];
            foreach ($cellIterator as $cell) { $rowData[] = $cell->getValue(); }

            // Trim semua nilai di rowData
             $rowData = array_map('trim', $rowData);

            if (!$headerFound) {
                // Cari header berdasarkan kata kunci, abaikan case
                 $foundCountry = false;
                 foreach($rowData as $cellValue) {
                    if (strcasecmp($cellValue, 'Country') === 0 || strcasecmp($cellValue, 'Negara') === 0) {
                         $foundCountry = true;
                         break;
                    }
                 }

                if ($foundCountry) {
                    $header = $rowData;
                    $headerFound = true;
                    $headerRowIndex = $rowIndex;
                    continue; // Langsung ke baris berikutnya setelah header ditemukan
                }
            }

            // Hanya proses baris setelah header ditemukan
            if ($headerFound && $rowIndex > $headerRowIndex) {
                // Hapus sel kosong di akhir jika jumlahnya melebihi header
                while (count($rowData) > count($header) && end($rowData) === null) {
                     array_pop($rowData);
                }
                // Tambahkan null jika jumlah sel kurang dari header
                while (count($rowData) < count($header)) {
                    $rowData[] = null;
                }
                // Pastikan jumlahnya sama persis
                if(count($rowData) === count($header)){
                    // Hanya tambahkan jika baris tidak sepenuhnya kosong
                    if (count(array_filter($rowData, fn($value) => $value !== null && $value !== '')) > 0) {
                         try {
                            $combined = array_combine($header, $rowData);
                            if($combined !== false) {
                                $data[] = $combined;
                            } else {
                                trigger_error("Gagal menggabungkan header dan data pada baris " . ($rowIndex + 1) . ". Jumlah kolom mungkin tidak cocok.", E_USER_WARNING);
                            }
                         } catch (\ValueError $e) {
                             trigger_error("Error array_combine di baris " . ($rowIndex + 1) . ": " . $e->getMessage(), E_USER_WARNING);
                         }
                    }
                } else {
                     trigger_error("Jumlah kolom tidak cocok antara header (".count($header).") dan data (".count($rowData).") pada baris " . ($rowIndex + 1), E_USER_WARNING);
                }
            }
        }
        if (!$headerFound) {
             trigger_error("Header dengan kata kunci 'Country' atau 'Negara' tidak ditemukan di file: " . basename($filePath), E_USER_WARNING);
        }
        return $data;

    } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
        trigger_error("Gagal membaca file: " . basename($filePath) . ". Error: " . $e->getMessage(), E_USER_ERROR);
        return []; // Kembalikan array kosong jika gagal baca
    } catch (\Exception $e) {
         trigger_error("Terjadi error saat memproses file: " . basename($filePath) . ". Error: " . $e->getMessage(), E_USER_ERROR);
        return [];
    }
}


// Logika pemrosesan file
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['meta_files']) && isset($_FILES['adsense_file'])) {
    $metaData = [];
    $metaHeader = []; // Reset header untuk setiap request
    foreach ($_FILES['meta_files']['tmp_name'] as $key => $tmpName) {
         // Pastikan file benar-benar diupload dan tidak ada error
        if (!empty($tmpName) && is_uploaded_file($tmpName) && $_FILES['meta_files']['error'][$key] === UPLOAD_ERR_OK) {
             $currentHeader = []; // Header spesifik file ini
            $currentMetaData = readDataFile($tmpName, $currentHeader);
            // Gunakan header dari file pertama sebagai referensi utama
            if(empty($metaHeader) && !empty($currentHeader)) {
                 $metaHeader = $currentHeader;
            }
            $metaData = array_merge($metaData, $currentMetaData);
        } elseif ($_FILES['meta_files']['error'][$key] !== UPLOAD_ERR_NO_FILE) {
             // Beri pesan error jika ada masalah upload selain file tidak dipilih
             trigger_error("Error saat mengupload file Meta Ads: " . $_FILES['meta_files']['name'][$key] . " (Code: " . $_FILES['meta_files']['error'][$key] . ")", E_USER_WARNING);
        }
    }

    $adsenseFile = $_FILES['adsense_file']['tmp_name'];
    $adsenseHeader = []; // Reset header AdSense
    $adsenseData = [];
     if (!empty($adsenseFile) && is_uploaded_file($adsenseFile) && $_FILES['adsense_file']['error'] === UPLOAD_ERR_OK) {
        $adsenseData = readDataFile($adsenseFile, $adsenseHeader); // Header AdSense dibaca tapi tidak digunakan untuk deteksi
     } elseif ($_FILES['adsense_file']['error'] !== UPLOAD_ERR_NO_FILE) {
         trigger_error("Error saat mengupload file AdSense: " . $_FILES['adsense_file']['name'] . " (Code: " . $_FILES['adsense_file']['error'] . ")", E_USER_WARNING);
     }

    // Deteksi bahasa HANYA jika header Meta Ads berhasil didapatkan
    $kolom_negara_meta = 'Country'; // Default English
    $kolom_biaya_meta = 'Amount spent (IDR)'; // Default English
    if (!empty($metaHeader)) {
        $foundNegara = false;
        $foundJumlah = false;
        foreach($metaHeader as $h) {
            if(strcasecmp(trim($h), 'Negara') === 0) $foundNegara = true;
            if(strcasecmp(trim($h), 'Jumlah yang dibelanjakan (IDR)') === 0) $foundJumlah = true;
        }
        if ($foundNegara && $foundJumlah) {
            $kolom_negara_meta = 'Negara';
            $kolom_biaya_meta = 'Jumlah yang dibelanjakan (IDR)';
        }
        // Jika hanya salah satu yang cocok, mungkin perlu penyesuaian/warning tambahan
    } else {
        trigger_error("Header Meta Ads tidak berhasil dibaca atau kosong.", E_USER_WARNING);
    }

    // Kolom AdSense biasanya standar
    $kolom_negara_adsense = 'Country';
    $kolom_earning_adsense = 'Estimated earnings (IDR)';

    // Normalisasi & Agregasi (dengan pengecekan kolom sebelum akses)
    foreach ($metaData as &$row) {
         if (isset($row[$kolom_negara_meta])) { // Cek kolom sebelum normalisasi
            $row[$kolom_negara_meta] = normalizeCountryName($row[$kolom_negara_meta]);
         }
    } unset($row);
    foreach ($adsenseData as &$row) {
         if (isset($row[$kolom_negara_adsense])) { // Cek kolom sebelum normalisasi
            $row[$kolom_negara_adsense] = normalizeCountryName($row[$kolom_negara_adsense]);
         }
    } unset($row);

    $metaGrouped = [];
    foreach ($metaData as $row) {
        // Cek semua kolom yang dibutuhkan sebelum digunakan
        if (!isset($row[$kolom_negara_meta]) || !isset($row[$kolom_biaya_meta])) continue;
        $country = $row[$kolom_negara_meta];
        if (!isset($metaGrouped[$country])) { $metaGrouped[$country] = ['total_spending' => 0]; }
        // Hapus karakter non-numerik KECUALI titik desimal (jika ada)
        $spendingValue = preg_replace('/[^\d.]/', '', $row[$kolom_biaya_meta]);
        $metaGrouped[$country]['total_spending'] += (float) $spendingValue;
    }
    $adsenseGrouped = [];
    foreach ($adsenseData as $row) {
        // Cek semua kolom yang dibutuhkan
        if (!isset($row[$kolom_negara_adsense]) || !isset($row[$kolom_earning_adsense])) continue;
        $country = $row[$kolom_negara_adsense];
        if (!isset($adsenseGrouped[$country])) { $adsenseGrouped[$country] = ['total_earnings' => 0]; }
        // Hapus karakter non-numerik KECUALI titik desimal
        $earningValue = preg_replace('/[^\d.]/', '', $row[$kolom_earning_adsense]);
        $adsenseGrouped[$country]['total_earnings'] += (float) $earningValue;
    }

    $finalData = [];
    $allCountries = array_unique(array_merge(array_keys($metaGrouped), array_keys($adsenseGrouped)));
    foreach ($allCountries as $country) {
        // Pastikan country bukan string kosong
        if(empty($country)) continue;

        $spending = $metaGrouped[$country]['total_spending'] ?? 0;
        $earnings = $adsenseGrouped[$country]['total_earnings'] ?? 0;
        if ($spending > 0) {
            $roi = (($earnings - $spending) / $spending) * 100;
            $finalData[$country] = [ 'total_spending' => $spending, 'total_earnings' => $earnings, 'roi' => $roi ];
        }
    }
    if (!empty($finalData)) { ksort($finalData); }
    $_SESSION['analysis_result'] = $finalData;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$analysisResult = $_SESSION['analysis_result'] ?? [];
if (!empty($analysisResult)) { ksort($analysisResult); }

// Perhitungan Total
$totalSpending = 0; $totalEarnings = 0; $overallRoi = null;
if (!empty($analysisResult)) {
    foreach ($analysisResult as $data) {
        $totalSpending += $data['total_spending'];
        $totalEarnings += $data['total_earnings'];
    }
    if ($totalSpending > 0) {
        $overallRoi = (($totalEarnings - $totalSpending) / $totalSpending) * 100;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/v/bs5/dt-2.0.8/datatables.min.css" rel="stylesheet">
    <title>ROI Analyzer by Country</title>
</head>
<body>
    <div class="container mt-5">
        <h2 class="mb-4 text-center">ROI Analyzer by Country</h2>
        <div class="table-responsive">
            <div class="mb-3">
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#uploadModal" data-wl-type="1">Analisis 1 WL</button>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal" data-wl-type="2">Analisis 2 WL</button>
            </div>

            <table id="roiTable" class="table table-hover table-bordered" style="width:100%">
                <thead class="table-info">
                    <tr>
                        <th scope="col" class="text-center">Tandai</th>
                        <th scope="col">No.</th>
                        <th scope="col">Negara</th>
                        <th scope="col">Total Spending</th>
                        <th scope="col">Total Earnings</th>
                        <th scope="col">ROI</th>
                        <th scope="col">Saran</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($analysisResult)): ?>
                        <tr><td colspan="7" class="text-center">Belum ada data. Silakan pilih mode analisis.</td></tr>
                    <?php else: ?>
                        <?php $rank = 1; foreach ($analysisResult as $country => $data): ?>
                            <tr>
                                <td class="text-center"><input class="form-check-input row-highlighter" type="checkbox"></td>
                                <th scope="row"><?php echo $rank++; ?></th>
                                <td><?php echo htmlspecialchars($country); ?></td>
                                <td><?php echo 'Rp ' . number_format($data['total_spending'], 0, ',', '.'); ?></td>
                                <td><?php echo 'Rp ' . number_format($data['total_earnings'], 0, ',', '.'); ?></td>
                                <td class="fw-bold"><span class="<?php echo $data['roi'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo number_format($data['roi'], 2, ',', '.') . '%'; ?></span></td>
                                <td><?php if ($data['roi'] < 100): ?><span class="badge bg-warning text-dark">Hapus Negara</span><?php else: ?><span class="badge bg-success">Pertahankan</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-secondary fw-bold">
                    <tr>
                        <td colspan="3" class="text-start">TOTAL KESELURUHAN:</td>
                        <td><?php echo 'Rp ' . number_format($totalSpending, 0, ',', '.'); ?></td>
                        <td><?php echo 'Rp ' . number_format($totalEarnings, 0, ',', '.'); ?></td>
                        <td>
                            <?php if ($overallRoi !== null): ?>
                                <span class="<?php echo $overallRoi >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo number_format($overallRoi, 2, ',', '.') . '%'; ?></span>
                            <?php else: ?> N/A <?php endif; ?>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"> <div class="modal-content"> <div class="modal-header"> <h5 class="modal-title" id="uploadModalLabel">Upload File Analisis</h5> <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button> </div> <form action="" method="POST" enctype="multipart/form-data"> <div class="modal-body"> <div class="mb-3"> <label for="meta_file_1" class="form-label">File Meta Ads 1 (.xlsx / .csv)</label> <input type="file" class="form-control" id="meta_file_1" name="meta_files[]" required> </div> <div class="mb-3" id="meta_file_2_wrapper" style="display: none;"> <label for="meta_file_2" class="form-label">File Meta Ads 2 (.xlsx / .csv)</label> <input type="file" class="form-control" id="meta_file_2" name="meta_files[]"> </div> <div class="mb-3"> <label for="adsense_file" class="form-label">File Google AdSense (.xlsx / .csv)</label> <input type="file" class="form-control" id="adsense_file" name="adsense_file" required> </div> </div> <div class="modal-footer"> <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button> <button type="submit" class="btn btn-primary">Proses & Analisis</button> </div> </form> </div> </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/v/bs5/dt-2.0.8/datatables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        function initializeHighlighters() { document.querySelectorAll('.row-highlighter').forEach(checkbox => { checkbox.removeEventListener('change', highlightRow); checkbox.addEventListener('change', highlightRow); }); }
        function highlightRow() { const row = this.closest('tr'); this.checked ? row.classList.add('table-danger') : row.classList.remove('table-danger'); }
        const uploadModal = document.getElementById('uploadModal');
        uploadModal.addEventListener('show.bs.modal', function (event) { const button = event.relatedTarget; const wlType = button.getAttribute('data-wl-type'); const modalTitle = uploadModal.querySelector('.modal-title'); const metaFile2Wrapper = document.getElementById('meta_file_2_wrapper'); const metaFile2Input = document.getElementById('meta_file_2'); if (wlType === '2') { modalTitle.textContent = 'Analisis 2 WL (2 Meta + 1 AdSense)'; metaFile2Wrapper.style.display = 'block'; metaFile2Input.required = true; } else { modalTitle.textContent = 'Analisis 1 WL (1 Meta + 1 AdSense)'; metaFile2Wrapper.style.display = 'none'; metaFile2Input.required = false; } });
        $(document).ready(function() {
            let table = new DataTable('#roiTable', {
                columnDefs: [
                    { orderable: false, targets: [0, 1, 6] },
                    { targets: 5, type: 'num', render: function ( data, type, row ) { if ( type === 'sort' ) { let numStr = data.replace(/Rp|\.|%|\s/g, '').replace(',', '.'); let spanMatch = numStr.match(/<span.*?>(.*?)<\/span>/); if (spanMatch && spanMatch[1] === 'N/A') { return -Infinity; } let numberMatch = numStr.match(/-?\d+(\.\d+)?/); return numberMatch ? parseFloat(numberMatch[0]) : -Infinity; } return data; } }
                ],
                order: [[ 2, 'asc' ]],
                paging: true, searching: true, info: true,
                footerCallback: function ( row, data, start, end, display ) { /* Biarkan kosong */ }
            });
            initializeHighlighters();
            table.on('draw', function () { initializeHighlighters(); });
        });
    </script>

    <footer class="mt-5 py-4 text-center">
         <div class="container"> <hr> <p class="text-muted mb-0"> © <?php echo date('Y'); ?> ROI Analyzer by Country - V1.3 </p> <small class="text-muted">Dibuat untuk Tim Analis</small> </div>
    </footer>
</body>
</html>