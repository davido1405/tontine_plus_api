<?php
include_once __DIR__ . '/../config/db.php';

include_once __DIR__ . '/../helpers/responses.php';


function code_participant(){
    $prefix= "PT";
    $date=date("ymd");
    $rand =strtoupper(substr(uniqid(), -5));
    return $prefix."-".$date."-".$rand;
}

function register_participant() {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['nom'], $data['prenom'], $data['email'], $data['password'], $data['mobile']) || empty($data['nom'])|| empty($data['prenom'])|| empty($data['email'])|| empty($data['password'])|| empty($data['mobile'])) {
        send_response(false, "Veuillez vérifier tout les champs");
    }

    try {
        $pdo = getDB();
        $code_parti=code_participant();
        $stmt = $pdo->prepare("INSERT INTO participants (code_participant, nom_participant, prenoms_participant, email_participant, mot_passe, numro_mobile_money) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt-> execute([$code_parti,$data['nom'],$data['prenom'],$data['email'],$data['password'],$data['mobile']]);
        send_response(true, "Participant inscrit avec succès",[
            "code_participant" => $code_parti,
            "nom"=> $data['nom'],
            "prenoms"=>$data['prenom'],
            "email"=>$data['email'],
            "numero"=>$data['mobile'],
            "code_tontine"=>$data['code_tontine'] ?? "default code",
            "type"=>$data['type'] ?? "Participant"
        ]);
    } catch (PDOException $e) {
        send_response(false, "Erreur : " . $e->getMessage());
    }
}


function login_participant() {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['email_participant'],$data['password'])|| empty($data['email_participant']) || empty($data['password'])) {
        send_response(false, "Veuillez vérifier tout les champs");
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT p.*,t.libelle_participant FROM participants p
        INNER JOIN type_participants t ON t.id_type_participant=p.id_type_participant WHERE email_participant=? AND mot_passe=?");
        $stmt->execute([$data['email_participant'],$data['password']]);
        $participant = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($participant) {
            $stmt2=$pdo->prepare("SELECT code_tontine FROM participer WHERE code_participant=? ORDER BY date_participation DESC LIMIT 1");
            $stmt2->execute([$participant['code_participant']]);
            $participations=$stmt2->fetch(PDO::FETCH_ASSOC);
            if($participations){
                // Tu peux retourner plus de données ici si tu veux (nom, type, etc.)
                send_response(true, "Connexion réussie", [
                    "code_participant" => $participant['code_participant'],
                    "nom" => $participant['nom_participant'],
                    "prenoms" => $participant['prenoms_participant'],
                    "email" => $participant['email_participant'],
                    "type" => $participant['libelle_participant'],
                    "numero"=>$participant['numro_mobile_money'],
                    "code_tontine"=>$participations['code_tontine']
                ]);
            }
        } else {
            send_response(false, "Identifiants ou mot de passe incorrects");
        }

    } catch (PDOException $e) {
        send_response(false, "Erreur : " . $e->getMessage());
    }
}


function get_profil() {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['code_participant']) || empty($data['code_participant'])) {
        send_response(false, "Le code du participant est requis.");
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT p.*,t.libelle_participant FROM participants p
        INNER JOIN type_participants t ON t.id_type_participant=p.id_type_participant
        WHERE code_participant = ?");
        $stmt->execute([$data['code_participant']]);
        $participant = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($participant) {
            send_response(true, "Profil récupéré avec succès", [
                "code_participant" => $participant['code_participant'],
                "nom" => $participant['nom_participant'],
                "prenoms" => $participant['prenoms_participant'],
                "email" => $participant['email_participant'],
                "type" => $participant['libelle_participant'],
                "numero_mobile_money" => $participant['numro_mobile_money']
            ]);
        } else {
            send_response(false, "Participant introuvable.");
        }

    } catch (PDOException $e) {
        send_response(false, "Erreur : " . $e->getMessage());
    }
}


function update_profil() {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['code_participant']) || empty($data['code_participant'])) {
        send_response(false, 'Entrez votre code participant.');
    }

    try {
        $pdo = getDB();
        $fields = [];
        $params = [];

        // Préparation dynamique des champs à modifier
        if (isset($data['nom_participant'])) {
            $fields[] = "nom_participant = ?";
            $params[] = $data['nom_participant'];
        }
        if (isset($data['prenoms_participant'])) {
            $fields[] = "prenoms_participant = ?";
            $params[] = $data['prenoms_participant'];
        }
        if (isset($data['email_participant'])) {
            $fields[] = "email_participant = ?";
            $params[] = $data['email_participant'];
        }
        if (isset($data['mot_passe']) && !empty($data['mot_passe'])) {
            $fields[] = "mot_passe = ?";
            $params[] = $data['mot_passe'];
        }
        if (isset($data['numero_mobile_money'])) {
            $fields[] = "numro_mobile_money = ?";
            $params[] = $data['numero_mobile_money'];
        }

        if (empty($fields)) {
            send_response(false, "Aucune donnée à mettre à jour.");
        }

        // Construction de la requête SQL
        $sql = "UPDATE participants SET " . implode(", ", $fields) . " WHERE code_participant = ?";
        $params[] = $data['code_participant'];

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() > 0) {
            send_response(true, "Profil mis à jour avec succès.");
        }else{
            send_response(false, "Aucune modification effectuée.");
        }

    } catch (PDOException $e) {
        send_response(false, "Erreur : " . $e->getMessage());
    }
}



function delete_participant() {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['code_participant'])) {
        send_response(false, 'Entrez votre code participant');
    }

    try {
        $pdo = getDB();

        $stmt = $pdo->prepare("DELETE FROM participants WHERE code_participant = ?");
        $stmt->execute([$data['code_participant']]);

        if ($stmt->rowCount() > 0) {
            send_response(true, "Participant supprimé avec succès.");
        } else {
            send_response(false, "Aucun participant trouvé avec ce code.");
        }

    } catch (PDOException $e) {
        send_response(false, "Erreur : " . $e->getMessage());
    }
}


function monTour(){
    $data=json_decode(file_get_contents('php://input'),true);

    if(!isset($data['code_tontine'],$data['code_participant']) || empty($data['code_tontine']) || empty($data['code_participant'])){
        send_response(false,"Veuillez fournir tout les champs");
    }

    $pdo=getDB();
    $stmt=$pdo->prepare("SELECT ordre,statut FROM ordre_tirage WHERE code_tontine=? AND code_participant=?");
    $stmt->execute([$data['code_tontine'],$data['code_participant']]);
    $montour=$stmt->fetch(PDO::FETCH_ASSOC);

    if($montour){
        send_response(true,"Mon tour",$montour);
    }else{
        send_response(false,"Aucun tour trouvé pour ce participant");
    }
}


function recupere_tour_actuel() {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['code_tontine']) || empty($data['code_tontine'])) {
        send_response(false, "Veuillez fournir le code de la tontine");
        exit;
    }

    $pdo = getDB();

    $sql = "SELECT d.code_participant, d.nom_participant,d.prenoms_participant, o.ordre
            FROM participer p
            INNER JOIN ordre_tirage o 
                ON o.code_participant = p.code_participant 
               AND o.code_tontine = p.code_tontine
            INNER JOIN tontine t 
                ON t.code_tontine = p.code_tontine
            INNER JOIN participants d ON d.code_participant=p.code_participant
            WHERE t.tour_actuel = o.ordre 
              AND o.code_tontine = ? 
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data['code_tontine']]);
    $receveur = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($receveur) {
        send_response(true, "Le bénéficiaire du tour actuel est :", $receveur);
    } else {
        send_response(false, "Aucun bénéficiaire trouvé pour ce tour");
    }

    exit;
}



?>



