<?php
require_once (__DIR__ . '/../controllers/participants.php');

if(isset($_GET['action'])){
    $action=$_GET['action'];

    switch($action){
        case 'inscrir_participant':
            register_participant();
            break;
        case 'connexion_participant':
            login_participant();
            break;
        case 'profil_participant':
            get_profil();
            break;
        case 'update_profil_participant':
            update_profil();
            break;
        case 'supprimer_profil_participant':
            delete_participant();
            break;

        case 'mon_tour':
            monTour();
            break;
            
        default:
            send_response(false,"Action incconue pour participant");

    }
}else{
    send_response(false, "Aucune action précisée !");
}

?>