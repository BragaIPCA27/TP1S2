<?php
require_once 'config.php';
require_group(['ADMIN']);

$login = $_SESSION['user']['login'];
$grupo = $_SESSION['user']['grupo_nome'] ?? 'ADMIN';
$tipoUtilizador = match ($grupo) {
  'ADMIN' => 'Administrador',
  'GESTOR' => 'Gestor',
  'FUNCIONARIO' => 'Funcionário',
  default => 'Aluno',
};

$perfilAdminSidebar = [];
$stmt = $conn->prepare("SELECT nome, email, telefone, morada, foto_path FROM admin_perfis WHERE login = ? LIMIT 1");
$stmt->bind_param("s", $login);
$stmt->execute();
$perfilAdminSidebar = $stmt->get_result()->fetch_assoc() ?: [];

function traduz_estado_aprovacao(string $status): string {
    return match (strtoupper($status)) {
        'APPROVED' => 'Aprovado',
        'REJECTED' => 'Recusado',
        default => 'Pendente',
    };
}

function apagar_foto_perfil(?string $path): void {
  if (!$path || !str_starts_with($path, 'assets/img/perfis/')) {
    return;
  }

  $abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
  if (is_file($abs)) {
    @unlink($abs);
  }
}

function guardar_aluno_aprovado_parcial(
  mysqli $conn,
  string $loginAluno,
  array $pedido,
  bool $aprovarNome,
  bool $aprovarTelefone,
  bool $aprovarMorada,
  bool $aprovarFoto
): void {
  $stmt = $conn->prepare("SELECT nome, email, telefone, morada, foto_path FROM alunos WHERE login = ? LIMIT 1");
  $stmt->bind_param("s", $loginAluno);
  $stmt->execute();
  $atual = $stmt->get_result()->fetch_assoc() ?: [];

  $matricula = $loginAluno;
  $nomeFinal = $aprovarNome ? (string)($pedido['nome'] ?? '') : (string)($atual['nome'] ?? '');
  $moradaFinal = $aprovarMorada ? (string)($pedido['morada'] ?? '') : (string)($atual['morada'] ?? '');
  $telefonePedido = trim((string)($pedido['telefone'] ?? ''));
  $telefoneFinal = $aprovarTelefone ? ($telefonePedido === '' ? null : $telefonePedido) : ($atual['telefone'] ?? null);
  $fotoPedido = trim((string)($pedido['foto_path'] ?? ''));
  $fotoFinal = $aprovarFoto ? ($fotoPedido === '' ? null : $fotoPedido) : ($atual['foto_path'] ?? null);
  $emailFinal = $atual['email'] ?? ($pedido['email'] ?? null);

  if (!empty($atual)) {
    $fotoAnterior = $atual['foto_path'] ?? null;

    $stmt = $conn->prepare(
      "UPDATE alunos
          SET nome = ?, morada = ?, email = ?, telefone = ?, foto_path = ?
        WHERE login = ?"
    );
    $stmt->bind_param("ssssss", $nomeFinal, $moradaFinal, $emailFinal, $telefoneFinal, $fotoFinal, $loginAluno);
    $stmt->execute();

    if ($aprovarFoto && $fotoAnterior && $fotoAnterior !== $fotoFinal) {
      apagar_foto_perfil($fotoAnterior);
    }
    return;
  }

  $stmt = $conn->prepare(
    "INSERT INTO alunos (login, matricula, nome, email, telefone, morada, foto_path)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
  );
  $stmt->bind_param("sssssss", $loginAluno, $matricula, $nomeFinal, $emailFinal, $telefoneFinal, $moradaFinal, $fotoFinal);
  $stmt->execute();
}

$erro = null;
$ok = null;

if (isset($_GET['user_action'], $_GET['login'])) {
    $targetLogin = trim($_GET['login']);
    $userAction = $_GET['user_action'];

  if (in_array($userAction, ['approve', 'reject', 'delete'], true)) {
    if ($userAction === 'delete') {
      $stmt = $conn->prepare(
        "SELECT g.GRUPO AS tipo_conta
           FROM users u
           JOIN grupos g ON g.ID = u.grupo
          WHERE u.login = ?
          LIMIT 1"
      );
      $stmt->bind_param("s", $targetLogin);
      $stmt->execute();
      $targetUser = $stmt->get_result()->fetch_assoc();

      if (strtoupper((string)($targetUser['tipo_conta'] ?? '')) === 'ADMIN') {
        header('Location: alunos_admin.php?ok=user_admin_blocked');
        exit;
      }

      if ($targetLogin === $login) {
        header('Location: alunos_admin.php?ok=user_self_blocked');
        exit;
      }

      $stmt = $conn->prepare("DELETE FROM users WHERE login = ?");
      $stmt->bind_param("s", $targetLogin);
      $stmt->execute();
      header('Location: alunos_admin.php?ok=user_deleted');
      exit;
    }

        $newStatus = $userAction === 'approve' ? 'APPROVED' : 'REJECTED';
        $stmt = $conn->prepare("UPDATE users SET approval_status = ?, approved_by = ?, approved_at = NOW() WHERE login = ?");
        $stmt->bind_param("sss", $newStatus, $login, $targetLogin);
        $stmt->execute();

        $mensagemConta = $newStatus === 'APPROVED'
          ? 'A tua conta foi aprovada pelos serviços académicos. Bem-vindo a plataforma do IPCA.'
          : 'O teu pedido de conta foi recusado pelos serviços académicos.';
        criar_notificacao_aluno($conn, $targetLogin, $mensagemConta);

        header('Location: alunos_admin.php?ok=user');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['perfil_action'], $_POST['login'])) {
  $targetLogin = trim((string)$_POST['login']);
  $perfilAction = trim((string)$_POST['perfil_action']);

  if (in_array($perfilAction, ['approve', 'reject'], true) && $targetLogin !== '') {
    $obsNome = trim((string)($_POST['obs_nome'] ?? ''));
    $obsTelefone = trim((string)($_POST['obs_telefone'] ?? ''));
    $obsMorada = trim((string)($_POST['obs_morada'] ?? ''));
    $obsFoto = trim((string)($_POST['obs_foto'] ?? ''));
    $obsRejeicao = trim((string)($_POST['obs_rejeicao'] ?? ''));

    if ($perfilAction === 'reject' && $obsRejeicao === '') {
      $erro = 'Ao recusar, deves preencher a observacao de recusa.';
    }

    if ($erro === null) {
      $stmt = $conn->prepare("SELECT nome, email, telefone, morada, foto_path FROM perfil_pedidos WHERE login = ? AND status = 'PENDING' LIMIT 1");
      $stmt->bind_param("s", $targetLogin);
      $stmt->execute();
      $pedido = $stmt->get_result()->fetch_assoc();

      if ($pedido) {
        try {
          $stmt = $conn->prepare("SELECT nome, telefone, morada, foto_path FROM alunos WHERE login = ? LIMIT 1");
          $stmt->bind_param("s", $targetLogin);
          $stmt->execute();
          $atual = $stmt->get_result()->fetch_assoc() ?: [];

          $nomeMudou = (string)($pedido['nome'] ?? '') !== (string)($atual['nome'] ?? '');
          $telefoneMudou = (string)($pedido['telefone'] ?? '') !== (string)($atual['telefone'] ?? '');
          $moradaMudou = (string)($pedido['morada'] ?? '') !== (string)($atual['morada'] ?? '');
          $fotoMudou = (string)($pedido['foto_path'] ?? '') !== (string)($atual['foto_path'] ?? '');

          $aprovarNome = isset($_POST['aprovar_nome']) && $nomeMudou;
          $aprovarTelefone = isset($_POST['aprovar_telefone']) && $telefoneMudou;
          $aprovarMorada = isset($_POST['aprovar_morada']) && $moradaMudou;
          $aprovarFoto = isset($_POST['aprovar_foto']) && $fotoMudou;

          if ($perfilAction === 'approve') {
            $haAlteracoes = $nomeMudou || $telefoneMudou || $moradaMudou || $fotoMudou;
            $haAprovacao = $aprovarNome || $aprovarTelefone || $aprovarMorada || $aprovarFoto;

            if ($haAlteracoes && !$haAprovacao) {
              $erro = 'Seleciona pelo menos um campo para aprovar.';
            } else {
              guardar_aluno_aprovado_parcial(
                $conn,
                $targetLogin,
                $pedido,
                $aprovarNome,
                $aprovarTelefone,
                $aprovarMorada,
                $aprovarFoto
              );
            }

            if ($erro !== null) {
              throw new RuntimeException($erro);
            }

            $newStatus = 'APPROVED';
            $obsRejeicao = null;

            $aprovouTodos =
              (!$nomeMudou || $aprovarNome) &&
              (!$telefoneMudou || $aprovarTelefone) &&
              (!$moradaMudou || $aprovarMorada) &&
              (!$fotoMudou || $aprovarFoto);
          } else {
            $newStatus = 'REJECTED';
            $aprovouTodos = false;
          }

          $stmt = $conn->prepare(
            "UPDATE perfil_pedidos
              SET status = ?, reviewed_by = ?, reviewed_at = NOW(),
                obs_nome = ?, obs_telefone = ?, obs_morada = ?, obs_foto = ?, obs_rejeicao = ?
              WHERE login = ?"
          );
          $stmt->bind_param("ssssssss", $newStatus, $login, $obsNome, $obsTelefone, $obsMorada, $obsFoto, $obsRejeicao, $targetLogin);
          $stmt->execute();

          if ($perfilAction === 'approve') {
            $mensagemPerfil = $aprovouTodos
              ? 'O teu pedido de alteração de perfil foi aprovado pelos serviços académicos.'
              : 'O teu pedido de alteração de perfil foi aprovado parcialmente pelos serviços académicos.';
          } else {
            $mensagemPerfil = 'O teu pedido de alteração de perfil foi recusado pelos serviços académicos.';
            if ($obsRejeicao) {
              $mensagemPerfil .= ' Motivo: ' . $obsRejeicao;
            }
          }

          criar_notificacao_aluno($conn, $targetLogin, $mensagemPerfil);

          if ($perfilAction === 'approve') {
            header('Location: alunos_admin.php?ok=' . ($aprovouTodos ? 'perfil' : 'perfil_parcial'));
          } else {
            header('Location: alunos_admin.php?ok=perfil_recusado');
          }
          exit;
        } catch (RuntimeException $e) {
          $erro = $e->getMessage();
        } catch (mysqli_sql_exception $e) {
          if ($e->getCode() === 1062) {
            $erro = 'Nao foi possivel aprovar o perfil porque o email ou telefone ja existe.';
          } else {
            throw $e;
          }
        }
      }
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['func_cursos_action'], $_POST['login'])) {
  $targetLogin = trim((string)$_POST['login']);
  $acaoCursos = trim((string)$_POST['func_cursos_action']);

  if ($acaoCursos === 'guardar' && $targetLogin !== '') {
    $stmt = $conn->prepare(
      "SELECT 1
         FROM users u
         JOIN grupos g ON g.ID = u.grupo
        WHERE u.login = ? AND g.GRUPO = 'FUNCIONARIO'
        LIMIT 1"
    );
    $stmt->bind_param('s', $targetLogin);
    $stmt->execute();

    if ($stmt->get_result()->num_rows === 0) {
      $erro = 'So e possivel associar cursos a utilizadores do tipo funcionario.';
    } else {
      $cursoIdsRaw = $_POST['curso_ids'] ?? [];
      if (!is_array($cursoIdsRaw)) {
        $cursoIdsRaw = [];
      }

      $cursoIds = [];
      foreach ($cursoIdsRaw as $cursoIdRaw) {
        $cid = (int)$cursoIdRaw;
        if ($cid > 0) {
          $cursoIds[$cid] = true;
        }
      }
      $cursoIds = array_keys($cursoIds);

      $conn->begin_transaction();
      try {
        $del = $conn->prepare("DELETE FROM funcionario_cursos WHERE funcionario_login = ?");
        $del->bind_param('s', $targetLogin);
        $del->execute();

        if (!empty($cursoIds)) {
          $validos = [];
          $cursosExistentes = $conn->query("SELECT ID FROM cursos");
          while ($ce = $cursosExistentes->fetch_assoc()) {
            $validos[(int)$ce['ID']] = true;
          }

          $ins = $conn->prepare(
            "INSERT INTO funcionario_cursos (funcionario_login, curso_id, assigned_by, assigned_at)
             VALUES (?, ?, ?, NOW())"
          );
          foreach ($cursoIds as $cid) {
            if (!isset($validos[$cid])) {
              continue;
            }
            $ins->bind_param('sis', $targetLogin, $cid, $login);
            $ins->execute();
          }
        }

        $conn->commit();
        header('Location: alunos_admin.php?ok=func_cursos');
        exit;
      } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
      }
    }
  }
}

// Handler para aprovar/rejeitar pedidos de cursos de funcionários
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['func_curso_pedido_action'], $_POST['pedido_id'])) {
  $pedidoId = (int)$_POST['pedido_id'];
  $acao = trim((string)$_POST['func_curso_pedido_action']);
  $motivo = trim((string)($_POST['motivo_rejeicao'] ?? ''));
  
  if (in_array($acao, ['aprovar', 'rejeitar'], true) && $pedidoId > 0) {
    $stmt = $conn->prepare(
      "SELECT id, funcionario_login, curso_id FROM funcionario_curso_pedidos WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $pedidoId);
    $stmt->execute();
    $pedido = $stmt->get_result()->fetch_assoc();
    
    if ($pedido) {
      $funcLogin = (string)$pedido['funcionario_login'];
      $cursoId = (int)$pedido['curso_id'];
      
      if ($acao === 'aprovar') {
        $conn->begin_transaction();
        try {
          // Adicionar curso à tabela funcionario_cursos
          $ins = $conn->prepare(
            "INSERT INTO funcionario_cursos (funcionario_login, curso_id, assigned_by, assigned_at)
             VALUES (?, ?, ?, NOW())"
          );
          $ins->bind_param('sis', $funcLogin, $cursoId, $login);
          $ins->execute();
          
          // Atualizar pedido para APPROVED
          $upd = $conn->prepare(
            "UPDATE funcionario_curso_pedidos SET status = 'APPROVED', revisado_por = ?, revisado_em = NOW()
             WHERE id = ?"
          );
          $upd->bind_param('si', $login, $pedidoId);
          $upd->execute();
          
          // Obter nome do curso para notificação
          $stmtCurso = $conn->prepare("SELECT Nome FROM cursos WHERE ID = ? LIMIT 1");
          $stmtCurso->bind_param('i', $cursoId);
          $stmtCurso->execute();
          $resCurso = $stmtCurso->get_result()->fetch_assoc();
          $nomeCurso = $resCurso['Nome'] ?? 'Desconhecido';
          
          // Notificar funcionário
          $mensagem = "Seu pedido para o curso " . htmlspecialchars($nomeCurso) . " foi aprovado.";
          criar_notificacao_aluno($conn, $funcLogin, $mensagem);
          
          $conn->commit();
          header('Location: alunos_admin.php?ok=func_curso_pedido_aprovado');
          exit;
        } catch (Throwable $e) {
          $conn->rollback();
          $erro = 'Erro ao aprovar pedido: ' . $e->getMessage();
        }
      } else {
        // Rejeitar pedido
        $upd = $conn->prepare(
          "UPDATE funcionario_curso_pedidos SET status = 'REJECTED', revisado_por = ?, revisado_em = NOW(), motivo_rejeicao = ?
           WHERE id = ?"
        );
        $upd->bind_param('ssi', $login, $motivo, $pedidoId);
        $upd->execute();
        header('Location: alunos_admin.php?ok=func_curso_pedido_recusado');
        exit;
      }
    }
  }
}

if (isset($_GET['ok']) && $ok === null) {
    if ($_GET['ok'] === 'user') {
        $ok = 'Estado da conta atualizado com sucesso.';
  } elseif ($_GET['ok'] === 'user_deleted') {
    $ok = 'Conta removida com sucesso.';
  } elseif ($_GET['ok'] === 'user_self_blocked') {
    $erro = 'Nao podes remover a tua propria conta.';
  } elseif ($_GET['ok'] === 'user_admin_blocked') {
    $erro = 'Nao e permitido remover contas de administradores.';
    } elseif ($_GET['ok'] === 'perfil') {
        $ok = 'Pedido de alteracao de perfil aprovado com sucesso.';
    } elseif ($_GET['ok'] === 'perfil_parcial') {
      $ok = 'Pedido aprovado parcialmente. Apenas os campos selecionados foram aplicados.';
    } elseif ($_GET['ok'] === 'perfil_recusado') {
        $ok = 'Pedido de alteracao de perfil recusado.';
    } elseif ($_GET['ok'] === 'func_cursos') {
      $ok = 'Cursos do funcionario atualizados com sucesso.';
    } elseif ($_GET['ok'] === 'func_curso_pedido_aprovado') {
      $ok = 'Pedido de acesso a curso aprovado com sucesso.';
    } elseif ($_GET['ok'] === 'func_curso_pedido_recusado') {
      $ok = 'Pedido de acesso a curso recusado.';
    }
}

$q = trim($_GET['q'] ?? '');
$openLogin = strtolower(trim((string)($_GET['open_login'] ?? '')));

$cursosDisponiveis = [];
$resCursos = $conn->query("SELECT ID, Nome FROM cursos ORDER BY Nome");
while ($curso = $resCursos->fetch_assoc()) {
  $cursosDisponiveis[] = $curso;
}

$funcionarioCursosMap = [];
$resFuncCursos = $conn->query("SELECT funcionario_login, curso_id FROM funcionario_cursos");
while ($fc = $resFuncCursos->fetch_assoc()) {
  $funcLogin = (string)$fc['funcionario_login'];
  $funcionarioCursosMap[$funcLogin][] = (int)$fc['curso_id'];
}

// Carregar pedidos de cursos pendentes
$pedidosCursosPendentes = [];
$stmtPed = $conn->prepare(
  "SELECT fcp.id, fcp.funcionario_login, fcp.curso_id, c.Nome as nome_curso, fcp.solicitado_em
   FROM funcionario_curso_pedidos fcp
   JOIN cursos c ON c.ID = fcp.curso_id
   WHERE fcp.status = 'PENDING'
   ORDER BY fcp.solicitado_em ASC"
);
$stmtPed->execute();
$pedidosCursosPendentes = $stmtPed->get_result()->fetch_all(MYSQLI_ASSOC);

$alunos = $conn->query(
    "SELECT
    u.login, u.approval_status, u.approved_by AS conta_approved_by, u.approved_at AS conta_approved_at, g.GRUPO AS tipo_conta,
    a.matricula,
    COALESCE(a.nome, ap.nome) AS nome,
    COALESCE(a.email, ap.email) AS email,
    COALESCE(a.telefone, ap.telefone) AS telefone,
    COALESCE(a.morada, ap.morada) AS morada,
    a.foto_path AS foto_atual,
    COALESCE(a.updated_at, ap.updated_at) AS updated_at,
    p.nome AS pedido_nome, p.telefone AS pedido_telefone, p.morada AS pedido_morada, p.foto_path AS pedido_foto,
  p.status AS perfil_status,
  COALESCE(p.reviewed_by, u.approved_by) AS perfil_reviewed_by,
  COALESCE(p.reviewed_at, u.approved_at) AS perfil_reviewed_at,
  COALESCE(p.requested_at, a.updated_at, ap.updated_at, u.approved_at) AS perfil_created_at,
    COALESCE(a.foto_path, pp.foto_path, ap.foto_path) AS foto_path
     FROM users u
     JOIN grupos g ON g.ID = u.grupo
     LEFT JOIN alunos a ON a.login = u.login
     LEFT JOIN admin_perfis ap ON ap.login = u.login
     LEFT JOIN perfil_pedidos p ON p.login = u.login
   LEFT JOIN perfil_pedidos pp ON pp.login = u.login AND pp.status = 'APPROVED'
    WHERE g.GRUPO IN ('ALUNO', 'FUNCIONARIO', 'GESTOR', 'ADMIN')
    ORDER BY FIELD(g.GRUPO, 'ALUNO', 'FUNCIONARIO', 'GESTOR', 'ADMIN'), COALESCE(a.nome, u.login), u.login"
);
?>
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Utilizadores</title>
  <style><?php include __DIR__ . '/assets/css/theme.css'; ?></style>
</head>
<body class='page-alunos-admin'>
  <div class="container">
    <div class="header">
      <div class="header-left">
        <a href="index.php" aria-label="Ir para o menu principal">
          <img class="logo" src="<?= htmlspecialchars(logo_asset_path()) ?>" alt="Logótipo da escola">
        </a>
        <h2>Utilizadores</h2>
      </div>
      <a href="index.php" class="back-btn">← Voltar</a>
    </div>

    <div class="main">
      <div class="card">
        <div class="form-section">
          <h3>Pesquisar Utilizadores</h3>
          <div class="form-row">
            <input id="search_utilizador" class="search-input-flex" type="search" placeholder="Pesquisar por login, matrícula ou nome…" value="<?= htmlspecialchars($q) ?>" oninput="filtrarUtilizadores()">
            <button type="button" class="clear-btn" onclick="document.getElementById('search_utilizador').value='';document.getElementById('filtro_curso').value='';filtrarUtilizadores();">Limpar</button>
          </div>
          <div class="curso-filter-wrap">
            <label for="filtro_curso" class="admin-filter-label">Filtrar por Curso (Funcionários)</label>
            <select id="filtro_curso" class="admin-filter-select" onchange="filtrarUtilizadores()">
              <option value="">Todos os cursos</option>
              <?php foreach ($cursosDisponiveis as $curso): ?>
                <option value="<?= (int)$curso['ID'] ?>"><?= htmlspecialchars((string)$curso['Nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div id="sem-utilizadores" class="empty-filter-message small">Nenhum utilizador corresponde à pesquisa.</div>
        </div>
      </div>

      <?php if (!empty($pedidosCursosPendentes)): ?>
      <div class="card">
        <div class="table-section">
          <h3>Pedidos de Acesso a Cursos (<?= count($pedidosCursosPendentes) ?>)</h3>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Funcionário</th>
                  <th>Curso</th>
                  <th>Solicitado em</th>
                  <th>Ações</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($pedidosCursosPendentes as $pedido): ?>
                <tr>
                  <td data-label="Funcionário"><?= htmlspecialchars($pedido['funcionario_login']) ?></td>
                  <td data-label="Curso"><?= htmlspecialchars($pedido['nome_curso']) ?></td>
                  <td data-label="Solicitado"><?= date('d/m/Y H:i', strtotime($pedido['solicitado_em'])) ?></td>
                  <td data-label="Ações">
                    <div class="action-group">
                      <form method="post" class="inline-form">
                        <input type="hidden" name="func_curso_pedido_action" value="aprovar">
                        <input type="hidden" name="pedido_id" value="<?= (int)$pedido['id'] ?>">
                        <button type="submit" class="action-link approve action-btn-reset">Aprovar</button>
                      </form>
                      <button type="button" class="action-link reject" onclick="showRejectForm(<?= (int)$pedido['id'] ?>)">Rejeitar</button>
                    </div>
                  </td>
                </tr>
                <tr id="reject-form-<?= (int)$pedido['id'] ?>" class="reject-row-hidden">
                  <td colspan="4" class="reject-cell-padding">
                    <form method="post" class="reject-form-grid">
                      <textarea name="motivo_rejeicao" class="reject-textarea" placeholder="Motivo da rejeição (opcional)"></textarea>
                      <div class="reject-actions-row">
                        <input type="hidden" name="func_curso_pedido_action" value="rejeitar">
                        <input type="hidden" name="pedido_id" value="<?= (int)$pedido['id'] ?>">
                        <button type="submit" class="action-link reject action-btn-reset grow-1">Confirmar Rejeição</button>
                        <button type="button" class="clear-btn grow-1" onclick="hideRejectForm(<?= (int)$pedido['id'] ?>)">Cancelar</button>
                      </div>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="table-section">
          <h3>Lista de Utilizadores</h3>
          <?php if ($erro): ?><div class="notice-err"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
          <?php if ($ok): ?><div class="notice-ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Login</th>
                  <th>Tipo</th>
                  <th>Conta</th>
                  <th>Pedido de Perfil</th>
                  <th>Ações Conta</th>
                  <th>Cursos (func.)</th>
                  <th>Ações Perfil</th>
                  <th>Dados</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($alunos->num_rows === 0): ?>
                  <tr>
                    <td colspan="8" class="empty-state">Sem utilizadores.</td>
                  </tr>
                <?php else: ?>
                  <?php $rowIdx = 0; $tipoAnterior = ''; while ($a = $alunos->fetch_assoc()): $rowIdx++; ?>
                    <?php $preenchido = !empty($a['matricula']) && !empty($a['nome']); ?>
                    <?php $contaStatus = strtoupper($a['approval_status'] ?? 'APPROVED'); ?>
                    <?php $perfilStatus = strtoupper($a['perfil_status'] ?? ''); ?>
                    <?php $tipoAtual = strtoupper((string)($a['tipo_conta'] ?? 'ALUNO')); ?>
                    <?php if ($tipoAtual !== $tipoAnterior): ?>
                      <?php $tipoAnterior = $tipoAtual; ?>
                      <tr class="utilizadores-group-row" data-group="<?= htmlspecialchars($tipoAtual) ?>" data-collapsed="1">
                        <td colspan="8" class="utilizadores-group-title">
                          <button
                            type="button"
                            class="category-dropdown-btn"
                            onclick="toggleCategoriaUtilizadores('<?= htmlspecialchars($tipoAtual) ?>', this)">
                            <span>
                              <?= match ($tipoAtual) {
                                'ADMIN' => 'Administradores',
                                'FUNCIONARIO' => 'Funcionários',
                                'GESTOR' => 'Gestores',
                                default => 'Alunos',
                              } ?>
                            </span>
                            <span class="category-chevron">▼</span>
                          </button>
                        </td>
                      </tr>
                    <?php endif; ?>
                    <?php $detailsId = 'detalhes_' . $rowIdx; ?>
                    <?php
                      $dataLogin = htmlspecialchars(strtolower((string)$a['login']));
                      $dataNome  = htmlspecialchars(strtolower(trim((string)($a['nome'] ?? ''))));
                      $dataMatricula = htmlspecialchars(strtolower(trim((string)($a['matricula'] ?? ''))));
                      $funcCursos = $tipoAtual === 'FUNCIONARIO' ? ($funcionarioCursosMap[(string)$a['login']] ?? []) : [];
                      $dataCursos = implode(',', array_map('intval', $funcCursos));
                    ?>
                    <tr class="utilizador-row" data-group="<?= htmlspecialchars($tipoAtual) ?>" data-login="<?= $dataLogin ?>" data-nome="<?= $dataNome ?>" data-matricula="<?= $dataMatricula ?>" data-cursos="<?= htmlspecialchars($dataCursos) ?>" data-details="<?= $detailsId ?>">
                      <td data-label="Login"><?= htmlspecialchars($a['login']) ?></td>
                      <td data-label="Tipo">
                        <?= htmlspecialchars(match ((string)$a['tipo_conta']) {
                          'ADMIN' => 'Administrador',
                          'FUNCIONARIO' => 'Funcionário',
                          'GESTOR' => 'Gestor',
                          default => 'Aluno',
                        }) ?>
                      </td>
                      <td data-label="Conta">
                        <?php if ($contaStatus === 'APPROVED'): ?>
                          <span class="pill complete"><?= htmlspecialchars(traduz_estado_aprovacao($contaStatus)) ?></span>
                          <div class="meta-detail-line">Aprovado por: <?= htmlspecialchars(nome_utilizador_por_login($conn, (string)($a['conta_approved_by'] ?? ''))) ?></div>
                        <?php elseif ($contaStatus === 'REJECTED'): ?>
                          <span class="pill rejected"><?= htmlspecialchars(traduz_estado_aprovacao($contaStatus)) ?></span>
                          <div class="meta-detail-line">Revisto por: <?= htmlspecialchars(nome_utilizador_por_login($conn, (string)($a['conta_approved_by'] ?? ''))) ?></div>
                        <?php else: ?>
                          <span class="pill pending"><?= htmlspecialchars(traduz_estado_aprovacao($contaStatus)) ?></span>
                        <?php endif; ?>
                      </td>
                      <td data-label="Pedido de Perfil">
                        <?php if ($perfilStatus === 'APPROVED'): ?>
                          <span class="pill complete"><?= htmlspecialchars(traduz_estado_aprovacao($perfilStatus)) ?></span>
                          <div class="meta-detail-line">Aprovado por: <?= htmlspecialchars(nome_utilizador_por_login($conn, (string)($a['perfil_reviewed_by'] ?? ''))) ?></div>
                        <?php elseif ($perfilStatus === 'REJECTED'): ?>
                          <span class="pill rejected"><?= htmlspecialchars(traduz_estado_aprovacao($perfilStatus)) ?></span>
                          <div class="meta-detail-line">Revisto por: <?= htmlspecialchars(nome_utilizador_por_login($conn, (string)($a['perfil_reviewed_by'] ?? ''))) ?></div>
                        <?php elseif ($perfilStatus === 'PENDING'): ?>
                          <span class="pill pending"><?= htmlspecialchars(traduz_estado_aprovacao($perfilStatus)) ?></span>
                        <?php else: ?>
                          <span class="muted">Sem pedido</span>
                        <?php endif; ?>
                      </td>
                      <td data-label="Ações Conta">
                        <div class="action-group">
                          <?php if ($contaStatus === 'PENDING' || $contaStatus === 'REJECTED'): ?>
                            <a class="action-link approve" href="?user_action=approve&login=<?= urlencode($a['login']) ?>">Aprovar</a>
                          <?php endif; ?>
                          <?php if ($contaStatus === 'PENDING'): ?>
                            <a class="action-link reject" href="?user_action=reject&login=<?= urlencode($a['login']) ?>">Recusar</a>
                          <?php endif; ?>
                          <?php if ($contaStatus === 'APPROVED' && $tipoAtual !== 'ADMIN'): ?>
                            <a class="action-link remove" href="?user_action=delete&login=<?= urlencode($a['login']) ?>" onclick="return confirm('Tens a certeza que queres remover esta conta?')">Remover conta</a>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td data-label="Cursos (func.)">
                        <?php if ($tipoAtual === 'FUNCIONARIO'): ?>
                          <?php $funcCursos = $funcionarioCursosMap[(string)$a['login']] ?? []; ?>
                          <?php $funcCursosSet = array_flip(array_map('intval', $funcCursos)); ?>
                          <?php
                            $funcCursosTotal = count($funcCursos);
                          ?>
                          <div class="func-cursos-cell">
                            <div class="func-cursos-summary">
                              <?php if ($funcCursosTotal === 0): ?>
                                <span class="muted">Nenhum curso associado.</span>
                              <?php else: ?>
                                <span class="meta-small"><strong><?= (int)$funcCursosTotal ?></strong> curso(s) associado(s)</span>
                              <?php endif; ?>
                            </div>

                            <details class="func-cursos-dropdown">
                              <summary>Gerir cursos</summary>
                              <div class="func-cursos-backdrop" onclick="this.closest('details').removeAttribute('open')"></div>
                              <form method="post" class="func-cursos-form">
                                <input type="hidden" name="func_cursos_action" value="guardar">
                                <input type="hidden" name="login" value="<?= htmlspecialchars((string)$a['login']) ?>">

                                <div class="func-cursos-form-topbar">
                                  <div class="func-cursos-title">Cursos de <?= htmlspecialchars((string)$a['login']) ?></div>
                                  <button type="button" class="func-cursos-close" onclick="this.closest('details').removeAttribute('open')">Fechar</button>
                                </div>

                                <div class="func-cursos-grid">
                                  <?php foreach ($cursosDisponiveis as $cursoOpt): ?>
                                    <?php $cid = (int)$cursoOpt['ID']; ?>
                                    <label class="func-curso-option">
                                      <input type="checkbox" name="curso_ids[]" value="<?= $cid ?>" <?= isset($funcCursosSet[$cid]) ? 'checked' : '' ?>>
                                      <span><?= htmlspecialchars((string)$cursoOpt['Nome']) ?></span>
                                    </label>
                                  <?php endforeach; ?>
                                </div>

                                <div class="func-cursos-actions">
                                  <button type="submit" class="action-link approve action-btn-reset">Guardar cursos</button>
                                </div>
                              </form>
                            </details>
                          </div>
                        <?php else: ?>
                          <span class="muted">N/A</span>
                        <?php endif; ?>
                      </td>
                      <td data-label="Ações Perfil">
                        <div class="action-group">
                          <?php if ($perfilStatus === 'PENDING'): ?>
                            <?php
                              $nomeAtual = (string)($a['nome'] ?? '');
                              $telAtual = (string)($a['telefone'] ?? '');
                              $moradaAtual = (string)($a['morada'] ?? '');
                              $fotoAtual = (string)($a['foto_atual'] ?? '');

                              $nomeNovo = (string)($a['pedido_nome'] ?? '');
                              $telNovo = (string)($a['pedido_telefone'] ?? '');
                              $moradaNova = (string)($a['pedido_morada'] ?? '');
                              $fotoNova = (string)($a['pedido_foto'] ?? '');

                              $nomeMudou = $nomeNovo !== $nomeAtual;
                              $telMudou = $telNovo !== $telAtual;
                              $moradaMudou = $moradaNova !== $moradaAtual;
                              $fotoMudou = $fotoNova !== $fotoAtual;
                            ?>
                            <details class="perfil-review-dropdown">
                              <summary>Revisao de alteracoes</summary>
                              <div class="perfil-review-backdrop" onclick="this.closest('details').removeAttribute('open')"></div>
                              <form method="post" class="perfil-review-form">
                                <input type="hidden" name="login" value="<?= htmlspecialchars($a['login']) ?>">
                                <div class="perfil-review-topbar">
                                  <div class="perfil-review-title">Comparacao por Campo</div>
                                  <button type="button" class="perfil-review-close" onclick="this.closest('details').removeAttribute('open')">Fechar</button>
                                </div>
                                <div class="perfil-compare-list">
                                  <div class="perfil-compare-row">
                                    <div class="perfil-compare-row-head">
                                      <span class="perfil-compare-name">Nome</span>
                                      <?php if ($nomeMudou): ?>
                                        <label class="perfil-approve-check"><input type="checkbox" name="aprovar_nome" value="1" checked> Aprovar</label>
                                      <?php else: ?>
                                        <span class="muted">Sem alteracao</span>
                                      <?php endif; ?>
                                    </div>
                                    <div class="perfil-compare-pair">
                                      <div>
                                        <div class="perfil-compare-label">Anterior</div>
                                        <div class="perfil-compare-value"><?= htmlspecialchars($nomeAtual !== '' ? $nomeAtual : '-') ?></div>
                                      </div>
                                      <div>
                                        <div class="perfil-compare-label">Alteracao</div>
                                        <div class="perfil-compare-value <?= $nomeMudou ? 'changed' : 'same' ?>"><?= htmlspecialchars($nomeNovo !== '' ? $nomeNovo : '-') ?></div>
                                      </div>
                                    </div>
                                  </div>

                                  <div class="perfil-compare-row">
                                    <div class="perfil-compare-row-head">
                                      <span class="perfil-compare-name">Telefone</span>
                                      <?php if ($telMudou): ?>
                                        <label class="perfil-approve-check"><input type="checkbox" name="aprovar_telefone" value="1" checked> Aprovar</label>
                                      <?php else: ?>
                                        <span class="muted">Sem alteracao</span>
                                      <?php endif; ?>
                                    </div>
                                    <div class="perfil-compare-pair">
                                      <div>
                                        <div class="perfil-compare-label">Anterior</div>
                                        <div class="perfil-compare-value"><?= htmlspecialchars($telAtual !== '' ? $telAtual : '-') ?></div>
                                      </div>
                                      <div>
                                        <div class="perfil-compare-label">Alteracao</div>
                                        <div class="perfil-compare-value <?= $telMudou ? 'changed' : 'same' ?>"><?= htmlspecialchars($telNovo !== '' ? $telNovo : '-') ?></div>
                                      </div>
                                    </div>
                                  </div>

                                  <div class="perfil-compare-row">
                                    <div class="perfil-compare-row-head">
                                      <span class="perfil-compare-name">Morada</span>
                                      <?php if ($moradaMudou): ?>
                                        <label class="perfil-approve-check"><input type="checkbox" name="aprovar_morada" value="1" checked> Aprovar</label>
                                      <?php else: ?>
                                        <span class="muted">Sem alteracao</span>
                                      <?php endif; ?>
                                    </div>
                                    <div class="perfil-compare-pair">
                                      <div>
                                        <div class="perfil-compare-label">Anterior</div>
                                        <div class="perfil-compare-value"><?= htmlspecialchars($moradaAtual !== '' ? $moradaAtual : '-') ?></div>
                                      </div>
                                      <div>
                                        <div class="perfil-compare-label">Alteracao</div>
                                        <div class="perfil-compare-value <?= $moradaMudou ? 'changed' : 'same' ?>"><?= htmlspecialchars($moradaNova !== '' ? $moradaNova : '-') ?></div>
                                      </div>
                                    </div>
                                  </div>

                                  <div class="perfil-compare-row">
                                    <div class="perfil-compare-row-head">
                                      <span class="perfil-compare-name">Fotografia</span>
                                      <?php if ($fotoMudou): ?>
                                        <label class="perfil-approve-check"><input type="checkbox" name="aprovar_foto" value="1" checked> Aprovar</label>
                                      <?php else: ?>
                                        <span class="muted">Sem alteracao</span>
                                      <?php endif; ?>
                                    </div>
                                    <div class="perfil-compare-pair">
                                      <div>
                                        <div class="perfil-compare-label">Anterior</div>
                                        <div class="perfil-compare-photo-wrap">
                                          <?php if ($fotoAtual !== ''): ?>
                                            <img class="detail-profile-photo" src="<?= htmlspecialchars($fotoAtual) ?>" alt="Foto atual">
                                          <?php else: ?>
                                            <span class="muted">Sem foto</span>
                                          <?php endif; ?>
                                        </div>
                                      </div>
                                      <div>
                                        <div class="perfil-compare-label">Alteracao</div>
                                        <div class="perfil-compare-photo-wrap <?= $fotoMudou ? 'changed' : 'same' ?>">
                                          <?php if ($fotoNova !== ''): ?>
                                            <img class="detail-profile-photo" src="<?= htmlspecialchars($fotoNova) ?>" alt="Foto nova">
                                          <?php else: ?>
                                            <span class="muted">Sem foto</span>
                                          <?php endif; ?>
                                        </div>
                                      </div>
                                    </div>
                                  </div>
                                </div>

                                <label>Obs. Nome</label>
                                <textarea name="obs_nome" rows="2" placeholder="Opcional"></textarea>

                                <label>Obs. Telefone</label>
                                <textarea name="obs_telefone" rows="2" placeholder="Opcional"></textarea>

                                <label>Obs. Morada</label>
                                <textarea name="obs_morada" rows="2" placeholder="Opcional"></textarea>

                                <label>Obs. Fotografia</label>
                                <textarea name="obs_foto" rows="2" placeholder="Opcional"></textarea>

                                <label>Motivo da recusa</label>
                                <textarea name="obs_rejeicao" rows="2" placeholder="Obrigatorio se recusar"></textarea>

                                <div class="action-group perfil-review-actions">
                                  <button class="action-link approve" type="submit" name="perfil_action" value="approve">Aprovar</button>
                                  <button class="action-link reject" type="submit" name="perfil_action" value="reject">Recusar</button>
                                </div>
                              </form>
                            </details>
                          <?php else: ?>
                            <span class="muted">Sem ações</span>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td data-label="Dados">
                        <button type="button" class="toggle-btn" onclick="toggleDetails('<?= $detailsId ?>', this)">Ver dados</button>
                      </td>
                    </tr>
                    <tr id="<?= $detailsId ?>" class="details-row" data-group="<?= htmlspecialchars($tipoAtual) ?>">
                      <td colspan="8" class="details-cell">
                        <div class="details-grid">
                          <?php if (!empty($a['foto_path'])): ?>
                            <div class="detail-item detail-photo-wrap full-span">
                              <img class="detail-profile-photo" src="<?= htmlspecialchars($a['foto_path']) ?>" alt="Fotografia de perfil">
                            </div>
                          <?php endif; ?>
                          <div class="detail-item"><strong>Login:</strong> <?= htmlspecialchars($a['login'] ?? '-') ?></div>
                          <div class="detail-item"><strong>Nome:</strong> <?= htmlspecialchars($a['nome'] ?? '-') ?></div>
                          <div class="detail-item"><strong>Email:</strong> <?= htmlspecialchars($a['email'] ?? '-') ?></div>
                          <div class="detail-item"><strong>Telefone:</strong> <?= htmlspecialchars($a['telefone'] ?? '-') ?></div>
                          <div class="detail-item"><strong>Morada:</strong> <?= htmlspecialchars($a['morada'] ?? '-') ?></div>
                          <?php if ($tipoAtual !== 'ADMIN'): ?>
                          <div class="detail-item"><strong>Data de criação do perfil:</strong> <?= htmlspecialchars($a['perfil_created_at'] ?? '-') ?></div>
                          <div class="detail-item"><strong>Estado Perfil:</strong> <?= $preenchido ? 'Perfil completo' : 'Por preencher' ?></div>
                          <div class="detail-item"><strong>Perfil aprovado por:</strong> <?= htmlspecialchars(nome_utilizador_por_login($conn, (string)($a['perfil_reviewed_by'] ?? ''))) ?></div>
                          <div class="detail-item"><strong>Perfil aprovado em:</strong> <?= htmlspecialchars($a['perfil_reviewed_at'] ?? '-') ?></div>
                          <?php endif; ?>
                          <div class="detail-item"><strong>Atualizado:</strong> <?= htmlspecialchars($a['updated_at'] ?? '-') ?></div>
                        </div>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="sidebar">
      <div class="card">
        <div class="user-info">
          <?php if (!empty($perfilAdminSidebar['foto_path'])): ?>
            <img class="profile-photo" src="<?= htmlspecialchars((string)$perfilAdminSidebar['foto_path']) ?>" alt="Fotografia de perfil">
          <?php endif; ?>
          <?php if (!empty($perfilAdminSidebar['nome'])): ?><strong>Nome:</strong> <?= htmlspecialchars((string)$perfilAdminSidebar['nome']) ?><br><?php endif; ?>
          <?php if (!empty($perfilAdminSidebar['email'])): ?><strong>Email:</strong> <?= htmlspecialchars((string)$perfilAdminSidebar['email']) ?><br><?php endif; ?>
          <?php if (!empty($perfilAdminSidebar['telefone'])): ?><strong>Telefone:</strong> <?= htmlspecialchars((string)$perfilAdminSidebar['telefone']) ?><br><?php endif; ?>
          <?php if (!empty($perfilAdminSidebar['morada'])): ?><strong>Morada:</strong> <?= htmlspecialchars((string)$perfilAdminSidebar['morada']) ?><br><?php endif; ?>
          <strong>Tipo de utilizador:</strong> <?= htmlspecialchars($tipoUtilizador) ?><br>
          <strong>Utilizador:</strong> <?= htmlspecialchars($_SESSION['user']['login']) ?>
        </div>
      </div>
    </div>
  </div>

  <script>
    const openLoginDireto = <?= json_encode($openLogin, JSON_UNESCAPED_UNICODE) ?>;

    function filtrarUtilizadores() {
      const term = document.getElementById('search_utilizador').value.toLowerCase().trim();
      const cursFilter = document.getElementById('filtro_curso').value.trim();
      const rows = document.querySelectorAll('tr.utilizador-row');
      const groupRows = document.querySelectorAll('tr.utilizadores-group-row');
      const visiveisPorGrupo = { ALUNO: 0, FUNCIONARIO: 0, GESTOR: 0, ADMIN: 0 };
      const collapsedPorGrupo = { ALUNO: false, FUNCIONARIO: false, GESTOR: false, ADMIN: false };

      groupRows.forEach(grpRow => {
        const grp = (grpRow.dataset.group || '').toUpperCase();
        collapsedPorGrupo[grp] = grpRow.dataset.collapsed === '1';
      });

      let visiveis = 0;
      rows.forEach(row => {
        const grp = (row.dataset.group || '').toUpperCase();
        const matchText = !term ||
          (row.dataset.login || '').includes(term) ||
          (row.dataset.nome || '').includes(term) ||
          (row.dataset.matricula || '').includes(term);
        
        let matchCurso = true;
        if (cursFilter !== '') {
          const cursosStr = (row.dataset.cursos || '').trim();
          const cursos = cursosStr === '' ? [] : cursosStr.split(',').map(c => c.trim()).filter(c => c !== '');
          matchCurso = cursos.includes(cursFilter);
        }
        
        const mostrar = matchText && matchCurso && !collapsedPorGrupo[grp];
        row.style.display = mostrar ? 'table-row' : 'none';
        const detailsRow = document.getElementById(row.dataset.details);
        if (detailsRow) detailsRow.style.display = mostrar && detailsRow.classList.contains('open') ? 'table-row' : 'none';
        if (matchText && matchCurso) {
          visiveis++;
          if (Object.prototype.hasOwnProperty.call(visiveisPorGrupo, grp)) {
            visiveisPorGrupo[grp]++;
          }
        }
      });

      groupRows.forEach(grpRow => {
        const grp = (grpRow.dataset.group || '').toUpperCase();
        grpRow.style.display = (visiveisPorGrupo[grp] ?? 0) > 0 ? '' : 'none';
      });

      document.getElementById('sem-utilizadores').style.display = visiveis === 0 ? 'block' : 'none';
    }

    function toggleCategoriaUtilizadores(grupo, button) {
      const grpRow = document.querySelector('tr.utilizadores-group-row[data-group="' + grupo + '"]');
      if (!grpRow) return;
      const estaFechado = grpRow.dataset.collapsed === '1';
      grpRow.dataset.collapsed = estaFechado ? '0' : '1';
      button.classList.toggle('open', estaFechado);
      filtrarUtilizadores();
    }

    function initFiltragem() {
      filtrarUtilizadores();
      abrirDadosUtilizadorDireto();
    }

    function abrirDadosUtilizadorDireto() {
      if (!openLoginDireto) {
        return;
      }

      const rows = Array.from(document.querySelectorAll('tr.utilizador-row'));
      const targetRow = rows.find(row => (row.dataset.login || '').trim() === openLoginDireto);
      if (!targetRow) {
        return;
      }

      const grupo = (targetRow.dataset.group || '').toUpperCase();
      const grpRow = document.querySelector('tr.utilizadores-group-row[data-group="' + grupo + '"]');
      if (grpRow && grpRow.dataset.collapsed === '1') {
        grpRow.dataset.collapsed = '0';
        const btn = grpRow.querySelector('.category-dropdown-btn');
        if (btn) {
          btn.classList.add('open');
        }
      }

      filtrarUtilizadores();

      const detailsRow = document.getElementById(targetRow.dataset.details);
      const toggleBtn = targetRow.querySelector('button.toggle-btn');
      if (detailsRow) {
        detailsRow.classList.add('open');
        detailsRow.style.display = 'table-row';
      }
      if (toggleBtn) {
        toggleBtn.textContent = 'Ocultar dados';
      }

      targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initFiltragem);
    } else {
      initFiltragem();
    }

    function toggleDetails(rowId, button) {
      const row = document.getElementById(rowId);
      if (!row) return;
      const isOpen = row.classList.contains('open');
      row.classList.toggle('open', !isOpen);
      row.style.display = isOpen ? 'none' : 'table-row';
      button.textContent = isOpen ? 'Ver dados' : 'Ocultar dados';
    }

    function showRejectForm(pedidoId) {
      const form = document.getElementById('reject-form-' + pedidoId);
      if (form) form.style.display = 'table-row';
    }

    function hideRejectForm(pedidoId) {
      const form = document.getElementById('reject-form-' + pedidoId);
      if (form) form.style.display = 'none';
    }
  </script>
  <?php render_auto_logout_on_close_script(); ?>
</body>
</html>




