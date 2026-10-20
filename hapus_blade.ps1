# Jalankan dari folder root repo (yang berisi folder platform). Menghapus UI Blade lama yang diganti Filament.
$files = @(
  'platform\app\Http\Controllers\AuditPageController.php',
  'platform\app\Http\Middleware\EnsureAuditUi.php',
  'platform\resources\views\layouts',
  'platform\resources\views\components',
  'platform\resources\views\audit'
)
foreach ($f in $files) { if (Test-Path $f) { Remove-Item $f -Recurse -Force; "dihapus: $f" } }
