<?php
require_once 'config.php';

// Se quiseres que qualquer pessoa possa registar-se, deixa assim.
// Se quiseres que só ADMIN possa criar alunos, troca por:
// require_group(['ADMIN']);

function guardar_foto_registo(array $ficheiro): string {
  if (($ficheiro['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Para registar aluno, tens de enviar uma fotografia.');
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

  return 'assets/img/perfis/' . $nome;
}

function guardar_foto_registo_base64(string $imagemBase64): string {
  if (!preg_match('/^data:(image\/(jpeg|png|webp));base64,/', $imagemBase64)) {
    throw new RuntimeException('A imagem final e invalida.');
  }

  $dados = base64_decode(substr($imagemBase64, strpos($imagemBase64, ',') + 1), true);
  if ($dados === false) {
    throw new RuntimeException('A imagem final e invalida.');
  }

  if (strlen($dados) > 2 * 1024 * 1024) {
    throw new RuntimeException('A fotografia final nao pode ultrapassar 2MB.');
  }

  $info = @getimagesizefromstring($dados);
  if (!$info || !isset($info['mime'])) {
    throw new RuntimeException('A imagem final e invalida.');
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
    throw new RuntimeException('Nao foi possivel guardar a fotografia final.');
  }

  return 'assets/img/perfis/' . $nome;
}

function apagar_foto_registo(?string $path): void {
  if (!$path || !str_starts_with($path, 'assets/img/perfis/')) {
    return;
  }

  $abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
  if (is_file($abs)) {
    @unlink($abs);
  }
}

$erro = null;
$sucesso = null;
$tipoConta = trim($_POST['tipo_conta'] ?? 'ALUNO');

$nomeAluno = trim($_POST['nome_aluno'] ?? '');
$apelidoAluno = trim($_POST['apelido_aluno'] ?? '');
$emailAluno = trim($_POST['email_aluno'] ?? '');
$telefoneAluno = trim($_POST['telefone_aluno'] ?? '');
$moradaAluno = trim($_POST['morada_aluno'] ?? '');
$cursoAlunoId = (int)($_POST['curso_aluno_id'] ?? 0);

$cursosDisponiveis = [];
$resCursos = $conn->query("SELECT ID, Nome FROM cursos ORDER BY Nome");
if ($resCursos instanceof mysqli_result) {
  while ($rowCurso = $resCursos->fetch_assoc()) {
    $cursosDisponiveis[] = [
      'ID' => (int)($rowCurso['ID'] ?? 0),
      'Nome' => (string)($rowCurso['Nome'] ?? ''),
    ];
  }
}

$matriculasTemStatus = false;
$colMatriculasStatus = $conn->query("SHOW COLUMNS FROM matriculas LIKE 'status'");
if ($colMatriculasStatus instanceof mysqli_result && $colMatriculasStatus->fetch_assoc()) {
  $matriculasTemStatus = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $pwd1  = (string)($_POST['pwd1'] ?? '');
    $pwd2  = (string)($_POST['pwd2'] ?? '');
  $tipoConta = trim($_POST['tipo_conta'] ?? 'ALUNO');

  $nomeAluno = trim($_POST['nome_aluno'] ?? '');
  $apelidoAluno = trim($_POST['apelido_aluno'] ?? '');
  $emailAluno = trim($_POST['email_aluno'] ?? '');
  $telefoneAluno = trim($_POST['telefone_aluno'] ?? '');
  $moradaAluno = trim($_POST['morada_aluno'] ?? '');
  $cursoAlunoId = (int)($_POST['curso_aluno_id'] ?? 0);

  $fotoAluno = $_FILES['foto_aluno'] ?? null;
  $fotoAlunoCortada = trim((string)($_POST['foto_aluno_cortada'] ?? ''));

  if ($login === '' || $pwd1 === '' || $pwd2 === '' || $tipoConta === '') {
        $erro = "Preenche todos os campos.";
  } elseif (!in_array($tipoConta, ['ALUNO', 'FUNCIONARIO'], true)) {
    $erro = "Tipo de conta inválido.";
  } elseif ($tipoConta === 'ALUNO' && ($nomeAluno === '' || $apelidoAluno === '' || $emailAluno === '' || $telefoneAluno === '' || $moradaAluno === '')) {
    $erro = "Para contas de aluno, nome, apelido, email, telemóvel e morada são obrigatórios.";
  } elseif ($tipoConta === 'ALUNO' && $cursoAlunoId <= 0) {
    $erro = "Para contas de aluno, seleciona o curso em que te queres inscrever.";
  } elseif ($tipoConta === 'ALUNO' && (($fotoAluno['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) && $fotoAlunoCortada === '') {
    $erro = "Para concluir o registo de aluno, adiciona uma fotografia.";
  } elseif ($tipoConta === 'ALUNO' && !filter_var($emailAluno, FILTER_VALIDATE_EMAIL)) {
    $erro = "Email inválido.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $login)) {
        $erro = "Login inválido. Usa 3-20 caracteres: letras, números ou underscore (_).";
    } elseif ($pwd1 !== $pwd2) {
        $erro = "As passwords não coincidem.";
    } elseif (strlen($pwd1) < 4) {
        $erro = "Password muito curta (mínimo 4).";
    } else {
        // Verificar se já existe
        $stmt = $conn->prepare("SELECT 1 FROM users WHERE login = ? LIMIT 1");
        $stmt->bind_param("s", $login);
        $stmt->execute();
        $existe = $stmt->get_result()->num_rows > 0;

        if ($existe) {
            $erro = "Esse utilizador já existe.";
        } else {
          $stmt = $conn->prepare("SELECT ID FROM grupos WHERE GRUPO = ? LIMIT 1");
          $stmt->bind_param("s", $tipoConta);
          $stmt->execute();
          $grupo = $stmt->get_result()->fetch_assoc();

          if (!$grupo) {
            $erro = "O tipo de conta selecionado não existe na base de dados.";
          } else {
            if ($tipoConta === 'ALUNO') {
              $cursoValido = false;
              foreach ($cursosDisponiveis as $cursoDisponivel) {
                if ((int)$cursoDisponivel['ID'] === $cursoAlunoId) {
                  $cursoValido = true;
                  break;
                }
              }

              if (!$cursoValido) {
                $erro = "O curso selecionado não é válido.";
              }
            }

            if ($tipoConta === 'ALUNO' && !$erro) {
              $stmt = $conn->prepare("SELECT 1 FROM alunos WHERE email = ? LIMIT 1");
              $stmt->bind_param("s", $emailAluno);
              $stmt->execute();
              if ($stmt->get_result()->num_rows > 0) {
                $erro = "Esse email já está registado noutro aluno.";
              } else {
                $stmt = $conn->prepare("SELECT 1 FROM alunos WHERE telefone = ? LIMIT 1");
                $stmt->bind_param("s", $telefoneAluno);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                  $erro = "Esse telemóvel já está registado noutro aluno.";
                }
              }
            }

            if (!$erro) {
              $hash = md5($pwd1);
              $status = 'PENDING';
              $grupoId = (int)$grupo['ID'];
              $fotoAlunoPath = null;

              if ($tipoConta === 'ALUNO') {
                try {
                  if ($fotoAlunoCortada !== '') {
                    $fotoAlunoPath = guardar_foto_registo_base64($fotoAlunoCortada);
                  } else {
                    $fotoAlunoPath = guardar_foto_registo((array)$fotoAluno);
                  }
                } catch (Throwable $t) {
                  $erro = $t->getMessage();
                }
              }

              if ($erro) {
                // sem acao: erro de upload/validacao antes da transacao
              } else {

                $conn->begin_transaction();
                try {
                  $stmt = $conn->prepare("INSERT INTO users (login, pwd, grupo, approval_status) VALUES (?, ?, ?, ?)");
                  $stmt->bind_param("ssis", $login, $hash, $grupoId, $status);
                  $stmt->execute();

                  if ($tipoConta === 'ALUNO') {
                    $nomeCompletoAluno = trim($nomeAluno . ' ' . $apelidoAluno);
                    $matricula = $login;
                    $stmt = $conn->prepare(
                      "INSERT INTO alunos (login, matricula, nome, email, telefone, morada, foto_path)
                       VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param("sssssss", $login, $matricula, $nomeCompletoAluno, $emailAluno, $telefoneAluno, $moradaAluno, $fotoAlunoPath);
                    $stmt->execute();

                    if ($matriculasTemStatus) {
                      $stmt = $conn->prepare("INSERT INTO matriculas (login, curso_id, status) VALUES (?, ?, 'PENDING')");
                      $stmt->bind_param("si", $login, $cursoAlunoId);
                      $stmt->execute();
                    } else {
                      $stmt = $conn->prepare("INSERT INTO matriculas (login, curso_id) VALUES (?, ?)");
                      $stmt->bind_param("si", $login, $cursoAlunoId);
                      $stmt->execute();
                    }
                  }

                  $conn->commit();
                  $sucesso = "Conta criada com sucesso. Aguarda aprovação dos serviços académicos antes de fazer login.";
                  $tipoConta = 'ALUNO';
                  $nomeAluno = '';
                  $apelidoAluno = '';
                  $emailAluno = '';
                  $telefoneAluno = '';
                  $moradaAluno = '';
                  $cursoAlunoId = 0;
                } catch (mysqli_sql_exception $e) {
                  $conn->rollback();
                  if ($fotoAlunoPath !== null) {
                    apagar_foto_registo($fotoAlunoPath);
                  }

                  if ($e->getCode() === 1062) {
                    $erro = "Não foi possível criar a conta porque já existe um registo com esses dados.";
                  } else {
                    throw $e;
                  }
                }
              }
            }
          }
        }
    }
}
?>
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <title>Criar Conta</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style><?php include __DIR__ . '/assets/css/theme.css'; ?></style>
</head>
<body class='page-registar'>
  <div class="container">
    <div class="card">
      <h2>Criar Conta</h2>

      <?php if ($erro): ?><div class="err"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
      <?php if ($sucesso): ?><div class="ok"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>

      <form method="post" enctype="multipart/form-data">
        <label for="tipo_conta">Tipo de conta</label>
        <select id="tipo_conta" name="tipo_conta" required>
          <option value="ALUNO" <?= $tipoConta === 'ALUNO' ? 'selected' : '' ?>>Aluno</option>
          <option value="FUNCIONARIO" <?= $tipoConta === 'FUNCIONARIO' ? 'selected' : '' ?>>Funcionário</option>
        </select>

        <div id="dados-aluno">
          <label for="nome_aluno">Nome</label>
          <input id="nome_aluno" name="nome_aluno" value="<?= htmlspecialchars($nomeAluno) ?>">

          <label for="apelido_aluno">Apelido</label>
          <input id="apelido_aluno" name="apelido_aluno" value="<?= htmlspecialchars($apelidoAluno) ?>">

          <label for="email_aluno">Email</label>
          <input id="email_aluno" name="email_aluno" type="email" value="<?= htmlspecialchars($emailAluno) ?>">

          <label for="telefone_aluno">Telemóvel</label>
          <input id="telefone_aluno" name="telefone_aluno" type="tel" value="<?= htmlspecialchars($telefoneAluno) ?>">

          <label for="morada_aluno">Morada</label>
          <input id="morada_aluno" name="morada_aluno" value="<?= htmlspecialchars($moradaAluno) ?>">

          <label for="curso_aluno_id">Curso pretendido</label>
          <select id="curso_aluno_id" name="curso_aluno_id">
            <option value="" disabled hidden <?= $cursoAlunoId <= 0 ? 'selected' : '' ?>>Seleciona um curso</option>
            <?php foreach ($cursosDisponiveis as $curso): ?>
              <option value="<?= (int)$curso['ID'] ?>" <?= $cursoAlunoId === (int)$curso['ID'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($curso['Nome']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label for="foto_aluno">Fotografia</label>
          <input id="foto_aluno" class="file-native-input" name="foto_aluno" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="foto_aluno_help foto_aluno_nome">
          <div class="file-picker">
            <label for="foto_aluno" class="file-picker-btn">Escolher ficheiro</label>
            <span id="foto_aluno_nome" class="file-picker-name" aria-live="polite">Nenhum ficheiro selecionado</span>
          </div>
          <input id="foto_aluno_cortada" name="foto_aluno_cortada" type="hidden" value="">
          <div id="foto_preview_wrap" class="foto-preview-wrap" hidden>
            <canvas id="foto_preview_canvas" class="foto-preview-canvas" width="96" height="96" aria-label="Pré-visualização da fotografia final"></canvas>
            <div class="foto-preview-controls">
              <p class="foto-preview-tip">Pré-visualizaçãoda foto de perfil. Arrasta para ajustar e usa a roda do rato para zoom.</p>

              <button id="foto_reset" type="button" class="action-btn action-secondary">Repor enquadramento</button>
            </div>
          </div>
          <small id="foto_aluno_help" style="display:block;margin-top:4px;font-size:10px;color:#64748b;">Formatos permitidos: JPG, PNG ou WebP. Tamanho máximo: 2MB.</small>
        </div>

        <label for="login">Login</label>
        <input id="login" name="login" required value="<?= htmlspecialchars($_POST['login'] ?? '') ?>">

        <label for="pwd1">Password</label>
        <input id="pwd1" name="pwd1" type="password" required>

        <label for="pwd2">Repetir password</label>
        <input id="pwd2" name="pwd2" type="password" required>

        <button type="submit">Criar conta</button>
      </form>

      <p class="link">
        <a href="login.php">Voltar ao login</a>
      </p>
    </div>
  </div>
</body>
<script>
  (function () {
    const tipoConta = document.getElementById('tipo_conta');
    const blocoAluno = document.getElementById('dados-aluno');
    const nome = document.getElementById('nome_aluno');
    const apelido = document.getElementById('apelido_aluno');
    const email = document.getElementById('email_aluno');
    const telefone = document.getElementById('telefone_aluno');
    const morada = document.getElementById('morada_aluno');
    const curso = document.getElementById('curso_aluno_id');
    const foto = document.getElementById('foto_aluno');
    const fotoNome = document.getElementById('foto_aluno_nome');
    const fotoCortada = document.getElementById('foto_aluno_cortada');
    const fotoPreviewWrap = document.getElementById('foto_preview_wrap');
    const fotoPreviewCanvas = document.getElementById('foto_preview_canvas');
    const fotoReset = document.getElementById('foto_reset');
    const fotoExportCanvas = document.createElement('canvas');
    fotoExportCanvas.width = 600;
    fotoExportCanvas.height = 600;

    let imagemSelecionada = null;
    let zoom = 100;
    let posX = 0;
    let posY = 0;
    let aArrastar = false;
    let startDragX = 0;
    let startDragY = 0;
    let startPosX = 0;
    let startPosY = 0;

    if (!tipoConta || !blocoAluno || !nome || !apelido || !email || !telefone || !morada || !curso || !foto || !fotoNome || !fotoCortada || !fotoPreviewWrap || !fotoPreviewCanvas || !fotoReset) {
      return;
    }

    function clamp(valor, min, max) {
      return Math.min(max, Math.max(min, valor));
    }

    function atualizarCamposAluno() {
      const isAluno = tipoConta.value === 'ALUNO';
      blocoAluno.style.display = isAluno ? 'block' : 'none';
      nome.required = isAluno;
      apelido.required = isAluno;
      email.required = isAluno;
      telefone.required = isAluno;
      morada.required = isAluno;
      curso.required = isAluno;
      foto.required = isAluno;
    }

    function resetarAjustesFoto() {
      zoom = 100;
      posX = 0;
      posY = 0;
    }

    function calcularEnquadramento(tamanho) {
      const largura = imagemSelecionada.naturalWidth || imagemSelecionada.width;
      const altura = imagemSelecionada.naturalHeight || imagemSelecionada.height;
      const baseScale = Math.max(tamanho / largura, tamanho / altura);
      const escala = baseScale * (zoom / 100);
      const drawW = largura * escala;
      const drawH = altura * escala;

      const limiteX = Math.max(0, (drawW - tamanho) / 2);
      const limiteY = Math.max(0, (drawH - tamanho) / 2);
      const deslocX = (posX / 100) * limiteX;
      const deslocY = (posY / 100) * limiteY;

      const dx = (tamanho - drawW) / 2 + deslocX;
      const dy = (tamanho - drawH) / 2 + deslocY;

      return { dx, dy, drawW, drawH };
    }

    function desenharCanvas(canvas) {
      const ctx = canvas.getContext('2d');
      if (!ctx || !imagemSelecionada) {
        return;
      }

      const size = canvas.width;
      const frame = calcularEnquadramento(size);

      ctx.clearRect(0, 0, size, size);
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, size, size);
      ctx.drawImage(imagemSelecionada, frame.dx, frame.dy, frame.drawW, frame.drawH);
    }

    function atualizarPreVisualizacaoFinal() {
      if (!imagemSelecionada) {
        fotoPreviewWrap.hidden = true;
        fotoCortada.value = '';
        return;
      }

      fotoPreviewWrap.hidden = false;
      desenharCanvas(fotoPreviewCanvas);
      desenharCanvas(fotoExportCanvas);
      fotoCortada.value = fotoExportCanvas.toDataURL('image/jpeg', 0.92);
    }

    function atualizarNomeFicheiro() {
      if (foto.files && foto.files.length > 0) {
        fotoNome.textContent = foto.files[0].name;
      } else {
        fotoNome.textContent = 'Nenhum ficheiro selecionado';
      }
    }

    function carregarImagemSelecionada() {
      if (!foto.files || foto.files.length === 0) {
        imagemSelecionada = null;
        fotoCortada.value = '';
        fotoPreviewWrap.hidden = true;
        return;
      }

      const ficheiro = foto.files[0];
      if (!/^image\/(jpeg|png|webp)$/i.test(ficheiro.type)) {
        imagemSelecionada = null;
        fotoCortada.value = '';
        fotoPreviewWrap.hidden = true;
        return;
      }

      const reader = new FileReader();
      reader.onload = function () {
        const img = new Image();
        img.onload = function () {
          imagemSelecionada = img;
          resetarAjustesFoto();
          atualizarPreVisualizacaoFinal();
        };
        img.src = String(reader.result || '');
      };
      reader.readAsDataURL(ficheiro);
    }

    tipoConta.addEventListener('change', atualizarCamposAluno);
    foto.addEventListener('change', function () {
      atualizarNomeFicheiro();
      carregarImagemSelecionada();
    });
    fotoReset.addEventListener('click', function () {
      resetarAjustesFoto();
      atualizarPreVisualizacaoFinal();
    });

    fotoPreviewCanvas.addEventListener('pointerdown', function (event) {
      if (!imagemSelecionada) {
        return;
      }

      aArrastar = true;
      startDragX = event.clientX;
      startDragY = event.clientY;
      startPosX = posX;
      startPosY = posY;
      fotoPreviewCanvas.classList.add('is-dragging');
      try {
        fotoPreviewCanvas.setPointerCapture(event.pointerId);
      } catch (e) {
        // Ignorar quando não suportado.
      }
    });

    fotoPreviewCanvas.addEventListener('pointermove', function (event) {
      if (!aArrastar || !imagemSelecionada) {
        return;
      }

      const rect = fotoPreviewCanvas.getBoundingClientRect();
      const sensX = rect.width > 0 ? (event.clientX - startDragX) / rect.width : 0;
      const sensY = rect.height > 0 ? (event.clientY - startDragY) / rect.height : 0;
      posX = Math.round(clamp(startPosX + sensX * 200, -100, 100));
      posY = Math.round(clamp(startPosY + sensY * 200, -100, 100));
      atualizarPreVisualizacaoFinal();
    });

    function terminarArrasto() {
      aArrastar = false;
      fotoPreviewCanvas.classList.remove('is-dragging');
    }

    fotoPreviewCanvas.addEventListener('pointerup', terminarArrasto);
    fotoPreviewCanvas.addEventListener('pointercancel', terminarArrasto);
    fotoPreviewCanvas.addEventListener('pointerleave', function () {
      if (aArrastar) {
        terminarArrasto();
      }
    });

    fotoPreviewCanvas.addEventListener('wheel', function (event) {
      if (!imagemSelecionada) {
        return;
      }

      event.preventDefault();
      const delta = event.deltaY < 0 ? 4 : -4;
      zoom = clamp(zoom + delta, 100, 250);
      atualizarPreVisualizacaoFinal();
    }, { passive: false });

    atualizarCamposAluno();
    atualizarNomeFicheiro();
    fotoPreviewWrap.hidden = true;
  }());
</script>
<?php render_back_to_top_script(); ?>
</html>



