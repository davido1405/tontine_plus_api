<?php
function getDB() {
    $host = "localhost";
    $dbname = "tontine_plus";
    $username = "root";
    $password = "";

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
