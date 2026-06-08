<?php
// router.php — Seguridad para php -S (no lee .htaccess)
// Bloquea acceso directo a directorios sensibles.

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rawurldecode($uri);

$blocked = ['/data/', '/cache/', '/backups/', '/logs/', '/.git/', '/includes/'];

foreach ($blocked as $dir) {
    if ($path === rtrim($dir, '/') || str_starts_with($path, $dir)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo '403 Forbidden';
        return true;
    }
}

// Archivos estáticos existentes: dejar que PHP los sirva directamente
if ($path !== '/' && file_exists(__DIR__ . $path) && !is_dir(__DIR__ . $path)) {
    return false;
}

// Todo lo demás va al entry-point correcto
$file = ltrim($path, '/');
if ($file === '' || $file === 'index') {
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
    require __DIR__ . '/index.php';
    return true;
}

if (is_file(__DIR__ . '/' . $file . '.php')) {
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/' . $file . '.php';
    require __DIR__ . '/' . $file . '.php';
    return true;
}

return false;
