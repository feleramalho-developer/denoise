<?php
include('protect.php');
include("conexao.php");

require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

// CONFIGURAÇÃO BANCO
$host = "caboose.proxy.rlwy.net";
$user = "root";
$password = "GXccXsOkyfFEJUBWDwaALivuPWPHwYgP";
$port = 46551;
$db = "railway";

// CONEXÃO COM BANCO
try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}

// DAR BAIXA EM CONTA
if (isset($_GET['baixa'])) {
    $id = intval($_GET['baixa']);
    $pdo->prepare("UPDATE findenoise SET situacao='pago' WHERE id=?")->execute([$id]);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}
// EXCLUIR REGISTRO
if (isset($_GET['excluir'])) {
    $id = intval($_GET['excluir']);
    $pdo->prepare("DELETE FROM findenoise WHERE id=?")->execute([$id]);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}
// EDITAR REGISTRO
if (!empty($_POST['editar_id'])) {
    try {
        $tipoU = $_POST['tipo'];
        $tipo_despesaU = $_POST['tipo_despesa'] ?? '';
        if ($tipoU === 'receber') {
            $tipo_despesaU = 'receita';
        }
        $stmt = $pdo->prepare("UPDATE findenoise SET 
        tipo=?, nome=?, categoria=?, parcelas=?, valor=?, vencimento=?, situacao=?, tipo_despesa=?, empresa=?, observacao=?, nf=?
        WHERE id=?");
        $stmt->execute([
            $tipoU,
            $_POST['nome'],
            $_POST['categoria'],
            $_POST['parcelas'] ?: 1,
            $_POST['valor'],
            $_POST['vencimento'],
            $_POST['situacao'] ?? 'pendente',
            $tipo_despesaU,
            $_POST['empresa'],
            $_POST['observacao'],
            $_POST['nf'],
            $_POST['editar_id'],
        ]);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        // echo '<div style="color:red;">Erro ao atualizar: ' . $e->getMessage() . '</div>';
    }
}

// Paginação
$por_pagina = 20;
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina - 1) * $por_pagina;


// ========================
// Funções auxiliares
// ========================

// Função helper para pegar valores do POST sem gerar warnings
function post($key, $default = '')
{
    return $_POST[$key] ?? $default;
}

// ========================
// INSERIR REGISTRO
// ========================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    empty(post('editar_id')) &&
    !isset($_POST['importar']) &&
    ($_POST['form'] ?? '') !== 'usuarios'
) {
    $tipo = post('tipo', '');
    $nome = post('nome', '');
    $categoria = post('categoria', '');
    $parcelas = max(1, intval(post('parcelas', 1)));
    $valor = post('valor', 0);
    $vencimento = post('vencimento', date('Y-m-d'));
    $situacao = post('situacao', 'pendente');
    $tipo_despesa = post('tipo_despesa', '');
    if ($tipo === 'receber') {
        $tipo_despesa = 'receita';
    }
    $empresa = post('empresa', '');
    $observacao = post('observacao', '');
    $nf = post('nf', '');

    try {
        $stmt = $pdo->prepare("INSERT INTO findenoise 
            (tipo, nome, categoria, parcelas, valor, vencimento, situacao, tipo_despesa, empresa, observacao, nf)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        for ($i = 0; $i < $parcelas; $i++) {
            $data_parcela = date('Y-m-d', strtotime("+$i month", strtotime($vencimento)));
            $obs_parcela = "Parcela " . ($i + 1) . "/" . $parcelas;
            if ($observacao !== '') {
                $obs_parcela .= " - " . $observacao;
            }
            $stmt->execute([
                $tipo,
                $nome,
                $categoria,
                $parcelas,
                $valor,
                $data_parcela,
                $situacao,
                $tipo_despesa,
                $empresa,
                $obs_parcela,
                $nf
            ]);
        }

        header("Refresh: 3; url=" . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        // echo "<div style='color:red;'>Erro ao salvar: " . $e->getMessage() . "</div>";
    }
}

// ========================
// IMPORTAR PLANILHA XLSX
// ========================
if (isset($_POST['importar']) && isset($_FILES['arquivo'])) {
    $arquivo = $_FILES['arquivo']['tmp_name'] ?? null;
    if ($arquivo) {
        try {
            $spreadsheet = IOFactory::load($arquivo);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            // Remove cabeçalho
            $cabecalho = array_shift($rows);

            $sql = "INSERT INTO findenoise 
                (nome, categoria, valor, vencimento, situacao, tipo, empresa, observacao, nf, tipo_despesa, parcelas)
                VALUES (:nome, :categoria, :valor, :vencimento, :situacao, :tipo, :empresa, :observacao, :nf, :tipo_despesa, :parcelas)";
            $stmt = $pdo->prepare($sql);

            foreach ($rows as $row) {
                // pula linhas totalmente vazias
                if (!is_array($row) || count(array_filter(array_map('trim', $row))) === 0) {
                    continue;
                }

                // Normaliza/trim nos valores e garante defaults
                $nome = trim($row[0] ?? '');
                $categoria = trim($row[1] ?? '');
                $valor_raw = trim($row[2] ?? '0');
                $valor = $valor_raw;

                $venc_raw = trim($row[3] ?? '');
                $vencimento = $venc_raw !== '' ? date('Y-m-d', strtotime($venc_raw)) : date('Y-m-d');

                $situacao = trim($row[4] ?? 'pendente');
                $tipo = trim($row[5] ?? '');
                $empresa = trim($row[6] ?? '');
                $observacao = trim($row[7] ?? '');
                $nf = trim($row[8] ?? '');
                $tipo_despesa = trim($row[9] ?? '');
                $parcelas_row = max(1, intval($row[10] ?? 1));

                $stmt->execute([
                    ':nome' => $nome,
                    ':categoria' => $categoria,
                    ':valor' => $valor,
                    ':vencimento' => $vencimento,
                    ':situacao' => $situacao,
                    ':tipo' => $tipo,
                    ':empresa' => $empresa,
                    ':observacao' => $observacao,
                    ':nf' => $nf,
                    ':tipo_despesa' => $tipo_despesa,
                    ':parcelas' => $parcelas_row
                ]);
            }

            header("Refresh:1; url=" . $_SERVER['PHP_SELF']);
            exit;
        } catch (Exception $e) {
            // echo "<div class='alert error'>Erro ao importar: " . $e->getMessage() . "</div>";
        }
    }
}



// CONSULTAR CONTAS VENCIDAS <!--Código incluído novo -->
$hoje = date('Y-m-d');
$stmt_vencidas = $pdo->prepare("SELECT * FROM findenoise WHERE vencimento <= ? AND situacao = 'pendente' ORDER BY vencimento ASC");
$stmt_vencidas->execute([$hoje]);
$contas_vencidas = $stmt_vencidas->fetchAll(PDO::FETCH_ASSOC);
// CONSULTA MOVIMENTAÇÕES
$filtro = $_GET['tipo_filtro'] ?? 'todos';
$clienteFiltro = trim($_GET['cliente'] ?? '');
$mesFiltro = intval($_GET['mes'] ?? 0);
$anoFiltro = intval($_GET['ano'] ?? 0);
$where = [];
$params = [];
$empresaFiltro = $_GET['empresa'] ?? '';

if ($filtro === 'pagar') {
    $where[] = "tipo='pagar'";
} elseif ($filtro === 'receber') {
    $where[] = "tipo='receber'";
}
if ($clienteFiltro !== '') {
    $where[] = "nome LIKE :cliente";
    $params[':cliente'] = "%$clienteFiltro%";
}
if ($mesFiltro > 0) {
    $where[] = "MONTH(vencimento) = :mes";
    $params[':mes'] = $mesFiltro;
}
if ($anoFiltro > 0) {
    $where[] = "YEAR(vencimento) = :ano";
    $params[':ano'] = $anoFiltro;
}
if ($empresaFiltro !== '') {   // 🔹 AQUI entra o filtro de empresa
    $where[] = "empresa = :empresa";
    $params[':empresa'] = $empresaFiltro;
}
// 🔹 Filtro por situação (pago/pendente)
$situacaoFiltro = $_GET['situacao'] ?? '';
if ($situacaoFiltro !== '') {
    $where[] = "situacao = :situacao";
    $params[':situacao'] = $situacaoFiltro;
}
$whereSQL = $where ? ("WHERE " . implode(" AND ", $where)) : '';

// CONSULTA TOTAIS (com filtros aplicados)
$sqlTotais = "
    SELECT 
        COALESCE(SUM(CASE WHEN tipo='pagar' AND (situacao IS NULL OR situacao='pendente') THEN valor ELSE 0 END),0) AS total_pagar_pendente,
        COALESCE(SUM(CASE WHEN tipo='pagar' AND situacao='pago' THEN valor ELSE 0 END),0) AS total_pagar_pago,
        COALESCE(SUM(CASE WHEN tipo='receber' AND (situacao IS NULL OR situacao='pendente') THEN valor ELSE 0 END),0) AS total_receber_pendente,
        COALESCE(SUM(CASE WHEN tipo='receber' AND situacao='pago' THEN valor ELSE 0 END),0) AS total_receber_recebido
    FROM findenoise
    $whereSQL
";
$stmtTotais = $pdo->prepare($sqlTotais);
foreach ($params as $k => $v) {
    $stmtTotais->bindValue($k, $v);
}
$stmtTotais->execute();
$totais = $stmtTotais->fetch(PDO::FETCH_ASSOC);

$total_pagar_pendente = $totais['total_pagar_pendente'];
$total_pagar_pago = $totais['total_pagar_pago'];
$total_receber_pendente = $totais['total_receber_pendente'];
$total_receber_recebido = $totais['total_receber_recebido'];
$resultado = $total_receber_recebido - $total_pagar_pago;
// Contagem total de registros para paginação
$sqlCount = "SELECT COUNT(*) FROM findenoise $whereSQL";
$stmtCount = $pdo->prepare($sqlCount);
// Bind dos mesmos filtros da listagem
foreach ($params as $k => $v) {
    $stmtCount->bindValue($k, $v);
}
$stmtCount->execute();
$total_reg = $stmtCount->fetchColumn();
$total_paginas = ceil($total_reg / $por_pagina);
$sql = "SELECT * FROM findenoise $whereSQL ORDER BY vencimento ASC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
// Bind dos filtros dinâmicos
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
// Bind da paginação
$stmt->bindValue(':limit', $por_pagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$movs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// KPIs estratégicos
$mesSelecionado = $_GET['mes'] ?? date('m');
$anoSelecionado = $_GET['ano'] ?? date('Y');
$filtroTipo = $_GET['tipo_filtro'] ?? 'todos';

$where = "WHERE MONTH(vencimento) = :mes AND YEAR(vencimento) = :ano";

if ($filtroTipo == 'pagar') {
    $where .= " AND tipo = 'pagar'";
} elseif ($filtroTipo == 'receber') {
    $where .= " AND tipo = 'receber'";
}

$sqlIndicadores = "
    SELECT
        COALESCE(SUM(CASE WHEN tipo='pagar' THEN 1 ELSE 0 END),0) AS qtd_pagar,
        COALESCE(SUM(CASE WHEN tipo='receber' THEN 1 ELSE 0 END),0) AS qtd_receber,
        COALESCE(SUM(CASE WHEN tipo='pagar' THEN valor ELSE 0 END),0) AS valor_total_pagar,
        COALESCE(SUM(CASE WHEN tipo='receber' THEN valor ELSE 0 END),0) AS valor_total_receber,
        COALESCE(SUM(CASE WHEN tipo='pagar' AND situacao='pendente' THEN valor ELSE 0 END),0) AS valor_pagar_pendente,
        COALESCE(SUM(CASE WHEN tipo='receber' AND situacao='pendente' THEN valor ELSE 0 END),0) AS valor_receber_pendente,
        COALESCE(SUM(CASE WHEN tipo='pagar' AND vencimento < CURDATE() AND situacao='pendente' THEN valor ELSE 0 END),0) AS valor_pagar_atraso,
        COALESCE(SUM(CASE WHEN tipo='receber' AND vencimento < CURDATE() AND situacao='pendente' THEN valor ELSE 0 END),0) AS valor_receber_atraso
    FROM findenoise
    $where
";

$stmtIndicadores = $pdo->prepare($sqlIndicadores);
$stmtIndicadores->execute([
    ':mes' => $mesSelecionado,
    ':ano' => $anoSelecionado
]);

$indicadores = $stmtIndicadores->fetch(PDO::FETCH_ASSOC);

$sql = "
    SELECT 
        MONTH(vencimento) AS mes,
        SUM(CASE WHEN tipo = 'receber' THEN valor ELSE 0 END) AS total_entradas,
        SUM(CASE WHEN tipo = 'pagar' THEN valor ELSE 0 END) AS total_saidas
    FROM findenoise
    WHERE YEAR(vencimento) = :ano
    GROUP BY MONTH(vencimento)
    ORDER BY mes
";

$stmt = $pdo->prepare($sql);
$stmt->execute(['ano' => $anoSelecionado]);


$dadosMensais = [
    'entradas' => array_fill(0, 12, 0),
    'saidas' => array_fill(0, 12, 0),
];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $mes = (int) $row['mes'] - 1; // índice 0 a 11
    $dadosMensais['entradas'][$mes] = (float) $row['total_entradas'];
    $dadosMensais['saidas'][$mes] = (float) $row['total_saidas'];
}


// se não vier nada, zera
if (!$indicadores) {
    $indicadores = [
        'qtd_pagar' => 0,
        'qtd_receber' => 0,
        'valor_total_pagar' => 0,
        'valor_total_receber' => 0,
        'valor_pagar_pendente' => 0,
        'valor_receber_pendente' => 0,
        'valor_pagar_atraso' => 0,
        'valor_receber_atraso' => 0
    ];
}

// =========================================
// CADASTRO DE USUÁRIOS (isolado com form='usuarios')
// =========================================
$page = $_GET['page'] ?? 'dashboard';
$msg_usuario = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($page == 'usuarios')) {
    $nome = trim($_POST['nome']);
    $email = trim($_POST['email']);
    $senha = trim($_POST['senha']);

    try {
        // Verificar se o email já existe
        $checkSql = "SELECT COUNT(*) FROM usuario_denoise WHERE email = :email";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute([':email' => $email]);
        $emailExists = $checkStmt->fetchColumn();

        if ($emailExists) {
            $_SESSION['msg_usuario'] = "<div class='alert alert-warning text-center'>Este email já está cadastrado!</div>";
            header("Location: ?page=usuarios");
            exit;
        } else {
            // Inserir usuário
            $sql = "INSERT INTO usuario_denoise (nome, email, senha) VALUES (:nome, :email, :senha)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':nome' => $nome,
                ':email' => $email,
                ':senha' => $senha
            ]);

            $_SESSION['msg_usuario'] = "<div class='alert alert-success text-center'>Usuário cadastrado com sucesso!</div>";
            header("Location: ?page=usuarios");
            exit;
        }

    } catch (PDOException $e) {
        $_SESSION['msg_usuario'] = "<div class='alert alert-danger text-center'>Erro ao cadastrar: " . $e->getMessage() . "</div>";
    }
}




?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <style>
        body.light-theme {
            background-image: linear-gradient(45deg, black, black);
            color: #222;
        }

        body.dark-theme {
            background-image: linear-gradient(45deg, black, black);
            color: #eee;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            border-radius: 10px;
        }

        table,
        th,
        td {
            border: 1px solid #888;
            padding: 5px;
            border-radius: 10px;
            font-size: 11px;
        }

        body.dark-theme table,
        body.dark-theme th,
        body.dark-theme td {
            border: 1px solid #555;
        }

        .btn {
            padding: 5px 10px;
            border: none;
            cursor: pointer;
            margin: 2px;
        }

        .theme-toggle {
            position: fixed;
            top: 10px;
            right: 10px;
            padding: 6px 12px;
            background: #1f7071;
            color: white;
            border-radius: 5px;
        }

        .btn-sair {
            position: fixed;
            top: 30px;
            left: 70px;
            padding: 12px 20px;
            background: red;
            color: white;
            border-radius: 10px;
            margin: 20px;
        }

        tr.vencida {
            background-color: rgba(255, 0, 0, 0.2);
            color: red;
            font-weight: bold;
        }

        * {
            margin: 0;
            padding: 0;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: row;
            /* 🔴 Remova overflow-y daqui */
            background-color: silver;
        }

        /* Sidebar fixa na vertical */
        .sidebar {
            width: 200px;
            background: #000000ff;
            color: #fff;
            min-height: 100vh;
            transition: width .3s;
            flex-shrink: 0;
            /* impede de encolher demais */
            position: sticky;
            /* mantém fixa ao rolar */
            top: 0;
        }

        /* Área principal ocupa o resto da tela */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .sidebar.collapsed {
            width: 70px;
        }

        .content {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            /* conteúdo rola dentro do painel */
        }

        .sidebar .logo {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid #ffff;
        }

        .sidebar .logo img {
            width: 40px;
            margin-bottom: 10px;
        }

        .sidebar h2 {
            font-size: 16px;
        }

        .sidebar ul {
            list-style: none;
            padding: 0;
            margin-top: 20px;
            flex: 1;
        }

        .sidebar ul li {
            padding: 15px 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 20px;
            transition: background 0.3s;
        }

        .sidebar ul li:hover {
            background-color: #000000ff;
        }

        .sidebar ul li i {
            font-size: 18px;
        }

        .sidebar.collapsed ul li span {
            display: none;
        }


        /* ----- CABEÇALHO ----- */
        .header {
            background: #000000ff;
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 20px;
            box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px,
                rgba(0, 0, 0, 0.3) 0px 30px 60px -30px,
                rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
        }

        .toggle-btn {
            background: transparent;
            border: none;
            color: #fff;
            font-size: 20px;
            cursor: pointer;
            margin-right: 15px;
        }

        .logout-btn {
            background: #e74c3c;
            border: none;
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
        }

        /* ----- CONTEÚDO DA PÁGINA ----- */
        .content {
            padding: 20px;
            flex: 1;
            overflow-y: auto;
        }

        h1 {
            text-align: center;
            margin: 20px 0;
            color: #fff;
        }

        h2 {
            text-align: left;
            margin: 20px 0;
            color: white;
            font-size: 14px;
            ;
        }

        h5 {
            text-align: right;
            margin: 10px 0;
            color: #fff;
        }

        .container {
            width: 90%;
            margin: auto;
        }

        .cards {
            display: flex;
            justify-content: space-between;
            margin-top: 5px;
            margin-bottom: 10px;
            flex-wrap: wrap;
            gap: 20px;

        }

        .ClassFiltro {
            background-color: rgba(0, 0, 0, 0.63);
            flex: 1;

            border-radius: 12px;
            padding: 5px;
            gap: 20px;
        }

        .card {
            flex: 1;
            padding: 20px;
            border-radius: 12px;
            color: #fff;
            text-align: center;
            font-size: 12px;
            box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
        }

        .pagar {
            background-color: rgba(100, 0, 0, 1);
        }

        .receber {
            background-color: rgba(0, 100, 0, 1);
        }

        .resultado {
            background-color: rgba(0, 0, 100, 1);
        }

        form {
            background: silver;
            padding: 10px;
            border-radius: 12px;
            box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 25px;
        }

        form h2 {
            grid-column: span 4;
            margin: 0;

        }

        input,
        select,
        textarea,
        button {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 11px;
        }

        button {
            background: #1f7071;
            color: #fff;
            border: none;
            cursor: pointer;
            transition: background 0.3s;
            box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
        }

        button:hover {
            background: #073435ff;
            box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
        }

        th,
        td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: center;
        }

        tr:nth-child(even) {
            background: #f9f9f9;
        }

        a.btn {
            padding: 6px 10px;
            border-radius: 6px;
            color: #fff;
            background: #e74c3c;
            text-decoration: none;
            font-size: 11px;
            transition: background 0.3s;
            box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
        }

        a.btn:hover {
            background-color: rgba(100, 0, 0, 0.6);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
        }

        .linha-card {
            border: none;
            border-bottom: 2px solid #ffffffff;
            margin: 5px 0 10px 0;
        }

        .modal-content {
            background-color: rgba(100, 0, 0, 0.6);
            margin: 0 auto;
            padding: 20px;
            border-radius: 10px;
            width: 50%;
            max-height: 70%;
            overflow-y: auto;

            /* Centralização */
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
        }

        .close-btn {
            background: #ff5c5c;
            color: #fff;
            border: none;
            padding: 10px 20px;
            cursor: pointer;
            border-radius: 5px;
        }

        footer {
            margin-top: 30px;
            padding: 15px;
            text-align: center;
            background: #4c4c4cff;
            color: white;
            font-size: 0.7rem;

        }

        .iframe-container {
            position: relative;
            width: 100%;
            padding-bottom: 66.66%;
            /* proporção 1200x800 (800/1200 = 0.6666) */
            height: 0;
            overflow: hidden;
        }

        .iframe-container iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: 0;
        }
    </style>
    <!-- Ícones -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <meta charset="UTF-8">
</head>

<body>
    <!-- MENU LATERAL -->
    <div class="sidebar" id="sidebar">
        <div class="logo">
            <img src="./img/Logo_Denoise.jpg" alt="Logo">
        </div>
        <ul>
            <li onclick="window.location.href='?page=dashboard'">
                <i class="fas fa-chart-line"></i> <span>Lançamentos</span>
            </li>
            <li onclick="window.location.href='?page=relatorios'">
                <i class="fas fa-file-alt"></i> <span>Relatórios</span>
            </li>
            <li onclick="window.location.href='?page=usuarios'">
                <i class="fas fa-users"></i> <span>Usuários</span>
            </li>
            <li onclick="window.location.href='logout.php'">
                <i class="fas fa-times"></i> <span>Sair</span>
            </li>
        </ul>
    </div>

    <!-- CONTEÚDO PRINCIPAL -->
    <div class="main">
        <div class="header">
            <div>
                <button class="toggle-btn" onclick="toggleSidebar()">☰</button>
                <span>Sistema Denoise</span>
            </div>
        </div>

        <!-- CONTEÚDO VARIÁVEL -->
        <div class="content">
            <?php

            if ($page == 'dashboard') {
                if (!empty($_SESSION['msg'])): ?>
                    <div style="background:#fdd;padding:10px;margin-bottom:10px;border-radius:5px;">
                        <?= $_SESSION['msg'];
                        unset($_SESSION['msg']); ?>
                    </div>
                <?php endif; ?>

                <!-- POPUP CONTAS VENCIDAS -->
                <?php if (!empty($contas_vencidas)): ?>
                    <div id="popupVencidas" class="modal">
                        <div class="modal-content">
                            <h2>⚠ Contas Vencidas</h2>
                            <table>
                                <tr>
                                    <th>Descrição</th>
                                    <th>Valor</th>
                                    <th>Vencimento</th>
                                    <th>Empresa</th>
                                    <th>Pagar/Receber</th>
                                    <th>Ações</th>
                                </tr>
                                <?php foreach ($contas_vencidas as $c): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($c['nome']) ?></td>
                                        <td>R$ <?= number_format($c['valor'], 2, ',', '.') ?></td>
                                        <td><?= date('d/m/Y', strtotime($c['vencimento'])) ?></td>
                                        <td><?= htmlspecialchars($c['empresa']) ?></td>
                                        <td><?= htmlspecialchars($c['tipo']) ?></td>
                                        <td>
                                            <a href="?baixa=<?= $c['id'] ?>" class="btn btn-success"
                                                style="background-color: #00803e; color: #fff; padding: 5px 10px; border-radius: 5px;">
                                                Dar Baixa
                                            </a>
                                            <a href="?excluir=<?= $c['id'] ?>" class="btn btn-danger"
                                                style="background-color: #b30000; color: #fff; padding: 5px 10px; border-radius: 5px;"
                                                onclick="return confirm('Tem certeza que deseja excluir esta conta?')">
                                                Excluir
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                            <br>
                            <button class="close-btn"
                                onclick="document.getElementById('popupVencidas').style.display='none'">Fechar</button>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- CARDS -->
                <div class="container">
                    <div class="cards">
                        <div class="card pagar">
                            <h3>Contas a Pagar</h3>
                            <hr class="linha-card">
                            <p>Pendente: R$ <?= number_format($total_pagar_pendente, 2, ',', '.') ?></p>
                            <p>Pago: R$ <?= number_format($total_pagar_pago, 2, ',', '.') ?></p>
                        </div>
                        <div class="card receber">
                            <h3>Contas a Receber</h3>
                            <hr class="linha-card">
                            <p>Pendente: R$ <?= number_format($total_receber_pendente, 2, ',', '.') ?></p>
                            <p>Recebido: R$ <?= number_format($total_receber_recebido, 2, ',', '.') ?></p>
                        </div>
                        <div class="card resultado">
                            <h3>Resultado</h3>
                            <p><strong>R$ <?= number_format($resultado, 2, ',', '.') ?></strong></p>
                        </div>
                    </div>

                    <!-- FORMULÁRIO -->
                    <div class="ClassFiltro">
                        <h2>Novo Lançamento</h2>
                        <form method='post'>
                            <input type='hidden' name='editar_id' id='editar_id' value=''>
                            <select name="tipo" required id="tipo" onchange="atualizaTipoDespesa()">
                                <option value="">-- Tipo Conta --</option>
                                <option value="pagar">Pagar</option>
                                <option value="receber">Receber</option>
                            </select>
                            <input type="text" name="nome" placeholder="Cliente/Fornecedor" required>
                            <select name="categoria">
                                <option value="projeto">Projeto</option>
                                <option value="recorrente">Recorrente</option>
                            </select>
                            <input type="number" name="parcelas" value="1" min="1">
                            <input type="number" step="0.01" name="valor" placeholder="Valor" required>
                            <input type="date" name="vencimento" required>
                            <select name="situacao">
                                <option value="">-- Situação --</option>
                                <option value="pendente">Pendente</option>
                                <option value="pago">Pago</option>
                            </select>
                            <select name="tipo_despesa" id="tipo_despesa" required>
                                <option value="">-- Custo/Despesa --</option>
                                <option value="custo fixo">Custo Fixo</option>
                                <option value="custo variavel">Custo Variável</option>
                                <option value="despesa fixa">Despesa Fixa</option>
                                <option value="despesa variavel">Despesa Variável</option>
                            </select>
                            <select name="empresa" required>
                                <option value="">-- Empresa --</option>
                                <option value="creative">Creative</option>
                                <option value="produtora">Produtora</option>
                                <option value="results">Results</option>
                            </select>
                            <textarea name="observacao" placeholder="Observação"></textarea>
                            <input type="text" name="nf" placeholder="Nota Fiscal">
                            <button type="submit">Salvar</button>
                        </form>

                        <!-- FILTRO -->
                        <h2>Filtro</h2>
                        <form method="get" style="padding:10px; border:1px solid #ccc; background:silver;">
                            <select name="tipo_filtro">
                                <option value="todos" <?= ($filtro === 'todos' ? 'selected' : '') ?>>Todos</option>
                                <option value="pagar" <?= ($filtro === 'pagar' ? 'selected' : '') ?>>Contas a pagar</option>
                                <option value="receber" <?= ($filtro === 'receber' ? 'selected' : '') ?>>Contas a receber
                                </option>
                            </select>
                            <?php
                            $clientes = $pdo->query("SELECT DISTINCT nome FROM findenoise ORDER BY nome ASC")->fetchAll(PDO::FETCH_COLUMN);
                            ?>

                            <select name="situacao" id="situacao" class="form-control">
                                <option value="">Todos</option>
                                <option value="pendente" <?php if (isset($_GET['situacao']) && $_GET['situacao'] == 'pendente')
                                    echo 'selected'; ?>>Pendente</option>
                                <option value="pago" <?php if (isset($_GET['situacao']) && $_GET['situacao'] == 'pago')
                                    echo 'selected'; ?>>Pago</option>
                            </select>
                            <select name="cliente">
                                <option value="">-- Todos os Clientes --</option>
                                <?php foreach ($clientes as $c): ?>
                                    <option value="<?= htmlspecialchars($c) ?>" <?= ($clienteFiltro === $c ? 'selected' : '') ?>>
                                        <?= htmlspecialchars($c) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <!-- Novo campo Empresa -->
                            <select name="empresa">
                                <option value="">-- Todas as Empresas --</option>
                                <option value="creative" <?= ($empresaFiltro === 'creative' ? 'selected' : '') ?>>Creative
                                </option>
                                <option value="produtora" <?= ($empresaFiltro === 'produtora' ? 'selected' : '') ?>>Produtora
                                </option>
                                <option value="results" <?= ($empresaFiltro === 'results' ? 'selected' : '') ?>>Results
                                </option>
                            </select>
                            <input type="number" name="mes" placeholder="Mês" min="1" max="12"
                                value="<?= $mesFiltro ?: '' ?>">
                            <input type="number" name="ano" placeholder="Ano" min="2000" max="2100"
                                value="<?= $anoFiltro ?: '' ?>">
                            <button type="submit">Filtrar</button>
                            <a href="lancamentos.php?tipo_filtro=todos"><button type="button">Limpar</button></a>
                        </form>

                        <!-- IMPORTAÇÃO -->
                        <h2 for="arquivo">Importar planilha:</h2>
                        <form action="" method="post" enctype="multipart/form-data">
                            <input type="file" name="arquivo" id="arquivo" accept=".csv, .xlsx">
                            <button type="submit" name="importar">Importar</button>
                        </form>

                        <!-- TABELA -->
                        <h2>Lançamentos</h2>
                        <table>
                            <tr>
                                <th>ID</th>
                                <th>Tipo</th>
                                <th>Nome</th>
                                <th>Categoria</th>
                                <th>Parcelas</th>
                                <th>Valor</th>
                                <th>Vencimento</th>
                                <th>Situação</th>
                                <th>Tipo Despesa</th>
                                <th>Empresa</th>
                                <th>Observação</th>
                                <th>Nota Fiscal</th>
                                <th>Ações</th>
                            </tr>
                            <?php foreach ($movs as $m): ?>
                                <?php
                                $classe = "";
                                if ($m['situacao'] === 'pendente' && $m['vencimento'] < date('Y-m-d')) {
                                    $classe = "vencida";
                                }
                                ?>
                                <tr class="<?= $classe ?>">
                                    <td><?= $m['id'] ?></td>
                                    <td><?= $m['tipo'] ?></td>
                                    <td><?= $m['nome'] ?></td>
                                    <td><?= $m['categoria'] ?></td>
                                    <td><?= $m['parcelas'] ?></td>
                                    <td>R$ <?= number_format(floatval(str_replace(',', '', $m['valor'])), 2, ',', '.') ?></td>
                                    <td><?= date('d/m/Y', strtotime($m['vencimento'])) ?></td>
                                    <td><?= $m['situacao'] ?></td>
                                    <td><?= $m['tipo_despesa'] ?></td>
                                    <td><?= $m['empresa'] ?></td>
                                    <td><?= $m['observacao'] ?></td>
                                    <td><?= $m['nf'] ?></td>
                                    <td>
                                        <a class="btn" href="?excluir=<?= htmlspecialchars($m['id']) ?>">Excluir</a>
                                        <button type="button" class="btn editarBtn" data-id="<?= htmlspecialchars($m['id']) ?>"
                                            data-tipo="<?= htmlspecialchars($m['tipo']) ?>"
                                            data-nome="<?= htmlspecialchars($m['nome']) ?>"
                                            data-categoria="<?= htmlspecialchars($m['categoria']) ?>"
                                            data-parcelas="<?= htmlspecialchars($m['parcelas']) ?>"
                                            data-valor="<?= htmlspecialchars($m['valor']) ?>"
                                            data-vencimento="<?= htmlspecialchars($m['vencimento']) ?>"
                                            data-situacao="<?= htmlspecialchars($m['situacao']) ?>"
                                            data-tipo_despesa="<?= htmlspecialchars($m['tipo_despesa']) ?>"
                                            data-empresa="<?= htmlspecialchars($m['empresa']) ?>"
                                            data-observacao="<?= htmlspecialchars($m['observacao']) ?>"
                                            data-nf="<?= htmlspecialchars($m['nf']) ?>">Editar</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>

                   <!-- PAGINAÇÃO -->
                    <div style="margin:20px; text-align:center; color: white">
                        <?php
                        // Captura todos os filtros atuais da URL
                        $queryString = $_GET;

                        // Remove o parâmetro de página atual
                        unset($queryString['pagina']);

                        // Reconstrói a query string preservando os filtros
                        $filtros = http_build_query($queryString);
                        ?>

                        <?php if ($pagina > 1): ?>
                            <a href="?pagina=<?= $pagina - 1 ?>&<?= $filtros ?>">« Anterior</a>
                        <?php endif; ?>

                        Página <?= $pagina ?> de <?= $total_paginas ?>

                        <?php if ($pagina < $total_paginas): ?>
                            <a href="?pagina=<?= $pagina + 1 ?>&<?= $filtros ?>">Próxima »</a>
                        <?php endif; ?>
                    </div>

                </div>

            <?php } elseif ($page == 'relatorios') { ?>

                <div class="iframe-container">
                    <iframe title="Dashboard Financeiro Denoise"
                        src="https://app.powerbi.com/view?r=eyJrIjoiNzIyMDliNzEtODM4NC00NjI3LWEyNDUtMWM5MTRlN2UxY2Y0IiwidCI6IjYxM2UzZWZlLTVlOTAtNDY1OC04Y2JjLThhODZiYTcyZGE4MSJ9"
                        frameborder="0" allowfullscreen="true">
                    </iframe>
                </div>


            <?php } elseif ($page == 'usuarios') { ?>
                <div class="container mt-5 mb-5">
                    <div class="card shadow-sm mx-auto" style="max-width: 450px;">
                        <div class="card-header text-center bg-success text-white">
                            <h4>Cadastro de Usuário</h4>
                        </div>
                        <div class="card-body">
                            <?= $msg_usuario ?>
                            <form method="POST">
                                <input type="hidden" name="form" value="usuarios">
                                <div class="mb-3">
                                    <label for="nome" class="form-label">Nome:</label>
                                    <input type="text" class="form-control" id="nome" name="nome" required>
                                </div>
                                <div class="mb-3">
                                    <label for="email" class="form-label">E-mail:</label>
                                    <input type="email" class="form-control" id="email" name="email" required>
                                </div>
                                <div class="mb-3">
                                    <label for="senha" class="form-label">Senha:</label>
                                    <input type="password" class="form-control" id="senha" name="senha" required>
                                </div>
                                <button type="submit" class="btn btn-success w-100">Cadastrar</button>
                            </form>
                        </div>
                    </div>
                </div>


            <?php } else { ?>
                <h1>404 - Página não encontrada</h1>
            <?php } ?>
        </div>

        <footer>
            <p>Sistema desenvolvido por <strong>Felipe Santos</strong> - Action Tech</p>
            <p>&copy; 2025 Todos os direitos reservados</p>
        </footer>
    </div>

    <!-- SCRIPTS -->
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById("sidebar");
            sidebar.classList.toggle("collapsed");
            if (sidebar.classList.contains("collapsed")) {
                localStorage.setItem("sidebarState", "collapsed");
            } else {
                localStorage.setItem("sidebarState", "expanded");
            }
        }
        document.addEventListener("DOMContentLoaded", function () {
            let savedTheme = localStorage.getItem("theme") || "light";
            let modal = document.getElementById("popupVencidas");
            if (modal) modal.style.display = 'block';
        });
        function atualizaTipoDespesa() {
            let tipo = document.getElementById("tipo").value;
            let selectDespesa = document.getElementById("tipo_despesa");
            selectDespesa.innerHTML = "";
            if (tipo === "receber") {
                let opt = document.createElement("option");
                opt.value = "receita";
                opt.text = "Receita";
                selectDespesa.appendChild(opt);
                selectDespesa.value = "receita";
                selectDespesa.disabled = true;
            } else if (tipo === "pagar") {
                let opcoes = ["-- Custo/Despesa --", "Custo Fixo", "Custo Variável", "Despesa Fixa", "Despesa Variável"];
                let valores = ["", "custo fixo", "custo variavel", "despesa fixa", "despesa variavel"];
                for (let i = 0; i < opcoes.length; i++) {
                    let opt = document.createElement("option");
                    opt.value = valores[i];
                    opt.text = opcoes[i];
                    selectDespesa.appendChild(opt);
                }
                selectDespesa.disabled = false;
            } else {
                let opt = document.createElement("option");
                opt.value = "";
                opt.text = "-- Custo/Despesa --";
                selectDespesa.appendChild(opt);
                selectDespesa.disabled = false;
            }
        }
        document.querySelectorAll('.editarBtn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.getElementById('editar_id').value = this.dataset.id;
                document.querySelector('[name=tipo]').value = this.dataset.tipo;
                if (typeof atualizaTipoDespesa === 'function') { atualizaTipoDespesa(); }
                document.querySelector('[name=nome]').value = this.dataset.nome;
                document.querySelector('[name=categoria]').value = this.dataset.categoria;
                document.querySelector('[name=parcelas]').value = this.dataset.parcelas;
                document.querySelector('[name=valor]').value = this.dataset.valor;
                document.querySelector('[name=vencimento]').value = this.dataset.vencimento;
                document.querySelector('[name=situacao]').value = this.dataset.situacao;
                document.querySelector('[name=tipo_despesa]').value = this.dataset.tipo_despesa;
                document.querySelector('[name=empresa]').value = this.dataset.empresa;
                document.querySelector('[name=observacao]').value = this.dataset.observacao;
                document.querySelector('[name=nf]').value = this.dataset.nf;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });
    </script>

</body>


</html>