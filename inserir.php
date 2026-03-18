<?php
require_once 'config.php';
require_login();

$submetidoPor = (string)($_SESSION['user']['login'] ?? '');

function garantir_colunas_submissao(mysqli $conn, string $tabela): void {
    $colSubmetidoPor = $conn->query("SHOW COLUMNS FROM {$tabela} LIKE 'submetido_por'")->fetch_assoc();
    if (!$colSubmetidoPor) {
        $conn->query("ALTER TABLE {$tabela} ADD COLUMN submetido_por VARCHAR(20) DEFAULT NULL");
    }

    $colSubmetidoEm = $conn->query("SHOW COLUMNS FROM {$tabela} LIKE 'submetido_em'")->fetch_assoc();
    if (!$colSubmetidoEm) {
        $conn->query("ALTER TABLE {$tabela} ADD COLUMN submetido_em DATETIME DEFAULT NULL");
    }
}

if (isset($_POST['add_curso'])) {
    garantir_colunas_submissao($conn, 'cursos');
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $descricao = $descricao === '' ? null : $descricao;

    if ($nome === '') {
        header("Location: cursos.php?error=empty");
        exit;
    }

    // Normalizar (opcional): evita "Matemática" vs "matemática "
    $nome_norm = preg_replace('/\s+/', ' ', $nome);

    // 1) Check antes de inserir (mensagem amigável)
    $stmt = $conn->prepare("SELECT 1 FROM cursos WHERE Nome = ? LIMIT 1");
    $stmt->bind_param("s", $nome_norm);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        header("Location: cursos.php?error=already_exists");
        exit;
    }

    // 2) Inserir
    $stmt = $conn->prepare("INSERT INTO cursos (Nome, descricao, submetido_por, submetido_em) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("sss", $nome_norm, $descricao, $submetidoPor);

    try {
        $stmt->execute();
        header("Location: cursos.php?success=added");
        exit;
    } catch (mysqli_sql_exception $e) {
        // Se houver UNIQUE e alguém inserir ao mesmo tempo, apanha aqui
        if ($e->getCode() === 1062) { // duplicate key
            header("Location: cursos.php?error=already_exists");
            exit;
        }
        throw $e; // outro erro -> deixa aparecer para debug
    }
}

if (isset($_POST['update_curso'])) {
    $id = (int)($_POST['curso_id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $descricao = $descricao === '' ? null : $descricao;

    if ($id <= 0) {
        header("Location: cursos.php");
        exit;
    }

    if ($nome === '') {
        header("Location: cursos.php?error=empty&edit_curso={$id}");
        exit;
    }

    $nome_norm = preg_replace('/\s+/', ' ', $nome);

    $stmt = $conn->prepare("SELECT 1 FROM cursos WHERE Nome = ? AND ID <> ? LIMIT 1");
    $stmt->bind_param("si", $nome_norm, $id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        header("Location: cursos.php?error=already_exists&edit_curso={$id}");
        exit;
    }

    $stmt = $conn->prepare("UPDATE cursos SET Nome = ?, descricao = ? WHERE ID = ?");
    $stmt->bind_param("ssi", $nome_norm, $descricao, $id);

    try {
        $stmt->execute();
        header("Location: cursos.php?success=edited");
        exit;
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) {
            header("Location: cursos.php?error=already_exists&edit_curso={$id}");
            exit;
        }
        throw $e;
    }
}

if (isset($_POST['add_disciplina'])) {
    garantir_colunas_submissao($conn, 'disciplinas');
    $nome = trim($_POST['nome'] ?? '');

    if ($nome === '') {
        header("Location: disciplinas.php?error=empty");
        exit;
    }

    // Normalizar (opcional): evita "Matemática" vs "matemática "
    $nome_norm = preg_replace('/\s+/', ' ', $nome);

    // 1) Check antes de inserir (mensagem amigável)
    $stmt = $conn->prepare("SELECT 1 FROM disciplinas WHERE Nome_disc = ? LIMIT 1");
    $stmt->bind_param("s", $nome_norm);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        header("Location: disciplinas.php?error=already_exists");
        exit;
    }

    // 2) Inserir
    $stmt = $conn->prepare("INSERT INTO disciplinas (Nome_disc, submetido_por, submetido_em) VALUES (?, ?, NOW())");
    $stmt->bind_param("ss", $nome_norm, $submetidoPor);

    try {
        $stmt->execute();
        header("Location: disciplinas.php?success=added");
        exit;
    } catch (mysqli_sql_exception $e) {
        // Se houver UNIQUE e alguém inserir ao mesmo tempo, apanha aqui
        if ($e->getCode() === 1062) { // duplicate key
            header("Location: disciplinas.php?error=already_exists");
            exit;
        }
        throw $e; // outro erro -> deixa aparecer para debug
    }
}

if (isset($_POST['update_disciplina'])) {
    $id = (int)($_POST['disciplina_id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');

    if ($id <= 0) {
        header("Location: disciplinas.php");
        exit;
    }

    if ($nome === '') {
        header("Location: disciplinas.php?error=empty&edit_disciplina={$id}");
        exit;
    }

    $nome_norm = preg_replace('/\s+/', ' ', $nome);

    $stmt = $conn->prepare("SELECT 1 FROM disciplinas WHERE Nome_disc = ? AND ID <> ? LIMIT 1");
    $stmt->bind_param("si", $nome_norm, $id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        header("Location: disciplinas.php?error=already_exists&edit_disciplina={$id}");
        exit;
    }

    $stmt = $conn->prepare("UPDATE disciplinas SET Nome_disc = ? WHERE ID = ?");
    $stmt->bind_param("si", $nome_norm, $id);

    try {
        $stmt->execute();
        header("Location: disciplinas.php?success=edited");
        exit;
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) {
            header("Location: disciplinas.php?error=already_exists&edit_disciplina={$id}");
            exit;
        }
        throw $e;
    }
}

if (isset($_POST['add_matricula'])) {
    $login = $_SESSION['user']['login'];
    $curso_id = (int)$_POST['curso_id'];
    if ($curso_id > 0) {
        $stmt = $conn->prepare("INSERT IGNORE INTO matriculas (login, curso_id) VALUES (?, ?)");
        $stmt->bind_param("si", $login, $curso_id);
        $stmt->execute();
    }
    header("Location: matriculas.php");
    exit;
}

if (isset($_POST['add_plano'])) {
    garantir_colunas_submissao($conn, 'plano_estudos');
    $curso_id = (int)$_POST['curso_id'];
    $disciplina_id = (int)$_POST['disciplina_id'];
    $semestre = (int)($_POST['semestre'] ?? 1);
    if ($semestre !== 2) {
        $semestre = 1;
    }
    if ($curso_id > 0 && $disciplina_id > 0) {
        // Verificar se o vínculo já existe
        $check = $conn->prepare("SELECT 1 FROM plano_estudos WHERE CURSOS = ? AND DISCIPLINA = ? LIMIT 1");
        $check->bind_param("ii", $curso_id, $disciplina_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            header("Location: plano_estudos.php?error=already_exists");
        } else {
            $stmt = $conn->prepare("INSERT INTO plano_estudos (CURSOS, DISCIPLINA, semestre, submetido_por, submetido_em) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("iiis", $curso_id, $disciplina_id, $semestre, $submetidoPor);
            $stmt->execute();
            header("Location: plano_estudos.php?success=added");
        }
    } else {
        header("Location: plano_estudos.php");
    }
    exit;
}

if (isset($_GET['del_curso'])) {
    $id = (int)$_GET['del_curso'];
    $conn->query("DELETE FROM cursos WHERE ID = $id");
    header("Location: cursos.php");
    exit;
}

if (isset($_GET['del_disciplina'])) {
    $id = (int)$_GET['del_disciplina'];
    $conn->query("DELETE FROM disciplinas WHERE ID = $id");
    header("Location: disciplinas.php");
    exit;
}

if (isset($_GET['del_curso_mat'])) {
    $login = $_SESSION['user']['login'];
    $curso_id = (int)$_GET['del_curso_mat'];
    $stmt = $conn->prepare("DELETE FROM matriculas WHERE login = ? AND curso_id = ?");
    $stmt->bind_param("si", $login, $curso_id);
    $stmt->execute();
    header("Location: matriculas.php");
    exit;
}

if (isset($_GET['del_plano_curso']) && isset($_GET['del_plano_disciplina'])) {
    $curso_id = (int)$_GET['del_plano_curso'];
    $disciplina_id = (int)$_GET['del_plano_disciplina'];
    $stmt = $conn->prepare("DELETE FROM plano_estudos WHERE CURSOS = ? AND DISCIPLINA = ?");
    $stmt->bind_param("ii", $curso_id, $disciplina_id);
    $stmt->execute();
    header("Location: plano_estudos.php");
    exit;
}

header("Location: index.php");
?>
