<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/controllers/notifications.php';
require_once __DIR__ . '/helpers/responses.php';
require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;

function code_cotisation_manquees() {
    $prefix = "COTM";
    $date = date("ymd");
    $random = strtoupper(substr(uniqid(), -5));
    return $prefix . "-" . $date . "-" . $random;
}

$pdo = getDB();

// Récupérer toutes les tontines en cours
$tontines = $pdo->query("
    SELECT t.code_tontine, t.montant_cotisation, f.libelle_frequence 
    FROM tontine as t 
    INNER JOIN frequence as f ON f.id_frequence = t.id_frequence 
    WHERE etat_tontine = 'En cours' AND statut = 'Pleine'
")->fetchAll(PDO::FETCH_ASSOC);

try {
    $pdo->beginTransaction();

    foreach ($tontines as $tontine) {

        // Récupérer participants
        $participants = $pdo->prepare("
            SELECT p.code_participant, p.indice_solvabilite, p.fcm_token
            FROM participer AS d
            INNER JOIN participants AS p ON d.code_participant = p.code_participant
            WHERE d.code_tontine = ?
        ");
        $participants->execute([$tontine['code_tontine']]);

        foreach ($participants->fetchAll(PDO::FETCH_ASSOC) as $part) {
            $code_participant = $part['code_participant'];
            $indice_precedent = (int)$part['indice_solvabilite'];
            
            // Dernière cotisation
            $stmt = $pdo->prepare("
                SELECT date_paiement, nombre_tour_avance 
                FROM cotisations 
                WHERE code_participant = ? AND code_tontine = ? 
                ORDER BY date_paiement DESC LIMIT 1
            ");
            $stmt->execute([$code_participant, $tontine['code_tontine']]);
            $last = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($last) {
                $now = new DateTime();
                $datePai = new DateTime($last['date_paiement']);
                $days = $datePai->diff($now)->days;
                $nombre_tour_avance = isset($last['nombre_tour_avance']) ? (int)$last['nombre_tour_avance'] : 0;

                $doit_marquer_retard = false;

                // ✅ Vérifier si en retard
                if ($tontine['libelle_frequence'] == 'Mensuelle' && $days >= 30 && $nombre_tour_avance === 0) {
                    $doit_marquer_retard = true;
                }
                if ($tontine['libelle_frequence'] == 'Hebdomadaire' && $days >= 7 && $nombre_tour_avance === 0) {
                    $doit_marquer_retard = true;
                }
                if ($tontine['libelle_frequence'] == 'Journalière' && $days >= 1 && $nombre_tour_avance === 0) {
                    $doit_marquer_retard = true;
                }

                if ($doit_marquer_retard) {
                    
                    // ✅ CORRECTION : Vérifier unicité avec code_tontine + date
                    $requete = $pdo->prepare("
                        SELECT code_cotisation_manquee 
                        FROM cotisations_manquees 
                        WHERE code_participant = ? 
                        AND code_tontine = ?
                        AND DATE(date_manquee) = CURDATE() AND id_statut_paiement=?
                    ");
                    $requete->execute([$code_participant, $tontine['code_tontine'],1]);
                    $deja_marque = $requete->fetch(PDO::FETCH_ASSOC);

                    // ✅ CORRECTION : Logique inversée corrigée
                    if (!$deja_marque) {
                        // ✅ Première fois marqué en retard aujourd'hui
                        
                        // Décrémenter l'indice
                        $nouvel_indice = max(0, $indice_precedent - 5);
                        $code_cotisation_manqu = code_cotisation_manquees();

                        // Insérer cotisation manquée
                        $stmt3 = $pdo->prepare("
                            INSERT INTO cotisations_manquees (
                                code_cotisation_manquee, 
                                code_participant, 
                                code_tontine, 
                                montant, 
                                date_manquee
                            ) VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt3->execute([
                            $code_cotisation_manqu,
                            $code_participant,
                            $tontine['code_tontine'],
                            $tontine['montant_cotisation'],
                            date('Y-m-d H:i:s')
                        ]);

                        // Notification
                        sendPushNotification(
                            $part['fcm_token'], 
                            "⚠️ Alerte cotisation manquée", 
                            "Vous avez une cotisation en retard. Merci de régulariser votre situation."
                        );

                        // Log
                        error_log("Retard marqué pour {$code_participant} dans {$tontine['code_tontine']} - Indice: {$indice_precedent} → {$nouvel_indice}");
                        
                    } else {
                        // ✅ Déjà marqué aujourd'hui - Ne rien faire
                        error_log("Retard déjà marqué aujourd'hui pour {$code_participant} dans {$tontine['code_tontine']}");
                        continue; // ✅ Passer au participant suivant
                    }

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

                    // ✅ Vérifier si historique déjà mis à jour AUJOURD'HUI
                    $exists = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM historique_indice_solvabilite 
                        WHERE code_participant = ? 
                        AND code_tontine = ? 
                        AND DATE(date_derniere_maj) = CURDATE()
                    ");
                    $exists->execute([$code_participant, $tontine['code_tontine']]);
                    
                    if ($exists->fetchColumn() == 0) {
                        // Archiver l'indice
                        $stmt1 = $pdo->prepare("
                            INSERT INTO historique_indice_solvabilite (
                                code_participant, 
                                code_tontine, 
                                indice_solvabilite, 
                                date_derniere_maj, 
                                statut
                            ) VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt1->execute([
                            $code_participant,
                            $tontine['code_tontine'],
                            $nouvel_indice,
                            date('Y-m-d H:i:s'),
                            $statut
                        ]);
                    }

                    // Mise à jour de l'indice
                    $stmt2 = $pdo->prepare("UPDATE participants SET indice_solvabilite = ? WHERE code_participant = ?");
                    $stmt2->execute([$nouvel_indice, $code_participant]);

                } elseif ($nombre_tour_avance > 0) {
                    // ✅ NOUVEAU : Décrémenter tour en avance si délai passé
                    
                    // Récupérer la dernière cotisation
                    $stmt = $pdo->prepare("
                        SELECT code_cotisation 
                        FROM cotisations 
                        WHERE code_participant = ? 
                        AND code_tontine = ? 
                        ORDER BY date_paiement DESC LIMIT 1
                    ");
                    $stmt->execute([$code_participant, $tontine['code_tontine']]);
                    $code_cotisation = $stmt->fetchColumn();

                    if ($code_cotisation) {
                        // Décrémenter uniquement si tour en avance
                        $pdo->prepare("
                            UPDATE cotisations 
                            SET nombre_tour_avance = GREATEST(nombre_tour_avance - 1, 0) 
                            WHERE code_cotisation = ?
                        ")->execute([$code_cotisation]);

                        // ✅ Incrémenter l'indice (récompense pour avoir payé en avance)
                        $nouvel_indice = min(100, $indice_precedent + 5);
                        
                        $pdo->prepare("UPDATE participants SET indice_solvabilite = ? WHERE code_participant = ?")
                            ->execute([$nouvel_indice, $code_participant]);

                        error_log("Tour en avance décrémenté pour {$code_participant} - Indice: {$indice_precedent} → {$nouvel_indice}");
                    }
                }
            }
        }
    }

    $pdo->commit();
    error_log("✅ Mise à jour solvabilité terminée avec succès");
    
} catch (Throwable $e) {
    error_log("❌ Erreur solvabilité : " . $e->getMessage());
    file_put_contents(__DIR__ . '/logs/solvabilite.log', date('Y-m-d H:i:s') . ' - ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    $pdo->rollBack();
    send_response(false, $e->getMessage());
}

send_response(true, "Indice de solvabilité mis à jour");