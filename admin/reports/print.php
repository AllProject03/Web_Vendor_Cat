<?php
/**
 * Laporan - Mode Cetak
 * Menggunakan seluruh query dan data dari index.php,
 * tetapi merender ulang stylesheet khusus print dan otomatis membuka dialog cetak.
 */

$_GET['print'] = '1';

ob_start();
require __DIR__ . '/index.php';
$html = ob_get_clean();

$printCss = <<<'CSS'
<style id="reports-print-style">
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

body.reports-page {
    background: #fff !important;
}

/* Hide application chrome and interactive controls */
.sidebar,
.topbar,
.report-filter,
.header-actions,
.table-actions,
.pagination,
.btn-primary,
.btn-secondary,
.btn-filter,
.reports-page .report-card .card-header a,
.reports-page .report-card .card-header button {
    display: none !important;
}

.main {
    margin-left: 0 !important;
    width: 100% !important;
    min-height: 0 !important;
}

.reports-page .page-container,
main.main > .page-container {
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
}

.reports-page .page-header {
    display: block !important;
    margin: 0 0 12px !important;
    padding: 0 0 10px !important;
    border-bottom: 2px solid #111827 !important;
}

.reports-page .breadcrumb {
    margin-bottom: 5px !important;
    color: #6b7280 !important;
    font-size: 9pt !important;
}

.reports-page .page-header h1 {
    margin: 0 0 3px !important;
    color: #111827 !important;
    font-size: 22pt !important;
}

.reports-page .page-header p {
    margin: 0 !important;
    color: #4b5563 !important;
    font-size: 10pt !important;
}

/* Summary cards */
.reports-page .summary-grid {
    grid-template-columns: repeat(4, 1fr) !important;
    gap: 10px !important;
    margin-bottom: 12px !important;
}

.reports-page .summary-card {
    padding: 10px 12px !important;
    border: 1px solid #d9dee7 !important;
    border-radius: 8px !important;
    box-shadow: none !important;
    break-inside: avoid !important;
}

.reports-page .summary-icon {
    width: 34px !important;
    height: 34px !important;
    flex-basis: 34px !important;
    font-size: 15px !important;
}

.reports-page .summary-card strong {
    font-size: 12pt !important;
    margin: 2px 0 !important;
}

.reports-page .summary-card span,
.reports-page .summary-card small {
    font-size: 8pt !important;
}

/* Report layout */
.reports-page .report-layout {
    grid-template-columns: 1.5fr 1fr !important;
    gap: 12px !important;
    margin-bottom: 12px !important;
}

.reports-page .report-card {
    border: 1px solid #d9dee7 !important;
    border-radius: 8px !important;
    box-shadow: none !important;
    break-inside: avoid !important;
}

.reports-page .card-header {
    padding: 10px 12px !important;
    border-bottom: 1px solid #e5e7eb !important;
}

.reports-page .card-header h2 {
    font-size: 11pt !important;
    margin: 0 0 2px !important;
}

.reports-page .card-header p {
    font-size: 8pt !important;
}

.reports-page .period-badge {
    font-size: 8pt !important;
    padding: 4px 7px !important;
}

/* Keep chart and branch performance compact */
.reports-page .chart-area {
    height: 170px !important;
    padding: 10px 12px !important;
}

.reports-page .y-labels {
    width: 40px !important;
    font-size: 7pt !important;
}

.reports-page .bar-wrap {
    font-size: 7pt !important;
}

.reports-page .bar {
    width: min(28px, 65%) !important;
}

.reports-page .branch-list {
    padding: 5px 12px 10px !important;
}

.reports-page .branch-row {
    padding: 8px 0 !important;
    grid-template-columns: 1fr 90px 30px !important;
}

.reports-page .branch-row strong {
    font-size: 8pt !important;
}

.reports-page .branch-row span,
.reports-page .branch-row b {
    font-size: 7pt !important;
}

/* Table */
.reports-page .table-card {
    overflow: visible !important;
}

.reports-page .table-responsive {
    overflow: visible !important;
}

#reportTable {
    width: 100% !important;
    min-width: 0 !important;
    border-collapse: collapse !important;
}

#reportTable th {
    background: #f3f4f6 !important;
    color: #111827 !important;
    padding: 7px 8px !important;
    font-size: 7.5pt !important;
    border: 1px solid #d1d5db !important;
}

#reportTable td {
    color: #111827 !important;
    padding: 7px 8px !important;
    font-size: 7.5pt !important;
    border: 1px solid #d1d5db !important;
    white-space: normal !important;
}

#reportTable tfoot td {
    background: #f3f4f6 !important;
    font-weight: 700 !important;
}

.reports-page .status {
    border: 0 !important;
    padding: 0 !important;
    background: transparent !important;
    color: #111827 !important;
    font-size: 7.5pt !important;
}

.reports-page .status::before {
    content: '' !important;
}

/* Prevent awkward splits */
.reports-page .summary-card,
.reports-page .report-card,
#reportTable tr {
    break-inside: avoid !important;
    page-break-inside: avoid !important;
}

.reports-page .chart-empty {
    min-height: 130px !important;
}

/* Print footer */
.reports-page::after {
    content: 'Dicetak pada ' attr(data-print-date);
    display: block;
    margin-top: 8px;
    padding-top: 6px;
    border-top: 1px solid #d1d5db;
    color: #6b7280;
    font-size: 7.5pt;
}

@media screen {
    body.reports-page {
        padding: 18px !important;
        background: #eef2f7 !important;
    }

    body.reports-page .page-container {
        background: #fff !important;
        padding: 24px !important;
        max-width: 1400px !important;
        margin: 0 auto !important;
        box-shadow: 0 8px 30px rgba(15,23,42,.08) !important;
    }
}
</style>
CSS;

$printScript = <<<'JS'
<script>
(function () {
    document.body.setAttribute('data-print-date', new Date().toLocaleString('id-ID'));
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
echo $html;
