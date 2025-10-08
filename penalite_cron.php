<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/controllers/notifications.php';
require_once __DIR__ . '/helpers/responses.php';
require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;

$pdo = getDB();

// Récupérer toutes les tontines en cours
$tontines = $pdo->query("SELECT t.code_tontine,t.montant_cotisation, f.libelle_frequence FROM tontine as t INNER JOIN frequence as f ON f.id_frequence=t.id_frequence WHERE etat_tontine='En cours' AND statut='Pleine'")->fetchAll(PDO::FETCH_ASSOC);

try {
    $pdo->beginTransaction();

    foreach ($tontines as $tontine) {

        // Récupérer participants + dernière cotisation en 1 requête JOIN
        $participants = $pdo->prepare("
            SELECT p.code_participant, p.indice_solvabilite, p.fcm_token,c.nombre_tour_avance,
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
            $stmt = $pdo->prepare("SELECT date_paiement,nombre_tour_avance FROM cotisations WHERE code_participant = ? AND code_tontine = ? ORDER BY date_paiement DESC LIMIT 1");
            $stmt->execute([$code_participant, $tontine['code_tontine']]);
            $last = $stmt->fetch(PDO::FETCH_ASSOC);


            //Possibilité de payer plusieurs tour d'avance prise en compte maintenant
            if ($last) {
                $now = new DateTime();
                $datePai = new DateTime($last['date_paiement']);
                $days = $datePai->diff($now)->days;
                $nombre_tour_avance = isset($last['nombre_tour_avance']) ? (int)$last['nombre_tour_avance'] : 0;

                //Vérifier si pénalité
                $penalite = false;

                $doit_decrementer = false;


                if ($tontine['libelle_frequence'] == 'Mensuelle' && $days >= 30 && max(0,$nombre_tour_avance)===0) $doit_decrementer = true;
                if ($tontine['libelle_frequence'] == 'Hebdomadaire' && $days >= 7 && max(0,$nombre_tour_avance)===0) $doit_decrementer = true;
                if ($tontine['libelle_frequence'] == 'Journalière' && $days >= 1 && max(0,$nombre_tour_avance)===0) $doit_decrementer = true;

                if ($doit_decrementer && $nombre_tour_avance === 0) {
                    $penalite = true;
                }

                if ($penalite) {
                    $nouvel_indice=max(0,$indice_precedent-5);
                    //Inserer dans la table cotisations_manques
                    $stmt3=$pdo->prepare("INSERT INTO cotisations_manquees(code_participant,code_tontine,montant,date_manque) VALUES (?,?,?,?)");
                    $stmt3->execute([$part['code_participant'],$tontine['code_tontine'],$tontine['montant_cotisation'],date('Y-m-d H:i:s')]);
                    
                    sendPushNotification($part['fcm_token'], "Alerte cotisation de manquée", "Pensez à vous acquiter de toutes vos cotisations le plus tôt possible. Merci !");
                }else{
                    $nouvel_indice=min(100,$indice_precedent+5);
                    // Mettre à jour le nombre de tour en avance(-1)
                    // 1. Récupérer la dernière cotisation
                    $stmt = $pdo->prepare("SELECT code_cotisation FROM cotisations WHERE code_participant = ? AND code_tontine = ? ORDER BY date_paiement DESC LIMIT 1");
                    $stmt->execute([$code_participant, $tontine['code_tontine']]);
                    $code_cotisation = $stmt->fetchColumn();

                    if ($code_cotisation) {
                        // 2. Mettre à jour uniquement celle-ci
                        $pdo->prepare("UPDATE cotisations SET nombre_tour_avance = GREATEST(nombre_tour_avance - 1, 0) WHERE code_cotisation = ?")
                            ->execute([$code_cotisation]);
                    }
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

                //Vérifier si historique déjà mis à jour
                $exists = $pdo->prepare("
                    SELECT COUNT(*) FROM historique_indice_solvabilite 
                    WHERE code_participant=? AND code_tontine=? AND DATE(date_derniere_maj)=CURDATE()
                ");
                $exists->execute([$code_participant, $tontine['code_tontine']]);
                if ($exists->fetchColumn() == 0) {
                    // insérer ici
                    //Archiver l'indice avant
                    $stmt1=$pdo->prepare("INSERT INTO historique_indice_solvabilite(code_participant,code_tontine,indice_solvabilite,date_derniere_maj,statut) VALUES(?,?,?,?,?)");
                    $stmt1->execute([$code_participant,$tontine['code_tontine'],$nouvel_indice,date('Y-m-d H:i:s'),$statut]);
                }
                // Mise à jour de l'indice de solvabilité
                $stmt2 = $pdo->prepare("UPDATE participants set indice_solvabilite=? WHERE code_participant = ?");
                $stmt2->execute([$nouvel_indice,$code_participant]);
                
            }
    }
    }
    $pdo->commit();
} catch (\Throwable $e) {
    file_put_contents(__DIR__ . '/logs/solvabilite.log', date('Y-m-d H:i:s') . ' - ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    $pdo->rollBack();
    send_response(false, $e->getMessage());
}
send_response(true,"Indice de solvabilité mis à jour");

