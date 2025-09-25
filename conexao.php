<?php
$host = "caboose.proxy.rlwy.net";
$user = "root";
$password = "GXccXsOkyfFEJUBWDwaALivuPWPHwYgP";
$port = 46551;
$db = "railway"; // ajuste se o nome do schema for outro

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // echo "Conexão bem sucedida!"; // teste
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}
