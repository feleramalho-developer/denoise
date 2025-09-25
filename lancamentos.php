<?php
include('protect.php');

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
// EXCLUIR REGISTRO
if (isset($_GET['excluir'])) {
    $id = intval($_GET['excluir']);
    $pdo->prepare("DELETE FROM movimentacoes WHERE id=?")->execute([$id]);
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
        $stmt = $pdo->prepare("UPDATE movimentacoes SET 
        tipo=?, descricao=?, categoria=?, parcelas=?, valor=?, vencimento=?, situacao=?, tipo_despesa=?, empresa=?, observacao=?
        WHERE id=?");
        $stmt->execute([
            $tipoU,
            $_POST['descricao'],
            $_POST['categoria'],
            $_POST['parcelas'] ?: 1,
            $_POST['valor'],
            $_POST['vencimento'],
            $_POST['situacao'],
            $tipo_despesaU,
            $_POST['empresa'],
            $_POST['observacao'],
            $_POST['editar_id']
        ]);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        echo '<div style="color:red;">Erro ao atualizar: ' . $e->getMessage() . '</div>';
    }
}
// --- Importar Planilha XLSX ---
if (isset($_POST['importar']) && isset($_FILES['arquivo'])) {
    $arquivo = $_FILES['arquivo']['tmp_name'];
    if ($arquivo) {
        try {
            $spreadsheet = IOFactory::load($arquivo);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();
            // Remove cabeçalho
            $cabecalho = array_shift($rows);
            $sql = "INSERT INTO movimentacoes 
            (tipo, descricao, categoria, parcelas, valor, vencimento, situacao, tipo_despesa, empresa, observacao)
            VALUES (:tipo, :descricao, :categoria, :parcelas, :valor, :vencimento, :situacao, :tipo_despesa, :empresa, :observacao)";
            $stmt = $pdo->prepare($sql);
            foreach ($rows as $row) {
                $stmt->execute([
                    ':tipo' => $row[0] ?? '',
                    ':descricao' => $row[1] ?? '',
                    ':categoria' => $row[2] ?? null,
                    ':parcelas' => $row[3] ?? 1,
                    ':valor' => $row[4] ?? 0,
                    ':vencimento' => date('Y-m-d', strtotime($row[5] ?? date('Y-m-d'))),
                    ':situacao' => $row[6] ?? 'pendente',
                    ':tipo_despesa' => $row[7] ?? '',
                    ':empresa' => $row[8] ?? '',
                    ':observacao' => $row[9] ?? ''
                ]);
            }
            // echo "<div class='alert success'>Importação concluída com sucesso!</div>";
            header("Refresh:1; url=" . $_SERVER['PHP_SELF']);
            exit;
        } catch (Exception $e) {
            echo "<div class='alert error'>Erro ao importar: " . $e->getMessage() . "</div>";
        }
    }
}
// Paginação
$por_pagina = 20;
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina - 1) * $por_pagina;
// INSERIR REGISTRO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['editar_id'])) {
    $tipo = $_POST['tipo'];
    $descricao = $_POST['descricao'];
    $categoria = $_POST['categoria'];
    $parcelas = max(1, intval($_POST['parcelas'] ?? 1));
    $valor = $_POST['valor'];
    $vencimento = $_POST['vencimento'];
    $situacao = $_POST['situacao'];
    $tipo_despesa = $_POST['tipo_despesa'] ?? '';
    if ($tipo === 'receber') {
        $tipo_despesa = 'receita';
    }
    $empresa = $_POST['empresa'];
    $observacao = $_POST['observacao'];
    try {
        $stmt = $pdo->prepare("INSERT INTO movimentacoes 
            (tipo, descricao, categoria, parcelas, valor, vencimento, situacao, tipo_despesa, empresa, observacao)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        for ($i = 0; $i < $parcelas; $i++) {
            $data_parcela = date('Y-m-d', strtotime("+$i month", strtotime($vencimento)));
            $obs_parcela = "Parcela " . ($i + 1) . "/" . $parcelas;
            if (!empty($observacao)) {
                $obs_parcela .= " - " . $observacao;
            }
            $stmt->execute([
                $tipo,
                $descricao,
                $categoria,
                $parcelas,
                $valor,
                $data_parcela,
                $situacao,
                $tipo_despesa,
                $empresa,
                $obs_parcela
            ]);
        }
        echo "<div style='color:green;'>Lançamento salvo com sucesso!</div>";
        header("Refresh: 1; url=" . $_SERVER['PHP_SELF']); // dá tempo de ver a msg
        exit;
    } catch (Exception $e) {
        echo "<div style='color:red;'>Erro ao salvar: " . $e->getMessage() . "</div>";
    }
}
// CONSULTA TOTAIS (COALESCE evita NULL)
$total_pagar_pendente = $pdo->query("SELECT COALESCE(SUM(valor),0) FROM movimentacoes WHERE tipo='pagar' AND (situacao IS NULL OR situacao='pendente')")->fetchColumn();
$total_pagar_pago = $pdo->query("SELECT COALESCE(SUM(valor),0) FROM movimentacoes WHERE tipo='pagar' AND situacao='pago'")->fetchColumn();
$total_receber_pendente = $pdo->query("SELECT COALESCE(SUM(valor),0) FROM movimentacoes WHERE tipo='receber' AND (situacao IS NULL OR situacao='pendente')")->fetchColumn();
$total_receber_recebido = $pdo->query("SELECT COALESCE(SUM(valor),0) FROM movimentacoes WHERE tipo='receber' AND situacao='pago'")->fetchColumn();
$resultado = $total_receber_recebido - $total_pagar_pago;
// CONSULTAR CONTAS VENCIDAS <!--Código incluído novo -->
$hoje = date('Y-m-d');
$stmt_vencidas = $pdo->prepare("SELECT * FROM movimentacoes WHERE vencimento <= ? AND situacao = 'pendente' ORDER BY vencimento ASC");
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
    $where[] = "descricao LIKE :cliente";
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
$whereSQL = $where ? ("WHERE " . implode(" AND ", $where)) : '';
// Contagem total de registros para paginação
$sqlCount = "SELECT COUNT(*) FROM movimentacoes $whereSQL";
$stmtCount = $pdo->prepare($sqlCount);
// Bind dos mesmos filtros da listagem
foreach ($params as $k => $v) {
    $stmtCount->bindValue($k, $v);
}
$stmtCount->execute();
$total_reg = $stmtCount->fetchColumn();
$total_paginas = ceil($total_reg / $por_pagina);
$sql = "SELECT * FROM movimentacoes $whereSQL ORDER BY vencimento ASC LIMIT :limit OFFSET :offset";
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

?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <style>
        body.light-theme {
            background-image: linear-gradient(45deg, white, silver);
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
            background: #007bff;
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
    </style>
    <meta charset="UTF-8">
    <title>Financeiro</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 0;
            background-image: url('./img/fundo.jpg');
            background-size: cover;
            background-position: center;
            color: #333;
        }

        h1 {
            text-align: center;
            margin: 20px 0;
            color: #fff;
        }

        h2 {
            text-align: left;
            margin: 20px 0;
            color: silver;
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
            margin: 20px 0;
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
            font-size: 18px;
            box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
        }

        .pagar {
            background-color: rgba(100, 0, 0, 0.6);
        }

        .receber {
            background-color: rgba(0, 100, 0, 0.6);
        }

        .resultado {
            background-color: rgba(0, 0, 100, 0.6);
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
            font-size: 14px;
        }

        button {
            background: #3498db;
            color: #fff;
            border: none;
            cursor: pointer;
            transition: background 0.3s;
            box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
        }

        button:hover {
            background: #2980b9;
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
            font-size: 14px;
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
    </style>
</head>
<!--Código incluído novo -->
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
                </tr>
                <?php foreach ($contas_vencidas as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c['descricao']) ?></td>
                        <td>R$ <?= number_format($c['valor'], 2, ',', '.') ?></td>
                        <td><?= date('d/m/Y', strtotime($c['vencimento'])) ?></td>
                        <td><?= htmlspecialchars($c['empresa']) ?></td>
                        <td><?= htmlspecialchars($c['tipo']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <br>
            <button class="close-btn"
                onclick="document.getElementById('popupVencidas').style.display='none'">Fechar</button>
        </div>
    </div>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            let modal = document.getElementById("popupVencidas");
            if (modal) modal.style.display = 'block';
        });
    </script>
    <?php $_SESSION['mostrar_modal'] = false; // já exibiu uma vez ?>
<?php endif; ?>

<body>
    <div class="container">
        <h1>💰 Controle Financeiro Denoise</h1>
        <p>
            <a href="logout.php">
                <button class="btn-sair">Sair</button>
            </a>
        </p>
        <h5> Usuário: <?php echo $_SESSION['nome'] ?></h5>
        <!-- CARDS -->
        <div class="cards">
            <div class="card pagar">
                <h3>Contas a Pagar</h3>
                <p>Pendente: R$ <?= number_format($total_pagar_pendente, 2, ',', '.') ?></p>
                <p>Pago: R$ <?= number_format($total_pagar_pago, 2, ',', '.') ?></p>
            </div>
            <div class="card receber">
                <h3>Contas a Receber</h3>
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
                <input type="text" name="descricao" placeholder="Cliente/Fornecedor" required>
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
                    <option value="creativa">Creativa</option>
                    <option value="produtora">Produtora</option>
                    <option value="results">Results</option>
                </select>
                <textarea name="observacao" placeholder="Observação"></textarea>
                <button type="submit">Salvar</button>
            </form>
            <!-- TABELA -->
            <!-- Ajustado 13/09 -->
            <h2>Filtro</h2>
            <form method="get" style="padding:10px; border:1px solid #ccc; background:silver;">
                <select name="tipo_filtro" placeholder="Cliente/Fornecedor">
                    <option value="todos" <?= ($filtro === 'todos' ? 'selected' : '') ?>>Todos</option>
                    <option value="pagar" <?= ($filtro === 'pagar' ? 'selected' : '') ?>>Contas a pagar</option>
                    <option value="receber" <?= ($filtro === 'receber' ? 'selected' : '') ?>>Contas a receber</option>
                </select>
                <?php
                $clientes = $pdo->query("SELECT DISTINCT descricao FROM movimentacoes ORDER BY descricao ASC")->fetchAll(PDO::FETCH_COLUMN);
                ?>
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
                    <option value="creativa" <?= ($empresaFiltro === 'creativa' ? 'selected' : '') ?>>Creativa</option>
                    <option value="results" <?= ($empresaFiltro === 'results' ? 'selected' : '') ?>>Results</option>
                    <option value="produtora" <?= ($empresaFiltro === 'produtora' ? 'selected' : '') ?>>Produtora</option>
                </select>
                <input type="number" name="mes" placeholder="Mês" min="1" max="12" value="<?= $mesFiltro ?: '' ?>">
                <input type="number" name="ano" placeholder="Ano" min="2000" max="2100" value="<?= $anoFiltro ?: '' ?>">
                <button type="submit">Filtrar</button>
                <a href="lancamentos.php?tipo_filtro=todos">
                    <button type="button">Limpar</button>
                </a>
            </form>
            <!-- FORMULÁRIO DE IMPORTAÇÃO -->
            <h2 for="arquivo">Importar planilha:</h2>
            <form action="" method="post" enctype="multipart/form-data">
                <input type="file" name="arquivo" id="arquivo" accept=".csv, .xlsx">
                <button type="submit" name="importar">Importar</button>
            </form>
            <h2>Lançamentos</h2>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Tipo</th>
                    <th>Descrição</th>
                    <th>Categoria</th>
                    <th>Parcelas</th>
                    <th>Valor</th>
                    <th>Vencimento</th>
                    <th>Situação</th>
                    <th>Empresa</th>
                    <th>Observação</th>
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
                        <td><?= $m['descricao'] ?></td>
                        <td><?= $m['categoria'] ?></td>
                        <td><?= $m['parcelas'] ?></td>
                        <td>R$ <?= number_format($m['valor'], 2, ',', '.') ?></td>
                        <td><?= date('d/m/Y', strtotime($m['vencimento'])) ?></td>
                        <td><?= $m['situacao'] ?></td>
                        <td><?= $m['empresa'] ?></td>
                        <td><?= $m['observacao'] ?></td>
                        <td>
                            <a class="btn" href="?excluir=<?= htmlspecialchars($m['id']) ?>">Excluir</a>
                            <button type="button" class="btn editarBtn" data-id="<?= htmlspecialchars($m['id']) ?>"
                                data-tipo="<?= htmlspecialchars($m['tipo']) ?>"
                                data-descricao="<?= htmlspecialchars($m['descricao']) ?>"
                                data-categoria="<?= htmlspecialchars($m['categoria']) ?>"
                                data-parcelas="<?= htmlspecialchars($m['parcelas']) ?>"
                                data-valor="<?= htmlspecialchars($m['valor']) ?>"
                                data-vencimento="<?= htmlspecialchars($m['vencimento']) ?>"
                                data-situacao="<?= htmlspecialchars($m['situacao']) ?>"
                                data-tipo_despesa="<?= htmlspecialchars($m['tipo_despesa']) ?>"
                                data-empresa="<?= htmlspecialchars($m['empresa']) ?>"
                                data-observacao="<?= htmlspecialchars($m['observacao']) ?>">Editar</button>
                        </td>

                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <div style="margin:20px; text-align:center; color: white">
            <?php if ($pagina > 1): ?>
                <a href="?pagina=<?= $pagina - 1 ?>&tipo_filtro=<?= $filtro ?>">« Anterior</a>
            <?php endif; ?>

            Página <?= $pagina ?> de <?= $total_paginas ?>

            <?php if ($pagina < $total_paginas): ?>
                <a href="?pagina=<?= $pagina + 1 ?>&tipo_filtro=<?= $filtro ?>">Próxima »</a>
            <?php endif; ?>
        </div>
    </div>
    <script>
        function toggleTheme() {
            let body = document.body;
            if (body.classList.contains("dark-theme")) {
                body.classList.remove("dark-theme");
                body.classList.add("light-theme");
                localStorage.setItem("theme", "light");
            } else {
                body.classList.remove("light-theme");
                body.classList.add("dark-theme");
                localStorage.setItem("theme", "dark");
            }
        }
    </script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            // Aplica o tema salvo
            let savedTheme = localStorage.getItem("theme") || "light";
            // document.body.classList.add(savedTheme + "-theme");
            // Se houver contas vencidas, abre o modal
            let modal = document.getElementById("popupVencidas");
            if (modal) {
                modal.style.display = 'block';
            }
        });
    </script>
    <script>
        function atualizaTipoDespesa() {
            let tipo = document.getElementById("tipo").value;
            let selectDespesa = document.getElementById("tipo_despesa");
            // limpa opções
            selectDespesa.innerHTML = "";
            if (tipo === "receber") {
                // Se for receber, fixa como Receita
                let opt = document.createElement("option");
                opt.value = "receita";
                opt.text = "Receita";
                selectDespesa.appendChild(opt);
                selectDespesa.value = "receita";
                selectDespesa.disabled = true;
            } else if (tipo === "pagar") {
                // Se for pagar, mostra todas as opções de despesa
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
                // nenhum selecionado
                let opt = document.createElement("option");
                opt.value = "";
                opt.text = "-- Custo/Despesa --";
                selectDespesa.appendChild(opt);
                selectDespesa.disabled = false;
            }
        }
    </script>
    <script>
        document.querySelectorAll('.editarBtn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.getElementById('editar_id').value = this.dataset.id;
                document.querySelector('[name=tipo]').value = this.dataset.tipo;
                if (typeof atualizaTipoDespesa === 'function') { atualizaTipoDespesa(); }
                document.querySelector('[name=descricao]').value = this.dataset.descricao;
                document.querySelector('[name=categoria]').value = this.dataset.categoria;
                document.querySelector('[name=parcelas]').value = this.dataset.parcelas;
                document.querySelector('[name=valor]').value = this.dataset.valor;
                document.querySelector('[name=vencimento]').value = this.dataset.vencimento;
                document.querySelector('[name=situacao]').value = this.dataset.situacao;
                document.querySelector('[name=tipo_despesa]').value = this.dataset.tipo_despesa;
                document.querySelector('[name=empresa]').value = this.dataset.empresa;
                document.querySelector('[name=observacao]').value = this.dataset.observacao;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });
    </script>
</body>

</html>