<?php
declare(strict_types=1);

/**
 * Diagnóstico: favicon / logo en pestañas del navegador.
 *
 * Uso: php scripts/diagnose_favicon.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Core\Url;

AppConfig::boot();

$root = dirname(__DIR__);
$logoRel = 'img/logo-Photoroom.png';
$logoAbs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logoRel);
$layout = $root . '/app/Views/layouts/main.php';
$basePath = Url::basePath();
$publicUrl = $basePath . '/' . $logoRel;

$errors = [];
$warnings = [];
$ok = [];

echo "=== Diagnóstico favicon (logo pestañas) ===" . PHP_EOL . PHP_EOL;

if (is_file($logoAbs)) {
    $size = filesize($logoAbs);
    $ok[] = "Archivo existe: {$logoAbs} (" . number_format((int)$size / 1024, 1) . ' KB)';
    if ($size > 512000) {
        $warnings[] = 'El PNG pesa más de 500 KB; los navegadores lo cargan, pero conviene un .ico o PNG ~32–64 px para rendimiento.';
    }
} else {
    $errors[] = "No existe el archivo: {$logoAbs}";
}

if (is_readable($logoAbs)) {
    $ok[] = 'El archivo es legible por PHP.';
} else {
    $errors[] = 'El archivo no es legible (permisos).';
}

$layoutHtml = is_file($layout) ? (string)file_get_contents($layout) : '';
if ($layoutHtml === '') {
    $errors[] = "No se pudo leer el layout: {$layout}";
} else {
    $hasIcon = (bool)preg_match('/rel\s*=\s*["\'](?:shortcut )?icon["\']/i', $layoutHtml);
    $hasLogoRef = str_contains($layoutHtml, 'logo-Photoroom');
    if ($hasIcon && $hasLogoRef) {
        $ok[] = 'main.php incluye <link rel="icon"> apuntando al logo.';
    } elseif ($hasIcon) {
        $warnings[] = 'main.php tiene rel="icon" pero no referencia logo-Photoroom.png.';
    } else {
        $errors[] = 'main.php NO tiene <link rel="icon"> — el navegador usa el favicon por defecto (p. ej. XAMPP).';
    }
}

$viewFile = $root . '/app/Core/View.php';
$viewPhp = is_file($viewFile) ? (string)file_get_contents($viewFile) : '';
if (str_contains($viewPhp, 'layouts/main.php')) {
    $ok[] = 'Todas las vistas pasan por app/Views/layouts/main.php (un solo lugar para el favicon).';
} else {
    $warnings[] = 'Revisar View.php: podría haber layouts sin favicon.';
}

echo 'URL pública esperada: http://localhost' . $publicUrl . PHP_EOL;
echo 'base_path config: ' . $basePath . PHP_EOL . PHP_EOL;

foreach ($ok as $msg) {
    echo '[OK] ' . $msg . PHP_EOL;
}
foreach ($warnings as $msg) {
    echo '[WARN] ' . $msg . PHP_EOL;
}
foreach ($errors as $msg) {
    echo '[ERROR] ' . $msg . PHP_EOL;
}

echo PHP_EOL;
if ($errors === []) {
    echo 'Conclusión: configuración lista; recarga con Ctrl+F5 si la pestaña seguía en caché.' . PHP_EOL;
    exit(0);
}

echo 'Conclusión: corregir los [ERROR] anteriores (normalmente añadir rel="icon" en main.php).' . PHP_EOL;
exit(1);
