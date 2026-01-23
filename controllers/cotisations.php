<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../helpers/responses.php';
include_once  __DIR__ . '/../controllers/notifications.php';
require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/../manageJWT.php';



use Firebase\JWT\JWT;


function code_cotisation() {
    $prefix = "COT";
    $date = date("ymd"); // Format YYMMDD
    $random = strtoupper(substr(uniqid(), -5)); // 5 derniers caractères aléatoires uniques

    return $prefix . "-" . $date . "-" . $random;
}

/**
 * Fonction pour l'ajout de pénalité
 */
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

//Notifications de paiement
function envoyer_notification_paiement($code_tontine,$code_participant,$montantCotiser){
    try {
        $pdo = getDB();

        //Récupérer le montant de cotisation de la tontine concernée
        $stmt1=$pdo->prepare("SELECT montant_cotisation as montant FROM tontine where code_tontine=?");
        $stmt1->execute([$code_tontine]);
        $resultat=$stmt1->fetch(PDO::FETCH_ASSOC);

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

        $nomParticipant = "";
        foreach($participants as $participant){
            if($participant['code_participant']==$code_participant){
                $nomParticipant=$participant['nom_participant']." ".$participant['prenoms_participant'];
                break;
            }
        }

        if(empty($nomParticipant)) {
            error_log("Participant payeur non trouvé: " . $code_participant);
            return false;
        }

        if($montantCotiser>$resultat['montant']){
            $montant=$montantCotiser;
            $contenu=$nomParticipant." vient de payer sa cotisation(".$montant." FCFA). Il a donc quelques tours d'avance. Qui le rattrape ?";
        }else{
            $montant=$resultat['montant'];
            $contenu=$nomParticipant." vient de payer sa cotisation(".$montant." FCFA). A qui le tour ?";
        }

        $notifications_envoyees = 0;
        foreach($participants as $participant){
            if($participant['code_participant']!=$code_participant){
                $token=$participant['fcm_token'];
                
                if(empty($token)) {
                    error_log("Token FCM vide pour participant: " . $participant['code_participant']);
                    continue;
                }

                $title="Paiement de cotisation";
                
                $result = sendPushNotification($token, $title, $contenu);
                if($result) {
                    $notifications_envoyees++;
                } else {
                    error_log("Échec notification pour participant: " . $participant['code_participant']);
                }
            }
        }
        
        error_log("Notifications envoyées: " . $notifications_envoyees . " sur " . (count($participants)-1));
        return true;
        
    } catch (Exception $e) {
        error_log("Erreur notification paiement: " . $e->getMessage());
        return false;
    }
}

//Fonction pour payer les cotisations
function payer_cotisation(){

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();

    $data=json_decode(file_get_contents('php://input'),true);

    if(!isset($data['code_tontine'],$data['code_participant'],$data['montant'],$data['libelle_mode_paiement'])|| empty($data['code_tontine'])||empty($data['code_participant'])||empty($data['montant'])||empty($data['libelle_mode_paiement'])){
        send_response(false,"Veuillez remplir tout les champs!");
    }
    $pdo=getDB();

    try {

        $pdo->beginTransaction();
        //Vérifier l'existance de la tontine
        $stmt4=$pdo->prepare("SELECT t.*,f.libelle_frequence_paiement as frequence_paiement, c.libelle_frequence as frequence_cotisation FROM tontine t INNER JOIN frequence_paiement as f ON f.id_frequence_paiement=t.id_frequence_paiement INNER JOIN frequence as c ON c.id_frequence=t.id_frequence WHERE t.code_tontine=?");
        $stmt4->execute([$data['code_tontine']]);
        $tontine=$stmt4->fetch(PDO::FETCH_ASSOC);


        //Récupérer la fréquence de paiement
        if($tontine['frequence_paiement']=="Hebdomadaire"){
            $periodicite=7;
        }elseif($tontine['frequence_paiement']=="Mensuelle"){
            $periodicite=30;
        }elseif($tontine['frequence_paiement']=="Trimestrielle"){
            $periodicite=90;
        }else{
            throw new Exception("Périodicité non définie");
        }

        //Récupérer la fréquence de cotisation
        if($tontine['frequence_cotisation']=="Journalière"){
            $nombre_cotisation=$periodicite;
        }elseif($tontine['frequence_cotisation']=="Hebdomadaire"){
            $nombre_cotisation=ceil($periodicite/7);
        }elseif($tontine['frequence_cotisation']=="Mensuelle"){
            $nombre_cotisation=ceil($periodicite/30);
        }else{
            throw new Exception("Nombre de cotisation impossible à déterminer");
        }

        // ========== NOUVELLE VÉRIFICATION : BLOCAGE DU SURPAIEMENT ==========

        // 1) Calculer le montant THÉORIQUE du tour actuel
        $montantParTour = $tontine['montant_cotisation'];

        $nbParticipants = (int)$tontine['nombre_participant'];

        $montantTheoriqueTour = $montantParTour * $nombre_cotisation;

        error_log("Tour " . $tontine['tour_actuel'] . " - Montant théorique : $montantTheoriqueTour FCFA à cotiser par participant");


        //Faut récupérer la date de la dernière distribution de gain comme nouveau point de départ pour la vérification de surpaiement
        // Ligne 59-67 : Récupérer la date de début du tour
        $verif = $pdo->prepare("
            SELECT p.date_paiement as dernier_paiement 
            FROM paiement_tour as p 
            WHERE p.code_tontine = ?
            ORDER BY p.date_paiement DESC 
            LIMIT 1
        ");
        $verif->execute([$data['code_tontine']]);
        $derniere_distribution = $verif->fetch(PDO::FETCH_ASSOC);

        // Si premier tour, utiliser date début tontine
        if ($derniere_distribution) {
            $date_debut_tour = $derniere_distribution['dernier_paiement'];
        } else {
            // Premier tour
            $stmt_date = $pdo->prepare("SELECT date_participation FROM participer WHERE code_tontine = ?  ORDER BY date_participation DESC LIMIT 1 ");
            $stmt_date->execute([$data['code_tontine']]);
            $date_tontine = $stmt_date->fetch(PDO::FETCH_ASSOC);
            $date_debut_tour = $date_tontine['date_participation'] ?? date('Y-m-d H:i:s');
        }

        error_log("Tour " . $tontine['tour_actuel'] . " - Date de référence : $date_debut_tour");

        $stmt_deja_cotise = $pdo->prepare("
            SELECT COALESCE(SUM(montant), 0) as total_deja_cotise
            FROM cotisations
            WHERE code_tontine = ?
            AND code_participant = ?
            AND id_statut_paiement = 2
            AND nombre_tour_avance = 0
           AND date_paiement > ?");//← AJOUTER CETTE LIGNE !
        $stmt_deja_cotise->execute([
            $data['code_tontine'],
            $data['code_participant'],
            $date_debut_tour]);//← AJOUTER CE PARAMÈTRE!


        $deja_cotise_row = $stmt_deja_cotise->fetch(PDO::FETCH_ASSOC);
        $montantDejaCotise = (float)$deja_cotise_row['total_deja_cotise'];

        error_log("Tour " . $tontine['tour_actuel'] . " - Déjà cotisé : $montantDejaCotise FCFA");

        // 3) Calculer ce qu'il RESTE à collecter
        $montantRestantACollecter = $montantTheoriqueTour - $montantDejaCotise;
        if ($montantRestantACollecter < 0) {
            $montantRestantACollecter = 0;
        }

        error_log("Tour " . $tontine['tour_actuel'] . " - Reste à collecter : $montantRestantACollecter FCFA");

        // 4) Calculer combien ce participant veut contribuer au tour ACTUEL
        $montantCotiserDemande = $data['montant'] / 1.02;

        // 4a) Compter les retards
        $stmt_retards = $pdo->prepare("SELECT COUNT(*) as nb_retards 
                                    FROM cotisations_manquees 
                                    WHERE id_statut_paiement = 1 
                                        AND code_tontine = ? 
                                        AND code_participant = ?");
        $stmt_retards->execute([$data['code_tontine'], $data['code_participant']]);
        $retards = $stmt_retards->fetch(PDO::FETCH_ASSOC);
        $nbRetards = (int)$retards['nb_retards'];

        // 4b) Montant qui ira au tour actuel (après paiement des retards)
        $montantApresRetards = $montantCotiserDemande - ($nbRetards * $montantParTour);
        if ($montantApresRetards < 0) {
            $montantApresRetards = 0;
        }

        $montantPourTourActuel = min($montantApresRetards, $montantParTour);

        // 5) VÉRIFIER que le montant ne dépasse pas le reste à collecter
        if ($montantPourTourActuel > $montantRestantACollecter) {
            throw new Exception(
                "❌ Montant trop élevé pour le tour actuel !\n\n" .
                "📊 Votre situation pour le tour " . $tontine['tour_actuel'] . " :\n" .
                "• Votre cotisation requise : " . number_format($montantTheoriqueTour, 0, ',', ' ') . " FCFA\n" .
                "  (" . $montantParTour . " FCFA/jour × " . $periodicite . " jours)\n\n" .
                "• Déjà payé par vous : " . number_format($montantDejaCotise, 0, ',', ' ') . " FCFA\n" .
                "• Reste à payer : " . number_format($montantRestantACollecter, 0, ',', ' ') . " FCFA\n\n" .
                ($nbRetards > 0 ? "• Vous avez " . $nbRetards . " retard(s) à régler en priorité\n\n" : "") .
                "💡 Après règlement des retards, votre paiement contribuerait " . 
                number_format($montantPourTourActuel, 0, ',', ' ') . " FCFA au tour actuel,\n" .
                "   mais vous ne devez payer que " . 
                number_format($montantRestantACollecter, 0, ',', ' ') . " FCFA pour ce tour.\n\n" .
                "Montant maximum autorisé : " . 
                number_format(($montantRestantACollecter + ($nbRetards * $montantParTour)) * 1.02, 0, ',', ' ') . " FCFA (avec frais 2%)"
            );
        }

        // ========== FIN DE LA VÉRIFICATION ==========

        // ========== VÉRIFICATION : LIMITES KYC (JOURNALIÈRE + MENSUELLE) ==========

        // Récupérer les limites journalière ET mensuelle
        $stmt9=$pdo->prepare("SELECT k.transaction_journaliere as limiteTransac,
                                     k.transaction_mensuelle as limiteMensuelle 
                             FROM participants as p 
                             INNER JOIN niveau_kyc as k ON p.id_niveau_kyc=k.id_niveau_kyc 
                             WHERE p.code_participant=?");
        $stmt9->execute([$data['code_participant']]);
        $niveau_kyc=$stmt9->fetch(PDO::FETCH_ASSOC);

        // Si transaction_mensuelle n'existe pas encore, utiliser des valeurs par défaut
        if (!isset($niveau_kyc['limiteMensuelle']) || $niveau_kyc['limiteMensuelle'] == 0) {
            $limites_mensuelles = [
                1 => 100000,   // KYC1 : 100k/mois
                2 => 500000,   // KYC2 : 500k/mois
                3 => 2000000,  // KYC3 : 2M/mois
                4 => 5000000   // KYC4 : 5M/mois
            ];
            
            $id_niveau_query = $pdo->prepare("SELECT id_niveau_kyc FROM participants WHERE code_participant = ?");
            $id_niveau_query->execute([$data['code_participant']]);
            $niveau = $id_niveau_query->fetch(PDO::FETCH_ASSOC);
            
            $niveau_kyc['limiteMensuelle'] = $limites_mensuelles[$niveau['id_niveau_kyc']] ?? 100000;
        }

        //Récupérer le total des transactions journalière et mensuelle effectuée pour vérification
        // Vérification JOURNALIÈRE (reset à minuit)
        $stmt10=$pdo->prepare("SELECT 
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
        $stmt10->execute([$data['code_participant'], $data['code_participant']]);
        $transaction_24H= $stmt10->fetch(PDO::FETCH_ASSOC);

        // Vérification MENSUELLE (reset le 1er du mois)
        $stmt_mensuel = $pdo->prepare("SELECT 
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
        $stmt_mensuel->execute([$data['code_participant'], $data['code_participant']]);
        $transaction_mois = $stmt_mensuel->fetch(PDO::FETCH_ASSOC);


        if(!$tontine){
            throw new Exception("Cette tontine n'existe pas.");
        }else if($tontine['statut']!="Pleine" || $tontine['etat_tontine']!="En cours"){
            throw new Exception("Vous n'êtes pas autorisé(e) à payer des cotisations pour le moment");
            //Corriger le calcul des frais ici après avoir corrigé au niveau de l'app
        }else if($data['montant']/1.02<$tontine['montant_cotisation']){
            throw new Exception("Veuillez saisir un montnant valide");
        }elseif ($transaction_24H['total_transactions_jour']+$data['montant']/1.02>$niveau_kyc['limiteTransac']) {
            throw new Exception("Le montant cotiser dépasse la limite journalière(".$niveau_kyc['limiteTransac'].") fixée pour votre profil");
        }elseif ($transaction_mois['total_transactions_mois']+$data['montant']/1.02>$niveau_kyc['limiteMensuelle']) {
            $reste_mois = $niveau_kyc['limiteMensuelle'] - $transaction_mois['total_transactions_mois'];
            $premier_jour_mois_prochain = date('Y-m-01', strtotime('first day of next month'));
            
            throw new Exception(
                "❌ Limite mensuelle dépassée !\n\n" .
                "Limite mensuelle : " . number_format($niveau_kyc['limiteMensuelle'], 0, ',', ' ') . " FCFA\n" .
                "Déjà utilisé ce mois : " . number_format($transaction_mois['total_transactions_mois'], 0, ',', ' ') . " FCFA\n" .
                "Reste disponible : " . number_format(max(0, $reste_mois), 0, ',', ' ') . " FCFA\n\n" .
                "💡 Quota renouvelé le " . date('d/m/Y', strtotime($premier_jour_mois_prochain)) . "."
            );
        }


        //Récupérer l'id du mode paiement
        $stmt1=$pdo->prepare("SELECT id_mode_paiement FROM mode_paiement WHERE libelle_mode_paiement=?");
        $stmt1->execute([$data['libelle_mode_paiement']]);
        $idModepai=$stmt1->fetch(PDO::FETCH_ASSOC);
        //Verifier la prise en compte du mode de paiement
        if(!$idModepai){
            throw new Exception("Mode paiement non pris en charge!");
        }

        

        //Calculer le montant cotisé
        $montantCotiser =$data['montant']/1.02;//Correspond maintenant au montant frais exclus parce que dans le cas de paiement en avance le montant sera supérieur au montant fixé //$tontine['montant_cotisation'];
        //Calculer la commission
        $commission = $data['montant'] - $montantCotiser;
        $montantRestant = $montantCotiser;

        // ========== FIN VÉRIFICATIONS KYC ==========

        //Si mode de paiement Wallet Djarra Mettre a jour
        if($data['libelle_mode_paiement']==="Wallet Djarra"){
            //Vérifie d'abord si fond suffisant donc récupérer d'abord
            $u=$pdo->prepare("SELECT solde_participant FROM wallet_participant WHERE code_participant=?");
            $u->execute([$data['code_participant']]);
            $row=$u->fetch(PDO::FETCH_ASSOC);
            if($row['solde_participant']<$montantCotiser){
                throw new Exception("Montant insuffisant. Solde disponible : " . $row['solde_participant'] . " FCFA");
            }
            // Débiter le wallet participant
            $maj1 = $pdo->prepare("UPDATE wallet_participant SET solde_participant = solde_participant - ? WHERE code_participant=?");
            $maj1->execute([(float)$montantCotiser, $data['code_participant']]);
            
            // Historiser le débit
            $historique = $pdo->prepare("INSERT INTO historique_wallet_participant 
                (code_participant, type_operation, montant, description, date_operation, code_tontine) 
                VALUES (?, 'Paiement cotisation', ?, ?, NOW(), ?)");
            $historique->execute([
                $data['code_participant'],
                $montantCotiser,
                "Cotisation tontine " . $data['code_tontine'],
                $data['code_tontine']
            ]);
        }

        //Payer les tours manqués en priorité s'il y en a
        $stmt7=$pdo->prepare("SELECT * FROM cotisations_manquees WHERE id_statut_paiement=? AND code_tontine=? AND code_participant=? ORDER BY date_manquee ASC");
        $stmt7->execute([1,$data['code_tontine'],$data['code_participant']]);
        $cotisations_manquees=$stmt7->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cotisations_manquees as $cotisation_manquee) {
            if ($montantRestant<$montantParTour) break;
            $stmt8=$pdo->prepare("UPDATE cotisations_manquees set id_statut_paiement=?,id_mode_paiement=?, date_rattrapage=? WHERE id_cotisation_manquee=?");
            $stmt8->execute([2,$idModepai['id_mode_paiement'],date('Y-m-d H:i:s'),$cotisation_manquee['id_cotisation_manquee']]);
            $montantRestant-=$montantParTour;
        }

        //En suite payer le tour en cours si possible
        if($montantRestant>=$montantParTour){
            //Générer un code de cotisation
            $code_coti=code_cotisation();

            $stmt3=$pdo->prepare("INSERT INTO cotisations(code_cotisation,code_tontine,code_participant,montant,nombre_tour_avance,date_paiement,id_mode_paiement,id_statut_paiement) VALUES(?,?,?,?,?,?,?,?)");
            $stmt3->execute([
                $code_coti,
                $data['code_tontine'],
                $data['code_participant'],
                $montantParTour,
                0,
                date('Y-m-d H:i:s'),
                $idModepai['id_mode_paiement'],
                2
            ]);
            $montantRestant-=$montantParTour;
        }

        //En fin payer les tours en avance si possible

        // 6️⃣ Si encore du montant → payer les tours en avance
        $tour_en_avance = floor($montantRestant / $montantParTour);

        if($tour_en_avance>0){
            //Générer un code de cotisation
            $code_coti=code_cotisation();

            $stmt3=$pdo->prepare("INSERT INTO cotisations(code_cotisation,code_tontine,code_participant,montant,nombre_tour_avance,date_paiement,id_mode_paiement,id_statut_paiement) VALUES(?,?,?,?,?,?,?,?)");
            $stmt3->execute([
                $code_coti,
                $data['code_tontine'],
                $data['code_participant'],
                $tour_en_avance*$tontine['montant_cotisation'],
                $tour_en_avance,
                date('Y-m-d H:i:s'),
                $idModepai['id_mode_paiement'],
                2
            ]);
            
            $montantRestant-=$tour_en_avance*$montantParTour;
        }

        //Mettre à jour le montant de commissions selon le mode de paiement
        
        $stmt6=$pdo->prepare("INSERT INTO commissions (operateur,montant_commission,date_paiement) VALUES(?,?,?)");
        $stmt6->execute([$data['libelle_mode_paiement'],$commission,date('Y-m-d H:i:s')]);

        //Mettre à jour le solde du wallet de la tontine
        $codeTontine = isset($data['code_tontine']) ? trim((string)$data['code_tontine']) : null;

        $maj = $pdo->prepare("UPDATE wallet_tontine 
                            SET solde_tontine = solde_tontine + ? 
                            WHERE code_tontine = ?");
        $maj->execute([(float)$montantCotiser, $codeTontine]);

        //Envoie du push pour notifier le paiement aux autres utilisateurs
        envoyer_notification_paiement($codeTontine,$data['code_participant'],$montantCotiser);
        
        $pdo->commit();
        send_response(true, "Paiement éffectué avec succès !");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        send_response(false, $e->getMessage());
    }
}


function payer_penalite() {

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();

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
            throw new UnexpectedValueException("Cette tontine n'existe pas.");
        }

        if($tontine['statut']!='Pleine' && $tontine['etat_tontine']!="En cours"){
            throw new UnexpectedValueException("Vous n'êtes pas autorisé(e) à payer des pénalités pour le moment.");
        }

        if ($data['montant'] != $tontine['montant_penalite']) {
            throw new UnexpectedValueException("Veuillez saisir un montant de pénalité valide.");
        }

        

        // Vérifier que la pénalité est bien non payée
        $stmtCheck = $pdo->prepare("SELECT * FROM penalites WHERE code_participant = ? AND code_tontine = ? AND statut = 'Non payée'");
        $stmtCheck->execute([$data['code_participant'],$data['code_tontine']]);
        $penalite = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$penalite) {
            throw new UnexpectedValueException("Aucune pénalité impayée trouvée.");
        }

        // Récupérer l'ID du mode de paiement
        $stmt1 = $pdo->prepare("SELECT id_mode_paiement FROM mode_paiement WHERE libelle_mode_paiement = ?");
        $stmt1->execute([$data['libelle_mode_paiement']]);
        $idModepai = $stmt1->fetch(PDO::FETCH_ASSOC);

        if (!$idModepai) {
            throw new UnexpectedValueException("Mode de paiement non pris en charge !");
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
            throw new UnexpectedValueException( "Une erreur s'est produite lors de la mis à jour du solde de la tontine");
        }

        $pdo->commit();
        send_response(true, "Pénalité payée avec succès.",$data['montant']);

    }catch(Throwable $e){
        if ($pdo->inTransaction()) $pdo->rollBack();
        send_response(false, $e->getMessage());
    };
}



function voir_mes_cotisations(){

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();


    $data=json_decode(file_get_contents('php://input'),true);

    if(!isset($data['code_participant'],$data['code_tontine'])){
        send_response(false,"Veuillez remplir tous les champs");
    }

    $pdo=getDB();

    // Récupérer toutes les cotisations (normales + manquées)
    $stmt1 = $pdo->prepare(
        "SELECT 
            c.code_cotisation AS code_transaction,
            c.code_participant, 
            c.code_tontine, 
            c.montant,
            c.nombre_tour_avance,
            c.date_paiement AS date_cotisation,
            c.date_paiement AS date_reference,
            m.libelle_mode_paiement,
            s.libelle_statut_paiement,
            'Cotisation' AS type_cotisation
        FROM cotisations c 
        INNER JOIN mode_paiement AS m ON c.id_mode_paiement = m.id_mode_paiement 
        INNER JOIN status_paiement AS s ON c.id_statut_paiement = s.id_statut_paiement 
        WHERE c.code_participant = ? AND c.code_tontine = ?
        
        UNION ALL
        
        SELECT 
            cm.code_cotisation_manquee AS code_transaction,
            cm.code_participant, 
            cm.code_tontine, 
            cm.montant,
            NULL AS nombre_tour_avance,
            cm.date_manquee AS date_cotisation,
            COALESCE(cm.date_rattrapage, cm.date_manquee) AS date_reference,
            m.libelle_mode_paiement,
            s.libelle_statut_paiement,
            'Rattrapage' AS type_cotisation
        FROM cotisations_manquees cm 
        INNER JOIN mode_paiement AS m ON cm.id_mode_paiement = m.id_mode_paiement 
        INNER JOIN status_paiement AS s ON cm.id_statut_paiement = s.id_statut_paiement 
        WHERE cm.code_participant = ? AND cm.code_tontine = ?
        
        ORDER BY date_reference DESC"
    );

    $stmt1->execute([
        $data['code_participant'], 
        $data['code_tontine'],
        $data['code_participant'], 
        $data['code_tontine']
    ]);
    $cotisations = $stmt1->fetchAll(PDO::FETCH_ASSOC);

    if(!$cotisations){
        send_response(false,"Vous n'avez encore payé aucune cotisation");
    }

    $formated=[];
    foreach($cotisations as $cotisation){
        $formated[]=[
            "code_cotisation"=>$cotisation['code_transaction'],
            "type_cotisation"=>$cotisation['type_cotisation'],
            "montant"=>$cotisation['montant'],
            "nombre_tour_avance"=>$cotisation['nombre_tour_avance'],
            "date_paiement"=>$cotisation['date_reference'],
            "mode_paiement"=>$cotisation['libelle_mode_paiement'],
            "statut_paiement"=>$cotisation['libelle_statut_paiement']
        ];
    }
    send_response(true,"Votre historique de cotisation est le suivant: ",$formated);
}


function voir_mes_penalites(){

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();


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

    //Vérifier le token utilisateur avant tous !
    $decoder=verifier_token();

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

    //Vérifier le token utilisateur avant tous !
    verifier_token();

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

//Voir repartition du montant à cotiser
function repartition_cotisation(){

    verifier_token();

    $data=json_decode(file_get_contents('php://input'),true);
    if(!isset($data['code_participant'],$data['code_tontine'],$data['montant']) || empty($data['code_participant']) || empty($data['code_tontine']) || empty($data['montant'])){
        send_response(false,"Vérifier tout les champs");
    }

    $code_tontine=$data['code_tontine'];
    $code_participant=$data['code_participant'];
    $montant=$data['montant'];

    $tour_en_cour_payable=false;


    $pdo=getDb();

    try {
        //Vérifier l'existance de la tontine
        $stmt4=$pdo->prepare("SELECT * FROM tontine WHERE code_tontine=?");
        $stmt4->execute([$data['code_tontine']]);
        $tontine=$stmt4->fetch(PDO::FETCH_ASSOC);

        if(!$tontine){
            send_response(false,"Cette tontine n'existe pas");
        }
        // ✅ RECOMMANDÉ
        // 1. Récupérer dernière distribution
        $stmt_date = $pdo->prepare("SELECT date_paiement FROM paiement_tour WHERE code_tontine = ? ORDER BY date_paiement DESC LIMIT 1");
        $stmt_date->execute([$code_tontine]);
        $derniere_distrib = $stmt_date->fetch(PDO::FETCH_ASSOC);

        // 2. Compter cotisations manquées
        if ($derniere_distrib) {
            $stmt1 = $pdo->prepare("SELECT COUNT(*) as nb_retards FROM cotisations_manquees WHERE code_tontine = ? AND code_participant = ? AND date_manquee > ? AND id_statut_paiement=?");
            $stmt1->execute([$code_tontine, $code_participant, $derniere_distrib['date_paiement'], 1]);
        } else {
            $stmt1 = $pdo->prepare("SELECT COUNT(*) as nb_retards FROM cotisations_manquees WHERE code_tontine = ? AND code_participant = ? AND id_statut_paiement=?");
            $stmt1->execute([$code_tontine, $code_participant,1]);
        }
        $retards=$stmt1->fetch(PDO::FETCH_ASSOC);

        $nombre_retards = (int)$retards['nb_retards'];

        // ✅ ÉTAPE 3 : SIMULER LA RÉPARTITION (comme payer_cotisation le ferait)
        $montantRestant = $montant;  // Montant net saisi
        
        $montantParTour = $tontine['montant_cotisation'];
        // 3.1 - Retards en priorité
        $montant_pour_retards = min($nombre_retards * $montantParTour, $montantRestant);
        $retards_payes = floor($montant_pour_retards / $montantParTour);
        $montantRestant -= ($retards_payes * $montantParTour);
        
        // 3.2 - Tour actuel
        $tour_actuel_payable = 0;
        $situation_tour = "Paiement impossible";
        
        if ($montantRestant >= $montantParTour) {
            $tour_actuel_payable = 1;
            $situation_tour = "Payable";
            $montantRestant -= $montantParTour;
        }
        
        // 3.3 - Tours en avance
        $tours_avance = floor($montantRestant / $montantParTour);
        $montant_pour_avance = $tours_avance * $montantParTour;
        $montantRestant -= $montant_pour_avance;

        // ✅ ÉTAPE 4 : CALCULER LES FRAIS (2% sur le montant effectif)
        $total_tours = $retards_payes + $tour_actuel_payable + $tours_avance;
        $montant_effectif = $total_tours * $montantParTour;
        $frais_totaux = $montant_effectif * 0.02;  // 2%
        $total_transaction = $montant_effectif + $frais_totaux;

        // ✅ RÉPONSE : PREVIEW DE LA RÉPARTITION
        send_response(true, "Aperçu de la répartition", [
            // Infos saisie
            "montant_saisi" => $montant,
            "montant_par_tour" => $montantParTour,
            
            // Répartition détaillée
            "cotisations_manquees" => $nombre_retards,
            "retards_payes" => $retards_payes,
            "montant_retards" => $retards_payes * $montantParTour,
            
            "tour_actuel_payable" => $tour_actuel_payable,
            "situation_tour" => $situation_tour,
            "montant_tour_actuel" => $tour_actuel_payable * $montantParTour,
            
            "tours_avance" => $tours_avance,
            "montant_avance" => $montant_pour_avance,
            
            // Totaux
            "total_tours_payes" => $total_tours,
            "montant_utilise" => $montant_effectif,
            "montant_non_utilise" => round($montantRestant, 2),
            
            // Frais et total
            "frais_totaux" => round($frais_totaux, 2),
            "total_transaction" => round($total_transaction, 2)
        ]);
    } catch (\Throwable $e) {
        send_response(false, $e->getMessage());
    } 
}