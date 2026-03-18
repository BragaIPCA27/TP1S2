<?php
require_once 'config.php';
require_login();

// Garantir que a tabela de matrículas tem o campo `status` (usado para validação de aprovação)
$col = $conn->query("SHOW COLUMNS FROM matriculas LIKE 'status'")->fetch_assoc();
if (!$col) {
    $conn->query(
        "ALTER TABLE matriculas
         ADD COLUMN status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
         ADD COLUMN approved_by VARCHAR(20) DEFAULT NULL,
         ADD COLUMN approved_at DATETIME DEFAULT NULL"
    );
}

$grupo = $_SESSION['user']['grupo_nome'] ?? '';
$login = $_SESSION['user']['login'];
$grupo_menu_class = strtolower((string)$grupo);
$notificacoesAluno = [];
$totalNotificacoes = 0;
$notificacoesAdmin = [];
$notificacoesAdminSistema = [];
$perfilIncompleto = false;

if (
  $_SERVER['REQUEST_METHOD'] === 'POST' &&
  in_array($grupo, ['ALUNO', 'FUNCIONARIO', 'ADMIN'], true)
) {
  if (in_array($grupo, ['ALUNO', 'FUNCIONARIO'], true) && isset($_POST['remove_notificacao'])) {
    $notificacaoId = (int)($_POST['remove_notificacao'] ?? 0);
    if ($notificacaoId > 0) {
      remover_notificacao_aluno($conn, $login, $notificacaoId);
    }

    header('Location: index.php?notifications=open');
    exit;
  }

  if (in_array($grupo, ['ALUNO', 'FUNCIONARIO'], true) && isset($_POST['mark_notificacoes_lidas'])) {
    marcar_notificacoes_aluno_como_lidas($conn, $login);
    header('Location: index.php?notifications=open');
    exit;
  }

  if ($grupo === 'ADMIN' && isset($_POST['dismiss_admin_notificacao'], $_POST['dismiss_admin_total'])) {
    $dismissKey = trim((string)($_POST['dismiss_admin_notificacao'] ?? ''));
    $dismissTotal = max(0, (int)($_POST['dismiss_admin_total'] ?? 0));

    if ($dismissKey !== '') {
      if (!isset($_SESSION['admin_notif_hidden']) || !is_array($_SESSION['admin_notif_hidden'])) {
        $_SESSION['admin_notif_hidden'] = [];
      }
      $_SESSION['admin_notif_hidden'][$dismissKey] = $dismissTotal;
    }

    header('Location: index.php?notifications=open');
    exit;
  }

  if ($grupo === 'ADMIN' && isset($_POST['remove_notificacao'])) {
    $notificacaoId = (int)($_POST['remove_notificacao'] ?? 0);
    if ($notificacaoId > 0) {
      remover_notificacao_aluno($conn, $login, $notificacaoId);
    }

    header('Location: index.php?notifications=open');
    exit;
  }
}

function traduz_grupo_nome(string $grupo): string {
  return match ($grupo) {
    'ADMIN' => 'Administrador',
    'GESTOR' => 'Gestor',
    'FUNCIONARIO' => 'Funcionário',
    default => 'Aluno',
  };
}

$perfil = null;
if ($grupo === 'ALUNO' || $grupo === 'FUNCIONARIO') {
  if (in_array($grupo, ['ALUNO', 'FUNCIONARIO'], true)) {
    $notificacoesAluno = listar_notificacoes_aluno($conn, $login);
    $totalNotificacoes = contar_notificacoes_nao_lidas($conn, $login);
  }

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

if ($grupo === 'ADMIN') {
  if (!isset($_SESSION['admin_notif_hidden']) || !is_array($_SESSION['admin_notif_hidden'])) {
    $_SESSION['admin_notif_hidden'] = [];
  }

  $hiddenAdminNotifs = $_SESSION['admin_notif_hidden'];
  $pendingContas = 0;
  $pendingPerfis = 0;
  $pendingMatriculas = 0;
  $pendingCancelamentos = 0;
  $pendingCursosFuncionarios = 0;

  $res = $conn->query("SELECT COUNT(*) AS total FROM users WHERE approval_status = 'PENDING'");
  if ($res) {
    $row = $res->fetch_assoc();
    $pendingContas = (int)($row['total'] ?? 0);
  }

  $res = $conn->query("SELECT COUNT(*) AS total FROM perfil_pedidos WHERE status = 'PENDING'");
  if ($res) {
    $row = $res->fetch_assoc();
    $pendingPerfis = (int)($row['total'] ?? 0);
  }

  $res = $conn->query("SELECT COUNT(*) AS total FROM matriculas WHERE status = 'PENDING'");
  if ($res) {
    $row = $res->fetch_assoc();
    $pendingMatriculas = (int)($row['total'] ?? 0);
  }

  $res = $conn->query("SELECT COUNT(*) AS total FROM matriculas WHERE status = 'CANCEL_PENDING'");
  if ($res) {
    $row = $res->fetch_assoc();
    $pendingCancelamentos = (int)($row['total'] ?? 0);
  }

  $res = $conn->query("SELECT COUNT(*) AS total FROM funcionario_curso_pedidos WHERE status = 'PENDING'");
  if ($res) {
    $row = $res->fetch_assoc();
    $pendingCursosFuncionarios = (int)($row['total'] ?? 0);
  }

  if ($pendingContas > 0 && ((int)($hiddenAdminNotifs['contas'] ?? -1) !== $pendingContas)) {
    $notificacoesAdmin[] = [
      'key' => 'contas',
      'total' => $pendingContas,
      'titulo' => 'Contas pendentes',
      'mensagem' => "Tens {$pendingContas} conta(s) a aguardar aprovação.",
      'link' => 'alunos_admin.php',
      'acao' => 'Ir para utilizadores',
    ];
  }

  if ($pendingPerfis > 0 && ((int)($hiddenAdminNotifs['perfis'] ?? -1) !== $pendingPerfis)) {
    $notificacoesAdmin[] = [
      'key' => 'perfis',
      'total' => $pendingPerfis,
      'titulo' => 'Perfis pendentes',
      'mensagem' => "Tens {$pendingPerfis} pedido(s) de alteração de perfil por rever.",
      'link' => 'alunos_admin.php',
      'acao' => 'Ir para revisão de perfis',
    ];
  }

  if ($pendingMatriculas > 0 && ((int)($hiddenAdminNotifs['matriculas'] ?? -1) !== $pendingMatriculas)) {
    $notificacoesAdmin[] = [
      'key' => 'matriculas',
      'total' => $pendingMatriculas,
      'titulo' => 'Matrículas pendentes',
      'mensagem' => "Tens {$pendingMatriculas} pedido(s) de matrícula para analisar.",
      'link' => 'admin_matriculas.php',
      'acao' => 'Ir para matrículas',
    ];
  }

  if ($pendingCancelamentos > 0 && ((int)($hiddenAdminNotifs['cancelamentos'] ?? -1) !== $pendingCancelamentos)) {
    $notificacoesAdmin[] = [
      'key' => 'cancelamentos',
      'total' => $pendingCancelamentos,
      'titulo' => 'Cancelamentos pendentes',
      'mensagem' => "Tens {$pendingCancelamentos} pedido(s) de cancelamento para decidir.",
      'link' => 'admin_matriculas.php',
      'acao' => 'Ir para cancelamentos',
    ];
  }

  if ($pendingCursosFuncionarios > 0 && ((int)($hiddenAdminNotifs['cursosfuncionarios'] ?? -1) !== $pendingCursosFuncionarios)) {
    $notificacoesAdmin[] = [
      'key' => 'cursosfuncionarios',
      'total' => $pendingCursosFuncionarios,
      'titulo' => 'Pedidos de cursos pendentes',
      'mensagem' => "Tens {$pendingCursosFuncionarios} pedido(s) de acesso a cursos para rever.",
      'link' => 'alunos_admin.php',
      'acao' => 'Ir para pedidos de cursos',
    ];
  }

  $stmt = $conn->prepare("SELECT nome, email, telefone, morada, foto_path FROM admin_perfis WHERE login = ? LIMIT 1");
  $stmt->bind_param('s', $login);
  $stmt->execute();
  $adminPerfil = $stmt->get_result()->fetch_assoc() ?: null;

  if ($adminPerfil) {
    $perfil = $adminPerfil;
  }

  $perfilIncompleto = !$adminPerfil || empty($adminPerfil['nome']) || empty($adminPerfil['email']) || empty($adminPerfil['telefone']) || empty($adminPerfil['morada']);

  if ($perfilIncompleto && ((int)($hiddenAdminNotifs['perfilincompleto'] ?? -1) !== 1)) {
    $notificacoesAdmin[] = [
      'key' => 'perfilincompleto',
      'total' => 1,
      'titulo' => 'Perfil incompleto',
      'mensagem' => 'Por favor, preenche o teu perfil com os dados necesários.',
      'link' => 'perfil_admin.php',
      'acao' => 'Preencher perfil',
    ];
  }

  $notificacoesAdminSistema = listar_notificacoes_aluno($conn, $login);

  $totalNotificacoes = count($notificacoesAdmin) + count($notificacoesAdminSistema);
}

$stmt = $conn->prepare("
  SELECT c.Nome
  FROM matriculas m
  JOIN cursos c ON c.ID = m.curso_id
  WHERE m.login = ?
    AND COALESCE(m.status, 'APPROVED') IN ('APPROVED', 'CANCEL_REJECTED')
  ORDER BY c.Nome
");
$stmt->bind_param("s", $login);
$stmt->execute();
$cursos_aluno = $stmt->get_result();
?>
<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IPCA - Gestão</title>
  <style><?php include __DIR__ . '/assets/css/theme.css'; ?></style>
</head>
<body class='page-index'>
<div class="container">
  <div class="header">
    <div class="header-left">
      <a href="index.php" aria-label="Ir para o menu principal">
        <img class="logo" src="<?= htmlspecialchars(logo_asset_path()) ?>" alt="Logótipo da escola">
      </a>
      <h2>Menu principal</h2>
    </div>
    <div class="header-actions">
      <?php if (in_array($grupo, ['ALUNO', 'FUNCIONARIO'], true)): ?>
        <details class="notifications-dropdown"<?= isset($_GET['notifications']) && $_GET['notifications'] === 'open' ? ' open' : '' ?>>
          <summary class="notification-bell<?= $totalNotificacoes > 0 ? ' has-notifications' : '' ?>" aria-label="Ver notificações">
            <span class="notification-bell-icon">&#128276;</span>
            <?php if ($totalNotificacoes > 0): ?>
              <span class="notification-bell-count"><?= $totalNotificacoes ?></span>
            <?php endif; ?>
          </summary>
          <div class="notifications-backdrop" onclick="this.parentElement.removeAttribute('open');"></div>
          <div class="notifications-panel" id="notificacoes">
            <form method="post" class="mark-notifications-read-form" id="mark-notifications-read-form">
              <input type="hidden" name="mark_notificacoes_lidas" value="1">
            </form>
            <div class="notifications-panel-topbar">
              <h3 class="section-title">Notificações</h3>
              <button type="button" class="notifications-close" onclick="this.closest('details').removeAttribute('open');">Fechar</button>
            </div>
            <?php if ($notificacoesAluno !== []): ?>
              <div class="notifications-list">
                <?php foreach ($notificacoesAluno as $notificacao): ?>
                  <div class="notification-item<?= empty($notificacao['read_at']) ? ' is-unread' : ' is-read' ?>">
                    <div class="notice-err"><?= htmlspecialchars($notificacao['mensagem']) ?></div>
                    <form method="post" class="notification-remove-form">
                      <input type="hidden" name="remove_notificacao" value="<?= (int)$notificacao['id'] ?>">
                      <button type="submit" class="action-btn action-btn-remove notification-remove-btn">Remover</button>
                    </form>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="notifications-empty">Não tens notificações pendentes.</p>
            <?php endif; ?>
          </div>
        </details>
      <?php elseif ($grupo === 'ADMIN'): ?>
        <details class="notifications-dropdown"<?= isset($_GET['notifications']) && $_GET['notifications'] === 'open' ? ' open' : '' ?>>
          <summary class="notification-bell<?= $totalNotificacoes > 0 ? ' has-notifications' : '' ?>" aria-label="Ver pendências administrativas">
            <span class="notification-bell-icon">&#128276;</span>
            <?php if ($totalNotificacoes > 0): ?>
              <span class="notification-bell-count"><?= $totalNotificacoes ?></span>
            <?php endif; ?>
          </summary>
          <div class="notifications-backdrop" onclick="this.parentElement.removeAttribute('open');"></div>
          <div class="notifications-panel" id="notificacoes">
            <div class="notifications-panel-topbar">
              <h3 class="section-title">Pendências administrativas</h3>
              <button type="button" class="notifications-close" onclick="this.closest('details').removeAttribute('open');">Fechar</button>
            </div>
            <?php if ($notificacoesAdmin !== [] || $notificacoesAdminSistema !== []): ?>
              <div class="notifications-list">
                <?php foreach ($notificacoesAdmin as $item): ?>
                  <div class="notification-item notification-admin-item is-unread">
                    <div class="notice-err">
                      <strong><?= htmlspecialchars($item['titulo']) ?>:</strong>
                      <?= htmlspecialchars($item['mensagem']) ?>
                    </div>
                    <div class="notification-admin-actions">
                      <a href="<?= htmlspecialchars($item['link']) ?>" class="action-link approve notification-action-link"><?= htmlspecialchars($item['acao']) ?></a>
                      <form method="post" class="notification-remove-form">
                        <input type="hidden" name="dismiss_admin_notificacao" value="<?= htmlspecialchars($item['key']) ?>">
                        <input type="hidden" name="dismiss_admin_total" value="<?= (int)$item['total'] ?>">
                        <button type="submit" class="action-btn action-btn-remove notification-remove-btn">Remover notificação</button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>

                <?php foreach ($notificacoesAdminSistema as $notificacao): ?>
                  <div class="notification-item notification-admin-item<?= empty($notificacao['read_at']) ? ' is-unread' : ' is-read' ?>">
                    <div class="notice-err"><?= htmlspecialchars((string)$notificacao['mensagem']) ?></div>
                    <div class="notification-admin-actions">
                      <a href="perfil_admin.php" class="action-link approve notification-action-link">Ver perfil admin</a>
                      <form method="post" class="notification-remove-form">
                        <input type="hidden" name="remove_notificacao" value="<?= (int)$notificacao['id'] ?>">
                        <button type="submit" class="action-btn action-btn-remove notification-remove-btn">Remover notificação</button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="notifications-empty">Não tens pendências administrativas no momento.</p>
            <?php endif; ?>
          </div>
        </details>
      <?php endif; ?>
      <a href="logout.php" class="back-btn logout">Sair</a>
    </div>
  </div>

  <div class="main">
    <div class="card">
      <div class="nav nav-<?= htmlspecialchars($grupo_menu_class) ?>">
        <?php if ($grupo === 'ADMIN' || $grupo === 'ALUNO'): ?>
          <a href="plano_estudos.php">Plano de Estudos</a>
        <?php endif; ?>
        <?php if (in_array(($_SESSION['user']['grupo_nome'] ?? ''), ['ALUNO', 'FUNCIONARIO', 'GESTOR'], true)): ?>
          <a href="perfil.php">Perfil</a>
        <?php endif; ?>
        <?php if ($grupo === 'ADMIN'): ?>
          <a href="perfil_admin.php">Perfil</a>
          <a href="admin_criar_admin.php">Criar Conta Administrativa</a>
          <a href="alunos_admin.php">Utilizadores</a>
          <a href="cursos.php">Cursos</a>
          <a href="disciplinas.php">Disciplinas</a>
          <a href="admin_matriculas.php">Matrículas</a>
          <a href="pautas.php">Pautas</a>
        <?php elseif ($grupo === 'GESTOR'): ?>
        <?php elseif ($grupo === 'FUNCIONARIO'): ?>
          <a href="admin_matriculas.php">Pedidos de Matrícula</a>
          <a href="pautas.php">Pautas de Avaliação</a>
        <?php elseif ($grupo === 'ALUNO'): ?>
          <a href="matriculas.php">Matrículas</a>
          <a href="pautas.php">Pautas</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($grupo === 'ADMIN' && $perfilIncompleto): ?>
    <div class="card">
      <div class="notice-err">Precisas de completar o teu perfil de administrador antes de acederes às restantes funcionalidades.</div>
    </div>
    <?php endif; ?>

    <?php if ($grupo === 'ALUNO'): ?>
    <div class="card">
      <h3 class="section-title">Os teus Cursos</h3>
      <?php 
      $count = 0;
      $cursos_array = [];
      while($row = $cursos_aluno->fetch_assoc()):
        $count++;
        $cursos_array[] = $row['Nome'];
      endwhile;
      ?>
      <?php if ($count > 0): ?>
        <ul class="cursos-list">
          <?php foreach($cursos_array as $curso): ?>
            <li><?= htmlspecialchars($curso) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="muted-empty-text">Não estás matriculado em nenhum curso.</p>
      <?php endif; ?>
    </div>
    <?php elseif ($grupo === 'FUNCIONARIO'): ?>
    <?php endif; ?>
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
        <strong>Utilizador:</strong> <?= htmlspecialchars($_SESSION['user']['login']) ?>
      </div>
    </div>
  </div>
  <footer>&copy; <?= date('Y') ?> IPCA - Gestão de Cursos. Todos os direitos reservados.</footer>
</div>
<script>
(function () {
  const dropdown = document.querySelector('.notifications-dropdown');
  const markReadForm = document.getElementById('mark-notifications-read-form');
  const unreadCount = <?= (int)$totalNotificacoes ?>;

  if (!dropdown || !markReadForm || unreadCount <= 0) return;

  let submitted = false;
  dropdown.addEventListener('toggle', function () {
    if (dropdown.open && !submitted) {
      submitted = true;
      markReadForm.submit();
    }
  });
})();
</script>
<?php render_auto_logout_on_close_script(); ?>
</body>
</html>



