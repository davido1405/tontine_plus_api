<?php
require_once __DIR__ . '/../controllers/cotisations.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;

if(isset($_GET['action'])){
    $action=$_GET['action'];

    switch($action){
        case 'payer_cotisation':
            payer_cotisation();
            break;
        case 'payer_penalite':
            payer_penalite();
            break;
        case 'voir_mes_cotisations':
            voir_mes_cotisations();
            break;
        case 'voir_mes_penalites':
            voir_mes_penalites();
            break;
        case 'total_cotisation':
            total_cotisation();
            break;
        case 'total_penalite':
            total_penalite();
            break;
        case 'preview_cotisation':
            repartition_cotisation();
            break;
        default:
            send_response(false,"Action incconue pour la cotisation");
    }
}else{
    send_response(false, "Aucune action précisée !");
}
