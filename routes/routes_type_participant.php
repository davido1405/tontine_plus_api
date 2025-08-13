<?php
require_once (__DIR__ . '/../controllers/type_participants.php');

if(isset($_GET['action'])){
    $action=$_GET['action'];

    switch($action){
        case 'type_participant_dispo':
            lister_type_participant();
            break;
        case 'ajouter_type_participant':
            ajouter_type_participant();
            break;
        case 'modifier_type_participant':
            modifier_type_participant();
            break;
        case 'supprimer_type_participant':
            supprimer_type_participant();
            break;
        default:
            send_response(false,"Action incconue pour la participant");

    }
}else{
    send_response(false, "Aucune action précisée !");
}

?>