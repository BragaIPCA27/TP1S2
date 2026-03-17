<?php
require_once 'config.php';
require_group(['ADMIN', 'FUNCIONARIO']);

$grupo = $_SESSION['user']['grupo_nome'] ?? '';

// Garantir que a tabela de matrículas suporta workflow de aprovação.
// (Se já existir, não faz nada.)
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
$perfil = null;

function traduz_grupo_nome(string $grupo): string {
  return match ($grupo) {
    'ADMIN' => 'Admin',
    'FUNCIONARIO' => 'Funcionário',
    default => 'Aluno',
  };
}

if ($grupo === 'ALUNO' || $grupo === 'FUNCIONARIO') {
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

// Aprovar/Rejeitar matrícula (com observação opcional via POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['aluno'], $_POST['curso_id'])) {
    $action   = $_POST['action'];
    $aluno    = $_POST['aluno'];
    $curso_id = (int)$_POST['curso_id'];
    $obs      = trim($_POST['observacao'] ?? '');

    if (in_array($action, ['approve', 'reject', 'restore_approved'], true)) {
      $stmt = $conn->prepare(
        "SELECT COALESCE(status, 'APPROVED') AS status
           FROM matriculas
          WHERE login = ? AND curso_id = ?
          LIMIT 1"
      );
      $stmt->bind_param("si", $aluno, $curso_id);
      $stmt->execute();
      $matriculaAtual = $stmt->get_result()->fetch_assoc();

      if ($matriculaAtual) {
        $statusAtual = strtoupper((string)($matriculaAtual['status'] ?? 'APPROVED'));
        $stmt = $conn->prepare("SELECT Nome FROM cursos WHERE ID = ? LIMIT 1");
        $stmt->bind_param("i", $curso_id);
        $stmt->execute();
        $cursoInfo = $stmt->get_result()->fetch_assoc();
        $cursoNome = trim((string)($cursoInfo['Nome'] ?? ''));
        $newStatus = null;
        if ($action === 'restore_approved' && $statusAtual === 'CANCEL_REJECTED') {
          $newStatus = 'APPROVED';
          $obs = '';
        } elseif ($statusAtual === 'CANCEL_PENDING') {
          $newStatus = $action === 'approve' ? 'CANCELLED' : 'CANCEL_REJECTED';
        } elseif ($action !== 'restore_approved') {
          $newStatus = $action === 'approve' ? 'APPROVED' : 'REJECTED';
        }

        if ($newStatus !== null) {
          $stmt = $conn->prepare(
            "UPDATE matriculas
             SET status = ?, approved_by = ?, approved_at = NOW(), observacao = ?
             WHERE login = ? AND curso_id = ?"
          );
          $stmt->bind_param("ssssi", $newStatus, $login, $obs, $aluno, $curso_id);
          $stmt->execute();

          $mensagem = null;
          if ($newStatus === 'APPROVED' && $statusAtual === 'PENDING') {
            $mensagem = $cursoNome !== ''
              ? "O teu pedido de matrícula no curso {$cursoNome} foi aprovado pelos serviços académicos."
              : "O teu pedido de matrícula foi aprovado pelos serviços académicos.";
          } elseif ($newStatus === 'REJECTED') {
            $mensagem = $cursoNome !== ''
              ? "O teu pedido de matrícula no curso {$cursoNome} foi recusado pelos serviços académicos."
              : "O teu pedido de matrícula foi recusado pelos serviços académicos.";
          } elseif ($newStatus === 'CANCELLED') {
            $mensagem = $cursoNome !== ''
              ? "O teu pedido de cancelamento da matrícula no curso {$cursoNome} foi aprovado pelos serviços académicos."
              : "O teu pedido de cancelamento da matrícula foi aprovado pelos serviços académicos.";
          } elseif ($newStatus === 'CANCEL_REJECTED') {
            $mensagem = $cursoNome !== ''
              ? "O teu pedido de cancelamento da matrícula no curso {$cursoNome} foi recusado pelos serviços académicos."
              : "O teu pedido de cancelamento da matrícula foi recusado pelos serviços académicos.";
          } elseif ($action === 'restore_approved') {
            $mensagem = $cursoNome !== ''
              ? "A tua matrícula no curso {$cursoNome} foi reposta ao estado aprovado pelos serviços académicos."
              : "A tua matrícula foi reposta ao estado aprovado pelos serviços académicos.";
          }

          if ($mensagem !== null) {
            criar_notificacao_aluno($conn, $aluno, $mensagem);
          }
        }
      }
    }

    header('Location: admin_matriculas.php');
    exit;
}

// Remover matrícula
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_curso'], $_POST['del_aluno'])) {
    $curso_id = (int)$_POST['del_curso'];
    $aluno = $_POST['del_aluno'];

  $stmt = $conn->prepare(
    "SELECT c.Nome AS curso_nome
       FROM matriculas m
       JOIN cursos c ON c.ID = m.curso_id
      WHERE m.login = ? AND m.curso_id = ?
      LIMIT 1"
  );
  $stmt->bind_param("si", $aluno, $curso_id);
  $stmt->execute();
  $matriculaInfo = $stmt->get_result()->fetch_assoc();

    $stmt = $conn->prepare("DELETE FROM matriculas WHERE login = ? AND curso_id = ?");
    $stmt->bind_param("si", $aluno, $curso_id);
    $stmt->execute();

  if ($stmt->affected_rows > 0) {
    $cursoNome = trim((string)($matriculaInfo['curso_nome'] ?? ''));
    $mensagem = $cursoNome !== ''
      ? "A tua matrícula no curso {$cursoNome} foi eliminada pelos serviços académicos."
      : "Uma das tuas matrículas foi eliminada pelos serviços académicos.";
    criar_notificacao_aluno($conn, $aluno, $mensagem);
  }

    header('Location: admin_matriculas.php');
    exit;
}

// Cursos para o filtro (todos)
$cursosFiltro = [];
$resCursosFiltro = $conn->query("SELECT ID, Nome FROM cursos ORDER BY Nome");
while ($cf = $resCursosFiltro->fetch_assoc()) {
  $cursosFiltro[] = $cf;
}

// Pegar todas as matrículas
$stmt = $conn->prepare(
  "SELECT m.login, u.grupo AS grupo, c.ID, c.Nome, m.data_matricula, m.status, m.approved_by, m.approved_at, m.observacao,
      a.nome AS aluno_nome, a.email AS aluno_email, a.telefone AS aluno_telefone, a.morada AS aluno_morada,
      COALESCE(a.foto_path, pp.foto_path) AS aluno_foto
     FROM matriculas m
     JOIN cursos c ON c.ID = m.curso_id
     JOIN users u ON u.login = m.login
   LEFT JOIN alunos a ON a.login = m.login
   LEFT JOIN perfil_pedidos pp ON pp.login = m.login AND pp.status = 'APPROVED'
     ORDER BY m.status, c.Nome, m.login"
);
$stmt->execute();
$matriculas = $stmt->get_result();

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
  <title>Área Administrativa - Matrículas</title>
  <style><?php include __DIR__ . '/assets/css/theme.css'; ?></style>
</head>
<body class='page-admin-matriculas'>
  <div class="container">
    <div class="header">
      <div class="header-left">
        <a href="index.php" aria-label="Ir para o menu principal">
          <img class="logo" src="<?= htmlspecialchars(logo_asset_path()) ?>" alt="Logótipo da escola">
        </a>
        <h2>Gestão de Matrículas</h2>
      </div>
      <a href="index.php" class="back-btn">← Voltar</a>
    </div>

    <div class="content-grid">
      <div class="card">
        <h3>Pedidos de matrícula</h3>
        <div class="search-bar" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:flex-end;">
          <div style="flex:1;min-width:180px;">
            <label for="search_aluno" style="margin-bottom:4px;">Nome do aluno</label>
            <input type="search" id="search_aluno" placeholder="Pesquisar por nome…" oninput="filtrarMatriculas()">
          </div>
          <div style="flex:1;min-width:180px;">
            <label for="search_curso" style="margin-bottom:4px;">Curso</label>
            <select id="search_curso" onchange="filtrarMatriculas()">
              <option value="">— Todos os cursos —</option>
              <?php foreach ($cursosFiltro as $cf): ?>
                <option value="<?= htmlspecialchars(strtolower((string)$cf['Nome'])) ?>"><?= htmlspecialchars($cf['Nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="button" onclick="limparPesquisa()" class="btn-sm" style="background:linear-gradient(135deg,#64748b,#475569);margin-bottom:1px;">Limpar</button>
        </div>
        <div id="sem-resultados" style="display:none;padding:20px;text-align:center;color:#888;">Nenhuma matrícula corresponde à pesquisa.</div>
        <div style="overflow-x:auto;">
          <table>
            <thead>
              <tr>
                <th>Aluno</th>
                <th>Estado</th>
                <th>Ações</th>
              </tr>
            </thead>
            <tbody>
              <?php $count = 0; $rowIdx = 0; while ($r = $matriculas->fetch_assoc()): $count++; $rowIdx++;
                $dataAluno = htmlspecialchars(strtolower(trim((string)($r['aluno_nome'] ?? $r['login']))));
                $dataCurso = htmlspecialchars(strtolower((string)$r['Nome']));
              ?>
                <?php
                  $status = strtoupper($r['status'] ?? 'PENDING');
                  $detailsId = 'matricula_' . $rowIdx;
                  $nomeAluno = trim((string)($r['aluno_nome'] ?? ''));
                  $nomeVisivel = $nomeAluno !== '' ? $nomeAluno : $r['login'];
                  $badgeClass = match($status) {
                      'APPROVED' => 'badge-approved',
                      'CANCEL_PENDING' => 'badge-pending',
                      'REJECTED' => 'badge-rejected',
                      'CANCELLED' => 'badge-rejected',
                      'CANCEL_REJECTED' => 'badge-rejected',
                      default => 'badge-pending',
                  };
                ?>
                <tr class="matricula-row" data-aluno="<?= $dataAluno ?>" data-curso="<?= $dataCurso ?>" data-details="<?= $detailsId ?>">
                  <td data-label="Aluno">
                    <button type="button" class="aluno-toggle" onclick="toggleMatricula('<?= $detailsId ?>', this)">
                      <span class="aluno-chevron">▾</span>
                      <?= htmlspecialchars($nomeVisivel) ?>
                    </button>
                  </td>
                  <td data-label="Estado">
                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars(traduz_status_matricula($status)) ?></span>
                    <div style="font-size:11px;color:#64748b;margin-top:4px;">
                      Aprovado por: <?= htmlspecialchars($r['approved_by'] ?? '-') ?>
                    </div>
                  </td>
                  <td data-label="Ações">
                    <?php if ($status === 'PENDING'): ?>
                      <form method="post" class="action-form" onsubmit="return confirmAction('Aprovar esta matrícula?')">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="aluno" value="<?= htmlspecialchars($r['login']) ?>">
                        <input type="hidden" name="curso_id" value="<?= (int)$r['ID'] ?>">
                        <textarea name="observacao" class="obs-field" placeholder="Observação (opcional)"></textarea>
                        <button type="submit" class="action-btn action-btn-approve">Aprovar</button>
                      </form>
                      <form method="post" class="action-form" onsubmit="return confirmAction('Rejeitar esta matrícula?')">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="aluno" value="<?= htmlspecialchars($r['login']) ?>">
                        <input type="hidden" name="curso_id" value="<?= (int)$r['ID'] ?>">
                        <textarea name="observacao" class="obs-field" placeholder="Motivo da rejeição (opcional)"></textarea>
                        <button type="submit" class="action-btn action-btn-reject">Rejeitar</button>
                      </form>
                    <?php elseif ($status === 'CANCEL_PENDING'): ?>
                      <form method="post" class="action-form" onsubmit="return confirmAction('Aprovar o cancelamento desta matrícula?')">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="aluno" value="<?= htmlspecialchars($r['login']) ?>">
                        <input type="hidden" name="curso_id" value="<?= (int)$r['ID'] ?>">
                        <textarea name="observacao" class="obs-field" placeholder="Observação sobre o cancelamento (opcional)"></textarea>
                        <button type="submit" class="action-btn action-btn-approve">Aprovar cancelamento</button>
                      </form>
                      <form method="post" class="action-form" onsubmit="return confirmAction('Rejeitar o cancelamento desta matrícula?')">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="aluno" value="<?= htmlspecialchars($r['login']) ?>">
                        <input type="hidden" name="curso_id" value="<?= (int)$r['ID'] ?>">
                        <textarea name="observacao" class="obs-field" placeholder="Motivo da rejeição do cancelamento (opcional)"></textarea>
                        <button type="submit" class="action-btn action-btn-reject">Rejeitar cancelamento</button>
                      </form>
                    <?php elseif ($status === 'CANCEL_REJECTED'): ?>
                      <form method="post" class="action-form action-form-inline" onsubmit="return confirmAction('Repor esta matrícula ao estado aprovado?')">
                        <input type="hidden" name="action" value="restore_approved">
                        <input type="hidden" name="aluno" value="<?= htmlspecialchars($r['login']) ?>">
                        <input type="hidden" name="curso_id" value="<?= (int)$r['ID'] ?>">
                        <button type="submit" class="action-btn action-btn-approve">Repor matricula aprovada</button>
                      </form>
                    <?php endif; ?>
                    <form method="post" class="action-form <?= $status === 'CANCEL_REJECTED' ? 'action-form-inline' : 'action-form-delete' ?>" onsubmit="return confirmAction('Eliminar esta matrícula? Esta ação não pode ser desfeita.')">
                      <input type="hidden" name="del_curso" value="<?= (int)$r['ID'] ?>">
                      <input type="hidden" name="del_aluno" value="<?= htmlspecialchars($r['login']) ?>">
                      <button type="submit" class="action-btn action-btn-remove">Eliminar</button>
                    </form>
                  </td>
                </tr>
                <tr id="<?= $detailsId ?>" class="details-row">
                  <td colspan="3" class="details-cell">
                    <div class="details-grid">
                      <?php if (!empty($r['aluno_foto'])): ?>
                      <div class="detail-item detail-photo-wrap" style="grid-column: 1 / -1;">
                        <img class="detail-profile-photo" src="<?= htmlspecialchars($r['aluno_foto']) ?>" alt="Fotografia de perfil">
                      </div>
                      <?php endif; ?>
                      <div class="detail-item"><strong>Login:</strong> <?= htmlspecialchars($r['login']) ?></div>
                      <div class="detail-item"><strong>Nome:</strong> <?= htmlspecialchars($nomeVisivel) ?></div>
                      <div class="detail-item"><strong>Email:</strong> <?= htmlspecialchars($r['aluno_email'] ?? '-') ?></div>
                      <div class="detail-item"><strong>Telefone:</strong> <?= htmlspecialchars($r['aluno_telefone'] ?? '-') ?></div>
                      <div class="detail-item" style="grid-column: 1 / -1;"><strong>Morada:</strong> <?= htmlspecialchars($r['aluno_morada'] ?? '-') ?></div>
                      <div class="detail-item"><strong>Curso:</strong> <?= htmlspecialchars($r['Nome']) ?></div>
                      <div class="detail-item"><strong>Data:</strong> <?= htmlspecialchars($r['data_matricula'] ?? '-') ?></div>
                      <div class="detail-item"><strong>Revisto por:</strong> <?= htmlspecialchars($r['approved_by'] ?? '-') ?></div>
                      <div class="detail-item"><strong>Revisto em:</strong> <?= htmlspecialchars($r['approved_at'] ?? '-') ?></div>
                      <?php if (!empty($r['observacao'])): ?>
                      <div class="detail-item" style="grid-column: 1 / -1;"><strong>Observação:</strong> <?= htmlspecialchars($r['observacao']) ?></div>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>

              <?php if ($count === 0): ?>
                <tr>
                  <td colspan="3" style="padding:30px; text-align:center; color:#555;">Ainda não existem matrículas.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="sidebar">
        <div class="card">
          <div class="user-info">
            <?php if (!empty($perfil['foto_path'])): ?>
              <img class="profile-photo" src="<?= htmlspecialchars($perfil['foto_path']) ?>" alt="Fotografia de perfil">
            <?php endif; ?>
            <?php if ($perfil && (!empty($perfil['nome']) || !empty($perfil['email']) || !empty($perfil['telefone']) || !empty($perfil['morada']))): ?>
              <strong>Nome:</strong> <?= htmlspecialchars($perfil['nome'] ?? '') ?><br>
              <strong>Email:</strong> <?= htmlspecialchars($perfil['email'] ?? '') ?><br>
              <strong>Telefone:</strong> <?= htmlspecialchars($perfil['telefone'] ?? '') ?><br>
              <strong>Morada:</strong> <?= htmlspecialchars($perfil['morada'] ?? '') ?><br>
            <?php endif; ?>
            <strong>Tipo de utilizador:</strong> <?= htmlspecialchars(traduz_grupo_nome($grupo)) ?><br>
            <strong>Utilizador:</strong> <?= htmlspecialchars($login) ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script>
    function confirmAction(msg) { return confirm(msg); }
    function toggleMatricula(rowId, button) {
      const row = document.getElementById(rowId);
      if (!row) return;
      const isOpen = row.classList.contains('open');
      row.classList.toggle('open', !isOpen);
      button.classList.toggle('open', !isOpen);
    }
    function filtrarMatriculas() {
      const termAluno = document.getElementById('search_aluno').value.toLowerCase().trim();
      const termCurso = document.getElementById('search_curso').value.trim();
      const rows = document.querySelectorAll('tr.matricula-row');
      let visiveis = 0;
      rows.forEach(row => {
        const aluno = (row.dataset.aluno || '');
        const curso = (row.dataset.curso || '');
        const match = (!termAluno || aluno.includes(termAluno)) && (!termCurso || curso.includes(termCurso));
        row.style.display = match ? '' : 'none';
        const detailsRow = document.getElementById(row.dataset.details);
        if (detailsRow) detailsRow.style.display = match && detailsRow.classList.contains('open') ? 'table-row' : 'none';
        if (match) visiveis++;
      });
      document.getElementById('sem-resultados').style.display = visiveis === 0 ? 'block' : 'none';
    }
    function limparPesquisa() {
      document.getElementById('search_aluno').value = '';
      document.getElementById('search_curso').value = '';
      filtrarMatriculas();
    }
  </script>
  <?php render_auto_logout_on_close_script(); ?>
</body>
</html>




