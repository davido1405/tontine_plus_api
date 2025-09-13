<?php
require_once (__DIR__ . '/../controllers/type_tontine.php');
require_once __DIR__ . '/../vendor/autoload.php';
use Firebase\JWT\JWT;

if(isset($_GET['action'])){
    $action=$_GET['action'];

    switch($action){
        case 'type_dispo':
            lister_type_tontine();
            break;
        case 'ajouter_type':
            ajouter_type_tontine();
            break;
        case 'modifier_type':
            modifier_type_tontine();
            break;
        case 'supprimer_type':
            supprimer_type_tontine();
            break;
        case 'lister_frequence':
            lister_frequence();
            break;
        case 'lister_frequence_paiement':
            lister_frequence_paiement();
            break;
        default:
            send_response(false,"Action incconue pour le type de tontine");

    }
}else{
    send_response(false, "Aucune action précisée !");
}

?>