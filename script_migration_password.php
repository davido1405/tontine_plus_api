<?php
require_once __DIR__ . '/config/db.php';

$pdo=getDB();

$pdo->beginTransaction();
//Récupérer tout les mots de passe actuels
$stmt=$pdo->prepare("SELECT numro_mobile_money as numero,mot_passe FROM participants");
$stmt->execute();
$resultats=$stmt->fetchAll(PDO::FETCH_ASSOC);

$nombreMigration=0;
foreach($resultats as $resultat){
    $password_nonHasher=$resultat['mot_passe'];
    $passwordHasher=password_hash($password_nonHasher,PASSWORD_DEFAULT);

    //Mettre à jour maintenant le mot de passe
    $stmt2=$pdo->prepare("UPDATE participants set mot_passe=? WHERE numro_mobile_money=?");
    $stmt2->execute([$passwordHasher,$resultat['numero']]);
    
    if($stmt->rowCount()>0){
        $nombreMigration++;
    }
}
$pdo->commit();
if($nombreMigration>0){
    echo 'Migration éffectuée avec succès !';
}else{
    echo 'Une erreur s\'est produite dans le script';
}
