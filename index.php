<?php
// Mulai session
session_start();
// Paksa PHP untuk menampilkan error agar mudah di-debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Wajib ada setelah install Composer
require 'vendor/autoload.php';

use Umpirsky\Country\CountryList;
use PhpOffice\PhpSpreadsheet\IOFactory;

// --- FUNGSI NORMALISASI OTOMATIS ---
function normalizeCountryName($countryName) {
    static $englishCountries = null, $indonesianLookup = null, $cache = [];
    if ($englishCountries === null) {
        if (file_exists(__DIR__ . '/vendor/umpirsky/country-list/data/en/country.php')) {
            $englishCountries = require __DIR__ . '/vendor/umpirsky/country-list/data/en/country.php';
            $indonesianCountries = require __DIR__ . '/vendor/umpirsky/country-list/data/id/country.php';
            $indonesianLookup = array_flip($indonesianCountries);
        } else { return $countryName; }
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
    if ($shortestDistance <= 3) { $cache[$name] = $bestMatch; return $bestMatch; }
    $cache[$name] = $name;
    return $name;
}

// --- FUNGSI PEMBACA FILE ---
function readDataFile($filePath, &$header) {
    $spreadsheet = IOFactory::load($filePath);
    $worksheet = $spreadsheet->getActiveSheet();
    $data = []; $headerFound = false;
    foreach ($worksheet->getRowIterator() as $row) {
        $cellIterator = $row->getCellIterator(); $cellIterator->setIterateOnlyExistingCells(FALSE);
        $rowData = [];
        foreach ($cellIterator as $cell) { $rowData[] = $cell->getValue(); }
        if (!$headerFound) {
            if (in_array('Country', $rowData) || in_array('Negara', $rowData)) {
                $header = $rowData; $headerFound = true; continue; 
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['meta_files']) && isset($_FILES['adsense_file'])) {
    
    $metaData = [];
    $metaHeader = [];
    foreach ($_FILES['meta_files']['tmp_name'] as $tmpName) {
        if (!empty($tmpName) && is_uploaded_file($tmpName)) {
            $currentMetaData = readDataFile($tmpName, $metaHeader);
            $metaData = array_merge($metaData, $currentMetaData);
        }
    }

    $adsenseFile = $_FILES['adsense_file']['tmp_name'];
    $adsenseHeader = [];
    $adsenseData = readDataFile($adsenseFile, $adsenseHeader);
    
    if (in_array('Negara', $metaHeader)) {
        $kolom_negara_meta = 'Negara'; $kolom_biaya_meta = 'Jumlah yang dibelanjakan (IDR)';
    } else {
        $kolom_negara_meta = 'Country'; $kolom_biaya_meta = 'Amount spent (IDR)';
    }
    $kolom_negara_adsense = 'Country';
    $kolom_earning_adsense = 'Estimated earnings (IDR)';

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
    
    $finalData = [];
    $allCountries = array_unique(array_merge(array_keys($metaGrouped), array_keys($adsenseGrouped)));

    foreach ($allCountries as $country) {
        $spending = $metaGrouped[$country]['total_spending'] ?? 0;
        $earnings = $adsenseGrouped[$country]['total_earnings'] ?? 0;
        
        // Ini adalah 'penjaga gerbang'. Hanya jika spending lebih dari 0,
        // data akan diproses dan dimasukkan ke hasil akhir.
        if ($spending > 0) {
            $roi = (($earnings - $spending) / $spending) * 100;

            $finalData[$country] = [
                'total_spending' => $spending,
                'total_earnings' => $earnings,
                'roi' => $roi
            ];
        }
    }
    
    if (!empty($finalData)) { ksort($finalData); }

    $_SESSION['analysis_result'] = $finalData;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$analysisResult = $_SESSION['analysis_result'] ?? [];
if (!empty($analysisResult)) { ksort($analysisResult); }
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
            
            <div class="mb-3">
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#uploadModal" data-wl-type="1">
                    Analisis 1 WL (1 Meta + 1 AdSense)
                </button>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal" data-wl-type="2">
                    Analisis 2 WL (2 Meta + 1 AdSense)
                </button>
            </div>
            
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
                        <tr><td colspan="7" class="text-center">Belum ada data. Silakan pilih mode analisis.</td></tr>
                    <?php else: ?>
                        <?php $rank = 1; foreach ($analysisResult as $country => $data): ?>
                            <tr>
                                <td class="text-center"><input class="form-check-input row-highlighter" type="checkbox"></td>
                                <th scope="row"><?php echo $rank++; ?></th>
                                <td><?php echo htmlspecialchars($country); ?></td>
                                <td><?php echo 'Rp ' . number_format($data['total_spending'], 0, ',', '.'); ?></td>
                                <td><?php echo 'Rp ' . number_format($data['total_earnings'], 0, ',', '.'); ?></td>
                                <td class="fw-bold">
                                    <?php if ($data['roi'] !== null): ?>
                                        <span class="<?php echo $data['roi'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo number_format($data['roi'], 2, ',', '.') . '%'; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($data['roi'] !== null): ?>
                                        <?php if ($data['roi'] < 100): ?><span class="badge bg-warning text-dark">Hapus Negara</span><?php else: ?><span class="badge bg-success">Pertahankan</span><?php endif; ?>
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
                            <label for="meta_file_1" class="form-label">File Meta Ads 1 (.xlsx / .csv)</label>
                            <input type="file" class="form-control" id="meta_file_1" name="meta_files[]" required>
                        </div>
                        <div class="mb-3" id="meta_file_2_wrapper" style="display: none;">
                            <label for="meta_file_2" class="form-label">File Meta Ads 2 (.xlsx / .csv)</label>
                            <input type="file" class="form-control" id="meta_file_2" name="meta_files[]">
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
        // Script untuk checkbox highlighter
        document.querySelectorAll('.row-highlighter').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const row = this.closest('tr');
                this.checked ? row.classList.add('table-danger') : row.classList.remove('table-danger');
            });
        });

        // Script untuk modal interaktif 1 WL / 2 WL
        const uploadModal = document.getElementById('uploadModal');
        uploadModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const wlType = button.getAttribute('data-wl-type');
            const modalTitle = uploadModal.querySelector('.modal-title');
            const metaFile2Wrapper = document.getElementById('meta_file_2_wrapper');
            const metaFile2Input = document.getElementById('meta_file_2');

            if (wlType === '2') {
                modalTitle.textContent = 'Analisis 2 WL (2 Meta + 1 AdSense)';
                metaFile2Wrapper.style.display = 'block';
                metaFile2Input.required = true;
            } else {
                modalTitle.textContent = 'Analisis 1 WL (1 Meta + 1 AdSense)';
                metaFile2Wrapper.style.display = 'none';
                metaFile2Input.required = false;
            }
        });
    </script>
    <footer class="mt-5 py-4 text-center">
        <div class="container">
            <hr>
            <p class="text-muted mb-0">
                © <?php echo date('Y'); ?> ROI Analyzer by Country - V1.2
            </p>
            </p>
            <small class="text-muted">Dibuat untuk Tim Analis</small>
        </div>
    </footer>
</body>
</html>