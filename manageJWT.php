<?php
require_once __DIR__ .'/helpers/responses.php';
require_once __DIR__ .'/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function generer_token_utilisateur($code_participant,$numero_utilisateur){
    //Charger le configuration
    $config= require_once __DIR__ .'/config/config.php';
    $secret_code=$config['jwt_code_secret'];
    $domain_name=$config['domain_name'];

    //Création du header (C'est optionnelle parce que la librairie Firebase JWT crée ça automatiquement)
    $header=[
        "alg"=>"HS256",
        "typ"=>"JWT"
    ];

    //Création du contenu (payload)
    $payload=[
        "iss"=> $domain_name,
        "iat"=>time(),
        "exp"=>time()+900,//Duré de vie de 5 min
        "code_participant"=> $code_participant,
        "numero"=>$numero_utilisateur
    ];

    //Génération du token prend en paramètre le payload, le code secret, l'algorithme de cryptage et le header
    $jwt=JWT::encode($payload,$secret_code,"HS256");

   return $jwt;
}

function verifier_token(){
    $config = require __DIR__ .'/config/config.php';
    $secret_code = $config['jwt_code_secret'];

    // Récupération du header Authorization de manière plus fiable
    $authHeader = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                $authHeader = $value;
                break;
            }
        }
    }

    if (!$authHeader) {
        http_response_code(401);
        send_response(false, "Token manquant ou header non reçu");
    }

    // Retirer le mot clé Bearer et espaces éventuels
    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        http_response_code(401);
        send_response(false, "Format token invalide");
    }
    $jwt = trim($matches[1]);

    try {
        $decoder = JWT::decode($jwt, new Key($secret_code, "HS256"));
        return $decoder;
    } catch (\Firebase\JWT\ExpiredException $e) {
        http_response_code(401);
        send_response(false,"Votre session a expiré, veuillez vous reconnecter !");
    } catch (\Exception $e) {
        http_response_code(401);
        send_response(false,"Token invalide");
    }
}
