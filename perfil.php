<?php
require_once 'config.php';
require_group(['ALUNO', 'FUNCIONARIO']);

$login = $_SESSION['user']['login'];
$matricula = $login; // usar login como matricula (unico e obrigatorio)
$grupo = $_SESSION['user']['grupo_nome'] ?? 'ALUNO';

function traduz_tipo_utilizador(string $grupo): string {
  return match ($grupo) {
    'ADMIN' => 'Admin',
    'FUNCIONARIO' => 'Funcionario',
    default => 'Aluno',
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

function guardar_foto_upload(array $ficheiro, ?string $antigaPath = null): string {
  if (($ficheiro['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Nao foi possivel carregar a fotografia.');
  }

  if (($ficheiro['size'] ?? 0) > 2 * 1024 * 1024) {
    throw new RuntimeException('A fotografia nao pode ultrapassar 2MB.');
  }

  $tmp = $ficheiro['tmp_name'] ?? '';
  if ($tmp === '' || !is_uploaded_file($tmp)) {
    throw new RuntimeException('Ficheiro de fotografia invalido.');
  }

  $info = @getimagesize($tmp);
  if (!$info || !isset($info['mime'])) {
    throw new RuntimeException('Seleciona uma imagem valida em JPG, PNG ou WebP.');
  }

  $ext = match ($info['mime']) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => '',
  };

  if ($ext === '') {
    throw new RuntimeException('Seleciona uma imagem valida em JPG, PNG ou WebP.');
  }

  $dir = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'perfis';
  if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    throw new RuntimeException('Nao foi possivel preparar a pasta das fotografias.');
  }

  $nome = 'perfil_' . bin2hex(random_bytes(16)) . '.' . $ext;
  $destino = $dir . DIRECTORY_SEPARATOR . $nome;
  if (!move_uploaded_file($tmp, $destino)) {
    throw new RuntimeException('Nao foi possivel guardar a fotografia.');
  }

  if ($antigaPath && $antigaPath !== 'assets/img/perfis/' . $nome) {
    apagar_foto_perfil($antigaPath);
  }

  return 'assets/img/perfis/' . $nome;
}

function guardar_foto_base64(string $imagemBase64, ?string $antigaPath = null): string {
  if (!preg_match('/^data:(image\/(jpeg|png|webp));base64,/', $imagemBase64)) {
    throw new RuntimeException('A imagem recortada e invalida.');
  }

  $dados = base64_decode(substr($imagemBase64, strpos($imagemBase64, ',') + 1), true);
  if ($dados === false) {
    throw new RuntimeException('A imagem recortada e invalida.');
  }

  if (strlen($dados) > 2 * 1024 * 1024) {
    throw new RuntimeException('A fotografia nao pode ultrapassar 2MB.');
  }

  $info = @getimagesizefromstring($dados);
  if (!$info || !isset($info['mime'])) {
    throw new RuntimeException('A imagem recortada e invalida.');
  }

  $ext = match ($info['mime']) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => '',
  };

  if ($ext === '') {
    throw new RuntimeException('Seleciona uma imagem valida em JPG, PNG ou WebP.');
  }

  $dir = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'perfis';
  if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    throw new RuntimeException('Nao foi possivel preparar a pasta das fotografias.');
  }

  $nome = 'perfil_' . bin2hex(random_bytes(16)) . '.' . $ext;
  $destino = $dir . DIRECTORY_SEPARATOR . $nome;
  if (file_put_contents($destino, $dados) === false) {
    throw new RuntimeException('Nao foi possivel guardar a fotografia.');
  }

  if ($antigaPath && $antigaPath !== 'assets/img/perfis/' . $nome) {
    apagar_foto_perfil($antigaPath);
  }

  return 'assets/img/perfis/' . $nome;
}

$stmt = $conn->prepare("SELECT nome, email, telefone, morada, foto_path FROM alunos WHERE login = ? LIMIT 1");
$stmt->bind_param("s", $login);
$stmt->execute();
$perfil = $stmt->get_result()->fetch_assoc() ?: [];

if (empty($perfil['foto_path'])) {
  $stmt = $conn->prepare("SELECT foto_path FROM perfil_pedidos WHERE login = ? AND status = 'APPROVED' LIMIT 1");
  $stmt->bind_param("s", $login);
  $stmt->execute();
  $perfilFotoAprovada = $stmt->get_result()->fetch_assoc();
  if (!empty($perfilFotoAprovada['foto_path'])) {
    $perfil['foto_path'] = $perfilFotoAprovada['foto_path'];
  }
}

$stmt = $conn->prepare("SELECT nome, email, telefone, morada, foto_path, status, obs_nome, obs_telefone, obs_morada, obs_foto, obs_rejeicao FROM perfil_pedidos WHERE login = ? LIMIT 1");
$stmt->bind_param("s", $login);
$stmt->execute();
$pedidoPerfil = $stmt->get_result()->fetch_assoc() ?: [];

$cursosMatriculados = [];
$notasPautas = [];
$cursosAssociadosFuncionario = [];
if ($grupo === 'ALUNO') {
  $stmtMat = $conn->prepare(
    "SELECT c.Nome AS nome_curso, m.data_matricula, COALESCE(m.status, 'APPROVED') AS status
       FROM matriculas m
       JOIN cursos c ON c.ID = m.curso_id
      WHERE m.login = ?
      ORDER BY c.Nome"
  );
  $stmtMat->bind_param('s', $login);
  $stmtMat->execute();
  $cursosMatriculados = $stmtMat->get_result()->fetch_all(MYSQLI_ASSOC);

  $stmtPN = $conn->prepare(
    "SELECT pn.pauta_id, pn.nota AS nota_final, pn.observacao AS obs_final,
            p.ano_letivo, p.semestre, p.epoca, c.Nome AS nome_curso
       FROM pauta_notas pn
       JOIN pautas p ON p.pauta_id = pn.pauta_id
       JOIN cursos c ON c.ID = p.curso_id
      WHERE pn.login = ?
      ORDER BY p.ano_letivo DESC, p.semestre, c.Nome, p.epoca"
  );
  $stmtPN->bind_param('s', $login);
  $stmtPN->execute();
  $pautasRows = $stmtPN->get_result()->fetch_all(MYSQLI_ASSOC);

  $stmtPND = $conn->prepare(
    "SELECT pnd.pauta_id, d.Nome_disc, pnd.notas_json, pnd.media AS media_disc
       FROM pauta_notas_disciplinas pnd
       JOIN disciplinas d ON d.ID = pnd.disciplina_id
      WHERE pnd.login = ?
      ORDER BY pnd.pauta_id, d.Nome_disc"
  );
  $stmtPND->bind_param('s', $login);
  $stmtPND->execute();
  $disciplinasRows = $stmtPND->get_result()->fetch_all(MYSQLI_ASSOC);

  $discByPauta = [];
  foreach ($disciplinasRows as $dr) {
    $pid = (int)$dr['pauta_id'];
    $notas = [];
    if (!empty($dr['notas_json'])) {
      $dec = json_decode((string)$dr['notas_json'], true);
      if (is_array($dec)) {
        foreach ($dec as $n) {
          if (is_numeric($n)) {
            $notas[] = round((float)$n, 1);
          }
        }
      }
    }
    $discByPauta[$pid][] = [
      'nome' => $dr['Nome_disc'],
      'notas' => $notas,
      'media' => $dr['media_disc'] !== null ? (float)$dr['media_disc'] : null,
    ];
  }

  foreach ($pautasRows as $pr) {
    $pid = (int)$pr['pauta_id'];
    $notasPautas[] = [
      'pauta_id' => $pid,
      'nome_curso' => $pr['nome_curso'],
      'ano_letivo' => $pr['ano_letivo'],
      'semestre' => (int)$pr['semestre'],
      'epoca' => $pr['epoca'],
      'nota_final' => $pr['nota_final'] !== null ? (float)$pr['nota_final'] : null,
      'obs_final' => $pr['obs_final'],
      'disciplinas' => $discByPauta[$pid] ?? [],
    ];
  }
} elseif ($grupo === 'FUNCIONARIO') {
  $stmtCF = $conn->prepare(
    "SELECT c.ID AS curso_id,
            c.Nome AS nome_curso,
            MAX(x.registado_em) AS ultima_edicao,
            COUNT(*) AS total_registos
       FROM (
             SELECT p.curso_id, pn.registado_em
               FROM pauta_notas pn
               JOIN pautas p ON p.pauta_id = pn.pauta_id
              WHERE pn.registado_por = ?
             UNION ALL
             SELECT p.curso_id, pnd.registado_em
               FROM pauta_notas_disciplinas pnd
               JOIN pautas p ON p.pauta_id = pnd.pauta_id
              WHERE pnd.registado_por = ?
       ) x
       JOIN cursos c ON c.ID = x.curso_id
      GROUP BY c.ID, c.Nome
      ORDER BY ultima_edicao DESC, c.Nome"
  );
  $stmtCF->bind_param('ss', $login, $login);
  $stmtCF->execute();
  $cursosAssociadosFuncionario = $stmtCF->get_result()->fetch_all(MYSQLI_ASSOC);
}

$erro = null;
$ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nome = trim((string)($_POST['nome'] ?? ''));
  $telefone = trim((string)($_POST['telefone'] ?? ''));
  $morada = trim((string)($_POST['morada'] ?? ''));
  $email = trim((string)($pedidoPerfil['email'] ?? $perfil['email'] ?? ''));

  $fotoPath = $pedidoPerfil['foto_path'] ?? $perfil['foto_path'] ?? null;
  $fotoAnteriorPedido = (($pedidoPerfil['status'] ?? '') !== 'APPROVED') ? ($pedidoPerfil['foto_path'] ?? null) : null;
  $fotoCortada = trim($_POST['foto_cortada'] ?? '');
  $temUploadFoto = isset($_FILES['foto']) && (int)($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

  $telefoneNorm = preg_replace('/\s+/', '', $telefone) ?: null;
  if ($email === '') {
    $email = null;
  }

  if ($nome === '') {
    $erro = 'O nome e obrigatorio.';
  }

  if (!$erro && $telefoneNorm !== null && !preg_match('/^[0-9+\-()]{6,20}$/', $telefoneNorm)) {
    $erro = 'Telemovel invalido.';
  }

  if (!$erro && $telefoneNorm !== null) {
    $stmt = $conn->prepare("SELECT 1 FROM alunos WHERE telefone = ? AND login <> ? LIMIT 1");
    $stmt->bind_param('ss', $telefoneNorm, $login);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
      $erro = 'Esse telemovel ja esta registado noutro utilizador.';
    }
  }

  if (!$erro) {
    try {
      if ($fotoCortada !== '') {
        $fotoPath = guardar_foto_base64($fotoCortada, $fotoAnteriorPedido);
      } elseif ($temUploadFoto) {
        $fotoPath = guardar_foto_upload($_FILES['foto'], $fotoAnteriorPedido);
      }

      $status = 'PENDING';
      $stmt = $conn->prepare(
        "INSERT INTO perfil_pedidos (login, nome, email, telefone, morada, foto_path, status, requested_at, reviewed_by, reviewed_at,
                                    obs_nome, obs_telefone, obs_morada, obs_foto, obs_rejeicao)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NULL, NULL, NULL, NULL, NULL, NULL, NULL)
         ON DUPLICATE KEY UPDATE
           nome = VALUES(nome),
           email = VALUES(email),
           telefone = VALUES(telefone),
           morada = VALUES(morada),
           foto_path = VALUES(foto_path),
           status = 'PENDING',
           requested_at = NOW(),
           reviewed_by = NULL,
           reviewed_at = NULL,
           obs_nome = NULL,
           obs_telefone = NULL,
           obs_morada = NULL,
           obs_foto = NULL,
           obs_rejeicao = NULL"
      );
      $stmt->bind_param('sssssss', $login, $nome, $email, $telefoneNorm, $morada, $fotoPath, $status);
      $stmt->execute();
      $ok = 'Pedido de alteracao enviado. Aguarda aprovacao do administrador.';
    } catch (RuntimeException $e) {
      $erro = $e->getMessage();
    } catch (mysqli_sql_exception $e) {
      if ($e->getCode() === 1062) {
        $erro = 'Telemovel ja esta registado noutro utilizador.';
      } else {
        throw $e;
      }
    }

    $stmt = $conn->prepare("SELECT nome, email, telefone, morada, foto_path FROM alunos WHERE login = ? LIMIT 1");
    $stmt->bind_param('s', $login);
    $stmt->execute();
    $perfil = $stmt->get_result()->fetch_assoc() ?: [];

    $stmt = $conn->prepare("SELECT nome, email, telefone, morada, foto_path, status, obs_nome, obs_telefone, obs_morada, obs_foto, obs_rejeicao FROM perfil_pedidos WHERE login = ? LIMIT 1");
    $stmt->bind_param('s', $login);
    $stmt->execute();
    $pedidoPerfil = $stmt->get_result()->fetch_assoc() ?: [];
  }
}
?>
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Os meus dados</title>
  <style><?php include __DIR__ . '/assets/css/theme.css'; ?></style>
</head>
<body class='page-perfil'>
  <div class="container">
    <div class="header">
      <div class="header-left">
        <a href="index.php" aria-label="Ir para o menu principal">
          <img class="logo" src="<?= htmlspecialchars(logo_asset_path()) ?>" alt="Logotipo da escola">
        </a>
        <h2>Perfil</h2>
      </div>
      <a href="index.php" class="back-btn">Voltar</a>
    </div>

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
        <strong>Tipo de utilizador:</strong> <?= htmlspecialchars(traduz_tipo_utilizador($grupo)) ?><br>
        <strong>Utilizador:</strong> <?= htmlspecialchars($_SESSION['user']['login']) ?>
      </div>
    </div>

    <?php if ($grupo === 'ALUNO'): ?>
    <div class="card">
      <div class="table-section">
        <h3>Cursos Matriculados</h3>
        <?php if (empty($cursosMatriculados)): ?>
          <p class="empty-state">Nao tens cursos matriculados.</p>
        <?php else: ?>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Curso</th>
                  <th>Data de Matricula</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($cursosMatriculados as $cm): ?>
                <?php
                  $st = strtoupper((string)($cm['status'] ?? 'PENDING'));
                  $badgeCls = match ($st) {
                    'APPROVED' => 'badge-approved',
                    'REJECTED' => 'badge-rejected',
                    default => 'badge-pending',
                  };
                  $stLabel = match ($st) {
                    'APPROVED' => 'Aprovado',
                    'REJECTED' => 'Recusado',
                    default => 'Pendente',
                  };
                ?>
                <tr>
                  <td><?= htmlspecialchars($cm['nome_curso']) ?></td>
                  <td><?= !empty($cm['data_matricula']) ? date('d/m/Y', strtotime($cm['data_matricula'])) : '-' ?></td>
                  <td><span class="badge <?= $badgeCls ?>"><?= $stLabel ?></span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="table-section">
        <h3>Notas</h3>
        <?php if (empty($notasPautas)): ?>
          <p class="empty-state">Ainda nao tens notas registadas.</p>
        <?php else: ?>
          <?php foreach ($notasPautas as $np): ?>
          <?php
            $notaFinal = $np['nota_final'];
            $notaBadgeCls = $notaFinal === null ? 'badge-pending' : ($notaFinal >= 10 ? 'badge-approved' : 'badge-rejected');
            $notaLabel = $notaFinal === null ? 'S/N' : number_format($notaFinal, 1);
            $semLabel = $np['semestre'] === 2 ? '2.o Semestre' : '1.o Semestre';
          ?>
          <div class="perfil-pauta-card">
            <div class="perfil-pauta-header">
              <div>
                <div class="perfil-pauta-curso"><?= htmlspecialchars($np['nome_curso']) ?></div>
                <div class="perfil-pauta-meta"><?= htmlspecialchars($np['ano_letivo']) ?> - <?= $semLabel ?> - <?= htmlspecialchars($np['epoca']) ?></div>
              </div>
              <div class="perfil-pauta-nota-wrap">
                <span class="perfil-nota-label">Nota Final</span>
                <span class="badge <?= $notaBadgeCls ?>" style="font-size:14px;padding:5px 14px;"><?= $notaLabel ?></span>
              </div>
            </div>
            <?php if (!empty($np['disciplinas'])): ?>
            <div class="perfil-pauta-body">
              <table class="disciplinas-table">
                <thead>
                  <tr>
                    <th>Disciplina</th>
                    <th>Notas</th>
                    <th>Media</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($np['disciplinas'] as $disc): ?>
                  <tr>
                    <td><?= htmlspecialchars($disc['nome']) ?></td>
                    <td>
                      <?php if (empty($disc['notas'])): ?>
                        <span style="color:#94a3b8;font-size:12px;">-</span>
                      <?php else: ?>
                        <?php foreach ($disc['notas'] as $n): ?>
                          <span class="nota-chip"><?= number_format($n, 1) ?></span>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($disc['media'] === null): ?>
                        <span style="color:#94a3b8;font-size:12px;">-</span>
                      <?php else: ?>
                        <span class="badge <?= $disc['media'] >= 10 ? 'badge-approved' : 'badge-rejected' ?>"><?= number_format($disc['media'], 1) ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php else: ?>
            <div class="perfil-pauta-empty">Sem notas por disciplina registadas.</div>
            <?php endif; ?>
            <?php if (!empty($np['obs_final'])): ?>
            <div class="perfil-pauta-obs"><strong>Observacao:</strong> <?= htmlspecialchars($np['obs_final']) ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($grupo === 'FUNCIONARIO'): ?>
    <div class="card">
      <div class="table-section">
        <h3>Cursos Geridos</h3>
        <?php if (empty($cursosAssociadosFuncionario)): ?>
          <p class="empty-state">Ainda nao editaste notas em nenhum curso.</p>
        <?php else: ?>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Curso</th>
                  <th>Ultima Edicao</th>
                  <th>Registos</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($cursosAssociadosFuncionario as $cursoFunc): ?>
                <?php $cursoHref = 'pautas.php?curso_id=' . (int)($cursoFunc['curso_id'] ?? 0); ?>
                <tr class="clickable-row" tabindex="0" role="link"
                    data-href="<?= htmlspecialchars($cursoHref) ?>"
                    onclick="window.location.href=this.dataset.href"
                    onkeydown="if(event.key==='Enter' || event.key===' '){ event.preventDefault(); window.location.href=this.dataset.href; }"
                    style="cursor:pointer;">
                  <td><?= htmlspecialchars((string)$cursoFunc['nome_curso']) ?></td>
                  <td>
                    <?php if (!empty($cursoFunc['ultima_edicao'])): ?>
                      <?= date('d/m/Y H:i', strtotime((string)$cursoFunc['ultima_edicao'])) ?>
                    <?php else: ?>
                      -
                    <?php endif; ?>
                  </td>
                  <td><?= (int)($cursoFunc['total_registos'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php $pedidoStatus = strtoupper($pedidoPerfil['status'] ?? ''); ?>
    <?php $abrirEdicao = !empty($erro) || !empty($ok) || $pedidoStatus === 'REJECTED'; ?>
    <div class="card">
      <details class="profile-edit-details" <?= $abrirEdicao ? 'open' : '' ?>>
        <summary class="profile-edit-summary">Editar Perfil</summary>
        <div class="form-section" style="margin-top:14px;">
          <?php if ($erro): ?><div class="err"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
          <?php if ($ok): ?><div class="ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

          <?php if ($pedidoStatus === 'PENDING'): ?>
            <div class="ok">Tens um pedido de alteracao pendente de aprovacao.</div>
          <?php elseif ($pedidoStatus === 'REJECTED'): ?>
            <div class="err">
              O ultimo pedido de alteracao foi recusado. Podes corrigir e submeter novamente.
              <?php if (!empty($pedidoPerfil['obs_rejeicao'])): ?>
                <br><strong>Motivo:</strong> <?= htmlspecialchars($pedidoPerfil['obs_rejeicao']) ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <form method="post" enctype="multipart/form-data" id="perfil-form">
            <?php $isRejected = $pedidoStatus === 'REJECTED'; ?>
            <?php $prefNome = htmlspecialchars($_POST['nome'] ?? $pedidoPerfil['nome'] ?? $perfil['nome'] ?? ''); ?>
            <?php $prefTel = htmlspecialchars($_POST['telefone'] ?? $pedidoPerfil['telefone'] ?? $perfil['telefone'] ?? ''); ?>
            <?php $prefMorada = htmlspecialchars($_POST['morada'] ?? $pedidoPerfil['morada'] ?? $perfil['morada'] ?? ''); ?>

            <div class="form-field">
              <label for="nome">Nome *</label>
              <?php if ($isRejected && !empty($pedidoPerfil['obs_nome'])): ?>
                <div class="field-obs-notice"><?= htmlspecialchars($pedidoPerfil['obs_nome']) ?></div>
              <?php endif; ?>
              <input id="nome" name="nome" type="text" required value="<?= $prefNome ?>">
            </div>

            <div class="form-field">
              <label for="telefone">Telemovel</label>
              <?php if ($isRejected && !empty($pedidoPerfil['obs_telefone'])): ?>
                <div class="field-obs-notice"><?= htmlspecialchars($pedidoPerfil['obs_telefone']) ?></div>
              <?php endif; ?>
              <input id="telefone" name="telefone" type="tel" value="<?= $prefTel ?>">
            </div>

            <div class="form-field">
              <label for="morada">Morada</label>
              <?php if ($isRejected && !empty($pedidoPerfil['obs_morada'])): ?>
                <div class="field-obs-notice"><?= htmlspecialchars($pedidoPerfil['obs_morada']) ?></div>
              <?php endif; ?>
              <input id="morada" name="morada" type="text" value="<?= $prefMorada ?>">
            </div>

            <div class="form-field">
              <label for="foto">Fotografia (max. 2MB)</label>
              <?php if ($isRejected && !empty($pedidoPerfil['obs_foto'])): ?>
                <div class="field-obs-notice"><?= htmlspecialchars($pedidoPerfil['obs_foto']) ?></div>
              <?php endif; ?>
              <input id="foto" name="foto" type="file" accept="image/jpeg,image/png,image/webp">
              <div class="photo-help">JPG, PNG, WebP. Arrasta para ajustar.</div>
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
                    <button type="button" class="cropper-zoom-btn" id="zoom-out" title="Diminuir zoom">−</button>
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
