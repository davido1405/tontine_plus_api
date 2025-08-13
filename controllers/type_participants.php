<?php
include_once __DIR__ . '/../config/db.php';

include_once __DIR__ . '/../helpers/responses.php';


function lister_type_participant(){
    $data=json_decode(file_get_contents ("php://input"),true);

    $pdo=getDB();
    $stmt=$pdo->prepare('SELECT * FROM type_participants');
    $stmt->execute();
    $type_participants=$stmt->fetchall(PDO::FETCH_ASSOC);
    if($type_participants){
        $formated=[];
        foreach($type_participants as $type_participant){
            $formated[]=[
                'id_type_participant'=>$type_participant['id_type_participant'],
                'type_participant'=>$type_participant['libelle_participant']
            ];
        };

        send_response(true,"Liste de tout les types de participant disponible:",$formated);
    }else{
        send_response(false, "Aucun type de participant disponible !");
    }
}


function ajouter_type_participant(){
    $data=json_decode(file_get_contents ("php://input"),true);

    if(!isset($data['libelle_participant'])){
        send_response(false,"Veuillez fournir le nom du type de participant à ajouter !");
    }

    $pdo=getDB();
    $stmt=$pdo->prepare("INSERT INTO type_participants(libelle_participant) VALUES(?)");
    $stmt->execute([$data['libelle_participant']]);
    $row=$stmt->rowCount();
    if($row>0){
        send_response(true,"Nouveau type de participant ajouté avec succès!");
    }else{
        send_response(false,"Une erreur s'est produite! Veuillez réessayer");
    }
}

function modifier_type_participant(){
    $data = json_decode(file_get_contents('php://input'), true);

    if (
        !isset($data['libelle_participant'], $data['nouveau_libelle_participant']) ||
        empty($data['libelle_participant']) || empty($data['nouveau_libelle_participant'])
    ) {
        send_response(false, "Veuillez remplir tous les champs svp.");
    }

    try {
        $pdo = getDB();

        // Vérifier si l'ancien libellé existe
        $stmt1 = $pdo->prepare("SELECT id_type_participant FROM type_participants WHERE libelle_participant = ?");
        $stmt1->execute([$data['libelle_participant']]);
        $idtype = $stmt1->fetch(PDO::FETCH_ASSOC);

        if (!$idtype) {
            send_response(false, "Le type de participant à modifier est introuvable.");
        }

        // Vérifier si le nouveau libellé existe déjà (optionnel)
        $stmt_check = $pdo->prepare("SELECT id_type_participant FROM type_participants WHERE libelle_participant = ?");
        $stmt_check->execute([$data['nouveau_libelle_participant']]);
        if ($stmt_check->fetch()) {
            send_response(false, "Ce libellé existe déjà, choisissez-en un autre.");
        }

        // Mise à jour
        $stmt = $pdo->prepare("UPDATE type_participants SET libelle_participant = ? WHERE id_type_participant = ?");
        $stmt->execute([
            $data['nouveau_libelle_participant'],
            $idtype['id_type_participant']
        ]);

        $row = $stmt->rowCount();
        if ($row > 0) {
            send_response(true, "Type de participant mis à jour avec succès.");
        } else {
            send_response(false, "Aucune mise à jour effectuée (peut-être que le nouveau libellé est identique à l'ancien).");
        }

    } catch (PDOException $e) {
        send_response(false, "Erreur : " . $e->getMessage());
    }
}


function supprimer_type_participant(){
    $data = json_decode(file_get_contents('php://input'), true);

    if (
        !isset($data['libelle_participant']) ||
        empty($data['libelle_participant'])
    ) {
        send_response(false, "Veuillez remplir tous les champs svp.");
    }

    try {
        $pdo = getDB();

        // Vérifier si le libellé existe
        $stmt1 = $pdo->prepare("SELECT id_type_participant FROM type_participants WHERE libelle_participant = ?");
        $stmt1->execute([$data['libelle_participant']]);
        $idtype = $stmt1->fetch(PDO::FETCH_ASSOC);

        if (!$idtype) {
            send_response(false, "Le type de participant à modifier est introuvable.");
        }

        // Suppression
        $stmt = $pdo->prepare("DELETE FROM type_participants WHERE id_type_participant = ?");
        $stmt->execute([
            $idtype['id_type_participant']
        ]);

        $row = $stmt->rowCount();
        if ($row > 0) {
            send_response(true, "Type de participant supprimé avec succès.");
        } else {
            send_response(false, "Aucune suppression effectuée.");
        }

    } catch (PDOException $e) {
        send_response(false, "Erreur : " . $e->getMessage());
    }
}
?>
