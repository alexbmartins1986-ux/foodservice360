<?php
/* Abre a conexão com o banco. Usado por todos os scripts do módulo CMV. */
function conectarBanco(): PDO {
    require __DIR__ . '/config.php';
    $dsn = "mysql:host=$DB_HOST;dbname=$DB_NOME;charset=utf8mb4";
    return new PDO($dsn, $DB_USUARIO, $DB_SENHA, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
