<?php
require_once 'config.php';
require_group(['ALUNO','ADMIN']);

// Garantir que a tabela `matriculas` suporta workflow de aprovação.
// Se a coluna `status` não existir, adiciona com DEFAULT 'PENDING'.
$col = $conn->query("SHOW COLUMNS FROM matriculas LIKE 'status'")->fetch_assoc();
if (!$col) {
    $conn->query(
        "ALTER TABLE matriculas
     ADD COLUMN status ENUM('PENDING','APPROVED','REJECTED','CANCEL_PENDING','CANCELLED','CANCEL_REJECTED') NOT NULL DEFAULT 'PENDING',
         ADD COLUMN approved_by VARCHAR(20) DEFAULT NULL,
         ADD COLUMN approved_at DATETIME DEFAULT NULL"
    );
} else {
  $statusType = strtolower((string)($col['Type'] ?? ''));
  if (
    strpos($statusType, "'cancel_pending'") === false ||
    strpos($statusType, "'cancelled'") === false ||
    strpos($statusType, "'cancel_rejected'") === false
  ) {
    $conn->query(
      "ALTER TABLE matriculas
       MODIFY COLUMN status ENUM('PENDING','APPROVED','REJECTED','CANCEL_PENDING','CANCELLED','CANCEL_REJECTED') NOT NULL DEFAULT 'PENDING'"
    );
  }
}

$login = $_SESSION['user']['login'];
$erro = null;

$perfil = null;
$grupo = $_SESSION['user']['grupo_nome'] ?? '';
if ($grupo === 'ALUNO') {
  $stmt = $conn->prepare("SELECT nome, email, telefone, morada, foto_path FROM alunos WHERE login = ? LIMIT 1");
  $stmt->bind_param("s", $login);
  $stmt->execute();
  $perfil = $stmt->get_result()->fetch_assoc();

  if (empty($perfil['foto_path'])) {
    $stmt = $conn->prepare("SELECT foto_path FROM perfil_pedidos WHERE login = ? AND status = 'APPROVED' LIMIT 1");
    $stmt->bind_param("s", $login);
    $stmt->execute();
    $perfilFotoAprovada = $stmt->get_result()->fetch_assoc();

    if (!empty($perfilFotoAprovada['foto_path'])) {
      $perfil['foto_path'] = $perfilFotoAprovada['foto_path'];
    }
  }
}

// adicionar matrícula (ficará pendente de aprovação)
if (isset($_POST['add_matricula'])) {
    $curso_id = (int)($_POST['curso_id'] ?? 0);
    if ($curso_id > 0) {
    $stmt = $conn->prepare("SELECT COALESCE(status, 'APPROVED') AS status FROM matriculas WHERE login = ? AND curso_id = ? LIMIT 1");
    $stmt->bind_param("si", $login, $curso_id);
    $stmt->execute();

    $existente = $stmt->get_result()->fetch_assoc();
    if ($existente) {
      $statusAtual = strtoupper((string)($existente['status'] ?? 'APPROVED'));

      if (in_array($statusAtual, ['REJECTED', 'CANCELLED'], true)) {
        $stmt = $conn->prepare(
          "UPDATE matriculas
           SET status = 'PENDING', approved_by = NULL, approved_at = NULL, observacao = NULL, data_matricula = NOW()
           WHERE login = ? AND curso_id = ?"
        );
        $stmt->bind_param("si", $login, $curso_id);
        $stmt->execute();
        header("Location: matriculas.php");
        exit;
      }

      header("Location: matriculas.php?error=already_registered");
      exit;
    }

    $stmt = $conn->prepare("INSERT INTO matriculas (login, curso_id, status) VALUES (?, ?, 'PENDING')");
    $stmt->bind_param("si", $login, $curso_id);
    $stmt->execute();
    }
    header("Location: matriculas.php");
    exit;
}

  // aluno pede cancelamento da matrícula, sujeito a aprovação do admin
  if ($grupo === 'ALUNO' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_cancel_matricula'])) {
    $curso_id = (int)($_POST['curso_id'] ?? 0);

    if ($curso_id > 0) {
      $stmt = $conn->prepare(
        "SELECT COALESCE(status, 'APPROVED') AS status
           FROM matriculas
          WHERE login = ? AND curso_id = ?
          LIMIT 1"
      );
      $stmt->bind_param("si", $login, $curso_id);
      $stmt->execute();
      $matriculaAtual = $stmt->get_result()->fetch_assoc();

      if ($matriculaAtual) {
        $statusAtual = strtoupper((string)($matriculaAtual['status'] ?? 'APPROVED'));
        if (in_array($statusAtual, ['APPROVED', 'CANCEL_REJECTED'], true)) {
          $stmt = $conn->prepare(
            "UPDATE matriculas
              SET status = 'CANCEL_PENDING', approved_by = NULL, approved_at = NULL, observacao = NULL
              WHERE login = ? AND curso_id = ?"
          );
          $stmt->bind_param("si", $login, $curso_id);
          $stmt->execute();
        }
      }
    }

    header("Location: matriculas.php");
    exit;
  }

// aprovar/rejeitar matrícula (apenas ADMIN)
if ($grupo === 'ADMIN' && isset($_GET['action'], $_GET['login'], $_GET['curso_id'])) {
    $action = $_GET['action'];
    $other_login = $_GET['login'];
    $curso_id = (int)$_GET['curso_id'];

    if (in_array($action, ['approve', 'reject', 'restore'], true)) {
      $stmt = $conn->prepare(
        "SELECT COALESCE(status, 'APPROVED') AS status
           FROM matriculas
          WHERE login = ? AND curso_id = ?
          LIMIT 1"
      );
      $stmt->bind_param("si", $other_login, $curso_id);
      $stmt->execute();
      $matriculaAtual = $stmt->get_result()->fetch_assoc();

      if ($matriculaAtual) {
        $statusAtual = strtoupper((string)($matriculaAtual['status'] ?? 'APPROVED'));
        $newStatus = null;
        if ($action === 'restore' && $statusAtual === 'CANCEL_REJECTED') {
          $newStatus = 'APPROVED';
        } elseif ($statusAtual === 'CANCEL_PENDING') {
          $newStatus = $action === 'approve' ? 'CANCELLED' : 'CANCEL_REJECTED';
        } elseif ($action !== 'restore') {
          $newStatus = $action === 'approve' ? 'APPROVED' : 'REJECTED';
        }

        if ($newStatus !== null) {
          $stmt = $conn->prepare(
            "UPDATE matriculas
             SET status = ?, approved_by = ?, approved_at = NOW(), observacao = NULL
             WHERE login = ? AND curso_id = ?"
          );
          $stmt->bind_param("sssi", $newStatus, $login, $other_login, $curso_id);
          $stmt->execute();
        }
      }
    }

    header("Location: matriculas.php");
    exit;
}

// remover matrícula diretamente apenas pelo admin
if ($grupo === 'ADMIN' && isset($_GET['del_curso'])) {
    $curso_id = (int)$_GET['del_curso'];
    $target_login = $login;

    // Admin pode remover matrícula de outro aluno usando ?login=X
    if ($grupo === 'ADMIN' && isset($_GET['login'])) {
        $target_login = $_GET['login'];
    }

    $stmt = $conn->prepare("DELETE FROM matriculas WHERE login = ? AND curso_id = ?");
    $stmt->bind_param("si", $target_login, $curso_id);
    $stmt->execute();
    header("Location: matriculas.php");
    exit;
}

$cursos = $conn->query("SELECT ID, Nome FROM cursos ORDER BY Nome");

$cursosRegistados = [];
if ($grupo === 'ALUNO') {
  $stmt = $conn->prepare("SELECT curso_id FROM matriculas WHERE login = ? AND COALESCE(status, 'APPROVED') NOT IN ('REJECTED', 'CANCELLED')");
  $stmt->bind_param("s", $login);
  $stmt->execute();
  $resRegistados = $stmt->get_result();
  while ($rReg = $resRegistados->fetch_assoc()) {
    $cursosRegistados[(int)$rReg['curso_id']] = true;
  }

  if (isset($_GET['error']) && $_GET['error'] === 'already_registered') {
    $erro = 'Já tens uma matrícula ou pedido para esse curso.';
  }
}

if ($grupo === 'ADMIN') {
    // Admin vê todas as matrículas, agrupadas por estado
    $stmt = $conn->prepare(
        "SELECT m.login, c.ID, c.Nome, m.data_matricula, m.status, m.approved_by, m.approved_at
         FROM matriculas m
         JOIN cursos c ON c.ID = m.curso_id
         ORDER BY m.status, c.Nome"
    );
    $stmt->execute();
    $meus = $stmt->get_result();
} else {
    $stmt = $conn->prepare(
      "SELECT c.ID, c.Nome, m.data_matricula, COALESCE(m.status, 'APPROVED') AS status, m.approved_by, m.approved_at, m.observacao
         FROM matriculas m
         JOIN cursos c ON c.ID = m.curso_id
         WHERE m.login = ?
         ORDER BY c.Nome"
    );
    $stmt->bind_param("s", $login);
    $stmt->execute();
    $meus = $stmt->get_result();
}

  function traduz_status_matricula(string $status): string {
    return match (strtoupper($status)) {
      'APPROVED' => 'Aprovada',
      'REJECTED' => 'Pedido rejeitado',
      'CANCEL_PENDING' => 'Cancelamento pendente',
      'CANCELLED' => 'Cancelada',
      'CANCEL_REJECTED' => 'Cancelamento rejeitado',
      default => 'Pendente',
    };
  }
?>
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>As minhas matrículas</title>
  <style><?php include __DIR__ . '/assets/css/theme.css'; ?></style>
</head>
<body class='page-matriculas'>
  <div class="container">
    <div class="header">
      <div class="header-left">
        <a href="index.php" aria-label="Ir para o menu principal">
          <img class="logo" src="<?= htmlspecialchars(logo_asset_path()) ?>" alt="Logótipo da escola">
        </a>
        <h2>As minhas matrículas</h2>
      </div>
      <a href="index.php" class="back-btn">← Voltar</a>
    </div>

    <div class="main">
      <div class="card">
        <div class="form-section">
          <h3>Adicionar Matrícula</h3>
          <?php if ($erro): ?><div class="err"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
          <form method="post">
            <div class="form-row">
              <select name="curso_id" required>
                <option value="">Selecionar curso…</option>
                <?php while($c = $cursos->fetch_assoc()): ?>
                  <?php if ($grupo !== 'ALUNO' || empty($cursosRegistados[(int)$c['ID']])): ?>
                    <option value="<?= (int)$c['ID'] ?>"><?= htmlspecialchars($c['Nome']) ?></option>
                  <?php endif; ?>
                <?php endwhile; ?>
              </select>
              <button type="submit" name="add_matricula">Matricular</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="table-section">
          <h3>Inscrito em</h3>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Curso</th>
                  <?php if ($grupo === 'ADMIN'): ?>
                    <th>Aluno</th>
                    <th>Data de Matrícula</th>
                    <th>Estado</th>
                    <th>Aprovado por</th>
                    <th>Data de Aprovação</th>
                    <th>Ações</th>
                  <?php else: ?>
                    <th>Data de Matrícula</th>
                    <th>Estado</th>
                    <th>Ações</th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php 
                $count = 0;
                while($r = $meus->fetch_assoc()): 
                  $count++;
                  $status = strtoupper($r['status'] ?? 'APPROVED');
                  $decididoPorLogin = trim((string)($r['approved_by'] ?? ''));
                  $decididoPorNome = $decididoPorLogin !== '' ? nome_utilizador_por_login($conn, $decididoPorLogin) : '-';
                  $statusClass = match($status) {
                    'APPROVED' => 'approved',
                    'CANCEL_PENDING' => 'pending',
                    'REJECTED' => 'rejected',
                    'CANCELLED' => 'rejected',
                    'CANCEL_REJECTED' => 'rejected',
                    default => 'pending',
                  };
                ?>
                  <tr>
                    <td data-label="Curso"><?= htmlspecialchars($r['Nome']) ?></td>
                    <?php if ($grupo === 'ADMIN'): ?>
                      <td data-label="Aluno"><?= htmlspecialchars($r['login']) ?></td>
                      <td data-label="Data de Matrícula"><?= htmlspecialchars($r['data_matricula'] ?? '-') ?></td>
                      <td data-label="Estado">
                        <span class="status-pill <?= $statusClass ?>\"><?= htmlspecialchars(traduz_status_matricula($status)) ?></span>
                      </td>
                      <td data-label="Aprovado por (utilizador)">
                        <?php if (!empty($r['approved_by']) && $r['approved_by'] !== '-'): ?>
                          <a href="alunos_admin.php?q=<?= urlencode($r['approved_by']) ?>&open_login=<?= urlencode($r['approved_by']) ?>" class="submitted-by-link" style="display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:999px;border:1px solid #bfdbfe;background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 100%);color:#1e3a8a;font-size:12px;font-weight:700;text-decoration:none;box-shadow:0 4px 10px rgba(30,58,138,0.12);transition:transform 0.18s,box-shadow 0.18s,background 0.18s,color 0.18s,border-color 0.18s;">
                            <?= htmlspecialchars($decididoPorNome) ?>
                          </a>
                        <?php else: ?>
                          -
                        <?php endif; ?>
                      </td>
                      <td data-label="Data de Aprovação">
                        <?= !empty($r['approved_at']) ? htmlspecialchars((string)$r['approved_at']) : '-' ?>
                      </td>
                      <td data-label="Ações">
                        <?php if ($status === 'PENDING'): ?>
                          <a class="action-btn action-btn-approve" href="?action=approve&login=<?= urlencode($r['login']) ?>&curso_id=<?= (int)$r['ID'] ?>">Aprovar</a>
                          <a class="action-btn action-btn-reject" href="?action=reject&login=<?= urlencode($r['login']) ?>&curso_id=<?= (int)$r['ID'] ?>">Rejeitar</a>
                        <?php elseif ($status === 'CANCEL_PENDING'): ?>
                          <a class="action-btn action-btn-approve" href="?action=approve&login=<?= urlencode($r['login']) ?>&curso_id=<?= (int)$r['ID'] ?>">Aprovar cancelamento</a>
                          <a class="action-btn action-btn-remove" href="?action=reject&login=<?= urlencode($r['login']) ?>&curso_id=<?= (int)$r['ID'] ?>">Recusar cancelamento</a>
                        <?php elseif ($status === 'CANCEL_REJECTED'): ?>
                          <a class="action-btn action-btn-approve" href="?action=restore&login=<?= urlencode($r['login']) ?>&curso_id=<?= (int)$r['ID'] ?>">Repor como aprovada</a>
                        <?php endif; ?>
                        <a class="action-btn" style="background:#95a5a6" href="?del_curso=<?= (int)$r['ID'] ?>&login=<?= urlencode($r['login']) ?>" onclick="return confirm('Tem a certeza que deseja remover esta matrícula?')">Remover</a>
                      </td>
                    <?php else: ?>
                      <td data-label="Data de Matrícula"><?= htmlspecialchars($r['data_matricula'] ?? '-') ?></td>
                      <td data-label="Estado">
                        <span class="status-pill <?= $statusClass ?>\"><?= htmlspecialchars(traduz_status_matricula($status)) ?></span>
                        <?php if (!empty($r['observacao'])): ?>
                          <div class="matricula-decision-note"><strong>Comentário:</strong> <?= htmlspecialchars((string)$r['observacao']) ?></div>
                        <?php elseif (in_array($status, ['APPROVED', 'REJECTED', 'CANCELLED', 'CANCEL_REJECTED'], true)): ?>
                          <div class="matricula-decision-note matricula-decision-note-muted">Sem comentário associado à decisão.</div>
                        <?php endif; ?>
                      </td>
                      <td data-label="Ações">
                        <?php if (in_array($status, ['APPROVED', 'CANCEL_REJECTED'], true)): ?>
                          <form method="post" style="display:inline;">
                            <input type="hidden" name="curso_id" value="<?= (int)$r['ID'] ?>">
                            <button type="submit" name="request_cancel_matricula" class="action-btn action-btn-remove" onclick="return confirm('Pretende pedir o cancelamento desta matrícula? O pedido terá de ser aprovado pelos serviços académicos.')">Pedir cancelamento</button>
                          </form>
                        <?php elseif ($status === 'CANCEL_PENDING'): ?>
                          <span style="font-size:12px;color:#92400e;font-weight:700;">A aguardar decisão dos serviços académicos</span>
                        <?php else: ?>
                          <span style="font-size:12px;color:#64748b;">Sem ações disponíveis</span>
                        <?php endif; ?>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endwhile; ?>
                <?php if ($count === 0): ?>
                  <tr>
                    <td colspan="<?= $grupo === 'ADMIN' ? 5 : 4 ?>" class="empty-state">
                      <p>
                        <?php if ($grupo === 'ADMIN'): ?>
                          Ainda não existem matrículas.
                        <?php else: ?>
                          Não estás matriculado em nenhum curso.
                        <?php endif; ?>
                      </p>
                    </td>
                  </tr>
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
          <?php if (!empty($perfil['foto_path'])): ?>
            <img class="profile-photo" src="<?= htmlspecialchars($perfil['foto_path']) ?>" alt="Fotografia de perfil">
          <?php endif; ?>
          <?php if ($grupo === 'ALUNO' && (!empty($perfil['nome']) || !empty($perfil['email']) || !empty($perfil['telefone']) || !empty($perfil['morada']))): ?>
            <strong>Nome:</strong> <?= htmlspecialchars($perfil['nome'] ?? '') ?><br>
            <strong>Email:</strong> <?= htmlspecialchars($perfil['email'] ?? '') ?><br>
            <strong>Telefone:</strong> <?= htmlspecialchars($perfil['telefone'] ?? '') ?><br>
            <strong>Morada:</strong> <?= htmlspecialchars($perfil['morada'] ?? '') ?><br>
          <?php endif; ?>
          <strong>Utilizador:</strong> <?= htmlspecialchars($_SESSION['user']['login']) ?>
        </div>
      </div>
    </div>
  </div>
  <?php render_back_to_top_script(); ?>
</body>
</html>



