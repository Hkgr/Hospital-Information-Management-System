# Requires local Microsoft Excel with Cairo and Microsoft Print to PDF installed. Opens only synthetic samples,
# with macros/events/links disabled, in a dedicated invisible Excel instance.
param([string]$Pattern = '*.xlsx')
$ErrorActionPreference = 'Stop'
$reportRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../docs/samples'))
$excelReview = New-Object -ComObject Excel.Application
$excelReview.Visible = $false
$excelReview.DisplayAlerts = $false
$excelReview.EnableEvents = $false
$excelReview.AutomationSecurity = 3
try {
    # Avoid a hidden Printer Setup dialog when the Windows default is an offline printer.
    # This selects the printer in our Excel instance; it does not change Windows defaults.
    $printerMapping = (Get-ItemProperty -LiteralPath 'HKCU:\Software\Microsoft\Windows NT\CurrentVersion\Devices').'Microsoft Print to PDF'
    if (-not $printerMapping) { throw 'Microsoft Print to PDF is required for the local print preview.' }
    foreach ($module in @('doctors','clinics')) {
        foreach ($report in Get-ChildItem -LiteralPath (Join-Path $reportRoot $module) -Filter $Pattern) {
            $workbookReview = $null
            try {
                $workbookReview = $excelReview.Workbooks.Open($report.FullName, 0, $true)
                $excelReview.ActivePrinter = 'Microsoft Print to PDF on ' + ($printerMapping -split ',')[-1]
                foreach ($worksheetReview in $workbookReview.Worksheets) {
                    if ($worksheetReview.UsedRange.Font.Name -ne 'Cairo') { throw "Unexpected font in $($report.Name)" }
                    Write-Output "$module/$($report.Name): $($worksheetReview.Name), Cairo, $($worksheetReview.UsedRange.Rows.Count) rows, opened by Microsoft Excel $($excelReview.Version)"
                    [void][Runtime.InteropServices.Marshal]::ReleaseComObject($worksheetReview)
                }
                $previewPath = Join-Path $report.DirectoryName ($report.BaseName + '-excel-preview.pdf')
                $workbookReview.ExportAsFixedFormat(0, $previewPath)
            } finally {
                if ($workbookReview) { $workbookReview.Close($false); [void][Runtime.InteropServices.Marshal]::ReleaseComObject($workbookReview) }
            }
        }
    }
} finally {
    $excelReview.Quit()
    [void][Runtime.InteropServices.Marshal]::ReleaseComObject($excelReview)
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}
