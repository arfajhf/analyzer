<?php
// Mulai session
session_start();
// Paksa PHP untuk menampilkan error
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Wajib ada setelah install Composer
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// --- FUNGSI NORMALISASI OTOMATIS (VERSI PALING AMAN DARI NULL) ---
function normalizeCountryName($countryName) {
    if ($countryName === null || !is_string($countryName) || ($name = trim($countryName)) === '') {
        return '';
    }
    static $englishCountries = null, $indonesianLookup = null, $cache = [];
    if ($englishCountries === null) {
        $enPath = __DIR__ . '/vendor/umpirsky/country-list/data/en/country.php';
        $idPath = __DIR__ . '/vendor/umpirsky/country-list/data/id/country.php';
        if (file_exists($enPath) && file_exists($idPath)) {
            $englishCountries = require $enPath;
            $indonesianCountries = require $idPath;
            $indonesianLookup = array_flip($indonesianCountries);
        } else {
            trigger_error("Data library umpirsky/country-list tidak ditemukan.", E_USER_WARNING);
            return $name;
        }
    }
    $upperName = strtoupper($name);
    if (isset($cache[$name])) { return $cache[$name]; }
    if (isset($englishCountries[$upperName])) { $cache[$name] = $englishCountries[$upperName]; return $cache[$name]; }
    if (isset($indonesianLookup[$name])) { $countryCode = $indonesianLookup[$name]; $cache[$name] = $englishCountries[$countryCode] ?? $name; return $cache[$name]; }
    $countryCode = array_search($name, $englishCountries, true);
    if ($countryCode !== false) { $cache[$name] = $name; return $name; }
    $bestMatch = ''; $shortestDistance = -1;
    foreach ($englishCountries as $standardName) {
        $distance = levenshtein(strtolower($name), strtolower($standardName));
        if ($shortestDistance < 0 || $distance < $shortestDistance) { $shortestDistance = $distance; $bestMatch = $standardName; }
    }
    if ($shortestDistance >= 0 && $shortestDistance <= 3) { $cache[$name] = $bestMatch; return $bestMatch; }
    $cache[$name] = $name;
    return $name;
}

// --- FUNGSI PEMBACA FILE (DIPERBAIKI UNTUK MENJAGA ANGKA) ---
function readDataFile($filePath, &$header) {
    try {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $data = []; $headerFound = false; $headerRowIndex = -1;
        foreach ($worksheet->getRowIterator() as $rowIndex => $row) {
            $cellIterator = $row->getCellIterator(); $cellIterator->setIterateOnlyExistingCells(false);
            $rowDataRaw = [];
            foreach ($cellIterator as $cell) { 
                // Gunakan getValue() untuk mengambil tipe data asli (angka tetap angka)
                $rowDataRaw[] = $cell->getValue(); 
            }

            // --- PERBAIKAN DI SINI ---
            $rowData = array_map(function($value) {
                if (is_string($value)) {
                    return trim($value); // Hanya trim jika string
                }
                if (is_numeric($value)) {
                    return $value; // JAGA ANGKA, JANGAN DIUBAH
                }
                return ''; // Ubah null/lainnya jadi string kosong
            }, $rowDataRaw);
            // ---------------------------------

            if (!$headerFound) {
                $foundCountry = false;
                foreach($rowData as $cellValue) {
                    if (strcasecmp($cellValue, 'Country') === 0 || strcasecmp($cellValue, 'Negara') === 0) {
                        $foundCountry = true; break;
                    }
                }
                if ($foundCountry) {
                    $header = $rowData; $headerFound = true; $headerRowIndex = $rowIndex; continue;
                }
            }
            if ($headerFound && $rowIndex > $headerRowIndex) {
                 while (count($rowData) > count($header) && end($rowData) === '') { array_pop($rowData); }
                 while (count($rowData) < count($header)) { $rowData[] = ''; }
                if (count($rowData) === count($header)) {
                    if (count(array_filter($rowData, fn($value) => $value !== '' && $value !== null)) > 0) {
                        try {
                            $combined = array_combine($header, $rowData);
                            if ($combined !== false) { $data[] = $combined; }
                            else { trigger_error("Gagal array_combine baris " . ($rowIndex + 1) . ".", E_USER_WARNING); }
                        } catch (\ValueError $e) { trigger_error("Error array_combine baris " . ($rowIndex + 1) . ": " . $e->getMessage(), E_USER_WARNING); }
                    }
                } else { trigger_error("Kolom tidak cocok header=".count($header)." data=".count($rowData)." baris " . ($rowIndex + 1), E_USER_WARNING); }
            }
        }
        if (!$headerFound) { trigger_error("Header 'Country'/'Negara' tidak ditemukan di " . basename($filePath), E_USER_WARNING); }
        return $data;
    } catch (\Exception $e) {
        trigger_error("Error baca file " . basename($filePath) . ": " . $e->getMessage(), E_USER_ERROR);
        return [];
    }
}

// === LOGIKA UTAMA (HANYA BERJALAN JIKA ADA REQUEST AJAX) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['meta_files']) && isset($_FILES['adsense_file'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'errors' => [], 'data' => []];
    
    ob_start();

    $metaData = []; $metaHeader = [];
    foreach ($_FILES['meta_files']['tmp_name'] as $key => $tmpName) {
        if (!empty($tmpName) && is_uploaded_file($tmpName) && $_FILES['meta_files']['error'][$key] === UPLOAD_ERR_OK) {
            $currentHeader = [];
            $currentMetaData = readDataFile($tmpName, $currentHeader);
            if (empty($metaHeader) && !empty($currentHeader)) { $metaHeader = $currentHeader; }
            $metaData = array_merge($metaData, $currentMetaData);
        } elseif ($_FILES['meta_files']['error'][$key] !== UPLOAD_ERR_NO_FILE) {
            $response['errors'][] = "Upload error Meta file " . $_FILES['meta_files']['name'][$key];
        }
    }

    $adsenseData = []; $adsenseHeader = [];
    if (!empty($_FILES['adsense_file']['tmp_name']) && is_uploaded_file($_FILES['adsense_file']['tmp_name']) && $_FILES['adsense_file']['error'] === UPLOAD_ERR_OK) {
        $adsenseData = readDataFile($_FILES['adsense_file']['tmp_name'], $adsenseHeader);
    } elseif ($_FILES['adsense_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $response['errors'][] = "Upload error AdSense file";
    }
    
    if (empty($metaData)) { $response['errors'][] = "Tidak ada data Meta Ads. Pastikan file berisi kolom 'Country' atau 'Negara'."; }
    if (empty($adsenseData)) { $response['errors'][] = "Tidak ada data AdSense. Pastikan file berisi kolom 'Country'."; }
    
    // Deteksi Bahasa & Kolom Meta Ads
    $kolom_negara_meta = 'Country'; $kolom_biaya_meta = 'Amount spent (IDR)';
    if (!empty($metaHeader)) {
        $headersLower = array_map('strtolower', $metaHeader); // $metaHeader sudah di-trim di readDataFile
        if (in_array('negara', $headersLower) && in_array('jumlah yang dibelanjakan (idr)', $headersLower)) {
            $indexNegara = array_search('negara', $headersLower); $indexBiaya = array_search('jumlah yang dibelanjakan (idr)', $headersLower);
            if($indexNegara !== false && $indexBiaya !== false) { $kolom_negara_meta = $metaHeader[$indexNegara]; $kolom_biaya_meta = $metaHeader[$indexBiaya]; }
        } else if (in_array('country', $headersLower) && in_array('amount spent (idr)', $headersLower)) {
            $indexNegara = array_search('country', $headersLower); $indexBiaya = array_search('amount spent (idr)', $headersLower);
            if($indexNegara !== false && $indexBiaya !== false) { $kolom_negara_meta = $metaHeader[$indexNegara]; $kolom_biaya_meta = $metaHeader[$indexBiaya]; }
        } else { $response['errors'][] = "Kolom 'Country'/'Negara' atau 'Amount spent (IDR)'/'Jumlah yang dibelanjakan (IDR)' tidak ditemukan di file Meta Ads."; }
    } else { $response['errors'][] = "Header Meta Ads tidak terbaca."; }

    $kolom_negara_adsense = 'Country'; $kolom_earning_adsense = 'Estimated earnings (IDR)';
    if (!empty($adsenseHeader)) {
         $adsenseHeadersLower = array_map('strtolower', $adsenseHeader);
         if(!in_array(strtolower($kolom_negara_adsense), $adsenseHeadersLower)) { $response['errors'][] = "Kolom '$kolom_negara_adsense' tidak ditemukan di file AdSense."; }
         if(!in_array(strtolower($kolom_earning_adsense), $adsenseHeadersLower)) { $response['errors'][] = "Kolom '$kolom_earning_adsense' tidak ditemukan di file AdSense."; }
    } else { $response['errors'][] = "Header AdSense tidak terbaca."; }
    
    if (!empty($response['errors'])) {
        $phpErrors = ob_get_clean();
        if(!empty($phpErrors)) $response['errors'][] = "Server Warning: " . $phpErrors;
        echo json_encode($response);
        exit;
    }

    foreach ($metaData as &$row) { if (isset($row[$kolom_negara_meta])) { $row[$kolom_negara_meta] = normalizeCountryName($row[$kolom_negara_meta]); } } unset($row);
    foreach ($adsenseData as &$row) { if (isset($row[$kolom_negara_adsense])) { $row[$kolom_negara_adsense] = normalizeCountryName($row[$kolom_negara_adsense]); } } unset($row);

    $metaGrouped = []; $adsenseGrouped = [];
    foreach ($metaData as $row) {
        if (!isset($row[$kolom_negara_meta], $row[$kolom_biaya_meta]) || empty($row[$kolom_negara_meta])) continue;
        $country = $row[$kolom_negara_meta];
        if (!isset($metaGrouped[$country])) { $metaGrouped[$country] = ['total_spending' => 0]; }

        // --- LOGIKA ANGKA YANG DIPERBAIKI ---
        $value = $row[$kolom_biaya_meta];
        if (is_string($value)) {
            $spendingValue = preg_replace('/[^\d]/', '', $value); // Format Rupiah (Rp 1.234)
        } else {
            $spendingValue = $value; // Angka murni (959017.00)
        }
        $metaGrouped[$country]['total_spending'] += (float) $spendingValue;
    }
    foreach ($adsenseData as $row) {
        if (!isset($row[$kolom_negara_adsense], $row[$kolom_earning_adsense]) || empty($row[$kolom_negara_adsense])) continue;
        $country = $row[$kolom_negara_adsense];
        if (!isset($adsenseGrouped[$country])) { $adsenseGrouped[$country] = ['total_earnings' => 0]; }

        // --- LOGIKA ANGKA YANG DIPERBAIKI ---
        $value = $row[$kolom_earning_adsense];
         if (is_string($value)) {
            $earningValue = preg_replace('/[^\d]/', '', $value); // Format Rupiah (Rp 39.488)
        } else {
            $earningValue = $value; // Angka murni
        }
        $adsenseGrouped[$country]['total_earnings'] += (float) $earningValue;
    }

    $finalData = [];
    $allCountries = array_unique(array_merge(array_keys($metaGrouped), array_keys($adsenseGrouped)));
    foreach ($allCountries as $country) {
        if (empty($country)) continue;
        $spending = $metaGrouped[$country]['total_spending'] ?? 0;
        $earnings = $adsenseGrouped[$country]['total_earnings'] ?? 0;
        if ($spending > 0) { // Filter N/A ROI
            $roi = (($earnings - $spending) / $spending) * 100;
            $finalData[$country] = [ 'total_spending' => $spending, 'total_earnings' => $earnings, 'roi' => $roi ];
        }
    }
    
    if (!empty($finalData)) { ksort($finalData); }
    
    $_SESSION['analysis_result'] = $finalData;
    $response['success'] = true;
    $response['data'] = $finalData;
    $response['count'] = count($finalData);
    $response['debug_metaGrouped'] = $metaGrouped;
    $response['debug_adsenseGrouped'] = $adsenseGrouped;
    
    $phpErrors = ob_get_clean();
    if(!empty($phpErrors)) $response['php_warnings'] = $phpErrors;
    
    echo json_encode($response);
    exit;
}

$analysisResult = $_SESSION['analysis_result'] ?? [];
if (!empty($analysisResult)) { ksort($analysisResult); }

$totalSpending = 0; $totalEarnings = 0; $overallRoi = null;
if (!empty($analysisResult)) {
    foreach ($analysisResult as $data) {
        $totalSpending += $data['total_spending'];
        $totalEarnings += $data['total_earnings'];
    }
    if ($totalSpending > 0) { $overallRoi = (($totalEarnings - $totalSpending) / $totalSpending) * 100; }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/v/bs5/dt-2.0.8/datatables.min.css" rel="stylesheet">
    <title>ROI Analyzer by Country</title>
    <style>
        #roiTable_wrapper .row { margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h2 class="mb-4 text-center">ROI Analyzer by Country</h2>
        
        <div id="notificationArea"></div>
        
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
                                <td><?php echo $rank++; ?></td>
                                <td><?php echo htmlspecialchars($country); ?></td>
                                <td data-order="<?php echo $data['total_spending']; ?>"><?php echo 'Rp ' . number_format($data['total_spending'], 0, ',', '.'); ?></td>
                                <td data-order="<?php echo $data['total_earnings']; ?>"><?php echo 'Rp ' . number_format($data['total_earnings'], 0, ',', '.'); ?></td>
                                <td class="fw-bold" data-order="<?php echo $data['roi']; ?>">
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
                <tfoot class="table-secondary fw-bold">
                    <tr>
                        <td colspan="3" class="text-end">TOTAL KESELURUHAN:</td>
                        <td id="totalSpending"><?php echo 'Rp ' . number_format($totalSpending, 0, ',', '.'); ?></td>
                        <td id="totalEarnings"><?php echo 'Rp ' . number_format($totalEarnings, 0, ',', '.'); ?></td>
                        <td id="overallRoi">
                            <?php if ($overallRoi !== null): ?>
                                <span class="<?php echo $overallRoi >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo number_format($overallRoi, 2, ',', '.') . '%'; ?>
                                </span>
                            <?php else: ?> N/A <?php endif; ?>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"> 
            <div class="modal-content"> 
                <div class="modal-header"> <h5 class="modal-title" id="uploadModalLabel">Upload File Analisis</h5> <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button> </div> 
                <form id="uploadForm" enctype="multipart/form-data"> 
                    <div class="modal-body"> 
                        <div class="mb-3"> <label for="meta_file_1" class="form-label">File Meta Ads 1 (.xlsx / .csv)</label> <input type="file" class="form-control" id="meta_file_1" name="meta_files[]" accept=".xlsx,.xls,.csv" required> </div> 
                        <div class="mb-3" id="meta_file_2_wrapper" style="display: none;"> <label for="meta_file_2" class="form-label">File Meta Ads 2 (.xlsx / .csv)</label> <input type="file" class="form-control" id="meta_file_2" name="meta_files[]" accept=".xlsx,.xls,.csv"> </div> 
                        <div class="mb-3"> <label for="adsense_file" class="form-label">File Google AdSense (.xlsx / .csv)</label> <input type="file" class="form-control" id="adsense_file" name="adsense_file" accept=".xlsx,.xls,.csv" required> </div>
                        <div class="alert alert-danger d-none" id="uploadError" role="alert"></div>
                    </div> 
                    <div class="modal-footer"> 
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button> 
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                            <span class="btn-text">Proses & Analisis</span>
                        </button> 
                    </div> 
                </form> 
            </div> 
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/v/bs5/dt-2.0.8/datatables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        function initializeHighlighters() { 
            document.querySelectorAll('.row-highlighter').forEach(checkbox => { 
                checkbox.removeEventListener('change', highlightRow); 
                checkbox.addEventListener('change', highlightRow); 
            }); 
        }
        
        function highlightRow() { 
            const row = this.closest('tr'); 
            this.checked ? row.classList.add('table-danger') : row.classList.remove('table-danger'); 
        }
        
        const uploadModal = document.getElementById('uploadModal');
        const myModal = new bootstrap.Modal(uploadModal);
        
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
            
            document.getElementById('uploadError').classList.add('d-none');
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.querySelector('.spinner-border').classList.add('d-none');
            submitBtn.querySelector('.btn-text').textContent = 'Proses & Analisis';
            submitBtn.disabled = false;
        });

        function formatRupiah(number) {
            return 'Rp ' + Number(number).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        }
        
        function formatPersen(number) {
            return Number(number).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
        }

        // --- GANTI FUNGSI LAMA DENGAN FUNGSI BARU INI ---
        function initDataTable() {
            // Hancurkan DataTable lama jika ada
            if ($.fn.DataTable.isDataTable('#roiTable')) {
                $('#roiTable').DataTable().destroy();
            }

            const tbody = document.querySelector('#roiTable tbody');
            const hasData = tbody && tbody.querySelector('tr') && !tbody.querySelector('tr td[colspan="7"]');
            
            // Hanya inisialisasi jika ada data
            if (hasData) {
                let table = new DataTable('#roiTable', {
                    columnDefs: [
                        // Kita hanya perlu matikan sorting di kolom yg tidak perlu
                        { orderable: false, targets: [0, 1, 6] } // Kolom Tandai, No, Saran
                    ],
                    order: [[ 2, 'asc' ]], // Default sort: Negara A-Z
                    paging: true, 
                    searching: true, 
                    info: true,
                    footerCallback: function ( row, data, start, end, display ) {} 
                });
                
                table.on('draw', function () { 
                    initializeHighlighters(); 
                });
            }
            // Panggil highlighter pertama kali
            initializeHighlighters();
        }
        // --------------------------------------------------

        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = document.getElementById('submitBtn');
            const spinner = submitBtn.querySelector('.spinner-border');
            const btnText = submitBtn.querySelector('.btn-text');
            const errorDiv = document.getElementById('uploadError');
            
            errorDiv.classList.add('d-none'); errorDiv.textContent = '';
            spinner.classList.remove('d-none');
            btnText.textContent = ' Memproses...';
            submitBtn.disabled = true;
            
            const formData = new FormData(this);
            
            fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) { throw new Error('Network response was not ok: ' + response.statusText); }
                return response.json();
            })
            .then(result => {
                
                // --- INI BAGIAN DEBUGGING ---
                console.log("Data mentah diterima dari server:", result);
                if (result.debug_metaGrouped) {
                    console.log("HASIL AGREGASI META ADS (setelah normalisasi):");
                    console.table(result.debug_metaGrouped);
                }
                if (result.debug_adsenseGrouped) {
                    console.log("HASIL AGREGASI ADSENSE (setelah normalisasi):");
                    console.table(result.debug_adsenseGrouped);
                }
                if (result.php_warnings) {
                    console.warn("PHP Warnings:", result.php_warnings);
                }
                // ---------------------------

                if (result.success) {
                    let html = '';
                    let rank = 1;
                    let totalSpending = 0;
                    let totalEarnings = 0;
                    
                    if (Object.keys(result.data).length > 0) {
                        for (const [country, data] of Object.entries(result.data)) {
                            totalSpending += data.total_spending;
                            totalEarnings += data.total_earnings;
                            
                            const roiClass = data.roi >= 0 ? 'text-success' : 'text-danger';
                            const saranBadge = data.roi < 100 
                                ? '<span class="badge bg-warning text-dark">Hapus Negara</span>'
                                : '<span class="badge bg-success">Pertahankan</span>';
                            
                            html += '<tr>' +
                                '<td class="text-center"><input class="form-check-input row-highlighter" type="checkbox"></td>' +
                                '<td>' + rank++ + '</td>' +
                                '<td>' + country + '</td>' +
                                '<td data-order="' + data.total_spending + '">' + formatRupiah(data.total_spending) + '</td>' +
                                '<td data-order="' + data.total_earnings + '">' + formatRupiah(data.total_earnings) + '</td>' +
                                '<td class="fw-bold" data-order="' + data.roi + '">' +
                                    '<span class="' + roiClass + '">' + formatPersen(data.roi) + '</span>' +
                                '</td>' +
                                '<td>' + saranBadge + '</td>' +
                                '</tr>';
                        }
                    } else {
                        html = '<tr><td colspan="7" class="text-center">Data tidak ditemukan.</td></tr>';
                    }
                    
                    document.querySelector('#roiTable tbody').innerHTML = html;
                    
                    const overallRoi = totalSpending > 0 ? (((totalEarnings - totalSpending) / totalSpending) * 100) : null;
                    const roiClass = overallRoi !== null && overallRoi >= 0 ? 'text-success' : 'text-danger';
                    const roiText = overallRoi !== null 
                        ? '<span class="' + roiClass + '">' + formatPersen(overallRoi) + '</span>'
                        : 'N/A';
                    
                    document.getElementById('totalSpending').innerHTML = formatRupiah(totalSpending);
                    document.getElementById('totalEarnings').innerHTML = formatRupiah(totalEarnings);
                    document.getElementById('overallRoi').innerHTML = roiText;
                    
                    initDataTable();
                    myModal.hide();
                    document.getElementById('uploadForm').reset();
                    
                    const successMsg = 'Berhasil! Data ' + result.count + ' negara telah diproses.';
                    showNotification(successMsg, 'success');
                    
                } else {
                    errorDiv.innerHTML = '<strong>Error!</strong><ul class="mb-0 mt-2">' + 
                        result.errors.map(err => `<li>${err}</li>`).join('') + 
                        '</ul>';
                    errorDiv.classList.remove('d-none');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                errorDiv.innerHTML = '<strong>Error!</strong> Terjadi kesalahan fatal saat mengirim data. Cek console log.';
                errorDiv.classList.remove('d-none');
            })
            .finally(() => {
                spinner.classList.add('d-none');
                btnText.textContent = 'Proses & Analisis';
                submitBtn.disabled = false;
            });
        });
        
        function showNotification(message, type = 'success') {
            const notificationArea = document.getElementById('notificationArea');
            const alertType = type === 'success' ? 'alert-success' : 'alert-danger';
            const alertHtml = `
                <div class="alert ${alertType} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
            notificationArea.innerHTML = alertHtml;
        }
        
        $(document).ready(function() {
            initDataTable();
        });
    </script>

    <footer class="mt-5 py-4 text-center">
         <div class="container"> <hr> <p class="text-muted mb-0"> © <?php echo date('Y'); ?> ROI Analyzer by Country - V1.4 </p> <small class="text-muted">Dibuat untuk Tim Analis</small> </div>
    </footer>
</body>
</html>