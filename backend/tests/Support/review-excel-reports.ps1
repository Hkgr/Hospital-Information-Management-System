# Requires local Microsoft Excel with Cairo and Microsoft Print to PDF installed. Opens only synthetic samples,
# with macros/events/links disabled, in a dedicated instance (visible only for sheet captures).
param([string]$Pattern = '*.xlsx', [ValidateSet('current','before','after')][string]$Stage = 'current', [switch]$CaptureSheets, [switch]$MeasureRows)
$ErrorActionPreference = 'Stop'
$reportRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../docs/samples'))
if ($Stage -ne 'current') { $reportRoot = Join-Path $reportRoot ('comparison/' + $Stage) }
$excelReview = New-Object -ComObject Excel.Application
$excelReview.Visible = $false
if ($CaptureSheets) { $excelReview.Visible = $true }
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
                    Write-Output "$module/$($report.Name): $($worksheetReview.Name), Cairo, $($worksheetReview.UsedRange.Rows.Count) rows, opened by Microsoft Excel $($excelReview.Version) build $($excelReview.Build)"
                    if ($MeasureRows -and $worksheetReview.Visible -eq -1) {
                        $firstDataRow = if ($worksheetReview.Index -eq 1) { 9 } else { 3 }
                        $lastDataRow = $worksheetReview.UsedRange.Rows.Count
                        if ($worksheetReview.Index -eq 1) { $lastDataRow = [Math]::Min(12, $lastDataRow) }
                        foreach ($rowNumber in $firstDataRow..$lastDataRow) {
                            $rowReview = $worksheetReview.Rows.Item($rowNumber)
                            $originalHeight = $rowReview.RowHeight
                            [void]$rowReview.AutoFit()
                            Write-Output "Row $rowNumber height: generated=$originalHeight pt; Excel AutoFit=$($rowReview.RowHeight) pt"
                            $rowReview.RowHeight = $originalHeight
                            [void][Runtime.InteropServices.Marshal]::ReleaseComObject($rowReview)
                        }
                    }
                    if ($CaptureSheets -and $worksheetReview.Visible -eq -1) {
                        $worksheetReview.Activate()
                        $captureRange = $worksheetReview.Range($worksheetReview.Cells.Item(1, 1), $worksheetReview.Cells.Item([Math]::Min(12, $worksheetReview.UsedRange.Rows.Count), $worksheetReview.UsedRange.Columns.Count))
                        $captureRange.CopyPicture(1, 2)
                        $chartReview = $worksheetReview.ChartObjects().Add(0, 0, $captureRange.Width, $captureRange.Height)
                        try {
                            [void]$chartReview.Activate()
                            [void]$chartReview.Chart.Paste()
                            [void]$chartReview.Chart.Refresh()
                            $imagePath = Join-Path $report.DirectoryName ($report.BaseName + '-sheet-' + $worksheetReview.Index + '.png')
                            [void]$chartReview.Chart.Export($imagePath, 'PNG')
                        } finally { $chartReview.Delete(); [void][Runtime.InteropServices.Marshal]::ReleaseComObject($chartReview) }
                        [void][Runtime.InteropServices.Marshal]::ReleaseComObject($captureRange)
                    }
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
