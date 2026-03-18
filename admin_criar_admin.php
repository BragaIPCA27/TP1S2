<?php
require_once 'config.php';
require_group(['ADMIN']);

$adminLogin = $_SESSION['user']['login'];
$grupo = $_SESSION['user']['grupo_nome'] ?? 'ADMIN';
$erro = null;
$ok = null;

$perfilAdminSidebar = [];
$stmt = $conn->prepare("SELECT nome, email, telefone, morada, foto_path FROM admin_perfis WHERE login = ? LIMIT 1");
$stmt->bind_param('s', $adminLogin);
$stmt->execute();
$perfilAdminSidebar = $stmt->get_result()->fetch_assoc() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $novoLogin = trim((string)($_POST['login'] ?? ''));
  $pwd1 = (string)($_POST['pwd1'] ?? '');
  $pwd2 = (string)($_POST['pwd2'] ?? '');

  if ($novoLogin === '' || $pwd1 === '' || $pwd2 === '') {
    $erro = 'Preenche todos os campos.';
  } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $novoLogin)) {
    $erro = 'Login inválido. Usa 3-20 caracteres: letras, números ou underscore (_).';
  } elseif ($pwd1 !== $pwd2) {
    $erro = 'As passwords não coincidem.';
  } elseif (strlen($pwd1) < 4) {
    $erro = 'Password muito curta (mínimo 4).';
  } else {
    $stmt = $conn->prepare("SELECT 1 FROM users WHERE login = ? LIMIT 1");
    $stmt->bind_param('s', $novoLogin);
    $stmt->execute();

    if ($stmt->get_result()->num_rows > 0) {
      $erro = 'Esse utilizador já existe.';
    } else {
      $stmt = $conn->prepare("SELECT ID FROM grupos WHERE GRUPO = 'ADMIN' LIMIT 1");
      $stmt->execute();
      $grupoAdmin = $stmt->get_result()->fetch_assoc();

      if (!$grupoAdmin) {
        $erro = 'Grupo ADMIN não encontrado na base de dados.';
      } else {
        $hash = md5($pwd1);
        $grupoId = (int)$grupoAdmin['ID'];
        $status = 'APPROVED';

        $stmt = $conn->prepare(
          "INSERT INTO users (login, pwd, grupo, approval_status, approved_by, approved_at)
           VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param('ssiss', $novoLogin, $hash, $grupoId, $status, $adminLogin);
        $stmt->execute();

        $ok = 'Conta admin criada com sucesso.';
      }
    }
  }
}
?>
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Criar Conta Admin</title>
  <style><?php include __DIR__ . '/assets/css/theme.css'; ?></style>
</head>
<body class='page-admin-criar-admin'>
  <div class="container">
    <div class="header">
      <div class="header-left">
        <a href="index.php" aria-label="Ir para o menu principal">
          <img class="logo" src="<?= htmlspecialchars(logo_asset_path()) ?>" alt="Logótipo da escola">
        </a>
        <h2>Admin - Criar Conta Admin</h2>
      </div>
      <a href="index.php" class="back-btn">← Voltar</a>
    </div>

    <div class="main">
      <div class="card">
        <?php if ($erro): ?><div class="notice-err"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="notice-ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

        <form method="post">
          <label for="login">Login do novo admin</label>
          <input id="login" name="login" required value="<?= htmlspecialchars((string)($_POST['login'] ?? '')) ?>">

          <label for="pwd1">Password</label>
          <input id="pwd1" name="pwd1" type="password" required>

          <label for="pwd2">Repetir password</label>
          <input id="pwd2" name="pwd2" type="password" required>

          <button type="submit" class="create-admin-submit">Criar conta admin</button>
        </form>
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
            <strong>Tipo de utilizador:</strong> <?= htmlspecialchars($grupo === 'ADMIN' ? 'Administrador' : 'Utilizador') ?><br>
            <strong>Utilizador:</strong> <?= htmlspecialchars($adminLogin) ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php render_auto_logout_on_close_script(); ?>
</body>
</html>
