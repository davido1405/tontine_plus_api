<?php
include_once __DIR__ . '/../config/db.php';

include_once __DIR__ . '/../helpers/responses.php';
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;

function lister_type_tontine(){
    $data=json_decode(file_get_contents ("php://input"),true);

    $pdo=getDB();
    $stmt=$pdo->prepare('SELECT * FROM type_tontine');
    $stmt->execute();
    $type_tontines=$stmt->fetchall(PDO::FETCH_ASSOC);
    if($type_tontines){
        $formated=[];
        foreach($type_tontines as $type_tontine){
            $formated[]=[
                'id_type_tontine'=>$type_tontine['id_type_tontine'],
                'type_tontine'=>$type_tontine['libelle_type_tontine']
            ];
        };

        send_response(true,"Liste de tout les types de tontine disponible:",$formated);
    }else{
        send_response(false, "Aucun type de tontine disponible !");
    }
}


function lister_frequence(){
    $data=json_decode(file_get_contents ("php://input"),true);

    $pdo=getDB();
    $stmt=$pdo->prepare('SELECT * FROM frequence');
    $stmt->execute();
    $frequences=$stmt->fetchall(PDO::FETCH_ASSOC);
    if($frequences){
        $formated=[];
        foreach($frequences as $frequence){
            $formated[]=[
                'frequence'=>$frequence['libelle_frequence']
            ];
        };

        send_response(true,"Liste des frequences:",$formated);
    }else{
        send_response(false, "Aucune frequence trouvée !");
    }
}

function lister_frequence_paiement(){
    $data=json_decode(file_get_contents ("php://input"),true);

    $pdo=getDB();
    $stmt=$pdo->prepare('SELECT * FROM frequence_paiement');
    $stmt->execute();
    $frequences=$stmt->fetchall(PDO::FETCH_ASSOC);
    if($frequences){
        $formated=[];
        foreach($frequences as $frequence){
            $formated[]=[
                'frequence_paiement'=>$frequence['libelle_frequence_paiement']
            ];
        };

        send_response(true,"Liste des frequences:",$formated);
    }else{
        send_response(false, "Aucune frequence trouvée !");
    }
}


function ajouter_type_tontine(){
    $data=json_decode(file_get_contents ("php://input"),true);

    if(!isset($data['libelle_type_tontine'])){
        send_response(false,"Veuillez fournir le nom du type de tontine à ajouter !");
    }

    $pdo=getDB();
    $stmt=$pdo->prepare("INSERT INTO type_tontine(libelle_type_tontine) VALUES(?)");
    $stmt->execute([$data['libelle_type_tontine']]);
    $row=$stmt->rowCount();
    if($row>0){
        send_response(true,"Nouveau type de tontine ajouté avec succès!");
    }else{
        send_response(false,"Une erreur s'est produite! Veuillez réessayer");
    }
}

function modifier_type_tontine(){
    $data = json_decode(file_get_contents('php://input'), true);

    if (
        !isset($data['libelle_type_tontine'], $data['nouveau_libelle_type_tontine']) ||
        empty($data['libelle_type_tontine']) || empty($data['nouveau_libelle_type_tontine'])
    ) {
        send_response(false, "Veuillez remplir tous les champs svp.");
    }

    try {
        $pdo = getDB();

        // Vérifier si l'ancien libellé existe
        $stmt1 = $pdo->prepare("SELECT id_type_tontine FROM type_tontine WHERE libelle_type_tontine = ?");
        $stmt1->execute([$data['libelle_type_tontine']]);
        $idtype = $stmt1->fetch(PDO::FETCH_ASSOC);

        if (!$idtype) {
            send_response(false, "Le type de tontine à modifier est introuvable.");
        }

        // Vérifier si le nouveau libellé existe déjà (optionnel)
        $stmt_check = $pdo->prepare("SELECT id_type_tontine FROM type_tontine WHERE libelle_type_tontine = ?");
        $stmt_check->execute([$data['nouveau_libelle_type_tontine']]);
        if ($stmt_check->fetch()) {
            send_response(false, "Ce libellé existe déjà, choisissez-en un autre.");
        }

        // Mise à jour
        $stmt = $pdo->prepare("UPDATE type_tontine SET libelle_type_tontine = ? WHERE id_type_tontine = ?");
        $stmt->execute([
            $data['nouveau_libelle_type_tontine'],
            $idtype['id_type_tontine']
        ]);

        $row = $stmt->rowCount();
        if ($row > 0) {
            send_response(true, "Type de tontine mis à jour avec succès.");
        } else {
            send_response(false, "Aucune mise à jour effectuée (peut-être que le nouveau libellé est identique à l'ancien).");
        }

    } catch (PDOException $e) {
        send_response(false, "Erreur : " . $e->getMessage());
    }
}


function supprimer_type_tontine(){
    $data = json_decode(file_get_contents('php://input'), true);

    if (
        !isset($data['libelle_type_tontine']) ||
        empty($data['libelle_type_tontine'])
    ) {
        send_response(false, "Veuillez remplir tous les champs svp.");
    }

    try {
        $pdo = getDB();

        // Vérifier si le libellé existe
        $stmt1 = $pdo->prepare("SELECT id_type_tontine FROM type_tontine WHERE libelle_type_tontine = ?");
        $stmt1->execute([$data['libelle_type_tontine']]);
        $idtype = $stmt1->fetch(PDO::FETCH_ASSOC);

        if (!$idtype) {
            send_response(false, "Le type de tontine à modifier est introuvable.");
        }

        // Suppression
        $stmt = $pdo->prepare("DELETE FROM type_tontine WHERE id_type_tontine = ?");
        $stmt->execute([
            $idtype['id_type_tontine']
        ]);

        $row = $stmt->rowCount();
        if ($row > 0) {
            send_response(true, "Type de tontine supprimé avec succès.");
        } else {
            send_response(false, "Aucune suppression effectuée.");
        }

    } catch (PDOException $e) {
        send_response(false, "Erreur : " . $e->getMessage());
    }
}
?>