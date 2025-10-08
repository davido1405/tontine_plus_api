<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../helpers/responses.php';
include_once 'tontine.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../manageJWT.php';
use Firebase\JWT\JWT;


function ajouter_participation() {

    // Vérifier le token utilisateur
    $decoder = verifier_token();
    
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['code_participant']) || empty($data['code_tontine'])) {
        send_response(false, "Veuillez renseigner tous les champs !");
    }

    try {
        $pdo = getDB();
        $pdo->beginTransaction();

        // 🔒 Verrouiller la tontine
        $stmt = $pdo->prepare("SELECT * FROM tontine WHERE code_tontine = ? FOR UPDATE");
        $stmt->execute([$data['code_tontine']]);
        $isTontine = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$isTontine) {
            $pdo->rollBack();
            send_response(false, "Cette tontine n'existe pas !");
        }

        if ($isTontine['statut'] === 'Pleine') {
            $pdo->rollBack();
            send_response(false, "Cette tontine est déjà pleine !");
        }

        // Vérifie si le participant existe
        $stmt = $pdo->prepare("SELECT * FROM participants WHERE code_participant = ?");
        $stmt->execute([$data['code_participant']]);
        if (!$stmt->fetch()) {
            $pdo->rollBack();
            send_response(false, "Participant introuvable !");
        }

        // Vérifie si le participant est déjà inscrit à CETTE tontine
        $stmt = $pdo->prepare("SELECT * FROM participer WHERE code_participant = ? AND code_tontine = ?");
        $stmt->execute([$data['code_participant'], $data['code_tontine']]);
        if ($stmt->fetch()) {
            $pdo->rollBack();
            send_response(false, "Vous êtes déjà inscrit dans cette tontine !");
        }

        // Vérifie si le participant est déjà inscrit ailleurs
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM participer WHERE code_participant = ?");
        $stmt->execute([$data['code_participant']]);
        if ((int)$stmt->fetchColumn() > 0) {
            $pdo->rollBack();
            send_response(false, "Vous êtes déjà inscrit dans une autre tontine !");
        }

        // ✅ Insertion du participant
        $stmt = $pdo->prepare("INSERT INTO participer (code_participant, code_tontine, date_participation) VALUES (?, ?, ?)");
        $stmt->execute([$data['code_participant'], $data['code_tontine'], date("Y-m-d H:i:s")]);

        // Recompter
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM participer WHERE code_tontine = ?");
        $stmt->execute([$data['code_tontine']]);
        $nombreParticipant = (int) $stmt->fetchColumn();

        $limite = (int) $isTontine['nombre_participant'];

        $pdo->commit();

        // 🚀 Si la tontine est pleine, on déclenche immédiatement la génération des tours
        if ($nombreParticipant >= $limite) {
            $stmt = $pdo->prepare("UPDATE tontine SET statut = 'Pleine', etat_tontine='En cours' WHERE code_tontine = ?");
            $stmt->execute([$data['code_tontine']]);

            // Appel direct à la fonction de génération des tours
            $code_tont=$data['code_tontine'];
            lister_tour($code_tont,$relancer=false);
        }

        // Sinon réponse classique
        send_response(true, "Vous participez désormais à cette tontine.");

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        send_response(false, "Erreur : " . $e->getMessage());
    }
}




function verifier_participation() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['code_participant']) || empty($data['code_participant'])) {
        send_response(false, "Veuillez remplir tous les champs");
        return;
    }

    $pdo = getDB();

    // Vérification si le participant existe
    $stmt = $pdo->prepare("SELECT * FROM participants WHERE code_participant = ?");
    $stmt->execute([$data['code_participant']]);
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$participant) {
        send_response(false, "Veuillez vous inscrire avant de participer à une tontine");
        return;
    }

    // Récupérer les participations
    $stmt = $pdo->prepare("SELECT code_tontine FROM participer WHERE code_participant = ?");
    $stmt->execute([$data['code_participant']]);
    $tontines = $stmt->fetchAll(PDO::FETCH_COLUMN); // retourne un tableau de codes

    if ($tontines && count($tontines) > 0) {
        send_response(true, "Vous êtes inscrit dans " . count($tontines) . " tontine(s).");
    } else {
        send_response(false, "Vous ne participez à aucune tontine actuellement");
    }
}


function lister_participation(){
    $data=json_decode(file_get_contents('php://input'),true);
    if(!isset($data['code_participant'])||empty($data['code_participant'])){
        send_response(false,"VEuilez remplir tous les champs");
    }

    $pdo=getDB();

    $stmt=$pdo->prepare("SELECT * FROM participants WHERE code_participant = ?");
    $stmt->execute([$data['code_participant']]);
    $participant=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$participant){
        send_response(false,"Veillez vous incrire avant de participer à une tontine");
    }

    $stmt=$pdo->prepare("SELECT t.*,f.libelle_frequence,m.libelle_type_tontine FROM tontine t 
    INNER JOIN participer p ON t.code_tontine=p.code_tontine
    INNER JOIN frequence f ON t.id_frequence=f.id_frequence
    INNER JOIN type_tontine m ON t.id_type_tontine=m.id_type_tontine
    WHERE p.code_participant=?");
    $stmt->execute([$data['code_participant']]);
    $tontines=$stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if($tontines){
        $formated=[];
        foreach($tontines as $tontine){
            $formated [ ]=[
                "code_tontine" => $tontine['code_tontine'],
                "nom_tontine" => $tontine['nom_tontine'],
                "montant_cotisation" => $tontine['montant_cotisation'],
                "nombre_participant" => $tontine['nombre_participant'],
                "frequence" => $tontine['libelle_frequence'],
                "statut" => $tontine['statut'],
                "type_tontine" => $tontine['libelle_type_tontine'],
                "date_creation" => $tontine['date_creation'],
                "montant_penalite" => $tontine['montant_penalite']
            ];
        }
        send_response(true,"Vous êtes inscrit dans les tontines suivantes :",$formated);
    }else{
        send_repsonse(false,"Vous ne participer à aucune tontine actuellement");
    }
}
?>