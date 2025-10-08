<?php
/**
 * Envoie une réponse formatée en JSON
 * @param bool $success - indique si l'opération a réussi
 * @param string $message - message à retourner
 * @param array|null $data - données optionnelles à renvoyer
 */
function send_response($success, $message, $data = null) {
    header('Content-Type: application/json');

    // Structure standard de la réponse
    $response = [
        'success' => $success,
        'message' => $message,
    ];

    // Ajouter les données si elles existent
    if (!is_null($data)) {
        $response['data'] = $data;
    }

    // Encoder la réponse en JSON
    echo json_encode($response);
    exit; // pour arrêter le script après la réponse
}
