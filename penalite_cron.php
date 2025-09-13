<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers/responses.php';
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;

$pdo = getDB();

// Récupérer toutes les tontines
$tontines = $pdo->query("SELECT code_tontine, id_frequence, montant_penalite FROM tontine")->fetchAll(PDO::FETCH_ASSOC);

foreach ($tontines as $tontine) {
    $frequence = $pdo->prepare("SELECT libelle_frequence FROM frequence WHERE id_frequence = ?");
    $frequence->execute([$tontine['id_frequence']]);
    $libelle = $frequence->fetch(PDO::FETCH_ASSOC)['libelle_frequence'];

    // Récupérer tous les participants de cette tontine
    $participants = $pdo->prepare("SELECT code_participant FROM participer WHERE code_tontine = ?");
    $participants->execute([$tontine['code_tontine']]);

    foreach ($participants->fetchAll(PDO::FETCH_ASSOC) as $part) {
        $code_participant = $part['code_participant'];

        // Dernière cotisation
        $stmt = $pdo->prepare("SELECT date_paiement FROM cotisations WHERE code_participant = ? AND code_tontine = ? ORDER BY date_paiement DESC LIMIT 1");
        $stmt->execute([$code_participant, $tontine['code_tontine']]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($last) {
            $now = new DateTime();
            $datePai = new DateTime($last['date_paiement']);
            $days = $datePai->diff($now)->days;

            $penalite = false;
            if ($libelle == 'Mensuelle' && $days >= 30) $penalite = true;
            if ($libelle == 'Hebdomadaire' && $days >= 7) $penalite = true;
            if ($libelle == 'Journalière' && $days >= 1) $penalite = true;

            if ($penalite) {
                // Vérifier si la pénalité n'existe pas déjà aujourd’hui
                $check = $pdo->prepare("SELECT * FROM penalites WHERE code_participant = ? AND code_tontine = ? AND DATE(date_penalite) = CURDATE()");
                $check->execute([$code_participant, $tontine['code_tontine']]);

                if (!$check->fetch()) {
                    $stmt = $pdo->prepare("INSERT INTO penalites(code_participant, code_tontine, montant, raison, date_penalite, statut) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $code_participant,
                        $tontine['code_tontine'],
                        $tontine['montant_penalite'],
                        "Retard de paiement",
                        date("Y-m-d H:i:s"),
                        "Non payée"
                    ]);
                }
            }
        }
    }
}
send_response(true,"Pénalités mises à jour");
?>
