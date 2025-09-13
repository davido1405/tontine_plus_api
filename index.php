<?php
// Fichier index.php à la racine de l’API
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;


header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Gérer les prévols (pré-requêtes OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Récupérer la ressource principale de l'URL (ex: "tontines", "cotisations")
$ressource = $_GET['ressource'] ?? null;

if (!$ressource) {
    echo json_encode(["success" => false, "message" => "Aucune ressource précisée."]);
    exit();
}

// Diriger vers le bon fichier de routes selon la ressource
switch ($ressource) {
    case 'tontines':
        require_once __DIR__ . '/routes/routes_tontine.php';
        break;

    case 'participants':
        require_once __DIR__ . '/routes/routes_participants.php';
        break;

    case 'cotisations':
        require_once __DIR__ . '/routes/routes_cotisations.php';
        break;

    case 'notifications':
        require_once __DIR__ . '/routes/routes_notifications.php';
        break;

    case 'participations':
        require_once __DIR__ . '/routes/routes_participations.php';
        break;

    case 'type_participants':
        require_once __DIR__ . '/routes/routes_type_participant.php';
        break;

    case 'type_tontine':
        require_once __DIR__ . '/routes/routes_type_tontine.php';
        break;


    default:
        echo json_encode(["success" => false, "message" => "Ressource inconnue : $ressource"]);
        break;
}
