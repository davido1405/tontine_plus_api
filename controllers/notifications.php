<?php
include_once __DIR__ . '/../config/db.php';

include_once __DIR__ . '/../helpers/responses.php';



function envoyer_notification_generale(){
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['code_tontine'], $data['type_notification'], $data['contenu_notification']) || empty($data['code_tontine'])||empty($data['type_notification']) ||empty($data['contenu_notification'])) {
        send_response(false, "Champs obligatoires manquants.");
    }

    $pdo = getDB();

    // Récupérer tous les participants de la tontine
    $stmt = $pdo->prepare("SELECT code_participant FROM participer WHERE code_tontine = ?");
    $stmt->execute([$data['code_tontine']]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$participants) {
        send_response(false, "Aucun participant trouvé pour cette tontine.");
    }

    // Récupérer l'ID du type de notification
    $stmtType = $pdo->prepare("SELECT id_type_notification FROM type_notification WHERE type_notification = ?");
    $stmtType->execute([$data['type_notification']]);
    $type = $stmtType->fetch(PDO::FETCH_ASSOC);

    if (!$type) {
        send_response(false, "Type de notification invalide.");
    }

    $date_envoie = date("Y-m-d H:i:s");
    $successCount = 0;

    // Envoyer une notification à chaque participant
    foreach ($participants as $participant) {
        $stmtNotif = $pdo->prepare("
            INSERT INTO notifications (contenu_notification, code_participant, date_envoie, id_type_notification, code_tontine)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmtNotif->execute([
            $data['contenu_notification'],
            $participant['code_participant'],
            $date_envoie,
            $type['id_type_notification'],
            $data['code_tontine']
        ]);

        if ($stmtNotif->rowCount() > 0) {
            $successCount++;
        }
    }

    send_response(true, "$successCount notifications envoyées avec succès.");
}



function envoyer_notification_penlaite(){
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['code_tontine'], $data['type_notification'])) {
        send_response(false, "Champs obligatoires manquants.");
    }

    $pdo = getDB();

    // Récupérer l'ID du type de notification
    $stmtType = $pdo->prepare("SELECT id_type_notification FROM type_notification WHERE type_notification = ?");
    $stmtType->execute([$data['type_notification']]);
    $type = $stmtType->fetch(PDO::FETCH_ASSOC);

    if (!$type) {
        send_response(false, "Type de notification invalide.");
    }

    //Récupérer toutes les pénalités de paiement

    $stmtPenali = $pdo->prepare("SELECT * FROM penalites  WHERE code_tontine=? AND statut=?");
    $stmtPenali->execute([$data['code_tontine'],"Non payée"]);
    $penalites = $stmtPenali->fetchAll(PDO::FETCH_ASSOC);

    if(!$penalites){
        send_response(false,"Vous n'avez aucune pénalité de retard à payée");
    }

    foreach($penalites as $penalite){
        $contenu="Vous avez ".$penalite['montant']." de pénalité de retard veuillez vous en acquité au plus vite. Merci";

        //Envoie de la notification
        $stmt = $pdo->prepare("
            INSERT INTO notifications (contenu_notification, code_participant, date_envoie, id_type_notification, code_tontine)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $contenu,
            $penalite['code_participant'],
            date("Y-m-d H:i:s"),
            $type['id_type_notification'],
            $data['code_tontine']
        ]);

    }

    if ($stmt->rowCount() > 0) {
        send_response(true, "Notification envoyée avec succès.");
    } else {
        send_response(false, "Échec de l'envoi de la notification.");
    }
}

//Envoyer des notifications générales

function envoyer_rappel_cotisation(){
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['code_tontine'], $data['type_notification'])) {
        send_response(false, "Champs obligatoires manquants.");
    }

    $pdo = getDB();

    // Récupérer l'ID du type de notification
    $stmtType = $pdo->prepare("SELECT id_type_notification FROM type_notification WHERE type_notification = ?");
    $stmtType->execute([$data['type_notification']]);
    $type = $stmtType->fetch(PDO::FETCH_ASSOC);

    if (!$type) {
        send_response(false, "Type de notification invalide.");
    }

    // Récupérer tous les participants à une tontine
    $stmtParticipe = $pdo->prepare("SELECT p.*,t.montant_cotisation FROM participer p
    INNER JOIN tontine t ON p.code_tontine=t.code_tontine WHERE p.code_tontine = ?");
    $stmtParticipe->execute([$data['code_tontine']]);
    $participants = $stmtParticipe->fetchAll(PDO::FETCH_ASSOC);

    if (!$participants) {
        send_response(false, "Cette tontine n'a aucun participant pour l'instant");
    }


    foreach($participants as $participant){

        $contenu="Pensez à payer votre cotisation ".$participant['montant_cotisation'].". Merci !";
        $stmt = $pdo->prepare("
        INSERT INTO notifications (contenu_notification, code_participant, date_envoie, id_type_notification, code_tontine)
        VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $contenu,
            $participant['code_participant'],
            date("Y-m-d H:i:s"),
            $type['id_type_notification'],
            $data['code_tontine']
        ]);
    }

    if ($stmt->rowCount() > 0) {
        send_response(true, "Rappel envoyée avec succès.");
    } else {
        send_response(false, "Échec de l'envoi des rappels.");
    }
}



function lister_notification1(){
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['code_participant'])||empty($data['code_participant'])) {
        send_response(false, "Code participant requis.");
    }

    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT n.id_notification, n.contenu_notification, n.date_envoie, 
               s.statut_notification, t.type_notification
        FROM notifications n
        INNER JOIN statut_notification s ON n.id_statut_notification = s.id_statut_notification
        INNER JOIN type_notification t ON n.id_type_notification = t.id_type_notification
        WHERE n.code_participant = ? AND s.statut_notification=?
        ORDER BY n.date_envoie DESC
    ");
    $stmt->execute([$data['code_participant'],"Non lu"]);
    $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($notifs) {
        send_response(true, "Notifications récupérées avec succès", $notifs);
    } else {
        send_response(false, "Aucune notification trouvée pour ce participant.");
    }
}


function lister_notification() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['code_participant']) || empty($data['code_participant'])) {
        send_response(false, "Code participant requis.");
    }

    // Gestion propre du filtre
    $filtre = isset($data['filtre']) && !empty(trim($data['filtre'])) ? trim($data['filtre']) : "Tous";

    $pdo = getDB();

    $sql = "
        SELECT n.id_notification, n.contenu_notification, n.date_envoie, 
               s.statut_notification, t.type_notification
        FROM notifications n
        INNER JOIN statut_notification s ON n.id_statut_notification = s.id_statut_notification
        INNER JOIN type_notification t ON n.id_type_notification = t.id_type_notification
        WHERE n.code_participant = ?
    ";

    $params = [$data['code_participant']];

    if ($filtre === "Lu" || $filtre === "Non lu") {
        $sql .= " AND s.statut_notification = ?";
        $params[] = $filtre;
    }

    $sql .= " ORDER BY n.date_envoie DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($notifs) {
        send_response(true, "Notifications récupérées avec succès", $notifs);
    } else {
        send_response(false, "Aucune notification trouvée pour ce participant.");
    }
}



function supprimer_notification() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['id_notification'])) {
        send_response(false, "ID de la notification requis.");
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE id_notification = ?");
    $stmt->execute([$data['id_notification']]);

    if ($stmt->rowCount() > 0) {
        send_response(true, "Notification supprimée avec succès.");
    } else {
        send_response(false, "La notification n'a pas été trouvée ou est déjà supprimée.");
    }
}

function lire_notification() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['id_notification'],$data['code_participant'])) {
        send_response(false, "Veuillez vérifier tout les champs");
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE notifications set id_statut_notification=?  WHERE id_notification = ? AND code_participant=?");
    $stmt->execute([2,$data['id_notification'],$data['code_participant']]);

    if ($stmt->rowCount() > 0) {
        send_response(true, "Notification lu.");
    } else {
        send_response(false, "La notification n'a pas été trouvée .");
    }
}


?>