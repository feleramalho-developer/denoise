<?php
$host = "caboose.proxy.rlwy.net";
$user = "root";
$password = "GXccXsOkyfFEJUBWDwaALivuPWPHwYgP";
$port = 46551;
$db = "railway"; // ajuste se o nome do schema for outro

$mysqli = new mysqli($host, $user, $password, $db, $port);

if ($mysqli->connect_error) {
    die("Falha na conexão: " . $mysqli->connect_error);
}
?>