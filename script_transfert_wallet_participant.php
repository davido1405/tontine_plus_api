<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/controllers/notifications.php';
require_once __DIR__ . '/helpers/responses.php';
require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;
//Oublie pas de regler le front pour le wallet participant



    //$data = json_decode(file_get_contents("php://input"), true);
    //if (empty($data['code_tontine']) || empty($data['code_participant'])) {
    //    send_response(false, "Veuillez remplir tous les champs !");
    //}

    //$code_tontine=$data['code_tontine'];
    $dateCourante=date('Ymd');
    $pdo = getDB();
    try {
        $pdo->beginTransaction();

        // 1) Récupérer ordre, statut, tour_actuel et solde de toutes les tontines en lockant
        $sql = "SELECT o.code_participant, o.ordre, o.statut, o.date_tour,
                   t.code_tontine, t.tour_actuel, t.etat_tontine, t.nombre_participant, 
                   t.nom_tontine, w.solde_tontine
            FROM ordre_tirage o
            JOIN tontine t ON t.code_tontine = o.code_tontine
            JOIN wallet_tontine w ON w.code_tontine = o.code_tontine
            WHERE o.ordre = t.tour_actuel 
              AND o.date_tour = ? 
              AND o.statut = 0
              AND w.solde_tontine > 0
            FOR UPDATE";
        $q = $pdo->prepare($sql);
        $q->execute([$dateCourante]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows || count($rows) === 0) {
            echo "ℹ️  Aucun participant à payer aujourd'hui.\n";
            $pdo->commit();
            exit(0);
        }

        foreach ($rows as $row) {
            $code_participant = $row['code_participant'];
            $code_tontine = $row['code_tontine'];
            $montant = (float)$row['solde_tontine'];
            if ($montant <= 0) {
                throw new Exception("Solde insuffisant pour effectuer le Virement.");
            }

            // 3) Marquer l'ordre payé
            $u1 = $pdo->prepare("UPDATE ordre_tirage 
                                SET statut = 1
                                WHERE code_tontine = ? AND code_participant = ?");
            $u1->execute([$code_tontine, $code_participant]);

            //Mettre à jour le wallet participant

            $u=$pdo->prepare("UPDATE wallet_participant SET solde_participant=solde_participant+? WHERE code_participant=?");
            $u->execute([$row['solde_tontine'],$code_participant]);

            // 4) Vider la cagnotte de CETTE tontine
            $u2 = $pdo->prepare("UPDATE wallet_tontine 
                                SET solde_tontine = 0 
                                WHERE code_tontine = ?");
            $u2->execute([$code_tontine]);

            // 5) Avancer le tour de CETTE tontine
            $u3 = $pdo->prepare("UPDATE tontine 
                                SET tour_actuel = tour_actuel + 1 
                                WHERE code_tontine = ?");
            $u3->execute([$code_tontine]);

            // 6) Historiser
            $u4 = $pdo->prepare("INSERT INTO paiement_tour 
                (code_participant, code_tontine, montant, date_paiement) 
                VALUES (?,?,?, NOW())");
            $u4->execute([$code_participant, $code_tontine, $montant]);

            // 7) ✅ NOUVEAU : Historiser le crédit wallet
            $historique = $pdo->prepare("INSERT INTO historique_wallet_participant 
                (code_participant, type_operation, montant, description, date_operation, code_tontine) 
                VALUES (?, 'Paiement de tour', ?, ?, NOW(), ?)");
            $historique->execute([
                $code_participant,
                $montant,
                "Transfert automatique tour " . $row['ordre'] . " - " . $row['nom_tontine'],
                $code_tontine
            ]);

            // 8) Vérifier l'état de la tontine si terminée ou pas
            
            //Récupérer le tour actuel et le nombre de participant
            $u6 =$pdo->prepare("SELECT nombre_participant,tour_actuel,etat_tontine FROM tontine WHERE code_tontine=?");
            $u6->execute([$code_tontine]);
            $nombreP=$u6->fetch(PDO::FETCH_ASSOC);
            
            $etat="En cours";

            //Mise à jour maintenant de l'état de la tontine selon que tous les tours soit payés ou pas
            if($nombreP['tour_actuel']>$nombreP['nombre_participant']){
                $etat="Terminée";
                $u7=$pdo->prepare("UPDATE tontine SET etat_tontine=? WHERE code_tontine=?");
                $u7->execute([$etat,$code_tontine]);



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

                //Envoyer une notification à tout les participants pour informer de la fin de la tontine
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
            $u8->execute([$code_tontine]);
            $newP=$u8->fetch(PDO::FETCH_ASSOC);

            $phrases_terminée = [
                "Virement de $montant FCFA effectué avec succès. La tontine est terminée 🚀. On relance ou pas ? 😏",
                "Caisse vidée ✅. Virement de $montant FCFA effectué. Tontine terminée 🎉",
                "Mission accomplie 💰 ! $montant FCFA retirés, tontine terminée. Qui est partant pour la prochaine ? 😎",
                "Et voilà, $montant FCFA dans la poche 😁. La tontine est terminée 🔥"
            ];
            $phrases_en_cours = [
                "Virement de $montant FCFA effectué avec succès. Au suivant ! 😏",
                "Caisse approvisionnée 💸. Virement de $montant FCFA effectué. On continue ! 🚀",
                "Virement de $montant FCFA effectué ✅. Prochain participant, c’est votre tour ! 😎",
                "Montant de $montant FCFA retiré avec succès. La tontine continue 😁"
            ];

            $message = $etat == "Terminée" ? $phrases_terminée[array_rand($phrases_terminée)] : $phrases_en_cours[array_rand($phrases_en_cours)];

            send_response(true, $message, [
                'total_tour'=>$newP['nombre_participant'],
                'tour_actuel'=>$newP['tour_actuel'],
                'statut_tontine'=>$newP['etat_tontine']
                ]
            );
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        send_response(false, $e->getMessage());
    }
?>