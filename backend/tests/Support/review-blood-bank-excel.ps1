# Read-only synthetic workbook preview using the installed Microsoft Excel.
param([string]$ArtifactDirectory, [string[]]$Names = @('list','donor','recipient','donation'))
$ErrorActionPreference='Stop'
$reviewRoot=if ($ArtifactDirectory) { [IO.Path]::GetFullPath($ArtifactDirectory) } else { [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../../frontend/.superdesign/blood-bank-reports')) }
$reviewExcel=New-Object -ComObject Excel.Application
$reviewExcel.Visible=$false
$reviewExcel.DisplayAlerts=$false
$reviewExcel.EnableEvents=$false
$reviewExcel.AutomationSecurity=3
try {
    $printer=(Get-ItemProperty -LiteralPath 'HKCU:\Software\Microsoft\Windows NT\CurrentVersion\Devices').'Microsoft Print to PDF'
    if (-not $printer) { throw 'Microsoft Print to PDF is required for preview.' }
    foreach ($name in $Names) {
        $reviewBook=$null
        try {
            $reviewBook=$reviewExcel.Workbooks.Open((Join-Path $reviewRoot ($name+'.xlsx')),0,$true)
            $reviewExcel.ActivePrinter='Microsoft Print to PDF on '+($printer -split ',')[-1]
            foreach ($sheet in $reviewBook.Worksheets) {
                if ($sheet.UsedRange.Font.Name -ne 'Cairo') { throw "Unexpected font in $name" }
                Write-Output "$name / $($sheet.Name): Microsoft Excel $($reviewExcel.Version) build $($reviewExcel.Build), Cairo, $($sheet.UsedRange.Rows.Count) rows"
                [void][Runtime.InteropServices.Marshal]::ReleaseComObject($sheet)
            }
            $reviewBook.ExportAsFixedFormat(0,(Join-Path $reviewRoot ($name+'-excel-preview.pdf')))
        } finally {
            if ($reviewBook) { $reviewBook.Close($false); [void][Runtime.InteropServices.Marshal]::ReleaseComObject($reviewBook) }
        }
    }
} finally {
    $reviewExcel.Quit()
    [void][Runtime.InteropServices.Marshal]::ReleaseComObject($reviewExcel)
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}
