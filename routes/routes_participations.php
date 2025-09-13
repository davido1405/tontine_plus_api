<?php
require_once (__DIR__ . '/../controllers/participations.php');
require_once __DIR__ . '/../vendor/autoload.php';
use Firebase\JWT\JWT;

if(isset($_GET['action'])){
    $action=$_GET['action'];

    switch($action){
        case 'participer':
            ajouter_participation();
            break;
        case 'verifier_participation':
            verifier_participation();
            break;
        case 'liste_participation':
            lister_participation();
            break;
        default:
            send_response(false,"Action incconue pour participation");

    }
}else{
    send_response(false, "Aucune action précisée !");
}

?>