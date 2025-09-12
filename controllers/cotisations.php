<?php
include_once __DIR__ . '/../config/db.php';

include_once __DIR__ . '/../helpers/responses.php';


function code_cotisation() {
    $prefix = "COT";
    $date = date("ymd"); // Format YYMMDD
    $random = strtoupper(substr(uniqid(), -5)); // 5 derniers caractères aléatoires uniques

    return $prefix . "-" . $date . "-" . $random;
}

function ajouter_penalites(){
    $data=json_decode(file_get_contents('php://input'),true);

    if(!isset($data['code_participant'],$data['code_tontine'],$data['montant'],$data['raison'])){
        send_response(false,"Veuillez remplir tous les champs");
    }

    $pdo=getDB();

    try {

        $pdo->beginTransaction();

        //Recupérer la date de la dernière cotisation
        $stmt1=$pdo->prepare("SELECT * FROM cotisations WHERE code_participant=? ORDER BY date_paiement DESC LIMIT 1");
        $stmt1->execute([$data['code_participant']]);
        $dernierPaiement=$stmt1->fetch(PDO::FETCH_ASSOC);

        if (!$dernierPaiement) {
            throw new Exception("Aucune cotisation enregistrée pour ce participant.");
        }

        //Recupérer l'id_frequence de paiment de la tontine
        $stmt2=$pdo->prepare("SELECT * FROM tontine WHERE code_tontine=?");
        $stmt2->execute([$data['code_tontine']]);
        $tontine=$stmt2->fetch(PDO::FETCH_ASSOC);

        if (!$tontine) {
            throw new Exception("Tontine introuvable");
        }

        //Recupérer la frequence de paiment de la tontine
        $stmt3=$pdo->prepare("SELECT * FROM frequence WHERE id_frequence=?");
        $stmt3->execute([$tontine['id_frequence']]);
        $frequence=$stmt3->fetch(PDO::FETCH_ASSOC);

        if (!$frequence) {
            throw new Exception("Fréquence introuvable");
        }

        $now = new DateTime();
        $datePai = new DateTime($dernierPaiement['date_paiement']); // suppose que tu l’as récupéré dans la BDD
        $interval = $datePai->diff($now);//recupère le nombre de jour du dernier paiement jusqu'à maintenant


        //Vérifier si penalité ou pas
        $ajouter_penalite = false;

        switch($frequence['libelle_frequence']){
            case 'Mensuelle':
                if ($interval->days >= 30) $ajouter_penalite = true;
                break;
            case 'Hebdomadaire':
                if ($interval->days >= 7) $ajouter_penalite = true;
                break;
            case 'Journalière':
                if ($interval->days >= 1) $ajouter_penalite = true;
                break;
            default:
                throw new Exception("Type de fréquence de cotisation non pris en compte");
                break;
        }

        if ($ajouter_penalite) {
            $stmt5 = $pdo->prepare("INSERT INTO penalites(code_participant, code_tontine, montant, raison, date_penalite, statut) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt5->execute([
                $data['code_participant'],
                $data['code_tontine'],
                $data['montant'],
                $data['raison'] ?? "Retard de paiement",
                date("Y-m-d H:i:s"),
                $data['statut'] ?? "Non payée"
            ]);

            if ($stmt5->rowCount()==0) {
                throw new Exception("Erreur lors de l'ajout de la pénalité");
            }
        }

        $pdo->commit();

        $message=$ajouter_penalite==true? "Pénalité de " . $data['montant'] . " ajoutée avec succès !":"Aucune pénalité à ajouter. Le délai n'est pas dépassé.";
        send_response(true,$message);
    }  catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        send_response(false, $e->getMessage());
    }

}


//Fonction pour payer les cotisations
function payer_cotisation(){
    $data=json_decode(file_get_contents('php://input'),true);

    if(!isset($data['code_tontine'],$data['code_participant'],$data['montant'],$data['libelle_mode_paiement'])|| empty($data['code_tontine'])||empty($data['code_participant'])||empty($data['montant'])||empty($data['libelle_mode_paiement'])){
        send_response(false,"Veuillez remplir tout les champs!");
    }
    $pdo=getDB();

    try {

        $pdo->beginTransaction();
        //Vérifier l'existance de la tontine
        $stmt4=$pdo->prepare("SELECT * FROM tontine WHERE code_tontine=?");
        $stmt4->execute([$data['code_tontine']]);
        $tontine=$stmt4->fetch(PDO::FETCH_ASSOC);

        if(!$tontine){
            throw new Exception("Cette tontine n'existe pas.");
        }else if($tontine['statut']!="Pleine" || $tontine['etat_tontine']!="En cours"){
            throw new Exception("Vous n'êtes pas autorisé(e) à payer des cotisations pour le moment");
        }else if($data['montant']<$tontine['montant_cotisation']){
            throw new Exception("Veuillez saisir un montnant valide");
        }

        //Récupérer l'id du mode paiement
        $stmt1=$pdo->prepare("SELECT id_mode_paiement FROM mode_paiement WHERE libelle_mode_paiement=?");
        $stmt1->execute([$data['libelle_mode_paiement']]);
        $idModepai=$stmt1->fetch(PDO::FETCH_ASSOC);
        //Verifier la prise en compte du mode de paiement
        if(!$idModepai){
            throw new Exception("Mode paiement non pris en charge!");
        }

        $commission=$data['montant']-$tontine['montant_cotisation'];
        $montantCotiser=$data['montant']-$commission;

        //Générer un code de cotisation
        $code_coti=code_cotisation();

        $stmt3=$pdo->prepare("INSERT INTO cotisations(code_cotisation,code_tontine,code_participant,montant,date_paiement,id_mode_paiement,id_statut_paiement) VALUES(?,?,?,?,?,?,?)");
        $stmt3->execute([
            $code_coti,
            $data['code_tontine'],
            $data['code_participant'],
            $montantCotiser,
            date('Y-m-d H:i:s'),
            $idModepai['id_mode_paiement'],
            2
        ]);
        $row=$stmt3->rowCount();
        if($row==0){
           throw new Exception("Le paiement a échoué! Veuillez réessayer");
        }

        //Mettre à jour le solde de commissions selon le mode de paiement
        switch ($data['libelle_mode_paiement']){
            case 'Wave':
                $colonne='Wave';
                break;
            case 'MTN Money':
                $colonne='MTN Money';
                break;
            case 'Moov Money':
                $colonne='MOOV Money';
                break;
            case 'Orange Money':
                $colonne='Orange Money';
                break;
            default:
                throw new Exception("Une erreur s'est produite lors de la récupération de la commission");
            break;
        }
        $stmt=$pdo->prepare("SELECT `$colonne` FROM commissions WHERE id_commissions=1");
        $stmt->execute();
        $ancienSoldeCommission=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$ancienSoldeCommission){
            throw new Exception("Une erreur s'est produite lors de la récupération de l'ancien solde de commission");
        }
        $stmtCommission1=$pdo->prepare("UPDATE commissions SET `$colonne`=?");
        $stmtCommission1->execute([$ancienSoldeCommission[$colonne]+$commission]);
        if(!$stmtCommission1){
            throw new Exception("Une erreur s'est produite lors de la récupération de la commission");
        }

        //Mettre à jour le solde du wallet de la tontine
        //1-Récupérer l'ancien solde
        $stmt=$pdo->prepare("SELECT solde_tontine FROM wallet_tontine WHERE code_tontine=?");
        $stmt->execute([$data['code_tontine']]);
        $ancienSolde=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$ancienSolde){
            throw new Exception("Une erreur s'est produite lors de la récupération de l'ancien solde de la tontine");
        }
        //2-Mettre à jour le nouveau solde
        $nouveauSolde=$ancienSolde['solde_tontine']+$montantCotiser;
        $maj=$pdo->prepare("UPDATE wallet_tontine SET solde_tontine=? WHERE code_tontine=?");
        $maj->execute([$nouveauSolde,$data['code_tontine']]);
        if($maj->rowCount()==0){
            throw new Exception("Une erreur s'est produite lors de votre paiement. Veuillez réessayer plus tard. Merci !");
        }
        if($maj->errorCode() !== "00000"){
            throw new Exception("Erreur SQL lors de la mise à jour du solde tontine");
        }

        $pdo->commit();
        send_response(true, "Paiement éffectué avec succès !");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        send_response(false, $e->getMessage());
    }
}


function payer_penalite() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (
        !isset($data['code_tontine'], $data['code_participant'], $data['montant'], $data['libelle_mode_paiement']) ||
        empty($data['code_tontine']) || empty($data['code_participant']) || empty($data['montant']) || empty($data['libelle_mode_paiement'])
    ) {
        send_response(false, "Veuillez remplir tous les champs !");
    }

    $pdo = getDB();
    try{

        $pdo->beginTransaction();

        // Vérifier l'existence de la tontine
        $stmt = $pdo->prepare("SELECT montant_penalite,etat_tontine,statut FROM tontine WHERE code_tontine = ?");
        $stmt->execute([$data['code_tontine']]);
        $tontine = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tontine) {
            throw new Exception("Cette tontine n'existe pas.");
        }

        if($tontine['statut']!='Pleine' && $tontine['etat_tontine']!="En cours"){
            throw new Exception("Vous n'êtes pas autorisé(e) à payer des pénalités pour le moment.");
        }

        if ($data['montant'] != $tontine['montant_penalite']) {
            throw new Exception("Veuillez saisir un montant de pénalité valide.");
        }

        

        // Vérifier que la pénalité est bien non payée
        $stmtCheck = $pdo->prepare("SELECT * FROM penalites WHERE code_participant = ? AND code_tontine = ? AND statut = 'Non payée'");
        $stmtCheck->execute([$data['code_participant'],$data['code_tontine']]);
        $penalite = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$penalite) {
            throw new Exception("Aucune pénalité impayée trouvée.");
        }

        // Récupérer l'ID du mode de paiement
        $stmt1 = $pdo->prepare("SELECT id_mode_paiement FROM mode_paiement WHERE libelle_mode_paiement = ?");
        $stmt1->execute([$data['libelle_mode_paiement']]);
        $idModepai = $stmt1->fetch(PDO::FETCH_ASSOC);

        if (!$idModepai) {
            throw new Exception("Mode de paiement non pris en charge !");
        }

        // Mise à jour de la pénalité
        $stmtUpdate = $pdo->prepare("
            UPDATE penalites 
            SET statut = ?, date_paiement = ?, id_mode_paiement = ? 
            WHERE id_penalite = ?
        ");
        $stmtUpdate->execute([
            "Payée",
            date('Y-m-d H:i:s'),
            $idModepai['id_mode_paiement'],
            $penalite['id_penalite']
        ]);

        //Mettre à jour le solde du wallet de la tontine
        //1-Récupérer l'ancien solde
        $stmt=$pdo->prepare("SELECT solde_tontine FROM wallet_tontine WHERE code_tontine=?");
        $stmt->execute([$data['code_tontine']]);
        $ancienSolde=$stmt->fetch(PDO::FETCH_ASSOC);
        //2-Ajouter le montant de la pénalité
        $nouveauSolde=$ancienSolde['solde_tontine']+$data['montant'];
        $maj=$pdo->prepare("UPDATE wallet_tontine SET solde_tontine=? WHERE code_tontine=?");
        $maj->execute([$nouveauSolde,$data['code_tontine']]);
        if($maj->rowCount()==0){
            throw new Exception( "Une erreur s'est produite lors de la mis à jour du solde de la tontine");
        }

        $pdo->commit();
        send_response(true, "Pénalité payée avec succès.",$data['montant']);

    }catch(Throwable $e){
        if ($pdo->inTransaction()) $pdo->rollBack();
        send_response(false, $e->getMessage());
};
}



function voir_mes_cotisations(){
    $data=json_decode(file_get_contents('php://input'),true);

    if(!isset($data['code_participant'],$data['code_tontine'])){
        send_response(false,"Veuillez remplir tous les champs");
    }

    $pdo=getDB();

    //Recupérer la date de la dernière cotisation
    $stmt1=$pdo->prepare("SELECT c.code_cotisation, c.code_tontine, c.code_participant,c.montant,c.date_paiement,m.libelle_mode_paiement,s.libelle_statut_paiement FROM cotisations c INNER JOIN mode_paiement as m ON c.id_mode_paiement=m.id_mode_paiement INNER JOIN status_paiement as s ON c.id_statut_paiement=s.id_statut_paiement WHERE code_participant=? AND code_tontine=? ORDER BY date_paiement DESC ");
    $stmt1->execute([$data['code_participant'],$data['code_tontine']]);
    $cotisations=$stmt1->fetchAll(PDO::FETCH_ASSOC);

    if(!$cotisations){
        send_response(false,"Vous n'avez encore payé aucune cotisation");
    }

    $formated=[];
    foreach($cotisations as $cotisation){
        $formated[]=[
            "code_cotisation"=>$cotisation['code_cotisation'],
            "montant"=>$cotisation['montant'],
            "date_paiement"=>$cotisation['date_paiement'],
            "mode_paiement"=>$cotisation['libelle_mode_paiement'],
            "statut_paiement"=>$cotisation['libelle_statut_paiement']
        ];
    }
    send_response(true,"Votre historique de cotisation est le suivant: ",$formated);
}


function voir_mes_penalites(){
    $data=json_decode(file_get_contents('php://input'),true);

    if(!isset($data['code_participant'],$data['code_tontine'])){
        send_response(false,"Veuillez remplir tous les champs");
    }

    $pdo=getDB();

    //Recupérer la date de la dernière cotisation
    $stmt1=$pdo->prepare("SELECT * FROM penalites WHERE code_participant=? AND statut=? ORDER BY date_paiement DESC ");
    $stmt1->execute([$data['code_participant'],'Payée']);
    $penalites=$stmt1->fetchAll(PDO::FETCH_ASSOC);

    if(!$penalites){
        send_response(false,"Historique de pénalités vide");
    }

    $formated=[];
    foreach($penalites as $penalite){
        $formated[]=[
            "raison"=>$penalite['raison'],
            "montant"=>$penalite['montant'],
            "date_penalite"=>$penalite['date_penalite'],
            "date_paiement"=>$penalite['date_paiement'],
            "statut_paiement"=>$penalite['statut']
        ];
    }
    send_response(true,"Votre historique de pénalités est le suivant: ",$formated);
}

function total_cotisation(){
    $data=json_decode(file_get_contents('php://input'),true);

    if(!isset($data['code_participant'],$data['code_tontine'])){
        send_response(false,"Veuillez remplir tous les champs");
    }

    $pdo=getDB();
    $stmt=$pdo->prepare("SELECT SUM(montant) AS total_paye FROM cotisations WHERE code_participant = ? AND code_tontine=? AND id_statut_paiement = ? ");
    $stmt->execute([$data['code_participant'],$data['code_tontine'],2]);
    $totalCotisation=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$totalCotisation['total_paye']){
        send_response(false,"Vous avez cotisé au total: ",0);
    }
    send_response(true,"Vous avez cotisé au total: ",$totalCotisation['total_paye']);
}

function total_penalite(){
    $data=json_decode(file_get_contents('php://input'),true);

    if(!isset($data['code_participant'],$data['code_tontine'])||empty($data['code_participant'])||empty($data['code_tontine'])){
        send_response(false,"Veuillez remplir tous les champs");
    }

    $pdo=getDB();
    $stmt=$pdo->prepare("SELECT SUM(montant) AS total_penalite FROM penalites WHERE code_participant = ? AND code_tontine=? AND statut= ? ");
    $stmt->execute([$data['code_participant'],$data['code_tontine'],"Payée"]);
    $totalPenalite=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$totalPenalite['total_penalite']){
        send_response(false,"Vous avez cotisé au total: ",0);
    }
    send_response(true,"Vous avez cotisé au total: ",$totalPenalite['total_penalite']);
}
?>