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

function register_participant() {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['nom'], $data['prenom'],$data['password'], $data['mobile']) || empty($data['nom'])|| empty($data['prenom'])||empty($data['password']) ||empty($data['mobile'])) {
        send_response(false, "Veuillez vérifier tout les champs");
    }

    try {
        $pdo = getDB();
        $code_parti=code_participant();

        //D'abord vérifier qu'un compte n'existe pas déjà pour le numéro renseigné
        $verif=$pdo->prepare("SELECT * FROM participants WHERE numro_mobile_money=?");
        $verif->execute([$data['mobile']]);
        $dejaCompte=$verif->fetch(PDO::FETCH_ASSOC);

        if($dejaCompte){
            send_response(false,"Vous avez déjà un compte veuillez vous connecter ou réinitialisez votre code Djarra Finances. Merci");
        }

        $stmt = $pdo->prepare("INSERT INTO participants (code_participant, nom_participant, prenoms_participant, mot_passe, numro_mobile_money) VALUES (?, ?, ?, ?, ?)");
        $stmt-> execute([$code_parti,$data['nom'],$data['prenom'],password_hash($data['password'],PASSWORD_DEFAULT),$data['mobile']]);
        $token=generer_token_utilisateur($code_parti,$data['mobile']);
        send_response(true, "Participant inscrit avec succès",[
            "code_participant" => $code_parti,
            "nom"=> $data['nom'],
            "prenoms"=>$data['prenom'],
            "numero"=>$data['mobile'],
            "code_tontine"=>$data['code_tontine'] ?? "default code",
            "type"=>$data['type'] ?? "Participant",
            "jwt_token"=>$token
        ]);
    } catch (PDOException $e) {
        send_response(false, "Erreur : " . $e->getMessage());
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
        $stmt = $pdo->prepare("SELECT p.*,t.libelle_participant,k.libelle as niveau_kyc FROM participants p
        INNER JOIN type_participants t ON t.id_type_participant=p.id_type_participant INNER JOIN niveau_kyc as k ON p.id_niveau_kyc=k.id_niveau_kyc WHERE numro_mobile_money=?");
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
                    "code_participant" => $participant['code_participant'],
                    "nom" => $participant['nom_participant'],
                    "prenoms" => $participant['prenoms_participant'],
                    "type" => $participant['libelle_participant'],
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
    verifier_token();

    
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['code_participant']) || empty($data['code_participant'])) {
        send_response(false,"Le code du participant est requis.");
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT p.*,t.libelle_participant,k.libelle as niveau_kyc FROM participants p
        INNER JOIN type_participants t ON t.id_type_participant=p.id_type_participant INNER JOIN niveau_kyc as k ON p.id_niveau_kyc=k.id_niveau_kyc WHERE p.code_participant=?");
        $stmt->execute([$data['code_participant']]);
        $participant = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($participant) {
            send_response(true,"Profil récupéré avec succès", [
                "code_participant" => $participant['code_participant'],
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

function verifier_mon_compte() {
    // Vérifier le token de session
    $decoder = verifier_token();

    // Récupérer les données envoyées
    $data = json_decode(file_get_contents('php://input'), true);

    // 🔹 ÉTAPE 1 : Validation des données d'entrée
    $champs_requis = [
        'code_participant',
        'type_document',
        'numero_document',
        'fichier_document_recto',
        'selfie_photo'
    ];

    foreach ($champs_requis as $champ) {
        if (!isset($data[$champ]) || empty(trim($data[$champ]))) {
            send_response(false, "Veuillez remplir tous les champs obligatoires.");
        }
    }

    // Validation du numéro de document
    $numero_document = trim($data['numero_document']);
    if (strlen($numero_document) < 5) {
        send_response(false, "Le numéro de document doit contenir au moins 5 caractères.");
    }

    // 🔹 VALIDATION DES FICHIERS
    
    // Validation du recto (obligatoire)
    if (!preg_match('/^data:image\/(jpeg|jpg|png);base64,/', $data['fichier_document_recto'])) {
        send_response(false, "Le format du fichier recto est invalide. Utilisez JPEG ou PNG.");
    }

    // Validation du verso (si fourni)
    $verso_fourni = isset($data['fichier_document_verso']) && !empty($data['fichier_document_verso']);
    
    if ($verso_fourni) {
        if (!preg_match('/^data:image\/(jpeg|jpg|png);base64,/', $data['fichier_document_verso'])) {
            send_response(false, "Le format du fichier verso est invalide. Utilisez JPEG ou PNG.");
        }
    }

    // Validation du selfie (obligatoire)
    if (!preg_match('/^data:image\/(jpeg|jpg|png);base64,/', $data['selfie_photo'])) {
        send_response(false, "Le format du selfie est invalide. Utilisez JPEG ou PNG.");
    }

    // 🔹 VÉRIFICATION DE LA TAILLE DES FICHIERS
    $max_size = 5 * 1024 * 1024; // 5 MB en octets

    // Taille du recto
    $fichier_recto_size = strlen(base64_decode(
        preg_replace('/^data:image\/\w+;base64,/', '', $data['fichier_document_recto'])
    ));

    if ($fichier_recto_size > $max_size) {
        send_response(false, "Le fichier recto ne doit pas dépasser 5 MB (" . 
            round($fichier_recto_size / 1024 / 1024, 2) . " MB fourni)."
        );
    }

    // Taille du verso (si fourni)
    if ($verso_fourni) {
        $fichier_verso_size = strlen(base64_decode(
            preg_replace('/^data:image\/\w+;base64,/', '', $data['fichier_document_verso'])
        ));

        if ($fichier_verso_size > $max_size) {
            send_response(false, "Le fichier verso ne doit pas dépasser 5 MB (" . 
                round($fichier_verso_size / 1024 / 1024, 2) . " MB fourni)."
            );
        }
    }

    // Taille du selfie
    $selfie_size = strlen(base64_decode(
        preg_replace('/^data:image\/\w+;base64,/', '', $data['selfie_photo'])
    ));

    if ($selfie_size > $max_size) {
        send_response(false, "Le fichier selfie ne doit pas dépasser 5 MB (" . 
            round($selfie_size / 1024 / 1024, 2) . " MB fourni)."
        );
    }

    $pdo = null;

    try {
        $pdo = getDB();
        $pdo->beginTransaction();

        // 🔹 ÉTAPE 2 : Vérifier l'existence du participant
        $stmt = $pdo->prepare("
            SELECT p.*, n.libelle as niveau_actuel 
            FROM participants as p
            LEFT JOIN niveau_kyc as n ON p.id_niveau_kyc = n.id_niveau_kyc
            WHERE p.code_participant = ?
        ");
        $stmt->execute([$data['code_participant']]);
        $participant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$participant) {
            $pdo->rollBack();
            send_response(false, "Session invalide. Veuillez vous reconnecter.");
        }

        // 🔹 ÉTAPE 3 : Vérifier le type de document
        $stmt = $pdo->prepare("
            SELECT * FROM type_document_kyc 
            WHERE libelle_type_document = ?
        ");
        $stmt->execute([$data['type_document']]);
        $type_doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$type_doc) {
            $pdo->rollBack();
            send_response(false, "Veuillez sélectionner un type de document valide.");
        }

        // 🔹 ÉTAPE 4 : Vérifier si verso est requis pour ce type de document
        $documents_recto_verso = ['CNI', 'Carte d\'identité', 'Permis de conduire', 'Titre de séjour'];
        $verso_requis = in_array($data['type_document'], $documents_recto_verso);

        if ($verso_requis && !$verso_fourni) {
            $pdo->rollBack();
            send_response(false, 
                "Le document '" . $data['type_document'] . "' nécessite une photo recto ET verso. " .
                "Veuillez fournir les deux faces du document."
            );
        }

        // 🔹 ÉTAPE 5 : Vérifier les demandes existantes
        
        // Vérifier si une demande est en attente (dans les 7 jours)
        $stmt = $pdo->prepare("
            SELECT * FROM document_kyc 
            WHERE code_participant = ? 
            AND statut_validation = 'En attente' 
            AND DATEDIFF(NOW(), date_soumission) <= 7
            ORDER BY date_soumission DESC 
            LIMIT 1
        ");
        $stmt->execute([$data['code_participant']]);
        $demandeEnCours = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($demandeEnCours) {
            $pdo->rollBack();
            send_response(false, 
                "Vous avez déjà une demande de vérification en cours de traitement " .
                "(soumise le " . date('d/m/Y', strtotime($demandeEnCours['date_soumission'])) . "). " .
                "Veuillez patienter, vous recevrez un SMS de confirmation sous peu."
            );
        }

        // Vérifier le nombre de demandes rejetées récentes (limite abus)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as nb_rejets 
            FROM document_kyc 
            WHERE code_participant = ? 
            AND statut_validation = 'Rejeté' 
            AND DATEDIFF(NOW(), date_soumission) <= 30
        ");
        $stmt->execute([$data['code_participant']]);
        $nb_rejets = (int) $stmt->fetchColumn();

        if ($nb_rejets >= 3) {
            $pdo->rollBack();
            send_response(false, 
                "Vous avez atteint le nombre maximum de tentatives pour ce mois. " .
                "Veuillez contacter le support pour assistance."
            );
        }

        // 🔹 ÉTAPE 6 : Enregistrer la demande de validation
        
        // Construire la requête selon si le verso est fourni ou non
        if ($verso_fourni) {
            $stmt = $pdo->prepare("
                INSERT INTO document_kyc (
                    code_participant, 
                    id_type_document, 
                    numero_document, 
                    fichier_document_recto,
                    fichier_document_verso,
                    selfie_document,
                    statut_validation,
                    date_soumission
                ) VALUES (?, ?, ?, ?, ?, ?, 'En attente', NOW())
            ");
            
            $stmt->execute([
                $data['code_participant'],
                $type_doc['id_type_document'],
                $numero_document,
                $data['fichier_document_recto'],
                $data['fichier_document_verso'],
                $data['selfie_photo']
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO document_kyc (
                    code_participant, 
                    id_type_document, 
                    numero_document, 
                    fichier_document_recto,
                    selfie_document,
                    statut_validation,
                    date_soumission
                ) VALUES (?, ?, ?, ?, ?, 'En attente', NOW())
            ");
            
            $stmt->execute([
                $data['code_participant'],
                $type_doc['id_type_document'],
                $numero_document,
                $data['fichier_document_recto'],
                $data['selfie_photo']
            ]);
        }

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            send_response(false, "Une erreur s'est produite lors de l'enregistrement. Veuillez réessayer.");
        }

        $id_demande = $pdo->lastInsertId();

        // 🔹 ÉTAPE 7 : Logger l'action
        $details = sprintf(
            'Type: %s - Numéro: %s - Recto-verso: %s',
            $data['type_document'],
            $numero_document,
            $verso_fourni ? 'Oui' : 'Non'
        );

        $stmt = $pdo->prepare("
            INSERT INTO kyc_logs (
                code_participant, 
                action, 
                details, 
                date_action
            ) VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([
            $data['code_participant'],
            'DEMANDE_VERIFICATION',
            $details
        ]);

        $pdo->commit();

        // 🔹 ÉTAPE 8 : Notifications (après commit)
        
        // TODO: Envoyer SMS au participant
        // envoyer_sms($participant['numero_telephone'], 
        //     "Votre demande de vérification a été reçue. Vous serez notifié du résultat sous 24-48h."
        // );

        // TODO: Notifier les administrateurs
        // notifier_admin_nouvelle_demande($id_demande, $data['code_participant']);

        $message_reponse = "✅ Votre demande de vérification a été enregistrée avec succès !\n\n" .
            "📱 Vous recevrez un SMS de confirmation sous 24 à 48 heures.\n" .
            "📄 Type de document : " . $data['type_document'] . "\n" .
            "🆔 Numéro : " . $numero_document . "\n" .
            "📸 Documents fournis : " . ($verso_fourni ? "Recto, Verso et Selfie" : "Recto et Selfie") . "\n\n" .
            "Merci de votre patience !";

        send_response(true, $message_reponse, [
            'id_demande' => $id_demande,
            'verso_fourni' => $verso_fourni
        ]);

    } catch (PDOException $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Erreur vérification compte : " . $e->getMessage());
        send_response(false, "Une erreur technique est survenue. Veuillez réessayer ultérieurement.");
    }
}
