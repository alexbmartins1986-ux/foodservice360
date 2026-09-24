<?php
/* =====================================================================
   CRIA AS TABELAS DO BANCO (roda uma vez, ou quantas vezes quiser,
   não duplica nada se já existir). Abra este arquivo no navegador.
   ===================================================================== */

require __DIR__ . '/conexao.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = conectarBanco();
} catch (PDOException $e) {
    die("Não consegui conectar no banco. Confira o db/config.php.\nDetalhe: " . $e->getMessage());
}

$sql = file_get_contents(__DIR__ . '/esquema.sql');
// remove as linhas de comentario ANTES de separar por ; (senao o comentario
// gruda no comando seguinte e o filtro descarta os dois juntos por engano)
$sqlSemComentarios = preg_replace('/^--.*$/m', '', $sql);
$comandos = array_filter(array_map('trim', explode(';', $sqlSemComentarios)));

$ok = 0;
foreach ($comandos as $cmd) {
    if ($cmd === '' || str_starts_with($cmd, '--')) continue;
    try {
        $pdo->exec($cmd);
        $ok++;
    } catch (PDOException $e) {
        echo "ERRO num comando: " . $e->getMessage() . "\n\n";
    }
}

echo "Migração concluída. $ok comando(s) executado(s) com sucesso.\n\n";
$tabelas = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Tabelas existentes no banco agora:\n";
foreach ($tabelas as $t) echo "  - $t\n";
