<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../helpers/responses.php';
include_once 'tontine.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../manageJWT.php';
use Firebase\JWT\JWT;

//Vérifier que le niveau de KYC du participant est conforme aux transactions qui seront générer dans la tontine à laquelle il veut participer
function verifier_kyc($code_participant,$nombre_participant,$frequence_cotisation,$frequence_paiement,$montant_cotisation){


    $pdo=getDB();

    //Récupérer le niveau KYC de l'utilisateur
    $stmt=$pdo->prepare("SELECT p.id_niveau_kyc,n.transaction_journaliere,n.solde_maximal FROM participants as p INNER JOIN niveau_kyc as n ON n.id_niveau_kyc=p.id_niveau_kyc WHERE p.code_participant=?");
    $stmt->execute([$code_participant]);
    $niveau_utilisateur=$stmt->fetch(PDO::FETCH_ASSOC);

    // 🚨 Vérifier que le résultat est bien un tableau
    if (!$niveau_utilisateur || !is_array($niveau_utilisateur)) {
        error_log("⚠️ Aucun niveau KYC trouvé pour le participant $code_participant");
        return false;
    }

    $transaction_max = (float)$niveau_utilisateur['transaction_journaliere'];
    $solde_max = (float)$niveau_utilisateur['solde_maximal'];

    // 🔹 1. Déterminer la durée du tour (selon la fréquence de paiement = fréquence des gains)
    switch ($frequence_paiement) {
        case "Hebdomadaire":
            $duree_tour= 7; //7 Jours
            break;
        case "Mensuelle":
            $duree_tour = 30; // 30 jours
            break;
        case "Trimestrielle":
            $duree_tour = 90; // 90 jours
            break;
        default:
            $duree_tour = 7; // 7 jours par défaut, éviter erreur
    }

    // 🔹 2. Déterminer la fréquence de cotisation en jours
    switch ($frequence_cotisation) {
        case "Journalière":
            $nombre_cotisation=$duree_tour; //1 cotisation par jour
            break;
        case "Hebdomadaire":
            $nombre_cotisation = ceil($duree_tour/7); // 1 cotisation par semaine
            break;
        case "Mensuelle":
            $nombre_cotisation = ceil($duree_tour/30); // 1 cotisation par mois
            break;
        default:
            $nombre_cotisation = $duree_tour; // par défaut, une cotisation par jour
    }

    //Montant total reçu par le participant
    $volume_transaction=$nombre_participant*$montant_cotisation*$nombre_cotisation;

    //Transaction journalière pour chaque participant
    $transaction_journaliere=$montant_cotisation;

    //Comparaison
    if($transaction_journaliere>$transaction_max || $volume_transaction>$solde_max){
        
        $details="Tentative d'adhésion ou de création d'une tontine avec un Niveau KYC insuffisant";
        
        $stmt = $pdo->prepare("
            INSERT INTO kyc_logs (
                code_participant, 
                action, 
                details, 
                date_action
            ) VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([
            $code_participant,
            'ADHESION/CREATION_TONTINE',
            $details
        ]);
        
        return false;

    }
    return true;
}


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
        $stmt = $pdo->prepare("SELECT t.*,f.libelle_frequence,r.libelle_frequence_paiement FROM tontine as t INNER JOIN frequence as f ON f.id_frequence=t.id_frequence INNER JOIN frequence_paiement as r ON t.id_frequence_paiement=r.id_frequence_paiement WHERE t.code_tontine = ? FOR UPDATE");
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

        //Formater les parametres pour la verification
        $code_participant=$data['code_participant'];
        $nombre_participant=$isTontine['nombre_participant'];
        $frequence_cotisation=$isTontine['libelle_frequence'];
        $frequence_paiement=$isTontine['libelle_frequence_paiement'];
        $montant_cotisation=$isTontine['montant_cotisation'];
        //Vérifier le niveau kyc
        $verifier=verifier_kyc($code_participant,$nombre_participant,$frequence_cotisation,$frequence_paiement,$montant_cotisation);

        if(!$verifier){
            $pdo->rollBack();
            send_response(false, "Votre niveau de vérification est insuffisant pour réjoindre cette tontine. Veuillez fournir des informations supplémentaire à votre identification. Merci");
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