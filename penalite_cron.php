<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/controllers/notifications.php';
require_once __DIR__ . '/helpers/responses.php';
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;

$pdo = getDB();

// Récupérer toutes les tontines en cours
$tontines = $pdo->query("SELECT code_tontine, id_frequence FROM tontine WHERE etat_tontine='En cours'")->fetchAll(PDO::FETCH_ASSOC);

try {
    $pdo->beginTransaction();

    foreach ($tontines as $tontine) {
        // Récupérer participants + dernière cotisation en 1 requête JOIN
        $participants = $pdo->prepare("
            SELECT p.code_participant, p.indice_solvabilite, p.fcm_token,
                   c.date_paiement
            FROM participer AS d
            INNER JOIN participants AS p ON d.code_participant=p.code_participant
            LEFT JOIN (
                SELECT code_participant, code_tontine, MAX(date_paiement) AS date_paiement
                FROM cotisations
                GROUP BY code_participant, code_tontine
            ) AS c ON c.code_participant = p.code_participant AND c.code_tontine = d.code_tontine
            WHERE d.code_tontine = ?
        ");
        $participants->execute([$tontine['code_tontine']]);
        // Boucle sur participants...
        foreach ($participants->fetchAll(PDO::FETCH_ASSOC) as $part) {
        
        $code_participant = $part['code_participant'];
        $indice_precedent=(int) $part['indice_solvabilite'];
        $nouvel_indice = $indice_precedent;
        
        
        // Dernière cotisation
        $stmt = $pdo->prepare("SELECT date_paiement FROM cotisations WHERE code_participant = ? AND code_tontine = ? ORDER BY date_paiement DESC LIMIT 1");
        $stmt->execute([$code_participant, $tontine['code_tontine']]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);


        //Penser à prendre en compte la possibilité de payer plusieurs tour d'avance


        if ($last) {
            $now = new DateTime();
            $datePai = new DateTime($last['date_paiement']);
            $days = $datePai->diff($now)->days;

            //Vérifier si pénalité
            $penalite = false;
            if ($libelle == 'Mensuelle' && $days >= 30) $penalite = true;
            if ($libelle == 'Hebdomadaire' && $days >= 7) $penalite = true;
            if ($libelle == 'Journalière' && $days >= 1) $penalite = true;

            if ($penalite) {
                $nouvel_indice=max(0,$indice_precedent-5);
                sendPushNotification($part['fcm_token'], "Alerte retard de paiement", "Pensez à vous acquiter de toutes vos cotisations le plus tôt possible. Merci !");
            }else{
                $nouvel_indice=min(100,$indice_precedent+5);
            };

            // Déterminer statut
            if ($nouvel_indice <= 25) {
                $statut = "Faible";
            } elseif ($nouvel_indice <= 50) {
                $statut = "Moyen";
            } elseif ($nouvel_indice <= 75) {
                $statut = "Bon";
            } else {
                $statut = "Excellent";
            }
            
            //Archiver l'indice avant
            $stmt1=$pdo->prepare("INSERT INTO historique_indice_solvabilite(code_participant,code_tontine,indice_solvabilite,date_derniere_maj,statut) VALUES(?,?,?,?,?)");
            $stmt1->execute([$code_participant,$tontine['code_tontine'],$nouvel_indice,date('Y-m-d H:i:s'),$statut]);
                
            // Mise à jour de l'indice de solvabilité
            $stmt2 = $pdo->prepare("UPDATE participants set indice_solvabilite=? WHERE code_participant = ?");
            $stmt2->execute([$nouvel_indice,$code_participant]);
            
        }
    }
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    send_response(false, $e->getMessage());
}
send_response(true,"Indice de solvabilité mis à jour");
?>
