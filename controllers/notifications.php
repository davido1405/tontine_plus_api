<?php
include_once __DIR__ . '/../config/db.php';

include_once __DIR__ . '/../helpers/responses.php';

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/../manageJWT.php';

use Firebase\JWT\JWT;



function envoyer_notification_personnalise(){

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['code_tontine'],$data['code_participant'], $data['type_notification'], $data['contenu_notification']) || empty($data['code_tontine'])||empty($data['type_notification']) ||empty($data['contenu_notification']) || empty($data['code_participant'])) {
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

    // Récupérer le participant ciblé et son FCM token
    $stmtParticipe = $pdo->prepare("
        SELECT p.*, t.montant_cotisation, w.fcm_token 
        FROM participer p
        INNER JOIN tontine t ON p.code_tontine=t.code_tontine
        INNER JOIN participants w ON w.code_participant = p.code_participant
        WHERE p.code_tontine = ? AND p.code_participant=?
    ");
    $stmtParticipe->execute([$data['code_tontine'],$data['code_participant']]);
    $participant = $stmtParticipe->fetchAll(PDO::FETCH_ASSOC);

    if (!$participant) {
        send_response(false, "Une erreur s'est produite, veuillez en informer le service technique");
    }

    $date_envoie = date("Y-m-d H:i:s");

    // Enregistrement de la notification au participant ciblé
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

        // Envoi du push si FCM token disponible
        if (!empty($participant['fcm_token'])) {
            $result=sendPushNotification($participant['fcm_token'], "Information", $contenu);
            if($result){
                send_response(true, "Notification envoyées avec succès.");
            }else{
                send_response(false, "Notification non envoyées.");
            }
        }
    
}



function envoyer_notification_penlaite(){

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();


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

function sendPushNotification($token, $title, $body) {
    $logFile = __DIR__ . '/fcm_debug.log';
    
    // Vérifier que le token n'est pas vide
    if (empty($token)) {
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Token FCM vide\n", FILE_APPEND);
        return false;
    }

    // Chemin vers le fichier JSON du compte de service
    $serviceAccountPath = __DIR__ . '/../djarrafinances-68d6f3033cc6.json';
    if (!file_exists($serviceAccountPath)) {
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Fichier service account non trouvé: $serviceAccountPath\n", FILE_APPEND);
        return false;
    }

    $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);
    if (!$serviceAccount) {
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Impossible de lire le fichier JSON du service account\n", FILE_APPEND);
        return false;
    }

    try {
        // Création du JWT
        $now = time();
        $claim = [
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $serviceAccount['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600
        ];
        
        if (!class_exists('\Firebase\JWT\JWT')) {
            throw new Exception("Librairie Firebase JWT non installée");
        }
        
        $jwt = \Firebase\JWT\JWT::encode($claim, $serviceAccount['private_key'], 'RS256', $serviceAccount['private_key_id']);

        // Récupérer access_token
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $serviceAccount['token_uri']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($response === false) {
            curl_close($ch);
            throw new Exception("Erreur CURL pour access token: " . curl_error($ch));
        }
        curl_close($ch);

        $data = json_decode($response, true);
        if ($httpCode !== 200 || !isset($data['access_token'])) {
            throw new Exception("Access token non reçu. Code HTTP: $httpCode, Réponse: " . $response);
        }
        
        $accessToken = $data['access_token'];
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Access token récupéré avec succès\n", FILE_APPEND);

        // Préparer le message
        $message = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'android' => [
                    'priority' => 'high',
                    'ttl' => '0s'
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                        'apns-expiration' => '0'
                    ]
                ],
                'data' => [
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    'title' => $title,
                    'body'  => $body,
                ]
            ],
        ];


        // Envoi du push
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/v1/projects/djarrafinances/messages:send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($result === false) {
            curl_close($ch);
            throw new Exception("Erreur CURL lors de l'envoi du push: " . curl_error($ch));
        }
        curl_close($ch);

        // Analyser la réponse
        $resultData = json_decode($result, true);
        
        if ($httpCode === 200) {
            // Succès
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] ✅ Push envoyé avec succès: '$title' -> Token: " . substr($token, 0, 20) . "...\n", FILE_APPEND);
            return true;
        } else {
            // Erreur
            $errorMessage = isset($resultData['error']['message']) ? $resultData['error']['message'] : 'Erreur inconnue';
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] ❌ Échec envoi push. Code HTTP: $httpCode, Erreur: $errorMessage, Token: " . substr($token, 0, 20) . "...\n", FILE_APPEND);
            
            // Si le token est invalide, on peut log spécifiquement
            if (strpos($errorMessage, 'registration-token-not-registered') !== false || 
                strpos($errorMessage, 'invalid-registration-token') !== false) {
                file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] 🚫 Token FCM invalide ou expiré\n", FILE_APPEND);
            }
            
            return false;
        }

    } catch (Exception $e) {
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] ❌ Exception: " . $e->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}




//Envoyer notifications push et enregistrer dans la BDD
function envoyer_rappel_cotisation(){

    // Vérifier le token utilisateur
    $decoder = verifier_token();

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['code_tontine'], $data['type_notification'])) {
        send_response(false, "Champs obligatoires manquants.");
    }

    $pdo = getDB();

    // Vérifier que la tontine est en cours et avec des tours générés
    $stmtTontine = $pdo->prepare("SELECT statut, etat_tontine, montant_cotisation FROM tontine WHERE code_tontine=?");
    $stmtTontine->execute([$data['code_tontine']]);
    $tontine = $stmtTontine->fetch(PDO::FETCH_ASSOC);

    if (!$tontine) {
        send_response(false, "Tontine introuvable");
    }
    if ($tontine['statut'] != "Pleine" || $tontine['etat_tontine'] != "En cours") {
        send_response(false, "Les rappels ne sont envoyés que lorsque la tontine est en cours.");
    }

    // Récupérer l'ID du type de notification
    $stmtType = $pdo->prepare("SELECT id_type_notification FROM type_notification WHERE type_notification = ?");
    $stmtType->execute([$data['type_notification']]);
    $type = $stmtType->fetch(PDO::FETCH_ASSOC);

    if (!$type) {
        send_response(false, "Type de notification invalide.");
    }

    // Récupérer les participants
    $stmtParticipe = $pdo->prepare("
        SELECT p.code_participant, w.fcm_token 
        FROM participer p
        INNER JOIN participants w ON w.code_participant = p.code_participant
        WHERE p.code_tontine = ?
    ");
    $stmtParticipe->execute([$data['code_tontine']]);
    $participants = $stmtParticipe->fetchAll(PDO::FETCH_ASSOC);

    if (!$participants) {
        send_response(false, "Cette tontine n'a aucun participant pour l'instant");
    }

    $succes = 0;
    foreach ($participants as $participant) {
        $contenu = "Pensez à payer votre cotisation de ".$tontine['montant_cotisation'].". Merci !";

        // Enregistrer en BDD
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

        // Envoi push
        if (!empty($participant['fcm_token'])) {
            $ok = sendPushNotification($participant['fcm_token'], "Rappel cotisation", $contenu);
            if ($ok) $succes++;
        }
    }

    if ($succes > 0) {
        send_response(true, "Rappel de cotisation envoyé à $succes participant(s).");
    } else {
        send_response(false, "Impossible d'envoyer les rappels de cotisation.");
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
        ORDER BY n.date_envoie DESC LIMIT 10
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

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['code_participant']) || empty($data['code_participant'])) {
        send_response(false, "Code participant requis.");
    }

    // Valeur par défaut "Tous"
    $filtre = isset($data['filtre']) && !empty(trim($data['filtre'])) 
        ? trim($data['filtre']) 
        : "Tous";

    try {
        $pdo = getDB();

        $sql = "
            SELECT n.id_notification, n.contenu_notification, n.date_envoie, 
                   s.statut_notification, t.type_notification
            FROM notifications n
            INNER JOIN statut_notification s 
                ON n.id_statut_notification = s.id_statut_notification
            INNER JOIN type_notification t 
                ON n.id_type_notification = t.id_type_notification
            WHERE n.code_participant = :code_participant ORDER BY n.date_envoie DESC LIMITS=10
        ";

        $params = ["code_participant" => $data['code_participant']];

        // Si filtre spécifique
        if (in_array($filtre, ["Lu", "Non lu"])) {
            $sql .= " AND s.statut_notification = :filtre";
            $params["filtre"] = $filtre;
        }

        $sql .= " ORDER BY n.date_envoie DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($notifs && count($notifs) > 0) {
            send_response(true, "Notifications récupérées avec succès", $notifs);
        } else {
            send_response(false, "Aucune notification trouvée pour ce participant.");
        }

    } catch (PDOException $e) {
        send_response(false, "Erreur de base de données : " . $e->getMessage());
    }
}




function supprimer_notification() {

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['id_notification'],$data['code_participant'])) {
        send_response(false, "Veuillez vérifier tout les champs.");
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

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();

    
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