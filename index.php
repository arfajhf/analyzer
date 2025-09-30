<?php
// Mulai session
session_start();
// Paksa PHP untuk menampilkan error
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Wajib ada setelah install Composer
require 'vendor/autoload.php';

use Umpirsky\Country\CountryList;
use PhpOffice\PhpSpreadsheet\IOFactory;

// --- FUNGSI NORMALISASI OTOMATIS ---
function normalizeCountryName($countryName) {
    // ... (fungsi ini tidak berubah)
    static $englishCountries = null;
    static $indonesianLookup = null;
    static $cache = [];
    if ($englishCountries === null) {
        if (file_exists(__DIR__ . '/vendor/umpirsky/country-list/data/en/country.php')) {
            $englishCountries = require __DIR__ . '/vendor/umpirsky/country-list/data/en/country.php';
            $indonesianCountries = require __DIR__ . '/vendor/umpirsky/country-list/data/id/country.php';
            $indonesianLookup = array_flip($indonesianCountries);
        } else {
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
        if ($shortestDistance < 0 || $distance < $shortestDistance) {
            $shortestDistance = $distance;
            $bestMatch = $standardName;
        }
    }
    if ($shortestDistance <= 3) { $cache[$name] = $bestMatch; return $bestMatch; }
    $cache[$name] = $name;
    return $name;
}

// --- BAGIAN KONFIGURASI NAMA KOLOM ---
$kolom_negara_meta = 'Negara';
$kolom_biaya_meta = 'Jumlah yang dibelanjakan (IDR)';
$kolom_negara_adsense = 'Country';
$kolom_earning_adsense = 'Estimated earnings (IDR)';

// --- FUNGSI PEMBACA FILE ---
function readDataFile($filePath, $headerKeyword) {
    // ... (Fungsi ini tidak berubah)
    $spreadsheet = IOFactory::load($filePath);
    $worksheet = $spreadsheet->getActiveSheet();
    $data = [];
    $header = [];
    $headerFound = false;
    foreach ($worksheet->getRowIterator() as $row) {
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(FALSE);
        $rowData = [];
        foreach ($cellIterator as $cell) { $rowData[] = $cell->getValue(); }
        if (!$headerFound) {
            if (in_array($headerKeyword, $rowData)) {
                $header = $rowData;
                $headerFound = true;
                continue; 
            }
        }
        if ($headerFound) {
            if (implode('', $rowData) != '') {
                while(count($rowData) > count($header)) { array_pop($rowData); }
                while(count($rowData) < count($header)) { $rowData[] = null; }
                $data[] = array_combine($header, $rowData);
            }
        }
    }
    return $data;
}

// Logika pemrosesan file
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['meta_file']) && isset($_FILES['adsense_file'])) {
    $metaFile = $_FILES['meta_file']['tmp_name'];
    $adsenseFile = $_FILES['adsense_file']['tmp_name'];
    $metaData = readDataFile($metaFile, $kolom_negara_meta);
    $adsenseData = readDataFile($adsenseFile, $kolom_negara_adsense);
    foreach ($metaData as &$row) { if (isset($row[$kolom_negara_meta])) { $row[$kolom_negara_meta] = normalizeCountryName($row[$kolom_negara_meta]); } } unset($row); 
    foreach ($adsenseData as &$row) { if (isset($row[$kolom_negara_adsense])) { $row[$kolom_negara_adsense] = normalizeCountryName($row[$kolom_negara_adsense]); } } unset($row);
    $metaGrouped = [];
    foreach ($metaData as $row) {
        if (!isset($row[$kolom_negara_meta]) || !isset($row[$kolom_biaya_meta])) continue;
        $country = $row[$kolom_negara_meta];
        if (!isset($metaGrouped[$country])) { $metaGrouped[$country] = ['total_spending' => 0]; }
        $metaGrouped[$country]['total_spending'] += (float) preg_replace('/[^\d.]/', '', $row[$kolom_biaya_meta]);
    }
    $adsenseGrouped = [];
    foreach ($adsenseData as $row) {
        if (!isset($row[$kolom_negara_adsense]) || !isset($row[$kolom_earning_adsense])) continue;
        $country = $row[$kolom_negara_adsense];
        if (!isset($adsenseGrouped[$country])) { $adsenseGrouped[$country] = ['total_earnings' => 0]; }
        $adsenseGrouped[$country]['total_earnings'] += (float) preg_replace('/[^\d.]/', '', $row[$kolom_earning_adsense]);
    }
    $finalData = [];
    $allCountries = array_unique(array_merge(array_keys($metaGrouped), array_keys($adsenseGrouped)));
    foreach ($allCountries as $country) {
        $spending = $metaGrouped[$country]['total_spending'] ?? 0;
        $earnings = $adsenseGrouped[$country]['total_earnings'] ?? 0;
        if ($spending > 0) {
            $roi = (($earnings - $spending) / $spending) * 100;
            $finalData[$country] = [ 'total_spending' => $spending, 'total_earnings' => $earnings, 'roi' => $roi ];
        }
    }
    
    // Urutkan berdasarkan abjad SEBELUM disimpan ke session
    if (!empty($finalData)) {
        ksort($finalData);
    }

    $_SESSION['analysis_result'] = $finalData;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Ambil data hasil analisis dari session untuk ditampilkan
$analysisResult = $_SESSION['analysis_result'] ?? [];

// PASTIKAN LAGI DATANYA TERURUT SESUAI ABJAD SEBELUM DITAMPILKAN
if (!empty($analysisResult)) {
    ksort($analysisResult);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>ROI Analyzer by Country</title>
</head>
<body>
    <div class="container mt-5">
        <h2 class="mb-4 text-center">ROI Analyzer by Country</h2>
        <div class="table-responsive">
            <button type="button" class="btn btn-outline-primary btn-sm mb-3" data-bs-toggle="modal" data-bs-target="#uploadModal">
                + Upload & Analisis File Excel
            </button>
            <table class="table table-hover table-bordered">
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
                        <tr>
                            <td colspan="7" class="text-center">Belum ada data. Silakan upload file untuk memulai analisis.</td>
                        </tr>
                    <?php else: ?>
                        <?php $rank = 1; foreach ($analysisResult as $country => $data): ?>
                            <tr>
                                <td class="text-center">
                                    <input class="form-check-input row-highlighter" type="checkbox">
                                </td>
                                <th scope="row"><?php echo $rank++; ?></th>
                                <td><?php echo htmlspecialchars($country); ?></td>
                                <td><?php echo 'Rp ' . number_format($data['total_spending'], 0, ',', '.'); ?></td>
                                <td><?php echo 'Rp ' . number_format($data['total_earnings'], 0, ',', '.'); ?></td>
                                <td class="fw-bold">
                                    <span class="<?php echo $data['roi'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo number_format($data['roi'], 2, ',', '.') . '%'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($data['roi'] < 100): ?>
                                        <span class="badge bg-warning text-dark">Hapus Negara</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Pertahankan</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="uploadModalLabel">Upload File Analisis</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="meta_file" class="form-label">File Meta Ads (.xlsx / .csv)</label>
                            <input type="file" class="form-control" id="meta_file" name="meta_file" required>
                        </div>
                        <div class="mb-3">
                            <label for="adsense_file" class="form-label">File Google AdSense (.xlsx / .csv)</label>
                            <input type="file" class="form-control" id="adsense_file" name="adsense_file" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Proses & Analisis</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const checkboxes = document.querySelectorAll('.row-highlighter');
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const row = this.closest('tr');
                if (this.checked) {
                    row.classList.add('table-danger');
                } else {
                    row.classList.remove('table-danger');
                }
            });
        });
    </script>
</body>
</html>