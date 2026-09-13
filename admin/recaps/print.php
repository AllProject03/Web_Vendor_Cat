<?php
/**
 * Rekap Per Cabang - Mode Cetak
 * Menggunakan seluruh query dan data dari index.php,
 * tetapi merender ulang stylesheet khusus print dan otomatis membuka dialog cetak.
 */

$_GET['print'] = '1';
ob_start();
require __DIR__ . '/index.php';
$html = ob_get_clean();

$printCss = <<<'CSS'
<style id="recaps-print-style">
@page {
    size: A4 landscape;
    margin: 12mm;
}

html,
body {
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
    color: #111827 !important;
    font-family: Inter, Arial, sans-serif !important;
}

body.recaps-page {
    background: #fff !important;
}

/* Hide application chrome and interactive controls */
.recaps-page .sidebar,
.recaps-page .topbar,
.recaps-page .header-actions,
.recaps-page .recap-filter,
.recaps-page .table-actions,
.recaps-page .pagination,
.recaps-page .btn-primary,
.recaps-page .btn-secondary,
.recaps-page .btn-filter {
    display: none !important;
}

.recaps-page .main {
    margin-left: 0 !important;
    width: 100% !important;
    min-height: 0 !important;
}

.recaps-page .page-container {
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
}

.recaps-page .page-header {
    display: block !important;
    margin: 0 0 10px !important;
    padding: 0 0 8px !important;
    border-bottom: 2px solid #111827 !important;
}

.recaps-page .breadcrumb {
    margin-bottom: 4px !important;
    color: #6b7280 !important;
    font-size: 8pt !important;
}

.recaps-page .page-header h1 {
    margin: 0 0 3px !important;
    color: #111827 !important;
    font-size: 20pt !important;
}

.recaps-page .page-header p {
    margin: 0 !important;
    color: #4b5563 !important;
    font-size: 9pt !important;
}

/* Print heading */
.recaps-page .print-heading {
    display: flex !important;
    flex-direction: column !important;
    gap: 2px !important;
    margin: 0 0 10px !important;
    padding: 0 0 8px !important;
    border-bottom: 1px solid #d1d5db !important;
    color: #111827 !important;
}

.recaps-page .print-heading strong {
    font-size: 14pt !important;
    letter-spacing: .03em !important;
}

.recaps-page .print-heading span {
    font-size: 8pt !important;
    color: #6b7280 !important;
}

/* Summary cards */
.recaps-page .summary-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    gap: 8px !important;
    margin-bottom: 8px !important;
}

.recaps-page .summary-card {
    padding: 9px 10px !important;
    border: 1px solid #d9dee7 !important;
    border-radius: 7px !important;
    box-shadow: none !important;
    break-inside: avoid !important;
    page-break-inside: avoid !important;
}

.recaps-page .summary-icon {
    width: 30px !important;
    height: 30px !important;
    flex-basis: 30px !important;
    font-size: 13px !important;
}

.recaps-page .summary-card span {
    font-size: 7pt !important;
    margin-bottom: 2px !important;
}

.recaps-page .summary-card strong {
    font-size: 10pt !important;
    margin: 1px 0 !important;
}

.recaps-page .summary-card small {
    font-size: 6.5pt !important;
}

/* Mini summary */
.recaps-page .summary-mini-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    gap: 8px !important;
    margin-bottom: 8px !important;
}

.recaps-page .mini-card {
    padding: 8px 10px !important;
    border: 1px solid #d9dee7 !important;
    border-radius: 7px !important;
    box-shadow: none !important;
    break-inside: avoid !important;
    page-break-inside: avoid !important;
}

.recaps-page .mini-card span {
    font-size: 7pt !important;
}

.recaps-page .mini-card strong {
    font-size: 10pt !important;
    margin-top: 4px !important;
}

.recaps-page .mini-card small {
    font-size: 6.5pt !important;
}

/* Dashboard */
.recaps-page .dashboard-grid {
    grid-template-columns: 1.5fr 1fr !important;
    gap: 8px !important;
    margin-bottom: 8px !important;
}

.recaps-page .report-card {
    border: 1px solid #d9dee7 !important;
    border-radius: 7px !important;
    box-shadow: none !important;
    break-inside: avoid !important;
    page-break-inside: avoid !important;
}

.recaps-page .card-header {
    padding: 8px 10px !important;
    border-bottom: 1px solid #e5e7eb !important;
}

.recaps-page .card-header h2 {
    font-size: 8.5pt !important;
    margin: 0 0 2px !important;
}

.recaps-page .card-header p {
    font-size: 6.5pt !important;
    margin: 2px 0 0 !important;
}

.recaps-page .performance-list {
    padding: 8px 10px !important;
}

.recaps-page .performance-row {
    padding-bottom: 6px !important;
    margin-bottom: 6px !important;
}

.recaps-page .performance-top strong,
.recaps-page .performance-top span {
    font-size: 7pt !important;
}

.recaps-page .progress {
    height: 6px !important;
}

.recaps-page .performance-row small {
    margin-top: 3px !important;
    font-size: 6pt !important;
}

.recaps-page .snapshot-list {
    padding: 0 10px 5px !important;
}

.recaps-page .snapshot-list > div {
    padding: 5px 0 !important;
}

.recaps-page .snapshot-list span,
.recaps-page .snapshot-list strong {
    font-size: 7pt !important;
}

.recaps-page .snapshot-note {
    margin: 0 10px 8px !important;
    padding: 6px 7px !important;
    font-size: 6pt !important;
    line-height: 1.35 !important;
}

/* Table */
.recaps-page .table-card {
    overflow: visible !important;
    margin-bottom: 0 !important;
}

.recaps-page .table-responsive {
    overflow: visible !important;
}

.recaps-page table {
    width: 100% !important;
    min-width: 0 !important;
    border-collapse: collapse !important;
    table-layout: fixed !important;
}

.recaps-page thead th {
    background: #f3f4f6 !important;
    color: #111827 !important;
    padding: 6px 5px !important;
    font-size: 6.5pt !important;
    border: 1px solid #d1d5db !important;
    white-space: nowrap !important;
}

.recaps-page tbody td {
    color: #111827 !important;
    padding: 6px 5px !important;
    font-size: 6.5pt !important;
    border: 1px solid #d1d5db !important;
    white-space: normal !important;
    vertical-align: middle !important;
}

.recaps-page tbody td strong {
    color: #111827 !important;
    font-size: 6.5pt !important;
}

.recaps-page tbody td small {
    color: #6b7280 !important;
    font-size: 5.5pt !important;
    margin-top: 1px !important;
}

.recaps-page .status {
    border: 0 !important;
    padding: 0 !important;
    min-width: 0 !important;
    background: transparent !important;
    color: #111827 !important;
    font-size: 6.5pt !important;
}

.recaps-page .transfer-count {
    font-size: 6pt !important;
}

.recaps-page .transfer-count.incoming {
    margin-top: 2px !important;
}

.recaps-page .transfer-count i {
    font-size: 6pt !important;
}

.recaps-page .empty-state {
    min-height: 80px !important;
    padding: 10px !important;
}

/* Prevent awkward splits */
.recaps-page .summary-card,
.recaps-page .mini-card,
.recaps-page .report-card,
.recaps-page table tr {
    break-inside: avoid !important;
    page-break-inside: avoid !important;
}

@media screen {
    body.recaps-page {
        padding: 18px !important;
        background: #eef2f7 !important;
    }

    body.recaps-page .page-container {
        background: #fff !important;
        padding: 24px !important;
        max-width: 1400px !important;
        margin: 0 auto !important;
        box-shadow: 0 8px 30px rgba(15, 23, 42, .08) !important;
    }
}
</style>
CSS;

$printScript = <<<'JS'
<script>
(function () {
    document.body.classList.add('recaps-print-mode');
    window.addEventListener('load', function () {
        setTimeout(function () {
            window.print();
        }, 300);
    });
})();
</script>
JS;

$html = str_replace('</head>', $printCss . "\n</head>", $html);
$html = str_replace('</body>', $printScript . "\n</body>", $html);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

echo $html;
