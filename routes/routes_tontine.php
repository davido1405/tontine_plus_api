<?php
require_once (__DIR__ . '/../controllers/tontine.php');
require_once (__DIR__ . '/../controllers/type_tontine.php');
require_once __DIR__ . '/../vendor/autoload.php';
use Firebase\JWT\JWT;
if(isset($_GET['action'])){
    $action=$_GET['action'];

    switch($action){
        case 'creer_tontine':
            create_tontine();
            break;
        case 'details_tontine':
            get_tontine_details();
            break;
        case 'liste_tontines':
            toutes_tontines();
            break;
        case 'lister_membres':
            listeParticipants();
            break;
        case 'lister_frequence':
            lister_frequence();
            break;
        case 'liste_tours':
            lister_tour();
            break;
        case 'wallet_infos':
            wallet_tontine_infos();
            break;
        case 'transactions':
            transactions();
            break;
        case 'ordre_paiement':
            ordrePaiement();
            break;
        case 'retirer':
            retrait();
            break;
        case 'inviter':
            GenererlienInvitation();
            break;
        case 'verifierInvitation':
            verifierInvitation();
            break;
        default:
            send_response(false,"Action incconue pour la tontine");
    }
}else{
    send_response(false, "Aucune action précisée !");
}

