<?php
require 'vendor/autoload.php';

$env = parse_ini_file('.env');
foreach ($env as $k => $v) $_ENV[$k] = $v;

$pdo = \Src\Kernel\Database\PdoFactory::fromEnv();

// Tenta DB2 se configurado
try {
    if (\Src\Kernel\Database\PdoFactory::hasSecondaryConnection()) {
        $pdo2 = \Src\Kernel\Database\PdoFactory::fromEnv('DB2');
        echo "DB2 disponivel: SIM\n";
    } else {
        $pdo2 = null;
        echo "DB2 disponivel: NAO\n";
    }
} catch (Exception $ex) {
    $pdo2 = null;
    echo "DB2 erro: " . $ex->getMessage() . "\n";
}

$moduleName = $argv[1] ?? 'Accounts';
$moduleDir  = __DIR__ . '/src/Modules/' . $moduleName;

echo "moduleName: $moduleName\n";
echo "moduleDir existe: " . (is_dir($moduleDir) ? 'SIM' : 'NAO') . "\n";

// Testa os dois cases
$migrUpper = $moduleDir . '/Database/Migrations';
$migrLower = $moduleDir . '/Database/migrations';
echo "Database/Migrations existe: " . (is_dir($migrUpper) ? 'SIM' : 'NAO') . "\n";
echo "Database/migrations existe: " . (is_dir($migrLower) ? 'SIM' : 'NAO') . "\n";

$migrDir = is_dir($migrUpper) ? $migrUpper : $migrLower;
$files = glob($migrDir . '/*.php') ?: [];
echo "Arquivos de migration: " . count($files) . "\n";
foreach ($files as $f) echo "  - " . basename($f) . "\n";

$seedUpper = $moduleDir . '/Database/Seeders';
$seedLower = $moduleDir . '/Database/seeders';
echo "Database/Seeders existe: " . (is_dir($seedUpper) ? 'SIM' : 'NAO') . "\n";
echo "Database/seeders existe: " . (is_dir($seedLower) ? 'SIM' : 'NAO') . "\n";

// Verifica connection.php
$connFile = $moduleDir . '/Database/connection.php';
echo "connection.php existe: " . (is_file($connFile) ? 'SIM' : 'NAO') . "\n";
if (is_file($connFile)) {
    $conn = (string)(include $connFile);
    echo "connection: $conn\n";
}

// Verifica tabela migrations no DB principal
echo "\n--- DB principal ---\n";
try {
    $stmt = $pdo->query("SELECT module, migration FROM migrations ORDER BY executed_at DESC LIMIT 20");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) echo "  (vazio)\n";
    foreach ($rows as $r) echo "  module=" . $r['module'] . "  migration=" . $r['migration'] . "\n";
} catch (Exception $ex) {
    echo "  Erro: " . $ex->getMessage() . "\n";
}

// Verifica tabela migrations no DB2
if ($pdo2) {
    echo "\n--- DB2 ---\n";
    try {
        $stmt = $pdo2->query("SELECT module, migration FROM migrations ORDER BY executed_at DESC LIMIT 20");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) echo "  (vazio)\n";
        foreach ($rows as $r) echo "  module=" . $r['module'] . "  migration=" . $r['migration'] . "\n";
    } catch (Exception $ex) {
        echo "  Erro: " . $ex->getMessage() . "\n";
    }
}
