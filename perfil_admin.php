<?php
require_once 'config.php';
require_group(['ADMIN']);

$login = $_SESSION['user']['login'];

function apagar_foto_admin(?string $path): void {
  if (!$path || !str_starts_with($path, 'assets/img/perfis/')) {
    return;
  }

  $abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
  if (is_file($abs)) {
    @unlink($abs);
  }
}

function guardar_foto_admin(array $ficheiro, ?string $antigaPath = null): string {
  if (($ficheiro['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Não foi possível carregar a fotografia.');
  }

  if (($ficheiro['size'] ?? 0) > 2 * 1024 * 1024) {
    throw new RuntimeException('A fotografia não pode ultrapassar 2MB.');
  }

  $tmp = $ficheiro['tmp_name'] ?? '';
  if ($tmp === '' || !is_uploaded_file($tmp)) {
    throw new RuntimeException('Ficheiro de fotografia inválido.');
  }

  $info = @getimagesize($tmp);
  if (!$info || !isset($info['mime'])) {
    throw new RuntimeException('Selecione uma imagem válida em JPG, PNG ou WebP.');
  }

  $ext = match ($info['mime']) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => '',
  };

  if ($ext === '') {
    throw new RuntimeException('Selecione uma imagem válida em JPG, PNG ou WebP.');
  }

  $dir = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'perfis';
  if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    throw new RuntimeException('Não foi possível preparar a pasta das fotografias.');
  }

  $nome = 'perfil_' . bin2hex(random_bytes(16)) . '.' . $ext;
  $destino = $dir . DIRECTORY_SEPARATOR . $nome;
  if (!move_uploaded_file($tmp, $destino)) {
    throw new RuntimeException('Não foi possível guardar a fotografia.');
  }

  if ($antigaPath && $antigaPath !== 'assets/img/perfis/' . $nome) {
    apagar_foto_admin($antigaPath);
  }

  return 'assets/img/perfis/' . $nome;
}

function guardar_foto_admin_base64(string $imagemBase64, ?string $antigaPath = null): string {
  if (!preg_match('/^data:(image\/(jpeg|png|webp));base64,/', $imagemBase64)) {
    throw new RuntimeException('A imagem recortada é inválida.');
  }

  $dados = base64_decode(substr($imagemBase64, strpos($imagemBase64, ',') + 1), true);
  if ($dados === false) {
    throw new RuntimeException('A imagem recortada é inválida.');
  }

  if (strlen($dados) > 2 * 1024 * 1024) {
    throw new RuntimeException('A fotografia não pode ultrapassar 2MB.');
  }

  $info = @getimagesizefromstring($dados);
  if (!$info || !isset($info['mime'])) {
    throw new RuntimeException('A imagem recortada é inválida.');
  }

  $ext = match ($info['mime']) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => '',
  };

  if ($ext === '') {
    throw new RuntimeException('Selecione uma imagem válida em JPG, PNG ou WebP.');
  }

  $dir = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'perfis';
  if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    throw new RuntimeException('Não foi possível preparar a pasta das fotografias.');
  }

  $nome = 'perfil_' . bin2hex(random_bytes(16)) . '.' . $ext;
  $destino = $dir . DIRECTORY_SEPARATOR . $nome;
  if (file_put_contents($destino, $dados) === false) {
    throw new RuntimeException('Não foi possível guardar a fotografia.');
  }

  if ($antigaPath && $antigaPath !== 'assets/img/perfis/' . $nome) {
    apagar_foto_admin($antigaPath);
  }

  return 'assets/img/perfis/' . $nome;
}

$stmt = $conn->prepare(
  "SELECT u.login, g.GRUPO AS grupo_nome, u.approval_status, u.approved_by, u.approved_at
     FROM users u
     JOIN grupos g ON g.ID = u.grupo
    WHERE u.login = ?
    LIMIT 1"
);
$stmt->bind_param('s', $login);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc() ?: [];

$stmt = $conn->prepare("SELECT nome, email, telefone, morada, foto_path FROM admin_perfis WHERE login = ? LIMIT 1");
$stmt->bind_param('s', $login);
$stmt->execute();
$perfilAdmin = $stmt->get_result()->fetch_assoc() ?: [];

function nome_tem_apelido_admin(string $nome): bool {
  $partes = preg_split('/\s+/', trim($nome)) ?: [];
  return count(array_filter($partes, static fn(string $p): bool => $p !== '')) >= 2;
}

$erro = null;
$ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $acao = trim((string)($_POST['acao'] ?? ''));

  if ($acao === 'apagar_conta') {
    $stmt = $conn->prepare(
      "SELECT COUNT(*) AS total
         FROM users u
         JOIN grupos g ON g.ID = u.grupo
        WHERE g.GRUPO = 'ADMIN'"
    );
    $stmt->execute();
    $rowAdmins = $stmt->get_result()->fetch_assoc() ?: [];
    $totalAdmins = (int)($rowAdmins['total'] ?? 0);

    if ($totalAdmins <= 1) {
      $erro = 'Não é possível apagar a única conta de administrador.';
    } else {
      $stmt = $conn->prepare(
        "SELECT u.login
           FROM users u
           JOIN grupos g ON g.ID = u.grupo
          WHERE g.GRUPO = 'ADMIN' AND u.login <> ?"
      );
      $stmt->bind_param('s', $login);
      $stmt->execute();
      $outrosAdmins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

      $mensagemNotificacao = "O administrador {$login} apagou a própria conta.";
      foreach ($outrosAdmins as $adminDestino) {
        $loginDestino = trim((string)($adminDestino['login'] ?? ''));
        if ($loginDestino !== '') {
          criar_notificacao_aluno($conn, $loginDestino, $mensagemNotificacao);
        }
      }

      if (!empty($perfilAdmin['foto_path'])) {
        apagar_foto_admin((string)$perfilAdmin['foto_path']);
      }

      $stmt = $conn->prepare("DELETE FROM users WHERE login = ? LIMIT 1");
      $stmt->bind_param('s', $login);
      $stmt->execute();

      $_SESSION = [];
      if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
          session_name(),
          '',
          time() - 42000,
          $params['path'],
          $params['domain'],
          (bool)$params['secure'],
          (bool)$params['httponly']
        );
      }
      session_destroy();

      header('Location: frontpage.php?conta_apagada=1');
      exit;
    }
  }

  if ($acao !== 'apagar_conta') {
  $nome = trim((string)($_POST['nome'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $telefone = trim((string)($_POST['telefone'] ?? ''));
  $morada = trim((string)($_POST['morada'] ?? ''));
  $fotoPath = (string)($perfilAdmin['foto_path'] ?? '');
  $fotoCortada = trim((string)($_POST['foto_cortada'] ?? ''));

  if ($nome === '') {
    $erro = 'O nome é obrigatório.';
  } elseif (!nome_tem_apelido_admin($nome)) {
    $erro = 'Indique nome e apelido.';
  } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $erro = 'Email inválido.';
  } elseif ($telefone !== '' && !preg_match('/^[0-9+\-()\s]{6,20}$/', $telefone)) {
    $erro = 'Telemóvel inválido.';
  }

  if ($erro === null && $fotoCortada !== '') {
    try {
      $fotoPath = guardar_foto_admin_base64($fotoCortada, $fotoPath !== '' ? $fotoPath : null);
    } catch (RuntimeException $e) {
      $erro = $e->getMessage();
    }
  } elseif ($erro === null && isset($_FILES['foto']) && (int)($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    try {
      $fotoPath = guardar_foto_admin($_FILES['foto'], $fotoPath !== '' ? $fotoPath : null);
    } catch (RuntimeException $e) {
      $erro = $e->getMessage();
    }
  }

  if ($erro === null) {
    $emailFinal = $email === '' ? null : $email;
    $telefoneFinal = $telefone === '' ? null : $telefone;
    $moradaFinal = $morada === '' ? null : $morada;
    $fotoFinal = $fotoPath === '' ? null : $fotoPath;

    $stmt = $conn->prepare(
      "INSERT INTO admin_perfis (login, nome, email, telefone, morada, foto_path)
       VALUES (?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE
         nome = VALUES(nome),
         email = VALUES(email),
         telefone = VALUES(telefone),
         morada = VALUES(morada),
         foto_path = VALUES(foto_path)"
    );
    $stmt->bind_param('ssssss', $login, $nome, $emailFinal, $telefoneFinal, $moradaFinal, $fotoFinal);
    $stmt->execute();

    $stmt = $conn->prepare("SELECT nome, email, telefone, morada, foto_path FROM admin_perfis WHERE login = ? LIMIT 1");
    $stmt->bind_param('s', $login);
    $stmt->execute();
    $perfilAdmin = $stmt->get_result()->fetch_assoc() ?: [];

    $ok = 'Perfil atualizado com sucesso.';
  }
  }
}

function traduz_estado_conta(string $estado): string {
  return match (strtoupper($estado)) {
    'PENDING' => 'Pendente',
    'REJECTED' => 'Recusada',
    default => 'Aprovada',
  };
}

$pendingContas = 0;
$pendingPerfis = 0;
$pendingMatriculas = 0;
$pendingCancelamentos = 0;

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
?>
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Perfil</title>
  <style><?php include __DIR__ . '/assets/css/theme.css'; ?></style>
</head>
<body class='page-perfil'>
  <div class="container">
    <div class="header">
      <div class="header-left">
        <a href="index.php" aria-label="Ir para o menu principal">
          <img class="logo" src="<?= htmlspecialchars(logo_asset_path()) ?>" alt="Logótipo da escola">
        </a>
        <h2>Perfil</h2>
      </div>
      <a href="index.php" class="back-btn">← Voltar</a>
    </div>

    <div class="card">
      <div class="user-info">
        <?php if (!empty($perfilAdmin['foto_path'])): ?>
          <img class="profile-photo" src="<?= htmlspecialchars((string)$perfilAdmin['foto_path']) ?>" alt="Fotografia de perfil">
        <?php endif; ?>
        <strong>Nome:</strong> <?= htmlspecialchars((string)($perfilAdmin['nome'] ?? 'Administrador')) ?><br>
        <?php if (!empty($perfilAdmin['email'])): ?><strong>Email:</strong> <?= htmlspecialchars((string)$perfilAdmin['email']) ?><br><?php endif; ?>
        <?php if (!empty($perfilAdmin['telefone'])): ?><strong>Telefone:</strong> <?= htmlspecialchars((string)$perfilAdmin['telefone']) ?><br><?php endif; ?>
        <?php if (!empty($perfilAdmin['morada'])): ?><strong>Morada:</strong> <?= htmlspecialchars((string)$perfilAdmin['morada']) ?><br><?php endif; ?>
        <strong>Tipo de utilizador:</strong> Administrador<br>
        <strong>Utilizador:</strong> <?= htmlspecialchars((string)($admin['login'] ?? $login)) ?>
      </div>
    </div>

    <div class="card">
      <div class="table-section">
        <h3>Resumo de Pendências</h3>
        <div class="details-grid">
          <div class="detail-item"><strong>Contas pendentes:</strong> <?= (int)$pendingContas ?></div>
          <div class="detail-item"><strong>Perfis pendentes:</strong> <?= (int)$pendingPerfis ?></div>
          <div class="detail-item"><strong>Matrículas pendentes:</strong> <?= (int)$pendingMatriculas ?></div>
          <div class="detail-item"><strong>Cancelamentos pendentes:</strong> <?= (int)$pendingCancelamentos ?></div>
        </div>
      </div>
    </div>

    <div class="card">
      <details class="profile-edit-details" <?= ($erro || $ok) ? 'open' : '' ?>>
        <summary class="profile-edit-summary">Editar Perfil</summary>
        <div class="form-section profile-edit-form-section">
          <?php if ($erro): ?><div class="err"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
          <?php if ($ok): ?><div class="ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

          <form method="post" enctype="multipart/form-data" id="perfil-form">
            <div class="form-field">
              <label for="nome">Nome *</label>
              <input id="nome" name="nome" type="text" required pattern=".*\s+.*" title="Indique nome e apelido." value="<?= htmlspecialchars((string)($_POST['nome'] ?? $perfilAdmin['nome'] ?? '')) ?>">
            </div>

            <div class="form-field">
              <label for="email">Email</label>
              <input id="email" name="email" type="email" value="<?= htmlspecialchars((string)($_POST['email'] ?? $perfilAdmin['email'] ?? '')) ?>">
            </div>

            <div class="form-field">
              <label for="telefone">Telemovel</label>
              <input id="telefone" name="telefone" type="tel" value="<?= htmlspecialchars((string)($_POST['telefone'] ?? $perfilAdmin['telefone'] ?? '')) ?>">
            </div>

            <div class="form-field">
              <label for="morada">Morada</label>
              <input id="morada" name="morada" type="text" value="<?= htmlspecialchars((string)($_POST['morada'] ?? $perfilAdmin['morada'] ?? '')) ?>">
            </div>

            <div class="form-field">
              <label for="foto">Fotografia</label>
              <input id="foto" name="foto" type="file" accept="image/jpeg,image/png,image/webp">
              <div class="photo-help">Formatos permitidos: JPG, PNG ou WebP. Tamanho máximo: 2MB.</div>
            </div>
            <input type="hidden" name="foto_cortada" id="foto_cortada">

            <div class="cropper-shell" id="cropper-shell">
              <div class="cropper-layout">
                <div class="cropper-container">
                  <div class="cropper-stage" id="cropper-stage">
                    <img id="cropper-image" class="cropper-image" alt="Pre-visualizacao da fotografia">
                    <div class="cropper-overlay"></div>
                  </div>
                  <div class="cropper-controls">
                    <button type="button" class="cropper-zoom-btn" id="zoom-out" title="Diminuir zoom">-</button>
                    <button type="button" class="cropper-zoom-btn" id="zoom-in" title="Aumentar zoom">+</button>
                    <button type="button" class="cropper-reset" id="cropper-reset">Repor</button>
                  </div>
                </div>
                <div class="crop-preview-wrap">
                  <span>Preview</span>
                  <div class="crop-preview-frame">
                    <canvas id="crop-preview" class="crop-preview-canvas" width="120" height="120"></canvas>
                  </div>
                </div>
              </div>
              <div class="cropper-tip">Arrasta para movimentar. Usa scroll ou botoes para zoom.</div>
            </div>

            <button type="submit" class="btn-submit">Guardar alteracoes</button>
          </form>
        </div>
      </details>
    </div>

    <div class="card">
      <div class="table-section">
        <h3>Zona de perigo</h3>
        <form method="post" onsubmit="return confirm('Tens a certeza que queres apagar a tua conta de administrador? Esta ação é irreversível.');" class="danger-form">
          <input type="hidden" name="acao" value="apagar_conta">
          <button type="submit" class="btn-submit btn-danger">Apagar a minha conta</button>
        </form>
      </div>
    </div>
  </div>
  <script>
    (function () {
      const fileInput = document.getElementById('foto');
      const hiddenInput = document.getElementById('foto_cortada');
      const form = document.getElementById('perfil-form');
      const shell = document.getElementById('cropper-shell');
      const stage = document.getElementById('cropper-stage');
      const image = document.getElementById('cropper-image');
      const previewCanvas = document.getElementById('crop-preview');
      const zoomOutBtn = document.getElementById('zoom-out');
      const zoomInBtn = document.getElementById('zoom-in');
      const resetBtn = document.getElementById('cropper-reset');

      if (!fileInput || !hiddenInput || !form || !shell || !stage || !image || !previewCanvas || !zoomOutBtn || !zoomInBtn || !resetBtn) {
        return;
      }

      const previewCtx = previewCanvas.getContext('2d');
      if (!previewCtx) {
        return;
      }

      const state = {
        img: null,
        src: '',
        scale: 1,
        minScale: 1,
        x: 0,
        y: 0,
        dragging: false,
        pointerId: null,
        startX: 0,
        startY: 0,
        lastX: 0,
        lastY: 0,
        naturalWidth: 0,
        naturalHeight: 0,
      };

      function clampPosition() {
        const size = stage.clientWidth || 320;
        const drawWidth = state.naturalWidth * state.scale;
        const drawHeight = state.naturalHeight * state.scale;
        const maxX = Math.max(0, (drawWidth - size) / 2);
        const maxY = Math.max(0, (drawHeight - size) / 2);
        state.x = Math.min(maxX, Math.max(-maxX, state.x));
        state.y = Math.min(maxY, Math.max(-maxY, state.y));
      }

      function render() {
        if (!state.img) {
          return;
        }
        clampPosition();
        image.style.width = (state.naturalWidth * state.scale) + 'px';
        image.style.height = (state.naturalHeight * state.scale) + 'px';
        image.style.transform = 'translate(calc(-50% + ' + state.x + 'px), calc(-50% + ' + state.y + 'px))';
        renderPreview();
      }

      function createCropCanvas(outputSize) {
        if (!state.img) {
          return null;
        }

        const stageSize = stage.clientWidth || 320;
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        if (!ctx) {
          return null;
        }

        canvas.width = outputSize;
        canvas.height = outputSize;

        const drawWidth = state.naturalWidth * state.scale;
        const drawHeight = state.naturalHeight * state.scale;
        const sourceX = ((drawWidth / 2) - (stageSize / 2) - state.x) / state.scale;
        const sourceY = ((drawHeight / 2) - (stageSize / 2) - state.y) / state.scale;
        const sourceSize = stageSize / state.scale;

        ctx.drawImage(
          state.img,
          Math.max(0, sourceX),
          Math.max(0, sourceY),
          Math.min(sourceSize, state.naturalWidth),
          Math.min(sourceSize, state.naturalHeight),
          0,
          0,
          outputSize,
          outputSize
        );

        return canvas;
      }

      function renderPreview() {
        previewCtx.clearRect(0, 0, previewCanvas.width, previewCanvas.height);
        const cropped = createCropCanvas(previewCanvas.width);
        if (!cropped) {
          return;
        }
        previewCtx.drawImage(cropped, 0, 0, previewCanvas.width, previewCanvas.height);
      }

      function adjustZoom(delta) {
        if (!state.img) {
          return;
        }
        const nextScale = Math.max(state.minScale, Math.min(state.scale + delta, state.minScale * 3));
        state.scale = nextScale;
        render();
      }

      function resetCrop() {
        if (!state.img) {
          return;
        }
        const size = stage.clientWidth || 320;
        state.minScale = Math.max(size / state.naturalWidth, size / state.naturalHeight);
        state.scale = state.minScale;
        state.x = 0;
        state.y = 0;
        render();
      }

      function loadImage(file) {
        const reader = new FileReader();
        reader.onload = function (event) {
          const src = event.target && typeof event.target.result === 'string' ? event.target.result : '';
          if (!src) {
            return;
          }
          const img = new Image();
          img.onload = function () {
            state.img = img;
            state.src = src;
            state.naturalWidth = img.naturalWidth;
            state.naturalHeight = img.naturalHeight;
            image.src = src;
            image.draggable = false;
            shell.classList.add('is-visible');
            requestAnimationFrame(resetCrop);
          };
          img.src = src;
        };
        reader.readAsDataURL(file);
      }

      function pointerPosition(event) {
        return { x: event.clientX, y: event.clientY };
      }

      fileInput.addEventListener('change', function () {
        hiddenInput.value = '';
        const file = fileInput.files && fileInput.files[0];
        if (!file) {
          shell.classList.remove('is-visible');
          return;
        }
        loadImage(file);
      });

      resetBtn.addEventListener('click', function () {
        resetCrop();
      });

      zoomInBtn.addEventListener('click', function () {
        adjustZoom(0.15);
      });

      zoomOutBtn.addEventListener('click', function () {
        adjustZoom(-0.15);
      });

      stage.addEventListener('wheel', function (event) {
        if (!state.img) {
          return;
        }
        event.preventDefault();
        adjustZoom(event.deltaY < 0 ? 0.08 : -0.08);
      }, { passive: false });

      stage.addEventListener('pointerdown', function (event) {
        if (!state.img) return;
        event.preventDefault();
        const point = pointerPosition(event);
        state.dragging = true;
        state.pointerId = event.pointerId;
        stage.classList.add('is-dragging');
        state.startX = point.x;
        state.startY = point.y;
        state.lastX = state.x;
        state.lastY = state.y;
        stage.setPointerCapture(event.pointerId);
      });

      stage.addEventListener('pointermove', function (event) {
        if (!state.dragging || state.pointerId !== event.pointerId) return;
        event.preventDefault();
        const point = pointerPosition(event);
        state.x = state.lastX + (point.x - state.startX);
        state.y = state.lastY + (point.y - state.startY);
        render();
      });

      function stopDragging(event) {
        if (!state.dragging) return;
        if (event && state.pointerId !== null && event.pointerId !== state.pointerId) return;
        state.dragging = false;
        state.pointerId = null;
        stage.classList.remove('is-dragging');
      }

      stage.addEventListener('pointerup', stopDragging);
      stage.addEventListener('pointercancel', stopDragging);
      stage.addEventListener('lostpointercapture', stopDragging);

      form.addEventListener('submit', function () {
        if (!state.img || !fileInput.files || !fileInput.files[0]) {
          return;
        }
        const cropped = createCropCanvas(400);
        if (!cropped) {
          return;
        }
        hiddenInput.value = cropped.toDataURL('image/jpeg', 0.92);
      });
    }());
  </script>
  <?php render_auto_logout_on_close_script(); ?>
</body>
</html>
