<?php
function getDB() {

    //Pour bosser hors ligne
    //$host = "localhost";
    //$dbname = "tontine_plus";
    //$username = "root";
    //$password = "";

    //Pour bosser sur le server
    $host = "mysql-tontineplus.alwaysdata.net";
    $dbname = "tontineplus_tontine_plus";
    $username = "427318";
    $password = "0575006528@@";

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => "Erreur de connexion : " . $e->getMessage()]);
        exit();
    }
}
?>
