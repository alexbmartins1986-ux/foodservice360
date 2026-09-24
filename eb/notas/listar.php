<?php
header('Content-Type: application/json; charset=utf-8');

$loja = preg_replace('/[^a-z0-9]/', '', strtolower($_GET['loja'] ?? ''));
$pasta = __DIR__ . "/data/pendentes/$loja";

$notas = [];
if (is_dir($pasta)) {
    foreach (glob($pasta . '/*.json') as $arq) {
        $conteudo = json_decode(file_get_contents($arq), true);
        if ($conteudo) $notas[] = $conteudo;
    }
    // mais recentes primeiro
    usort($notas, fn($a, $b) => strcmp($b['recebidaEm'] ?? '', $a['recebidaEm'] ?? ''));
}

echo json_encode(['notas' => $notas], JSON_UNESCAPED_UNICODE);
