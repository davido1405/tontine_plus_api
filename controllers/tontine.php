<?php
include_once __DIR__ . '/../config/db.php';

include_once __DIR__ . '/../helpers/responses.php';


//Générer un code tontine unique

function code_tontine(){
    $prefix= "TNT";
    $date=date("ymd");
    $rand =strtoupper(substr(uniqid(), -5));
    return $prefix."-".$date."-".$rand;
}


//Générer un code wallet
function code_wallet(){
    $prefix= "Wall";
    $date=date("ymd");
    $rand =strtoupper(substr(uniqid(), -5));
    return $prefix."-".$date."-".$rand;
}


//Créer une tontine
function create_tontine(){
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['code_participant'],$data['nom_tontine'], $data['type_tontine'], $data['montant_cotisation'], $data['nombre_participant'], $data['frequence'])) {
        send_response(false, "Champs obligatoires manquants.");
    }

    try{

        $pdo=getDB();

        //Récupérer l'id_type_tontine
        $stmt1=$pdo->prepare("SELECT id_type_tontine FROM type_tontine WHERE libelle_type_tontine=?");
        $stmt1->execute([$data['type_tontine']]);
        $type=$stmt1->fetch(PDO::FETCH_ASSOC);

        if (!$type) {
            send_response(false, "Type de tontine invalide.");
        }

        //Récupérer l'id_frequence
        $stmt2=$pdo->prepare("SELECT id_frequence FROM frequence WHERE libelle_frequence=?");
        $stmt2->execute([$data['frequence']]);
        $frequence=$stmt2->fetch(PDO::FETCH_ASSOC);

        if (!$frequence) {
            send_response(false, "Fréquence invalide.");
        }


        //Vérifier l'unicité de l'organisation d'une tontine
        $stmt3=$pdo->prepare("SELECT code_tontine FROM organiser_tontine WHERE code_participant=?");
        $stmt3->execute([$data['code_participant']]);
        $organiseDeja=$stmt3->fetch(PDO::FETCH_ASSOC);

        if($organiseDeja){
            send_response(false,"Vous ne pouvez organiser que une tontine à la fois");
        }

        //Ajout de la tontine
        $sql=$pdo->prepare("INSERT INTO tontine(code_tontine,nom_tontine,montant_cotisation,nombre_participant,id_frequence,id_type_tontine,date_creation,montant_penalite) VALUES(?,?,?,?,?,?,?,?)");

        //Definir la date
        $date=date("Y-m-d H:i:s");
        //Générer un code tontine
        $code_ton=code_tontine();

        $sql->execute(
            [
                $code_ton,
                $data['nom_tontine'],
                $data['montant_cotisation'],
                $data['nombre_participant'],
                $frequence['id_frequence'],
                $type['id_type_tontine'],
                $date,
                $data['montant_penalite'] ?? 1000
            ]
        );

        //Ajout de l'organisteur de la tontine
        $sql2=$pdo->prepare("INSERT INTO organiser_tontine(code_participant,code_tontine,date_organiser) VALUES (?,?,?)");
        $sql2->execute([$data['code_participant'],$code_ton,$date]);

        //Mise à jour du type du participant
        $sql3=$pdo->prepare("UPDATE participants SET id_type_participant=? WHERE code_participant=?");
        $sql3->execute([1,$data['code_participant']]);

        //Mise à jour de la table participer(Par défaut l'organisateur est lui même participant)
        $sql4=$pdo->prepare("INSERT INTO participer(code_participant,code_tontine,date_participation) VALUES(?,?,?)");
        $sql4->execute([$data['code_participant'],$code_ton,$date]);

        if ($sql && $sql2 && $sql3 && $sql4->rowCount() > 0) {
            $code_wallet=code_wallet();
            $stmt=$pdo->prepare('INSERT INTO wallet_tontine(code_wallet,code_tontine,solde_tontine) VALUES(?,?,?)');
            $stmt->execute([$code_wallet,$code_ton,0]);
            if($stmt){
                send_response(true, 
                    "Tontine créée avec succès", 
                    [
                        "code_tontine" => $code_ton,
                        "organisateur" => $data['code_participant'],
                        "date_creation" => $date,
                        "code_wallet" =>$code_wallet
                    ]);
            }
            
        } else {
            send_response(false, "Échec de la création de la tontine.");
        }

        
    }catch(PDOException $e){
        send_response(false, "Erreur: ". $e->getMessage());
    }
}


//Details d'une tontine

function get_tontine_details() {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['code_tontine']) || empty($data['code_tontine'])) {
        send_response(false, "L'identifiant de la tontine est requis.");
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT o.code_tontine, o.nom_tontine, o.montant_cotisation, o.nombre_participant, f.libelle_frequence,o.statut,t.libelle_type_tontine,o.date_creation,w.code_wallet
                               FROM tontine as o
                               INNER JOIN type_tontine as t
                               ON o.id_type_tontine=t.id_type_tontine
                               INNER JOIN frequence as f
                               ON o.id_frequence=f.id_frequence
                               INNER JOIN wallet_tontine as w
                               ON w.code_tontine=o.code_tontine
                               WHERE o.code_tontine = ?");
        $stmt->execute([$data['code_tontine']]);
        $tontine = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tontine) {
            send_response(true, "Tontine récupéré avec succès", [
                "code_tontine" => $tontine['code_tontine'],
                "nom" => $tontine['nom_tontine'],
                "montant" => $tontine['montant_cotisation'],
                "nombre participant" => $tontine['nombre_participant'],
                "frequence" => $tontine['libelle_frequence'],
                "statut" => $tontine['statut'],
                "type" => $tontine['libelle_type_tontine'],
                "date creation" => $tontine['date_creation'],
                "code_wallet" =>$tontine['code_wallet']
            ]);
        } else {
            send_response(false, "Tontine introuvable.");
        }

    } catch (PDOException $e) {
        send_response(false, "Erreur : " . $e->getMessage());
    }
}


//Charger toutes les tontines

function toutes_tontines() {
    try {
        $pdo = getDB();

        $stmt = $pdo->prepare("SELECT o.code_tontine,o.nom_tontine,o.montant_cotisation,o.nombre_participant,f.libelle_frequence,o.statut,t.libelle_type_tontine,o.date_creation,o.montant_penalite,w.code_wallet FROM tontine AS o
                               INNER JOIN type_tontine AS t ON o.id_type_tontine = t.id_type_tontine
                               INNER JOIN frequence AS f ON o.id_frequence = f.id_frequence
                               INNER JOIN wallet_tontine as w ON w.code_tontine=o.code_tontine
                               ");
        $stmt->execute();
        $tontines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($tontines) {
            // Préparer un tableau formaté
            $formatted = [];
            foreach ($tontines as $tontine) {
                $formatted[] = [
                    "code_tontine" => $tontine['code_tontine'],
                    "nom" => $tontine['nom_tontine'],
                    "montant" => $tontine['montant_cotisation'],
                    "nombre_participant" => $tontine['nombre_participant'],
                    "frequence" => $tontine['libelle_frequence'],
                    "statut" => $tontine['statut'],
                    "type" => $tontine['libelle_type_tontine'],
                    "date_creation" => $tontine['date_creation'],
                    "code_wallet" =>$tontine['code_wallet']
                ];
            }

            // Envoyer la réponse finale
            send_response(true, "Liste des tontines récupérée avec succès", $formatted);
        } else {
            send_response(false, "Aucune tontine trouvée.");
        }

    } catch (PDOException $e) {
        send_response(false, "Erreur : " . $e->getMessage());
    }
}


//Ppour charger les participants d'une tontine en particulier

function listeParticipants(){
    $data=json_decode(file_get_contents("php://input"),true);
    if(!isset($data['code_tontine'])||empty($data['code_tontine'])){
        send_response(false,"Veuillez vérifier tous les champs");
    }
    $pdo=getDB();
    $stmt=$pdo->prepare("SELECT p.nom_participant,p.prenoms_participant,p.email_participant,p.numro_mobile_money,a.date_participation,t.libelle_participant FROM participants p INNER JOIN participer as a ON p.code_participant=a.code_participant INNER JOIN type_participants as t ON p.id_type_participant=t.id_type_participant WHERE code_tontine=?");
    $stmt->execute([$data['code_tontine']]);
    $listeParticipants=$stmt->fetchAll(PDO::FETCH_ASSOC);

    if(!$listeParticipants){
        send_response(false,"Cette tontine est vide");
    }
    $formated=[];
    foreach($listeParticipants as $listeParticipant){
        $formated[]=[
            "nom" => $listeParticipant['nom_participant'],
            "prenoms" => $listeParticipant['prenoms_participant'],
            "email" => $listeParticipant['email_participant'],
            "mobile" => $listeParticipant['numro_mobile_money'],
            "date_participation" => $listeParticipant['date_participation'],
            "type" => $listeParticipant['libelle_participant']
        ];
    }
    send_response(true,"Liste des participants de la tontine",$formated);
}

function lister_tour(){
    $data=json_decode(file_get_contents("php://input"),true);
    if(!isset($data) || empty($data['code_tontine'])){
        send_response(false,"Veuillez fournir le code tontine");
    }

    $pdo=getDB();

    // Récupérer type de tontine
    $stmt=$pdo->prepare("
        SELECT t.*, o.libelle_type_tontine 
        FROM tontine AS t 
        INNER JOIN type_tontine AS o 
        ON o.id_type_tontine = t.id_type_tontine 
        WHERE code_tontine=?
    ");
    $stmt->execute([$data['code_tontine']]);
    $type=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$type){
        send_response(false,"Type non défini pour cette tontine");
    }
    
    $nombreLimite = (int) $type['nombre_participant'];

    // Nombre actuel
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM participer WHERE code_tontine = ?");
    $stmt->execute([$data['code_tontine']]);
    $nombreParticipant = (int) $stmt->fetchColumn();

    // Vérifier si déjà généré
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ordre_tirage WHERE code_tontine = ?");
    $stmt->execute([$data['code_tontine']]);
    $toursExistants = (int) $stmt->fetchColumn();
    
    if ($nombreParticipant == $nombreLimite && $toursExistants == 0) {
        
        // Récupérer participants
        $stmt1=$pdo->prepare("SELECT code_participant FROM participer WHERE code_tontine=?");
        $stmt1->execute([$data['code_tontine']]);
        $participants=$stmt1->fetchAll(PDO::FETCH_ASSOC);

        $codes = array_column($participants, 'code_participant');

        if ($type['libelle_type_tontine'] === 'Tirage') {
            shuffle($codes);
        }

        // Insérer les tours
        foreach ($codes as $i => $code) {
            $ordre = $i + 1;
            $stmt=$pdo->prepare("INSERT INTO ordre_tirage(code_tontine, code_participant, ordre, statut) VALUES(?,?,?,0)");
            $stmt->execute([$data['code_tontine'], $code, $ordre]);
        }

        send_response(true,"Tours générés avec succès !");
    }
}


//Récupérer les infos wallet_tontine
function wallet_tontine_infos(){
    $data=json_decode(file_get_contents("php://input"),true);
    if(!isset($data['code_tontine']) || empty($data['code_tontine'])){
        send_response(false,"Veuillez remplir tous les champs svp !");
    }
    //récupérer les différents
    $pdo=getDB();
    $stmt=$pdo->prepare("SELECT * FROM wallet_tontine WHERE code_tontine=?");
    $stmt->execute([$data['code_tontine']]);
    $wallet_infos=$stmt->fetch(PDO::FETCH_ASSOC);
    if($wallet_infos){
        send_response(true,"Le wallet de la tontine est:",[
            "code_wallet"=>$wallet_infos['code_wallet'],
            "code_tontine"=>$wallet_infos['code_tontine'],
            "solde"=>$wallet_infos['solde_tontine']
        ]);
    }else{
        send_response(true,"Une erreur est survenue. Veuillez réessayer !");
    }
}



function transactions(){
    $data=json_decode(file_get_contents("php://input"),true);

    if(!isset($data['code_tontine']) || empty($data['code_tontine'])){
        send_response(false,"Veuillez remplir tous les champs svp !");
    }

    $pdo=getDB();
    $stmt=$pdo->prepare("SELECT 
            p.nom_participant,
            p.prenoms_participant,
            c.montant,
            c.date_paiement AS date_transaction,
            'Cotisation' AS type_transaction,
            m.libelle_mode_paiement AS mode_paiement,
            s.libelle_statut_paiement AS statut
        FROM cotisations c INNER JOIN participants as p ON p.code_participant=c.code_participant INNER JOIN mode_paiement as m ON m.id_mode_paiement=c.id_mode_paiement INNER JOIN status_paiement AS s ON s.id_statut_paiement=c.id_statut_paiement WHERE c.code_tontine=?

        UNION ALL

        SELECT
            p.nom_participant,
            p.prenoms_participant,
            d.montant,
            d.date_penalite AS date_transaction,
            'Penalite' AS type_transaction,
            m.libelle_mode_paiement AS mode_paiement,
            CASE 
            	WHEN statut='Payée' THEN 'Effectué'
            	ELSE 'En attente'
                END AS statut
        FROM penalites d INNER JOIN participants as p ON p.code_participant=d.code_participant INNER JOIN mode_paiement as m ON m.id_mode_paiement=d.id_mode_paiement WHERE d.code_tontine=?

        UNION ALL

        SELECT
            p.nom_participant,
            p.prenoms_participant,
            e.montant,
            e.date_paiement AS date_transaction,
            'Retrait' AS type_transaction,
            m.libelle_mode_paiement AS mode_paiement,
            s.libelle_statut_paiement AS statut
        FROM paiement_tour e INNER JOIN participants as p ON p.code_participant=e.code_participant INNER JOIN mode_paiement as m ON m.id_mode_paiement=e.id_mode_paiement INNER JOIN status_paiement AS s ON s.id_statut_paiement=e.id_statut_paiement WHERE e.code_tontine=?
        
        ORDER BY date_transaction DESC;
        ");
    $stmt->execute([$data['code_tontine'],$data['code_tontine'],$data['code_tontine']]);
    $resultats=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if($resultats){
        $transactions=[];
        foreach($resultats as $resultat){
            $transactions[]=[
                "nom"=>$resultat['nom_participant'],
                "prenoms"=>$resultat['prenoms_participant'],
                "type_transaction"=>$resultat['type_transaction'],
                "montant_transaction"=>$resultat['montant'],
                "date_transaction"=>$resultat['date_transaction'],
                "mode_paiement"=>$resultat['mode_paiement'],
                "statut_paiement"=>$resultat['statut']
            ];
        }
        send_response(true,"Liste des transactions",$transactions);
    }else{
        send_response(false,"Une erreur s'est produite. Veuillez réessayer !");
    }
}

//Récupérer les ordre de tour
function ordrePaiement() {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['code_tontine']) || empty($data['code_tontine'])) {
        send_response(false, "Veuillez fournir le code de la tontine !");
        exit;
    }

    $pdo = getDB();

    $sql = "SELECT p.code_participant, d.nom_participant, d.prenoms_participant, o.ordre, o.statut
            FROM participer AS p
            JOIN participants AS d 
                ON d.code_participant = p.code_participant
            LEFT JOIN ordre_tirage AS o 
                ON o.code_tontine = p.code_tontine 
               AND o.code_participant = p.code_participant
            JOIN tontine AS t
                ON t.code_tontine = p.code_tontine
            WHERE p.code_tontine = ? 
            GROUP BY p.code_participant, d.nom_participant, d.prenoms_participant, o.ordre, o.statut
            ORDER BY o.ordre ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data['code_tontine']]);
    $resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($resultats) {
        $utilisateurs = [];
        foreach ($resultats as $resultat) {
            $utilisateurs[] = [
                "code_participant"   => $resultat['code_participant'],
                "nom_participant"    => $resultat['nom_participant'],
                "prenoms_participant"=> $resultat['prenoms_participant'],
                "ordre"              => $resultat['ordre'],
                "statut"             => $resultat['statut']
            ];
        }
        send_response(true, "Ordre de paiement (tours à venir)", $utilisateurs);
    } else {
        send_response(false, "Aucun tour à venir trouvé");
    }

    exit;
}


//Faire un retrait
function retrait() {
    $data = json_decode(file_get_contents("php://input"), true);
    if (empty($data['code_tontine']) || empty($data['code_participant'])) {
        send_response(false, "Veuillez remplir tous les champs !");
    }

    $pdo = getDB();

    try {
        $pdo->beginTransaction();

        // 1) Récupérer ordre, statut, tour_actuel et solde en lockant
        $sql = "SELECT o.ordre, o.statut, t.tour_actuel, w.solde_tontine
                FROM ordre_tirage o
                JOIN tontine t ON t.code_tontine = o.code_tontine
                JOIN wallet_tontine w ON w.code_tontine = o.code_tontine
                WHERE o.code_tontine = ? AND o.code_participant = ?
                FOR UPDATE";
        $q = $pdo->prepare($sql);
        $q->execute([$data['code_tontine'], $data['code_participant']]);
        $row = $q->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new Exception("Participant introuvable pour cette tontine.");
        }

        // 2) Vérifier que c'est SON tour et non déjà payé
        if ((int)$row['statut'] === 1) {
            throw new Exception("Ce tour est déjà marqué comme payé.");
        }
        if ((int)$row['ordre'] !== (int)$row['tour_actuel']) {
            throw new Exception("Ce n'est pas encore son tour.");
        }

        $montant = (int)$row['solde_tontine'];
        if ($montant <= 0) {
            throw new Exception("Solde insuffisant pour effectuer le retrait.");
        }

        // 3) Marquer l'ordre payé
        $u1 = $pdo->prepare("UPDATE ordre_tirage 
                             SET statut = 1
                             WHERE code_tontine = ? AND code_participant = ?");
        $u1->execute([$data['code_tontine'], $data['code_participant']]);

        // 4) Vider la cagnotte de CETTE tontine
        $u2 = $pdo->prepare("UPDATE wallet_tontine 
                             SET solde_tontine = 0 
                             WHERE code_tontine = ?");
        $u2->execute([$data['code_tontine']]);

        // 5) Avancer le tour de CETTE tontine
        $u3 = $pdo->prepare("UPDATE tontine 
                             SET tour_actuel = tour_actuel + 1 
                             WHERE code_tontine = ?");
        $u3->execute([$data['code_tontine']]);

        // 6) Historiser
        $u4 = $pdo->prepare("INSERT INTO paiement_tour 
            (code_participant, code_tontine, montant, date_paiement) 
            VALUES (?,?,?, NOW())");
        $u4->execute([$data['code_participant'], $data['code_tontine'], $montant]);

        $pdo->commit();

        send_response(true, "Retrait effectué avec succès !", ["montant" => $montant]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        send_response(false, $e->getMessage());
    }
}


?>