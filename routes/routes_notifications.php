<?php
require_once (__DIR__ . '/../controllers/notifications.php');
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;

if (isset($_GET['action'])) {
    $action = $_GET['action'];

    switch ($action) {

        case 'envoyer_notification_generale':
            envoyer_notification_generale();
            break;

        case 'envoyer_notification_penlaite':
            envoyer_notification_penlaite();
            break;

        case 'envoyer_rappel_cotisation':
            envoyer_rappel_cotisation();
            break;
        case 'lister_notification':
            lister_notification();
            break;

        case 'supprimer_notification':
            supprimer_notification();
            break;

         case 'lire_notification':
            lire_notification();
            break;

        default:
            send_response(false, "Action inconnue pour notifications.");
    }

} else {
    send_response(false, "Aucune action précisée !");
}
