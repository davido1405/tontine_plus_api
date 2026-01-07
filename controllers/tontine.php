<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../config/config.php';

include_once __DIR__ . '/notifications.php';

include_once __DIR__ . '/participations.php';

include_once __DIR__ . '/../helpers/responses.php';

require_once __DIR__ . '/../vendor/autoload.php';

include_once __DIR__ . '/../manageJWT.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;


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

    //Vérifier le token utilisateur avant tous !
    //$decoder=verifier_token();

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['code_participant'],$data['nom_tontine'], $data['type_tontine'], $data['montant_cotisation'], $data['nombre_participant'], $data['frequence'],$data['frequence_paiement'])) {
        send_response(false, "Champs obligatoires manquants.");
    }
    
    
    $pdo=getDB();

    try{
        $pdo->beginTransaction();

        //Récupérer l'id_type_tontine
        $stmt1=$pdo->prepare("SELECT id_type_tontine FROM type_tontine WHERE libelle_type_tontine=?");
        $stmt1->execute([$data['type_tontine']]);
        $type=$stmt1->fetch(PDO::FETCH_ASSOC);

        if (!$type) {
            throw new Exception("Type de tontine invalide.");
        }

        //Récupérer l'id_frequence
        $stmt2=$pdo->prepare("SELECT id_frequence FROM frequence WHERE libelle_frequence=?");
        $stmt2->execute([$data['frequence']]);
        $frequence=$stmt2->fetch(PDO::FETCH_ASSOC);

        if (!$frequence) {
            throw new Exception("Fréquence invalide.");
        }

        //Récupérer l'id_frequence de paiement
        $stmt5=$pdo->prepare("SELECT id_frequence_paiement FROM frequence_paiement WHERE libelle_frequence_paiement=?");
        $stmt5->execute([$data['frequence_paiement']]);
        $frequence_paiement=$stmt5->fetch(PDO::FETCH_ASSOC);

        if (!$frequence_paiement) {
            throw new Exception("Fréquence de paiement invalide.");
        }


        //Vérifier l'unicité de l'organisation d'une tontine
        $stmt3=$pdo->prepare("SELECT code_tontine FROM organiser_tontine WHERE code_participant=?");
        $stmt3->execute([$data['code_participant']]);
        $organiseDeja=$stmt3->fetch(PDO::FETCH_ASSOC);

        if($organiseDeja){
            throw new Exception("Vous ne pouvez organiser que une tontine à la fois");
        }

        //Formater les parametres pour la verification
        $code_participant=$data['code_participant'];
        $nombre_participant=$data['nombre_participant'];
        $frequence_cotisation_libelle=$data['frequence'];
        $frequence_paiement_libelle=$data['frequence_paiement'];
        $montant_cotisation=$data['montant_cotisation'];
        //Vérifier le niveau kyc
        $verifier=verifier_kyc($code_participant,$nombre_participant,$frequence_cotisation_libelle,$frequence_paiement_libelle,$montant_cotisation);

        if(!$verifier){
            $pdo->rollBack();
            send_response(false, "Votre niveau de vérification est insuffisant pour réjoindre cette tontine. Veuillez fournir des informations supplémentaire à votre identification. Merci");
        }

        //Ajout de la tontine
        $sql=$pdo->prepare("INSERT INTO tontine(code_tontine,nom_tontine,montant_cotisation,nombre_participant,id_frequence,id_frequence_paiement,id_type_tontine,date_creation) VALUES(?,?,?,?,?,?,?,?)");

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
                $frequence_paiement['id_frequence_paiement'],
                $type['id_type_tontine'],
                $date,
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

        $code_wallet=code_wallet();
        $stmt=$pdo->prepare('INSERT INTO wallet_tontine(code_wallet,code_tontine,solde_tontine) VALUES(?,?,?)');
        $stmt->execute([$code_wallet,$code_ton,0]);

        $pdo->commit();

        send_response(true,"Tontine créée avec succès", 
        [
            "code_tontine" => $code_ton,
            "organisateur" => $data['code_participant'],
            "date_creation" => $date,
            "code_wallet" =>$code_wallet
        ]);
    }catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        send_response(false, $e->getMessage());
    }
}

//Générer un lien d'invitation
function GenererlienInvitation(){

    //Vérifier le tonken session d'abord
    $decoder=verifier_token();

    //Définir le type de données
    $data=json_decode(file_get_contents("php://input"),true);

    if(!isset($data['code_tontine'],$data['code_participant'])||empty($data['code_tontine'])||empty($data['code_participant'])){
        send_response(false,"Veuillez vérifier les différents champs");
    }

    $config = require __DIR__ .'/../config/config.php';
    $secret_code=$config['jwt_code_secret'];

    //Préparer le payload
    $payload=[
        'iss'=>$config['domain_name'],
        'iat'=>time(),
        'exp'=>time() + (24 * 60 * 60),//Valide 24H
        'code_organisateur'=>$data['code_participant'],
        'code_tontine'=>$data['code_tontine'],
        'scope'=>'invitation'
    ];

    $token=JWT::encode($payload,$secret_code,'HS256');

    //Enregistrement en base
    $pdo=getDB();

    try {
        //Récupérer le nombre d'utilisateur pour la tontine
        $stmt1=$pdo->prepare("SELECT nombre_participant as utilisateur_max FROM tontine WHERE code_tontine=?");
        $stmt1->execute([$data['code_tontine']]);
        $tontine=$stmt1->fetch(PDO::FETCH_ASSOC);
        if(!$tontine){
            send_response(false,"Cette tontine n'existe pas !");
        }

        $date_creation = date('Y-m-d H:i:s', $payload['iat']);
        $date_expiration = date('Y-m-d H:i:s', $payload['exp']);
        $stmt=$pdo->prepare("INSERT INTO lien_invitation(date_creation,date_expiration,code_organisateur,code_tontine,token,utilisation_max) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$date_creation,$date_expiration,$data['code_participant'],$data['code_tontine'],$token,$tontine['utilisateur_max']]);
        if($stmt->rowCount()===0){
            send_response(false,"Une erreur s'est produite lors de la génération du lien !");
        }
        //A modifier quand on passera en production
        $lienInvitation="djarrafinances://app/invite?token=".$token;

        send_response(true,"Lien généré avec succès !",[
            'lien'=>$lienInvitation
        ]);
    } catch (Exception $e) {
        send_response(false,"Une erreur s'est produite lors de la génération du lien");
    }
}

//Vérifier le lien
function verifierInvitation(){

    //Définir le type de donnée
    $data=json_decode(file_get_contents("php://input"),true);

    if(!isset($data['token'])||empty($data['token'])){
        send_response(false,"Veuillez vérifier les différents champs");
    }
    $token=$data['token'];

    $config = require __DIR__ .'/../config/config.php';
    $secret_code=$config['jwt_code_secret'];
    
    //Décoder le token récupéré
    try{
        $decode=JWT::decode($token, New key($secret_code,'HS256'));
        //Vérifier si le lien n'a pas expiré
        if($decode->exp < time()){
            send_response(false,"Ce lien a expiré !");
        }
        if($decode->scope!=='invitation'){
            send_response(false,"Format token incorrecte");
        }
        $code_tontine=$decode->code_tontine;
        $code_participant_hote=$decode->code_organisateur;

        
        $pdo=getDB();

        $pdo->beginTransaction();

        //Vérifier que la tontine existe et le que l'organisateur est bien à l'origine du lien
        $stmt=$pdo->prepare("SELECT t.*,o.*,f.libelle_frequence,c.libelle_frequence_paiement FROM tontine as t INNER JOIN organiser_tontine as o ON t.code_tontine=o.code_tontine INNER JOIN frequence as f ON f.id_frequence=t.id_frequence INNER JOIN frequence_paiement as c ON c.id_frequence_paiement=t.id_frequence_paiement  WHERE t.code_tontine=? AND o.code_participant=?");
        $stmt->execute([$code_tontine,$code_participant_hote]);
        $tontine=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$tontine){
            send_response(false,"L'émeteur de ce lien n'a pas les autorisation requise !");
        }
        //Vérifier que le lien est encore utilisable
        $stmt1=$pdo->prepare("SELECT * FROM lien_invitation WHERE code_tontine=? AND token=? AND compteur_utilisation<utilisation_max");
        $stmt1->execute([$code_tontine,$token]);
        $resultat=$stmt1->fetch(PDO::FETCH_ASSOC);
        if(!$resultat){
            send_response(false,"Lien invalide ou corrompus");
        }
        //Mettre à jour compteur utilisation
        $stmt2=$pdo->prepare("UPDATE lien_invitation SET compteur_utilisation=compteur_utilisation+1 WHERE code_tontine=? AND token=?");
        $stmt2->execute([$code_tontine,$token]);
        if($stmt2->rowCount()===0){
            send_response(false,"Une erreur s'est produite lors de la mise à jour de compteur d'utilisation du lien !");
        }
        $pdo->commit();
        //Tout est en règle le participant peut réjoindre la tontine
        send_response(true,"Lien validé avec succès !",[
            'code_tontine'=>$tontine['code_tontine'],
            'nom_tontine'=>$tontine['nom_tontine'],
            'montant_cotisation'=>$tontine['montant_cotisation'],
            'frequence_cotisation'=>$tontine['libelle_frequence'],
            'frequence_paiement'=>$tontine['libelle_frequence_paiement']
        ]);
    }catch(Exception $e){
        $pdo->rollBack();
        send_response(false,"Erreur complet au niveau de la transaction !");
    }
}


function get_tontine_details() {

    $decoder = verifier_token();

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['code_tontine']) || empty($data['code_tontine'])) {
        send_response(false, "L'identifiant de la tontine est requis.");
    }

    try {
        $pdo = getDB();
        
        // ✅ SOLUTION : Utiliser des sous-requêtes au lieu de JOIN avec COUNT
        $stmt = $pdo->prepare("SELECT 
                                    o.code_tontine, 
                                    o.nom_tontine, 
                                    o.montant_cotisation, 
                                    o.nombre_participant, 
                                    o.tour_actuel, 
                                    o.statut,
                                    o.date_creation, 
                                    o.etat_tontine,
                                    f.libelle_frequence,
                                    p.libelle_frequence_paiement, 
                                    t.libelle_type_tontine,
                                    w.code_wallet,
                                    (SELECT COUNT(*) 
                                     FROM participer 
                                     WHERE code_tontine = o.code_tontine) as participant_inscrit
                               FROM tontine as o
                               INNER JOIN type_tontine as t ON o.id_type_tontine = t.id_type_tontine
                               INNER JOIN frequence as f ON o.id_frequence = f.id_frequence
                               INNER JOIN frequence_paiement as p ON o.id_frequence_paiement = p.id_frequence_paiement
                               INNER JOIN wallet_tontine as w ON w.code_tontine = o.code_tontine
                               WHERE o.code_tontine = ?");
        
        $stmt->execute([$data['code_tontine']]);
        $tontine = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tontine) {
            send_response(false, "Tontine introuvable.");
        }

        // Variables
        $frequence_paiement = $tontine['libelle_frequence_paiement'];
        $frequence_cotisation = $tontine['libelle_frequence'];
        $nombre_participant = $tontine['nombre_participant'];
        $montant_cotisation = $tontine['montant_cotisation'];

        // Calculs de fréquence
        switch ($frequence_paiement) {
            case "Hebdomadaire":
                $duree_tour = 7;
                break;
            case "Mensuelle":
                $duree_tour = 30;
                break;
            case "Trimestrielle":
                $duree_tour = 90;
                break;
            default:
                $duree_tour = 7;
        }

        switch ($frequence_cotisation) {
            case "Journalière":
                $nombre_cotisation = $duree_tour;
                break;
            case "Hebdomadaire":
                $nombre_cotisation = ceil($duree_tour / 7);
                break;
            case "Mensuelle":
                $nombre_cotisation = ceil($duree_tour / 30);
                break;
            default:
                $nombre_cotisation = $duree_tour;
        }

        $cagnotte = $nombre_participant * $montant_cotisation * $nombre_cotisation;

        send_response(true, "Tontine récupérée avec succès", [
            "code_tontine" => $tontine['code_tontine'],
            "nom" => $tontine['nom_tontine'],
            "montant" => $tontine['montant_cotisation'],
            "nombre_participant" => $tontine['nombre_participant'],  
            "tour_actuel" => $tontine['tour_actuel'] ?? '0',  
            "frequence" => $tontine['libelle_frequence'],
            "frequence_paiement" => $tontine['libelle_frequence_paiement'],
            "cagnotte" => $cagnotte,
            "participant_inscrit" => $tontine['participant_inscrit'],
            "statut" => $tontine['statut'],
            "type" => $tontine['libelle_type_tontine'],
            "etat_tontine" => $tontine['etat_tontine'],
            "date_creation" => $tontine['date_creation'],
            "code_wallet" => $tontine['code_wallet']
        ]);

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

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();


    $data=json_decode(file_get_contents("php://input"),true);
    if(!isset($data['code_tontine'])||empty($data['code_tontine'])){
        send_response(false,"Veuillez vérifier tous les champs");
    }
    $pdo=getDB();
    $stmt=$pdo->prepare("SELECT p.code_participant, p.nom_participant,p.prenoms_participant,p.numro_mobile_money,p.indice_solvabilite,a.date_participation,t.libelle_participant FROM participants p INNER JOIN participer as a ON p.code_participant=a.code_participant INNER JOIN type_participants as t ON p.id_type_participant=t.id_type_participant WHERE code_tontine=? ORDER BY p.indice_solvabilite DESC");
    $stmt->execute([$data['code_tontine']]);
    $listeParticipants=$stmt->fetchAll(PDO::FETCH_ASSOC);

    if(!$listeParticipants){
        send_response(false,"Cette tontine est vide");
    }
    $formated=[];
    foreach($listeParticipants as $listeParticipant){
        $formated[]=[
            "code_participant"=>$listeParticipant['code_participant'],
            "nom" => $listeParticipant['nom_participant'],
            "prenoms" => $listeParticipant['prenoms_participant'],
            "mobile" => $listeParticipant['numro_mobile_money'],
            "date_participation" => $listeParticipant['date_participation'],
            "type" => $listeParticipant['libelle_participant'],
            "points_confiance"=>$listeParticipant['indice_solvabilite']
        ];
    }
    send_response(true,"Liste des participants de la tontine",$formated);
}


function lister_tour($code_tontine=null,$relancer=false){

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();

    // Récupération des données JSON uniquement si $code_tontine non fourni
    if ($code_tontine === null) {
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['code_tontine'])) {
            send_response(false, "Veuillez vérifier tous les champs");
        }
        $code_tontine = $data['code_tontine'];
        $relancer = !empty($data['relancer']) && ($data['relancer'] === true || $data['relancer'] === 'Oui');
    }
    $pdo=getDB();

    try {
        $pdo->beginTransaction();

        // Récupérer infos tontine
        $stmt=$pdo->prepare("
            SELECT t.*, o.libelle_type_tontine, fp.libelle_frequence_paiement, t.etat_tontine
            FROM tontine AS t 
            INNER JOIN type_tontine AS o ON o.id_type_tontine = t.id_type_tontine
            INNER JOIN frequence_paiement AS fp ON fp.id_frequence_paiement = t.id_frequence_paiement
            WHERE code_tontine=?
        ");
        $stmt->execute([$code_tontine]);
        $type=$stmt->fetch(PDO::FETCH_ASSOC);

        if(!$type){
            throw new Exception("Type non défini pour cette tontine");
        }
        
        $nombreLimite = (int) $type['nombre_participant'];

        // Nombre actuel de participants
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM participer WHERE code_tontine = ?");
        $stmt->execute([$code_tontine]);
        $nombreParticipant = (int) $stmt->fetchColumn();

        if($nombreParticipant<$nombreLimite){
            throw new Exception('Tontine incomplète');
        }

        // Vérifier si des tours existent déjà
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ordre_tirage WHERE code_tontine = ?");
        $stmt->execute([$code_tontine]);
        $toursExistants = (int) $stmt->fetchColumn();

        // Relancer si demandé et si la tontine est terminée
        if($relancer && $type['etat_tontine']==="Terminée"){
            //Archiver les anciens tours
            $pdo->prepare("
                INSERT INTO ordre_tirage_archive (code_tontine, code_participant, ordre, statut, date_tour, archived_at)
                SELECT code_tontine, code_participant, ordre, statut, date_tour, NOW()
                FROM ordre_tirage
                WHERE code_tontine=?
            ")->execute([$code_tontine]);

            //Supprimer les tours actifs
            $pdo->prepare("DELETE FROM ordre_tirage WHERE code_tontine=?")
                ->execute([$code_tontine]);
            
            //J'ai rajouté ça parce que j'ai remarqué que le tour_actuel n'était pas réinitilisé
            $pdo->prepare("UPDATE tontine SET tour_actuel=1,etat_tontine=? WHERE code_tontine=?")
                ->execute(["En cours",$code_tontine]);

            $toursExistants = 0; // On force régénération
        }
        
        if ($nombreParticipant == $nombreLimite && $toursExistants == 0) {
            $stmt1=$pdo->prepare("SELECT code_participant FROM participer WHERE code_tontine=?");
            $stmt1->execute([$code_tontine]);
            $participants=$stmt1->fetchAll(PDO::FETCH_ASSOC);

            $codes = array_column($participants, 'code_participant');

            //Melanger les tours si c'est un "Tirage"
            if ($type['libelle_type_tontine'] === 'Tirage') {
                shuffle($codes);
            }

            //Date du premier tour
            $startDate = new DateTime();

            switch($type['libelle_frequence_paiement']){
                case 'Mensuelle':     $startDate->modify('+1 month'); break;
                case 'Hebdomadaire':  $startDate->modify('+1 week'); break;
                case 'Trimestrielle': $startDate->modify('+3 month'); break;
            }

            foreach ($codes as $i => $code) {
                $ordre = $i + 1;
                $dateTour = clone $startDate;

                switch($type['libelle_frequence_paiement']){
                    case 'Mensuelle':     $dateTour->modify('+'.$i.' month'); break;
                    case 'Hebdomadaire':  $dateTour->modify('+'.($i*7).' days'); break;
                    case 'Trimestrielle': $dateTour->modify('+'.($i*3).' month'); break;
                }

                $stmt=$pdo->prepare("
                    INSERT INTO ordre_tirage(code_tontine, code_participant, ordre, statut, date_tour) 
                    VALUES(?,?,?,?,?)
                ");
                $stmt->execute([$code_tontine, $code, $ordre, 0, $dateTour->format('Y-m-d H:i:s')]);
            }
        }

        $pdo->commit();
        send_response(true,"Relance effectuée avec succès !");
    } catch (\Throwable $th) {
        if ($pdo->inTransaction()){
            $pdo->rollBack();
            send_response(false,"Erreur");
        }
    }
}



//Récupérer les infos wallet_tontine
function wallet_tontine_infos(){

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();


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

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();


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
    FROM cotisations c 
    INNER JOIN participants AS p ON p.code_participant = c.code_participant 
    INNER JOIN mode_paiement AS m ON m.id_mode_paiement = c.id_mode_paiement 
    INNER JOIN status_paiement AS s ON s.id_statut_paiement = c.id_statut_paiement 
    WHERE c.code_tontine = ?

    UNION ALL

    SELECT
        p.nom_participant,
        p.prenoms_participant,
        e.montant,
        e.date_paiement AS date_transaction,
        'Retrait' AS type_transaction,
        m.libelle_mode_paiement AS mode_paiement,
        s.libelle_statut_paiement AS statut
    FROM paiement_tour e 
    INNER JOIN participants AS p ON p.code_participant = e.code_participant 
    INNER JOIN mode_paiement AS m ON m.id_mode_paiement = e.id_mode_paiement 
    INNER JOIN status_paiement AS s ON s.id_statut_paiement = e.id_statut_paiement 
    WHERE e.code_tontine = ?

    UNION ALL

    SELECT 
        p.nom_participant,
        p.prenoms_participant, 
        cm.montant,
        COALESCE(cm.date_rattrapage, cm.date_manquee) AS date_transaction,
        'Rattrapage' AS type_transaction,
        m.libelle_mode_paiement AS mode_paiement,
        s.libelle_statut_paiement AS statut
    FROM cotisations_manquees cm
    INNER JOIN participants AS p ON p.code_participant = cm.code_participant
    INNER JOIN mode_paiement AS m ON cm.id_mode_paiement = m.id_mode_paiement 
    INNER JOIN status_paiement AS s ON cm.id_statut_paiement = s.id_statut_paiement 
    WHERE cm.code_tontine = ? AND cm.id_statut_paiement = ?

    ORDER BY date_transaction DESC");

    $stmt->execute([$data['code_tontine'],$data['code_tontine'],$data['code_tontine'],2]);
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

    $decoder = verifier_token();

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['code_tontine']) || empty($data['code_tontine'])) {
        send_response(false, "Veuillez fournir le code de la tontine !");
        return;
    }

    try {
        $pdo = getDB();

        // ✅ Récupérer les infos de la tontine
        $stmtTontine = $pdo->prepare("
            SELECT 
                t.code_tontine,
                t.montant_cotisation,
                t.nombre_participant,
                t.tour_actuel,
                f.libelle_frequence,
                fp.libelle_frequence_paiement
            FROM tontine t
            INNER JOIN frequence f ON t.id_frequence = f.id_frequence
            INNER JOIN frequence_paiement fp ON t.id_frequence_paiement = fp.id_frequence_paiement
            WHERE t.code_tontine = ?
        ");
        $stmtTontine->execute([$data['code_tontine']]);
        $tontine = $stmtTontine->fetch(PDO::FETCH_ASSOC);

        if (!$tontine) {
            send_response(false, "Tontine introuvable");
            return;
        }

        // Variables
        $frequence_paiement = $tontine['libelle_frequence_paiement'];
        $frequence_cotisation = $tontine['libelle_frequence'];
        $nombre_participant = $tontine['nombre_participant'];
        $montant_cotisation = $tontine['montant_cotisation'];

        // Calculs de fréquence
        switch ($frequence_paiement) {
            case "Hebdomadaire":
                $duree_tour = 7;
                break;
            case "Mensuelle":
                $duree_tour = 30;
                break;
            case "Trimestrielle":
                $duree_tour = 90;
                break;
            default:
                $duree_tour = 7;
        }

        switch ($frequence_cotisation) {
            case "Journalière":
                $nombre_cotisation = $duree_tour;
                break;
            case "Hebdomadaire":
                $nombre_cotisation = ceil($duree_tour / 7);
                break;
            case "Mensuelle":
                $nombre_cotisation = ceil($duree_tour / 30);
                break;
            default:
                $nombre_cotisation = $duree_tour;
        }

        $cagnotte = $nombre_participant * $montant_cotisation * $nombre_cotisation;
        $tour_actuel = $tontine['tour_actuel'] ?? 0;

        // ✅ CORRECTION : INNER JOIN pour ne récupérer QUE si ordre_tirage existe
        $sql = "SELECT 
                    o.code_participant, 
                    d.nom_participant, 
                    d.prenoms_participant, 
                    o.ordre,
                    o.statut,
                    o.date_tour,
                    CASE 
                        WHEN o.statut = 2 THEN 'complete'
                        WHEN o.statut = 1 THEN 'en_cours'
                        ELSE 'a_venir'
                    END as etat
                FROM ordre_tirage AS o
                INNER JOIN participants AS d 
                    ON d.code_participant = o.code_participant
                WHERE o.code_tontine = ? 
                ORDER BY o.ordre ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$data['code_tontine']]);
        $resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ✅ Si ordre_tirage est vide, retourner liste vide
        if (empty($resultats)) {
            send_response(true, "Ordre de paiement non généré", [
                'beneficiaires' => [],
                'statistiques' => [
                    'tours_completes' => 0,
                    'total_tours' => 0,
                    'tour_actuel' => 0,
                    'montant_cagnotte' => (double)$cagnotte
                ]
            ]);
            return;
        }

        // ✅ Traiter les résultats
        $beneficiaires = [];
        $tours_completes = 0;
        $total_tours = count($resultats);

        foreach ($resultats as $resultat) {
            // Compter les tours complétés
            if ($resultat['statut'] == 2) {
                $tours_completes++;
            }

            $beneficiaires[] = [
                "code_participant" => $resultat['code_participant'],
                "nom_participant" => $resultat['nom_participant'],
                "prenoms_participant" => $resultat['prenoms_participant'],
                "ordre" => (int)$resultat['ordre'],
                "statut" => (int)$resultat['statut'],
                "etat" => $resultat['etat'],
                "date_tour" => $resultat['date_tour'] ?? '',
                "montant" => (double)$cagnotte
            ];
        }

        // ✅ Retourner avec les statistiques
        send_response(true, "Ordre de paiement", [
            'beneficiaires' => $beneficiaires,
            'statistiques' => [
                'tours_completes' => $tours_completes,
                'total_tours' => $total_tours,
                'tour_actuel' => (int)$tour_actuel,
                'montant_cagnotte' => (double)$cagnotte
            ]
        ]);

    } catch (PDOException $e) {
        send_response(false, "Erreur SQL : " . $e->getMessage());
    } catch (Exception $e) {
        send_response(false, "Erreur : " . $e->getMessage());
    }
}

//Ancienne version de la fonction pour les retraits
function retrait_ancien(){

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();


    $data = json_decode(file_get_contents("php://input"), true);
    if (empty($data['code_tontine']) || empty($data['code_participant'])) {
        send_response(false, "Veuillez remplir tous les champs !");
    }

    $code_tontine=$data['code_tontine'];
    $pdo = getDB();
    try {
        $pdo->beginTransaction();

        // 1) Récupérer ordre, statut, tour_actuel et solde en lockant
        $sql = "SELECT o.ordre, o.statut, t.tour_actuel,t.etat_tontine,t.nombre_participant, w.solde_tontine
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
            throw new Exception("Ce n'est pas encore ton tour.");
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

        // 7) Vérifier l'état de la tontine si terminée ou pas
        //Récupérer le tour actuel et le nombre de participant
        $u6 =$pdo->prepare("SELECT nombre_participant,tour_actuel,etat_tontine FROM tontine WHERE code_tontine=?");
        $u6->execute([$data['code_tontine']]);
        $nombreP=$u6->fetch(PDO::FETCH_ASSOC);
        
        $etat="En cours";

        //Mise à jour maintenant de l'état de la tontine selon que tous les tours soit payés ou pas
        if($nombreP['tour_actuel']>$nombreP['nombre_participant']){
            $etat="Terminée";
            $u7=$pdo->prepare("UPDATE tontine SET etat_tontine=? WHERE code_tontine=?");
            $u7->execute([$etat,$data['code_tontine']]);

            //Récupérer les participants maintenant
            $stmtParticipe = $pdo->prepare("
                SELECT p.*, w.* 
                FROM participer p
                INNER JOIN tontine t ON p.code_tontine=t.code_tontine
                INNER JOIN participants w ON w.code_participant = p.code_participant
                WHERE p.code_tontine = ?
            ");
            $stmtParticipe->execute([$code_tontine]);
            $participants = $stmtParticipe->fetchAll(PDO::FETCH_ASSOC);

            if (!$participants) {
                error_log("Aucun participant trouvé pour la tontine: " . $code_tontine);
                throw new Exception("Aucun participant trouvé pour cette tontine");
            }

            //Envoyer un notification à tout les participants pour informer de la fin de la tontine
            foreach($participants as $participant){
                $token=$participant['fcm_token'];
                
                if(empty($token)) {
                    error_log("Token FCM vide pour participant: " . $participant['code_participant']);
                    continue;
                }
                
                $phrases_fin_tontine = [
                    "Encore une tontine qui se termine sans bagarre 🚀. On relance ou pas ? 😏😏",
                    "Mission accomplie ! Les cotisations sont dans la poche 💰. Qui est partant pour la prochaine ? 😎",
                    "Tontine terminée 🎉. Prochain tour, mêmes règles ou on innove ? 😉",
                    "Bravo à tous ! Cette session a été un succès 🚀. On remet ça bientôt ? 😏",
                    "Et voilà, une tontine de plus dans les annales 📜. Qui signe pour la suivante ? 😎",
                    "Pas de drame, que des gains 💸. On relance la machine ? 😏",
                    "Une tontine sans clash, c'est rare 🔥. Qui veut tenter la prochaine ? 😎",
                    "Caisse remplie ✅, sourires garantis 😁. On repart pour un tour ? 🎯"
                ];

                // Tirer une phrase aléatoire
                $contenu_aleatoire = $phrases_fin_tontine[array_rand($phrases_fin_tontine)];
                
                $result = sendPushNotification($token, "Fin de la tontine", $contenu_aleatoire);
                if($result) {
                    $notifications_envoyees++;
                } else {
                    error_log("Échec notification pour participant: " . $participant['code_participant']);
                }
        }
        }
        $pdo->commit();

        //Récupérer les nouvelles valeurs de tour_actuel, le nombre de participant et l'état de la tontine
        $u8 =$pdo->prepare("SELECT nombre_participant,tour_actuel,etat_tontine FROM tontine WHERE code_tontine=?");
        $u8->execute([$data['code_tontine']]);
        $newP=$u8->fetch(PDO::FETCH_ASSOC);

        $phrases_terminée = [
            "Retrait de $montant FCFA effectué avec succès. La tontine est terminée 🚀. On relance ou pas ? 😏",
            "Caisse vidée ✅. Retrait de $montant FCFA effectué. Tontine terminée 🎉",
            "Mission accomplie 💰 ! $montant FCFA retirés, tontine terminée. Qui est partant pour la prochaine ? 😎",
            "Et voilà, $montant FCFA dans la poche 😁. La tontine est terminée 🔥"
        ];
        $phrases_en_cours = [
            "Retrait de $montant FCFA effectué avec succès. Au suivant ! 😏",
            "Caisse approvisionnée 💸. Retrait de $montant FCFA effectué. On continue ! 🚀",
            "Retrait de $montant FCFA effectué ✅. Prochain participant, c’est votre tour ! 😎",
            "Montant de $montant FCFA retiré avec succès. La tontine continue 😁"
        ];

        $message = $etat == "Terminée" ? $phrases_terminée[array_rand($phrases_terminée)] : $phrases_en_cours[array_rand($phrases_en_cours)];

        send_response(true, $message, [
            'total_tour'=>$newP['nombre_participant'],
            'tour_actuel'=>$newP['tour_actuel'],
            'statut_tontine'=>$newP['etat_tontine']
            ]
        );

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        send_response(false, $e->getMessage());
    }
}



