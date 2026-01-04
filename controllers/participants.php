<?php
include_once __DIR__ . '/../config/db.php';

include_once __DIR__ . '/../helpers/responses.php';
require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/../manageJWT.php';

use Firebase\JWT\JWT;


function code_participant(){
    $prefix= "PT";
    $date=date("ymd");
    $rand =strtoupper(substr(uniqid(), -5));
    return $prefix."-".$date."-".$rand;
}

function code_wallet_participant(){
    $prefix= "WP";
    $date=date('ymd');
    $rand=strtoupper(substr(uniqid(), -5));

    return $prefix."-".$date."-".$rand;
}

//Inscription de nouveau participant
function register_participant() {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['nom'], $data['prenom'],$data['password'], $data['mobile']) || empty($data['nom'])|| empty($data['prenom'])||empty($data['password']) ||empty($data['mobile'])) {
        send_response(false, "Veuillez vérifier tout les champs");
    }

    // Ajouter des validations
    if (!preg_match('/^\+?[0-9]{8,15}$/', $data['mobile'])) {
        send_response(false, "Numéro de téléphone invalide");
    }

    if (strlen($data['password']) < 6) {
        send_response(false, "Le mot de passe doit contenir au moins 6 caractères");
    }

    $pdo = getDB();
    try {
        $pdo->beginTransaction();

        $code_parti=code_participant();
        $code_wallet_parti=code_wallet_participant();

        //D'abord vérifier qu'un compte n'existe pas déjà pour le numéro renseigné
        $verif=$pdo->prepare("SELECT * FROM participants WHERE numro_mobile_money=?");
        $verif->execute([$data['mobile']]);
        $dejaCompte=$verif->fetch(PDO::FETCH_ASSOC);

        if($dejaCompte){
            send_response(false,"Vous avez déjà un compte veuillez vous connecter ou réinitialisez votre code Djarra Finances. Merci");
        }

        //Créer maintenant le profil du participant
        $stmt = $pdo->prepare("INSERT INTO participants (code_participant, nom_participant, prenoms_participant, mot_passe, numro_mobile_money) VALUES (?, ?, ?, ?, ?)");
        $stmt-> execute([$code_parti,$data['nom'],$data['prenom'],password_hash($data['password'],PASSWORD_DEFAULT),$data['mobile']]);
        
        //Ouvrir un wallet participant
        // ✅ CORRECT : 3 colonnes = 3 valeurs
        $stmt1=$pdo->prepare("INSERT INTO wallet_participant(code_wallet_participant, code_participant, solde_participant) VALUES(?,?,?)");
        $stmt1->execute([$code_wallet_parti, $code_parti, 0.00]);
        //                                                    ↑ Ajouter le solde initial

        $token=generer_token_utilisateur($code_parti,$data['mobile']);

        $pdo->commit();

        send_response(true, "Participant inscrit avec succès",[
            "code_participant" => $code_parti,
            "code_wallet_participant"=>$code_wallet_parti,
            "nom"=> $data['nom'],
            "prenoms"=>$data['prenom'],
            "numero"=>$data['mobile'],
            "code_tontine"=>$data['code_tontine'] ?? "default code",
            "type"=>$data['type'] ?? "Participant",
            "niveau_kyc"=>"KYC1",
            "jwt_token"=>$token
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Erreur inscription: " . $e->getMessage());
        send_response(false, "Une erreur est survenue lors de l'inscription");
    }
}

function saveFcmToken() {
    $data = json_decode(file_get_contents("php://input"), true);
    $pdo = getDB(); //Connexion PDO
    $stmt = $pdo->prepare("UPDATE participants SET fcm_token=? WHERE code_participant=?");
    $stmt->execute([ $data['fcm_token'],$data['code_participant']]);
}


function login_participant() {

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['numero_participant'],$data['password'])|| empty($data['numero_participant']) || empty($data['password'])) {
        send_response(false, "Veuillez vérifier tout les champs");
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT p.*,t.libelle_participant,k.libelle as niveau_kyc,w.code_wallet_participant as compte_participant FROM participants p
        INNER JOIN type_participants t ON t.id_type_participant=p.id_type_participant INNER JOIN niveau_kyc as k ON p.id_niveau_kyc=k.id_niveau_kyc INNER JOIN wallet_participant as W ON w.code_participant=p.code_participant WHERE numro_mobile_money=?");
        $stmt->execute([$data['numero_participant']]);
        $participant = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($participant) {
            //Si un participant existe avec ce numéro alors on verifie le mot de passe
            if(!password_verify($data['password'],$participant['mot_passe'])){
                //Si le mot de passe envoyer côté font ne correspond pas
                send_response(false,"Identifiants ou mot de passe incorrects");
            }

            //Sinon envoyer les informations de l'utilisateur pour la session
            $stmt2=$pdo->prepare("SELECT code_tontine FROM participer WHERE code_participant=? ORDER BY date_participation DESC LIMIT 1");
            $stmt2->execute([$participant['code_participant']]);
            $participations=$stmt2->fetch(PDO::FETCH_ASSOC);
            if($participations){
                //Générer un nouveau token
                $token=generer_token_utilisateur($participant['code_participant'],$participant['numro_mobile_money']);
                // Tu peux retourner plus de données ici si tu veux (nom, type, etc.)
                send_response(true,"Connexion réussie",[
                    "code_participant" =>$participant['code_participant'],
                    "code_wallet_participant"=>$participant['compte_participant'],
                    "nom" =>$participant['nom_participant'],
                    "prenoms" =>$participant['prenoms_participant'],
                    "type" =>$participant['libelle_participant'],
                    "numero"=>$participant['numro_mobile_money'],
                    "indice_solvabilite"=>$participant['indice_solvabilite'],
                    "niveau_kyc"=>$participant['niveau_kyc'],
                    "code_tontine"=>$participations['code_tontine'],
                    "jwt_token"=>$token??""
                ]);
            }else {
                //Générer un nouveau token
                $token=generer_token_utilisateur($participant['code_participant'],$participant['numro_mobile_money']);
                // Retourne quand même les infos sans tontine
                send_response(true,"Connexion réussie (pas encore de tontine)",[
                    "code_participant" => $participant['code_participant'],
                    "code_wallet_participant"=>$participant['compte_participant'],
                    "nom" => $participant['nom_participant'],
                    "prenoms" => $participant['prenoms_participant'],
                    "type" => $participant['libelle_participant'],
                    "numero" => $participant['numro_mobile_money'],
                    "indice_solvabilite"=>$participant['indice_solvabilite'],
                    "niveau_kyc"=>$participant['niveau_kyc'],
                    "code_tontine" => "",
                    "jwt_token"=>$token??""
                ]);
            }
        }
    } catch (PDOException $e) {
        send_response(false,"Erreur : " . $e->getMessage());
    }
}


function get_profil() {

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();

    
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['code_participant']) || empty($data['code_participant'])) {
        send_response(false,"Le code du participant est requis.");
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT p.*,t.libelle_participant,k.libelle as niveau_kyc,w.code_wallet_participant as compte_participant FROM participants p
        INNER JOIN type_participants t ON t.id_type_participant=p.id_type_participant INNER JOIN niveau_kyc as k ON p.id_niveau_kyc=k.id_niveau_kyc INNER JOIN wallet_participant as W ON w.code_participant=p.code_participant WHERE p.code_participant=?");
        $stmt->execute([$data['code_participant']]);
        $participant = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($participant) {
            send_response(true,"Profil récupéré avec succès", [
                "code_participant" => $participant['code_participant'],
                "code_wallet_participant"=>$participant['compte_participant'],
                "nom" => $participant['nom_participant'],
                "prenoms" => $participant['prenoms_participant'],
                "type" => $participant['libelle_participant'],
                "numero_mobile_money" => $participant['numro_mobile_money'],
                "indice_solvabilite"=>$participant['indice_solvabilite'],
                "niveau_kyc"=>$participant['niveau_kyc']
            ]);
        } else {
            send_response(false,"Participant introuvable.");
        }

    } catch (PDOException $e) {
        send_response(false, "Erreur : " . $e->getMessage());
    }
}


function update_profil() {

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();

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
            send_response(false, "Vérifier les informations saisi");
        }

    } catch (PDOException $e) {
        send_response(false, "Erreur : " . $e->getMessage());
    }
}



function delete_participant() {

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['code_participant'])) {
        send_response(false, 'Entrez le code participant');
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

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();

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

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['code_tontine']) || empty($data['code_tontine'])) {
        send_response(false, "Veuillez fournir le code de la tontine");
        exit;
    }

    $pdo = getDB();

    $sql = "SELECT d.code_participant, d.nom_participant,d.prenoms_participant, o.ordre,o.date_tour
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

//Récupérer nombre de cotisations manqués
function cotisation_manques(){

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();

    $data=json_decode(file_get_contents('php://input'),true);

    if(!isset($data['code_participant'],$data['code_tontine'])||empty($data['code_participant'])||empty($data['code_tontine'])){
        send_response(false,"Veuillez vérifier tous les champs");
    }

    $code_participant=$data['code_participant'];
    $code_tontine=$data['code_tontine'];

    $pdo=getDB();

    $stmt=$pdo->prepare("SELECT COUNT(*) as total_retards FROM cotisations_manquees WHERE code_tontine=? AND code_participant=? AND id_statut_paiement=?");
    $stmt->execute([$code_tontine,$code_participant,1]);
    $retards=$stmt->fetch(PDO::FETCH_ASSOC);

    $total=$retards['total_retards']??0;
    if($retards){
        send_response(true,"Vous avez au total",$total);
    }
    
}

function demande_upgrade_kyc() {
    // Vérifier le token
    $decoder = verifier_token();

    // --- 🔹 Étape 1 : Validation des champs de formulaire (envoyés via Multipart) ---
    $champs_requis = ['code_participant', 'type_document', 'numero_document'];

    foreach ($champs_requis as $champ) {
        if (!isset($_POST[$champ]) || empty(trim($_POST[$champ]))) {
            send_response(false, "Le champ '$champ' est obligatoire.");
        }
    }

    $code_participant = trim($_POST['code_participant']);
    $type_document = trim($_POST['type_document']);
    $numero_document = trim($_POST['numero_document']);

    // --- 🔹 Étape 2 : Validation des fichiers ---
    if (!isset($_FILES['fichier_document_recto'])) {
        send_response(false, "Le fichier recto est requis.");
    }

    if (!isset($_FILES['selfie_photo'])) {
        send_response(false, "La photo selfie est requise.");
    }

    $verso_fourni = isset($_FILES['fichier_document_verso']);

    $allowed_types = ['image/jpeg', 'image/png'];
    $max_size = 5 * 1024 * 1024; // 5MB

    // --- 🔹 Étape 3 : Vérifications de cohérence AVANT upload ---
    $pdo = null;
    try {
        $pdo = getDB();

        // 🔹 1. Vérifier l'existence du participant
        $stmt = $pdo->prepare("SELECT * FROM participants WHERE code_participant=?");
        $stmt->execute([$code_participant]);
        if (!$stmt->fetch()) {
            send_response(false, "Participant introuvable !");
        }

        // 🔹 2. Vérifier le type de document
        $stmt = $pdo->prepare("SELECT id_type_document FROM type_document_kyc WHERE libelle_type_document=?");
        $stmt->execute([$type_document]);
        $type_doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$type_doc) {
            send_response(false, "Type de document invalide.");
        }

        // 🔹 3. Vérifier les demandes en attente (CRITIQUE : avant upload)
        $stmt = $pdo->prepare("SELECT * FROM document_kyc 
                               WHERE code_participant=? 
                               AND statut_validation='En attente' 
                               AND DATEDIFF(NOW(), date_soumission)<=7");
        $stmt->execute([$code_participant]);
        if ($stmt->fetch()) {
            send_response(false, "Vous avez déjà une demande de vérification en cours.");
        }

        // ✅ Toutes les vérifications sont OK, on peut maintenant uploader les fichiers
        
        $upload_dir = __DIR__ . "/../uploads/documents_kyc/" . $code_participant . "/";
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // 🔹 Fonction utilitaire corrigée
        function enregistrer_image($file, $prefix, $upload_dir, $code_participant, $allowed_types, $max_size) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Erreur de téléchargement pour le fichier " . $file['name']);
            }

            if ($file['size'] > $max_size) {
                throw new Exception("Le fichier " . $file['name'] . " dépasse la taille maximale de 5MB");
            }

            // Vérification du type réel de l'image
            $info = getimagesize($file['tmp_name']);
            if (!$info) {
                throw new Exception("Le fichier " . $file['name'] . " n'est pas une image valide.");
            }

            // Types acceptés
            if (!in_array($info['mime'], $allowed_types)) {
                throw new Exception("Format non supporté pour " . $file['name'] . " (JPEG ou PNG requis)");
            }

            // Extension correcte
            $ext = ($info['mime'] === 'image/png') ? 'png' : 'jpg';
            $filename = $prefix . "_" . uniqid() . "." . $ext;
            $destination = $upload_dir . $filename;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new Exception("Impossible d'enregistrer le fichier " . $file['name']);
            }

            return "uploads/documents_kyc/" . $code_participant . "/" . $filename;
        }

        // 🔹 Upload des fichiers (uniquement si toutes les vérifications sont passées)
        $path_recto = enregistrer_image($_FILES['fichier_document_recto'], "recto", $upload_dir, $code_participant, $allowed_types, $max_size);
        $path_selfie = enregistrer_image($_FILES['selfie_photo'], "selfie", $upload_dir, $code_participant, $allowed_types, $max_size);
        $path_verso = $verso_fourni 
            ? enregistrer_image($_FILES['fichier_document_verso'], "verso", $upload_dir, $code_participant, $allowed_types, $max_size)
            : null;

        // --- 🔹 Étape 4 : Enregistrement en base ---
        $pdo->beginTransaction();

        // --- 🔹 Enregistrer la demande en base ---
        $sql = $verso_fourni ? 
            "INSERT INTO document_kyc(code_participant,id_type_document,numero_document,fichier_document_recto,fichier_document_verso,fichier_selfie,statut_validation,date_soumission)
             VALUES(?,?,?,?,?,?, 'En attente', NOW())" :
            "INSERT INTO document_kyc(code_participant,id_type_document,numero_document,fichier_document_recto,fichier_selfie,statut_validation,date_soumission)
             VALUES(?,?,?,?,?, 'En attente', NOW())";

        $params = $verso_fourni ?
            [$code_participant, $type_doc['id_type_document'], $numero_document, $path_recto, $path_verso, $path_selfie] :
            [$code_participant, $type_doc['id_type_document'], $numero_document, $path_recto, $path_selfie];

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $pdo->commit();

        send_response(true, "✅ Votre demande de vérification a été enregistrée avec succès !", [
            "recto" => $path_recto,
            "verso" => $path_verso,
            "selfie" => $path_selfie
        ]);

    } catch (Throwable $e) {
        // 🔹 En cas d'erreur après upload, supprimer les fichiers uploadés
        if (isset($path_recto) && file_exists(__DIR__ . "/../" . $path_recto)) {
            unlink(__DIR__ . "/../" . $path_recto);
        }
        if (isset($path_verso) && $path_verso && file_exists(__DIR__ . "/../" . $path_verso)) {
            unlink(__DIR__ . "/../" . $path_verso);
        }
        if (isset($path_selfie) && file_exists(__DIR__ . "/../" . $path_selfie)) {
            unlink(__DIR__ . "/../" . $path_selfie);
        }
        
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        send_response(false, "Erreur : " . $e->getMessage());
    }
}

function info_wallet_participant(){

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();

    $data=json_decode(file_get_contents("php://input"),true);

    if(!isset($data['code_participant']) || empty($data['code_participant'])){
        send_response(false,"Veuillez vérifier tous les champs");
    }

    $pdo=getDB();

    //Récupérer le solde du participant
            $stmt0=$pdo->prepare("SELECT solde_participant FROM wallet_participant WHERE code_participant=?");
            $stmt0->execute([$data['code_participant']]);
            $compte_participant=$stmt0->fetch(PDO::FETCH_ASSOC);

    // Récupérer les limites journalière ET mensuelle
        $stmt1=$pdo->prepare("SELECT k.transaction_journaliere as limiteTransac,
                                     k.transaction_mensuelle as limiteMensuelle 
                             FROM participants as p 
                             INNER JOIN niveau_kyc as k ON p.id_niveau_kyc=k.id_niveau_kyc 
                             WHERE p.code_participant=?");
        $stmt1->execute([$data['code_participant']]);
        $niveau_kyc=$stmt1->fetch(PDO::FETCH_ASSOC);
    //Récupérer le transactions journaliere et mensuelle éffectuées
    
    //Transactions journaliere
    $stmt2=$pdo->prepare("SELECT 
        COALESCE(
            (SELECT SUM(montant) 
            FROM cotisations 
            WHERE code_participant =?
            AND DATE(date_paiement) = CURDATE()
            AND id_statut_paiement = 2), 0
        ) +
        COALESCE(
            (SELECT SUM(montant) 
            
            FROM cotisations_manquees  
            WHERE code_participant =?
            AND DATE(date_rattrapage) = CURDATE()
            AND id_statut_paiement = 2), 0
        ) AS total_transactions_jour");
        $stmt2->execute([$data['code_participant'], $data['code_participant']]);
        $transaction_24H= $stmt2->fetch(PDO::FETCH_ASSOC);

    //Transaction mensuelle
    $stmt3 = $pdo->prepare("SELECT 
        COALESCE(
            (SELECT SUM(montant) 
            FROM cotisations 
            WHERE code_participant = ?
            AND YEAR(date_paiement) = YEAR(CURDATE())
            AND MONTH(date_paiement) = MONTH(CURDATE())
            AND id_statut_paiement = 2), 0
        ) +
        COALESCE(
            (SELECT SUM(montant) 
            FROM cotisations_manquees 
            WHERE code_participant = ?
            AND YEAR(date_rattrapage) = YEAR(CURDATE())
            AND MONTH(date_rattrapage) = MONTH(CURDATE())
            AND id_statut_paiement = 2), 0
        ) AS total_transactions_mois");
        $stmt3->execute([$data['code_participant'], $data['code_participant']]);
        $transaction_mois = $stmt3->fetch(PDO::FETCH_ASSOC);

        send_response(true,"Information wallet participant",[
            "solde_participant"=>$compte_participant['solde_participant'],
            "transaction_journaliere"=>$transaction_24H['total_transactions_jour'],
            "transaction_mois"=>$transaction_mois['total_transactions_mois'],
            "limite_kyc_jour"=>$niveau_kyc['limiteTransac'],
            "limite_kyc_mois"=>$niveau_kyc['limiteMensuelle']
        ]);
    }

//Modifié pour un retrait depuis le wallet participant
function retrait(){

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();


    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['libelle_mode_paiement'],$data['montant'],$data['code_participant']) ||empty($data['libelle_mode_paiement']) ||empty($data['montant']) || empty($data['code_participant'])) {
        send_response(false, "Veuillez remplir tous les champs !");
    }


    $code_participant=$data['code_participant'];
    $montant=(float)$data['montant'];
    $mode_paiement=$data['libelle_mode_paiement'];
    

    $pdo = getDB();
    try {
        $pdo->beginTransaction(); 

        // 1) Vérifier le solde AVEC verrouillage
        $check_solde = $pdo->prepare("SELECT solde_participant 
                                    FROM wallet_participant 
                                    WHERE code_participant = ? 
                                    FOR UPDATE");
        $check_solde->execute([$code_participant]);
        $wallet = $check_solde->fetch(PDO::FETCH_ASSOC);

        // 2) Valider le solde
        if ($wallet['solde_participant'] < $montant) {
            throw new Exception("Solde insuffisant. Disponible : " . $wallet['solde_participant'] . " FCFA");
        }

        // 3) Débiter
        $debiter = $pdo->prepare("UPDATE wallet_participant 
                                SET solde_participant = solde_participant - ? 
                                WHERE code_participant = ?");
        $debiter->execute([$montant, $code_participant]);

        //4) Historiser le débit
            $historique = $pdo->prepare("INSERT INTO historique_wallet_participant 
                (code_participant, type_operation, montant, description, date_operation) 
                VALUES (?, 'Retrait', ?, ?, NOW())");
            $historique->execute([
                $code_participant,
                $montant,
                "Retrait de ".$montant. "FCFA par ".$mode_paiement,
            ]);

            $phrases = [
                "Retrait de " . number_format($montant, 0, ',', ' ') . " FCFA effectué avec succès. Avec Djarra ton cœur bat pas ! 😏",
                "Retrait de " . number_format($montant, 0, ',', ' ') . " FCFA 💸 effectué. Le paiya est clair ! 🚀",
                "Retrait de " . number_format($montant, 0, ',', ' ') . " FCFA effectué ✅. T'es fan ou bien ! 😎",
                "Montant de " . number_format($montant, 0, ',', ' ') . " FCFA retiré avec succès. Djarra c'est la ref 😁"
            ];

            $message = $phrases[array_rand($phrases)];

        $pdo->commit();

        // 1) Récupérer le nouveau solde APRÈS le commit
        $nouveau_solde_query = $pdo->prepare("SELECT solde_participant 
                                            FROM wallet_participant 
                                            WHERE code_participant = ?");
        $nouveau_solde_query->execute([$code_participant]);
        $nouveau_solde = $nouveau_solde_query->fetch(PDO::FETCH_ASSOC);

        // 2) Envoyer une réponse complète
        send_response(true, $message, [
            'montant_retire' => $montant,
            'ancien_solde' => $wallet['solde_participant'],
            'nouveau_solde' => $nouveau_solde['solde_participant'],
            'mode_paiement' => $mode_paiement
        ]);

        }catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        send_response(false, $e->getMessage());
    }
}
