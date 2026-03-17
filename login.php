<?php
require_once 'config.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $pwd   = (string)($_POST['pwd'] ?? '');

    if ($login === '' || $pwd === '') {
        $error = "Preenche login e password.";
    } else {
        $stmt = $conn->prepare(" 
          SELECT u.login, u.pwd, u.grupo, u.approval_status, g.GRUPO AS grupo_nome
            FROM users u
            JOIN grupos g ON g.ID = u.grupo
            WHERE u.login = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $login);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        $pwd_md5 = md5($pwd);

        if ($row && hash_equals($row['pwd'], $pwd_md5)) {
          $approvalStatus = strtoupper($row['approval_status'] ?? 'APPROVED');

          if ($approvalStatus === 'PENDING') {
            $error = "A tua conta ainda aguarda aprovação do administrador.";
          } elseif ($approvalStatus === 'REJECTED') {
            $error = "A tua conta foi recusada. Contacta o administrador.";
          } else {
            $_SESSION['user'] = [
                'login' => $row['login'],
                'grupo' => (int)$row['grupo'],
                'grupo_nome' => $row['grupo_nome'], // 'ADMIN' ou 'ALUNO'
            ];
            header('Location: index.php');
            exit;
          }
        }

        if ($error === null) {
          $error = "Credenciais inválidas.";
        }
    }
}
?>
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login - Cursos</title>
  <style><?php include __DIR__ . '/assets/css/theme.css'; ?></style>
</head>
<body class='page-login'>
  <div class="container">
    <div class="card">
      <a href="frontpage.php" class="login-back-icon" aria-label="Voltar à página inicial" title="Voltar à página inicial" onclick="window.location.href='frontpage.php'; return false;">&#8592;</a>
      <div class="header-logo">
        <a href="frontpage.php" aria-label="Ir para a página inicial">
          <img class="logo" src="<?= htmlspecialchars(logo_asset_path()) ?>" alt="Logótipo da escola">
        </a>
      </div>

      <h2>Bem-vindo</h2>
      <p class="subtitle">Faça login para continuar</p>

      <?php if ($error): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post">
        <div class="form-group">
          <label for="login">Login</label>
          <input id="login" name="login" type="text" required autocomplete="username">
        </div>
        
        <div class="form-group">
          <label for="pwd">Password</label>
          <input id="pwd" name="pwd" type="password" required autocomplete="current-password">
        </div>

        <button type="submit">Entrar</button>
      </form>

      <p class="create-account-wrap"><a href="registar.php" class="create-account">Criar conta</a></p>
    </div>
  </div>
  <?php render_back_to_top_script(); ?>
</body>
</html>



