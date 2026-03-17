<?php
require_once 'config.php';
require_group(['ADMIN', 'FUNCIONARIO', 'ALUNO']);

$login = $_SESSION['user']['login'];
$grupo = $_SESSION['user']['grupo_nome'] ?? '';
$canEdit = ($grupo === 'FUNCIONARIO');
$isAlunoView = ($grupo === 'ALUNO');
$cursoFiltroId = isset($_GET['curso_id']) ? (int)$_GET['curso_id'] : 0;
$msg   = '';
$error = '';

// Processar pedido de curso para funcionários
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitar_curso'])) {
  if ($grupo === 'FUNCIONARIO') {
    $cursoId = (int)($_POST['curso_id'] ?? 0);
    
    if ($cursoId <= 0) {
      $error = 'Seleciona um curso valido.';
    } else {
      $stmt = $conn->prepare("SELECT 1 FROM cursos WHERE ID = ? LIMIT 1");
      $stmt->bind_param('i', $cursoId);
      $stmt->execute();
      
      if ($stmt->get_result()->num_rows === 0) {
        $error = 'Curso invalido.';
      } else {
        // Verificar se já existe um pedido pendente ou aprovado
        $stmt = $conn->prepare(
          "SELECT id FROM funcionario_curso_pedidos 
           WHERE funcionario_login = ? AND curso_id = ? AND status IN ('PENDING', 'APPROVED')"
        );
        $stmt->bind_param('si', $login, $cursoId);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
          $error = 'Ja tens este curso solicitado ou aprovado.';
        } else {
          // Inserir pedido
          $stmt = $conn->prepare(
            "INSERT INTO funcionario_curso_pedidos (funcionario_login, curso_id, status, solicitado_em)
             VALUES (?, ?, 'PENDING', NOW())
             ON DUPLICATE KEY UPDATE status = 'PENDING', solicitado_em = NOW()"
          );
          $stmt->bind_param('si', $login, $cursoId);
          $stmt->execute();
          
          $msg = 'Pedido enviado com sucesso. Aguarda aprovacao do administrador.';
        }
      }
    }
  } else {
    $error = 'So funcionarios podem solicitar cursos.';
  }
}

$cursosPermitidosFuncionario = [];
if ($grupo === 'FUNCIONARIO') {
  $stmtPerm = $conn->prepare(
    "SELECT curso_id
       FROM funcionario_cursos
      WHERE funcionario_login = ?"
  );
  $stmtPerm->bind_param('s', $login);
  $stmtPerm->execute();
  $resPerm = $stmtPerm->get_result();
  while ($rp = $resPerm->fetch_assoc()) {
    $cursosPermitidosFuncionario[] = (int)$rp['curso_id'];
  }
}

// Cursos disponíveis para solicitar (não atribuídos e sem pedido pendente)
$cursosDisponivelsPedido = [];
if ($grupo === 'FUNCIONARIO') {
  $stmt = $conn->prepare(
    "SELECT DISTINCT c.ID, c.Nome FROM cursos c
     WHERE c.ID NOT IN (SELECT curso_id FROM funcionario_cursos WHERE funcionario_login = ?)
       AND c.ID NOT IN (SELECT curso_id FROM funcionario_curso_pedidos 
                        WHERE funcionario_login = ? AND status = 'PENDING')
     ORDER BY c.Nome"
  );
  $stmt->bind_param('ss', $login, $login);
  $stmt->execute();
  $cursosDisponivelsPedido = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function funcionario_pode_gerir_curso(string $grupo, array $cursosPermitidos, int $cursoId): bool {
  if ($grupo !== 'FUNCIONARIO') {
    return true;
  }
  return in_array($cursoId, $cursosPermitidos, true);
}

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

$anos_letivos = [];
$anoAtual = (int)date('Y');
for ($offset = 1; $offset <= 5; $offset++) {
  $inicio = $anoAtual - $offset;
  $fim = $inicio + 1;
  $anos_letivos[] = $inicio . '/' . $fim;
}

function formatar_semestre(int $semestre): string {
  return $semestre === 2 ? '2.º semestre' : '1.º semestre';
}

function parse_notas_multiplas($raw): array {
  $partes = [];

  if (is_array($raw)) {
    $partes = $raw;
  } else {
    $raw = trim((string)$raw);
    if ($raw === '') {
      return [];
    }
    $partes = preg_split('/[\s,;]+/', $raw) ?: [];
  }

  $notas = [];

  foreach ($partes as $parte) {
    $parte = str_replace(',', '.', trim($parte));
    if ($parte === '' || !is_numeric($parte)) {
      continue;
    }

    $valor = (float)$parte;
    if ($valor < 0 || $valor > 20) {
      continue;
    }

    $notas[] = round($valor, 1);
  }

  return $notas;
}

function media_notas(array $notas): ?float {
  if (empty($notas)) {
    return null;
  }

  return round(array_sum($notas) / count($notas), 1);
}

function calcular_media_curso_pauta(mysqli $conn, int $pautaId): ?float {
  $stmt = $conn->prepare(
    "SELECT AVG(nota) AS media_curso
       FROM pauta_notas
      WHERE pauta_id = ?
        AND nota IS NOT NULL"
  );
  $stmt->bind_param('i', $pautaId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();

  if (!isset($row['media_curso']) || $row['media_curso'] === null) {
    return null;
  }

  return round((float)$row['media_curso'], 1);
}

function carregar_disciplinas_da_pauta(mysqli $conn, int $pautaId): array {
  $stmt = $conn->prepare(
    "SELECT p.semestre
       FROM pautas p
      WHERE p.pauta_id = ?
      LIMIT 1"
  );
  $stmt->bind_param('i', $pautaId);
  $stmt->execute();
  $pautaData = $stmt->get_result()->fetch_assoc();
  if (!$pautaData) {
    return [];
  }
  
  $semestre = (int)$pautaData['semestre'];
  
  $stmt2 = $conn->prepare(
    "SELECT d.ID, d.Nome_disc
       FROM pautas p
       JOIN plano_estudos pe ON pe.CURSOS = p.curso_id
       JOIN disciplinas d ON d.ID = pe.DISCIPLINA
      WHERE p.pauta_id = ? AND pe.semestre = ?
      ORDER BY d.Nome_disc"
  );
  $stmt2->bind_param('ii', $pautaId, $semestre);
  $stmt2->execute();
  return $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
}

function carregar_notas_por_disciplina(mysqli $conn, int $pautaId): array {
  $stmt = $conn->prepare(
    "SELECT login, disciplina_id, notas_json, media, observacao
       FROM pauta_notas_disciplinas
      WHERE pauta_id = ?"
  );
  $stmt->bind_param('i', $pautaId);
  $stmt->execute();

  $map = [];
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $lista = [];
    if (!empty($row['notas_json'])) {
      $dec = json_decode((string)$row['notas_json'], true);
      if (is_array($dec)) {
        foreach ($dec as $n) {
          if (is_numeric($n)) {
            $lista[] = round((float)$n, 1);
          }
        }
      }
    }

    $login = (string)$row['login'];
    $discId = (int)$row['disciplina_id'];
    $map[$login][$discId] = [
      'lista' => $lista,
      'media' => isset($row['media']) ? (float)$row['media'] : null,
      'observacao' => (string)($row['observacao'] ?? ''),
    ];
  }

  return $map;
}

function sincronizar_alunos_pautas_normais(mysqli $conn): void {
  $conn->query(
    "INSERT IGNORE INTO pauta_notas (pauta_id, login)
     SELECT p.pauta_id, m.login
       FROM pautas p
       JOIN matriculas m ON m.curso_id = p.curso_id
      WHERE p.epoca = 'Normal'
        AND m.status IN ('APPROVED', 'CANCEL_REJECTED')"
  );
}

function carregar_alunos_disponiveis_para_pauta(mysqli $conn, int $pautaId): array {
  $stmt = $conn->prepare(
    "SELECT m.login, COALESCE(NULLIF(a.nome, ''), m.login) AS nome, 
            a.email, a.telefone, a.morada, a.foto_path
       FROM pautas p
       JOIN matriculas m ON m.curso_id = p.curso_id AND m.status IN ('APPROVED', 'CANCEL_REJECTED')
       LEFT JOIN alunos a ON a.login = m.login
       LEFT JOIN pauta_notas pn ON pn.pauta_id = p.pauta_id AND pn.login = m.login
      WHERE p.pauta_id = ?
        AND pn.login IS NULL
      ORDER BY nome"
  );
  $stmt->bind_param('i', $pautaId);
  $stmt->execute();
  return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function renderInserirAlunoManual(int $pautaId, bool $canEdit, string $epoca, array $alunosDisponiveis, string $tipoAvaliacao = 'Continua'): string {
  if (!$canEdit) {
    return '';
  }
  
  // Mostrar form para seleção manual em: épocas de exames (qualquer época), ou Contínua em Recurso/Especial
  $mostrarForm = ($tipoAvaliacao === 'Exame') || ($tipoAvaliacao === 'Continua' && in_array($epoca, ['Recurso', 'Especial'], true));
  
  if (!$mostrarForm) {
    return '';
  }

  $panelId = 'manual_add_panel_' . $pautaId;
  $html = '<div class="manual-add-box">';
  $html .= '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px;">';
  $html .= '<h4 class="section-title" style="margin:0;">Alunos da pauta</h4>';
  $html .= '<button type="button" class="btn-sm btn-save" onclick="toggleAddAlunoForm(\'' . $panelId . '\', this)">Adicionar aluno</button>';
  $html .= '</div>';

  if (empty($alunosDisponiveis)) {
    $html .= '<p class="meta-small" style="margin:0;">Sem alunos aprovados pendentes para inserir nesta pauta.</p>';
    $html .= '</div>';
    return $html;
  }

  $html .= '<div id="' . $panelId . '" style="display:none;">';
  $html .= '<div class="alunos-disponiveis-list" style="background:#f9f9f9;border:1px solid #ddd;border-radius:4px;padding:10px;max-height:400px;overflow-y:auto;">';
  $html .= '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
  $html .= '<thead><tr style="border-bottom:2px solid #ccc;">';
  $html .= '<th style="text-align:center;padding:6px;width:50px;">Foto</th>';
  $html .= '<th style="text-align:left;padding:6px;">Nome</th>';
  $html .= '<th style="text-align:left;padding:6px;">Login</th>';
  $html .= '<th style="text-align:left;padding:6px;">Email</th>';
  $html .= '<th style="text-align:left;padding:6px;">Telefone</th>';
  $html .= '<th style="text-align:center;padding:6px;">Ação</th>';
  $html .= '</tr></thead><tbody>';

  foreach ($alunosDisponiveis as $idx => $aluno) {
    $login = htmlspecialchars((string)$aluno['login']);
    $nome = htmlspecialchars((string)$aluno['nome']);
    $email = htmlspecialchars((string)($aluno['email'] ?? '—'));
    $telefone = htmlspecialchars((string)($aluno['telefone'] ?? '—'));
    $fotoPath = trim((string)($aluno['foto_path'] ?? ''));
    $fotoUrl = $fotoPath !== '' ? htmlspecialchars($fotoPath) : 'assets/img/perfis/default.png';
    
    $html .= '<tr style="border-bottom:1px solid #eee;">';
    $html .= '<td style="padding:6px;text-align:center;">';
    $html .= '<img src="' . $fotoUrl . '" alt="' . $login . '" style="width:40px;height:40px;border-radius:4px;object-fit:cover;border:1px solid #ddd;">';
    $html .= '</td>';
    $html .= '<td style="padding:6px;">';
    $html .= '<strong>' . $nome . '</strong>';
    if (!empty($aluno['morada'])) {
      $morada = htmlspecialchars((string)$aluno['morada']);
      $html .= '<br><span style="font-size:11px;color:#666;">' . $morada . '</span>';
    }
    $html .= '</td>';
    $html .= '<td style="padding:6px;color:#666;">' . $login . '</td>';
    $html .= '<td style="padding:6px;color:#666;font-size:12px;">' . $email . '</td>';
    $html .= '<td style="padding:6px;color:#666;">' . $telefone . '</td>';
    $html .= '<td style="padding:6px;text-align:center;">';
    $html .= '<form method="post" style="margin:0;display:inline;">';
    $html .= '<input type="hidden" name="add_aluno_pauta" value="1">';
    $html .= '<input type="hidden" name="pauta_id" value="' . $pautaId . '">';
    $html .= '<input type="hidden" name="aluno_login" value="' . $login . '">';
    $html .= '<button type="submit" class="btn-sm btn-save" style="padding:3px 8px;font-size:12px;">Adicionar</button>';
    $html .= '</form>';
    $html .= '</td>';
    $html .= '</tr>';
  }

  $html .= '</tbody></table>';
  $html .= '</div>';
  $html .= '</div>';
  $html .= '</div>';

  return $html;
}

sincronizar_alunos_pautas_normais($conn);

/* ---------------------------------------------------------------
   POST: Criar nova pauta
--------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nova_pauta'])) {
  if (!$canEdit) {
    $error = 'Não tem permissão para criar pautas.';
  } else {
    $curso_id   = (int)($_POST['curso_id'] ?? 0);
    $ano_letivo = trim($_POST['ano_letivo'] ?? '');
  $semestre   = (int)($_POST['semestre'] ?? 0);
    $epoca      = $_POST['epoca'] ?? 'Normal';
    $tipoAvaliacao = $_POST['tipo_avaliacao'] ?? 'Continua';

    if (!in_array($epoca, ['Normal', 'Recurso', 'Especial'], true)) {
        $epoca = 'Normal';
    }

    if (!in_array($tipoAvaliacao, ['Continua', 'Exame'], true)) {
      $tipoAvaliacao = 'Continua';
    }

  if (!in_array($ano_letivo, $anos_letivos, true)) {
    $ano_letivo = '';
  }

  if (!in_array($semestre, [1, 2], true)) {
    $semestre = 0;
  }

  if ($curso_id <= 0 || $ano_letivo === '' || $semestre === 0) {
        $error = 'Preencha todos os campos para criar uma pauta.';
    } elseif (!funcionario_pode_gerir_curso($grupo, $cursosPermitidosFuncionario, $curso_id)) {
      $error = 'Nao tem permissao para criar pautas neste curso.';
    } else {
        // Verificar se já existe pauta para esta combinação
        $chk = $conn->prepare(
      "SELECT pauta_id FROM pautas WHERE curso_id = ? AND ano_letivo = ? AND semestre = ? AND epoca = ? AND tipo_avaliacao = ? LIMIT 1"
        );
    $chk->bind_param("isiss", $curso_id, $ano_letivo, $semestre, $epoca, $tipoAvaliacao);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
      $error = 'Já existe uma pauta para este curso, ano letivo, semestre, época e tipo de avaliação.';
        } else {
            // Criar pauta
            $ins = $conn->prepare(
        "INSERT INTO pautas (curso_id, ano_letivo, semestre, epoca, tipo_avaliacao, criado_por) VALUES (?, ?, ?, ?, ?, ?)"
            );
      $ins->bind_param("isisss", $curso_id, $ano_letivo, $semestre, $epoca, $tipoAvaliacao, $login);
            $ins->execute();
            $pauta_id = (int)$conn->insert_id;

      // Na avaliação contínua época Normal os alunos entram automaticamente.
      // Em todas as épocas de exames ou avaliação contínua em Recurso/Especial a pauta começa vazia e a inserção é manual.
      if ($tipoAvaliacao === 'Continua' && $epoca === 'Normal') {
        $alunos = $conn->prepare(
          "SELECT login FROM matriculas WHERE curso_id = ? AND status IN ('APPROVED', 'CANCEL_REJECTED')"
        );
        $alunos->bind_param("i", $curso_id);
        $alunos->execute();
        $res = $alunos->get_result();
        while ($row = $res->fetch_assoc()) {
          $insNota = $conn->prepare(
            "INSERT IGNORE INTO pauta_notas (pauta_id, login) VALUES (?, ?)"
          );
          $insNota->bind_param("is", $pauta_id, $row['login']);
          $insNota->execute();
        }
            }

            if ($tipoAvaliacao === 'Exame') {
              $msg = 'Pauta criada com sucesso. Agora selecione os alunos qualificados para esta época de exames.';
            } else {
              $msg = 'Pauta criada com sucesso.';
            }
        }
    }
        }
}

/* ---------------------------------------------------------------
   POST: Inserir aluno manualmente (Recurso/Especial)
--------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_aluno_pauta'])) {
  if (!$canEdit) {
    $error = 'Não tem permissão para inserir alunos na pauta.';
  } else {
    $pauta_id = (int)($_POST['pauta_id'] ?? 0);
    $aluno_login = trim((string)($_POST['aluno_login'] ?? ''));

    if ($pauta_id <= 0 || $aluno_login === '') {
      $error = 'Selecione um aluno válido para inserir na pauta.';
    } else {
      $chkPauta = $conn->prepare("SELECT curso_id, epoca, tipo_avaliacao FROM pautas WHERE pauta_id = ? LIMIT 1");
      $chkPauta->bind_param('i', $pauta_id);
      $chkPauta->execute();
      $pautaMeta = $chkPauta->get_result()->fetch_assoc();

      if (!$pautaMeta) {
        $error = 'Pauta não encontrada.';
      } elseif (!funcionario_pode_gerir_curso($grupo, $cursosPermitidosFuncionario, (int)$pautaMeta['curso_id'])) {
        $error = 'Nao tem permissao para editar esta pauta.';
      } elseif (($pautaMeta['tipo_avaliacao'] ?? 'Continua') === 'Continua' && ($pautaMeta['epoca'] ?? '') === 'Normal') {
        $error = 'Na avaliação contínua época Normal os alunos aprovados entram automaticamente na pauta.';
      } else {
        $cursoId = (int)$pautaMeta['curso_id'];
        $chkMat = $conn->prepare(
          "SELECT 1
             FROM matriculas
            WHERE curso_id = ? AND login = ? AND status IN ('APPROVED', 'CANCEL_REJECTED')
            LIMIT 1"
        );
        $chkMat->bind_param('is', $cursoId, $aluno_login);
        $chkMat->execute();

        if ($chkMat->get_result()->num_rows === 0) {
          $error = 'Este aluno não tem matrícula aprovada para o curso desta pauta.';
        } else {
          $ins = $conn->prepare("INSERT IGNORE INTO pauta_notas (pauta_id, login) VALUES (?, ?)");
          $ins->bind_param('is', $pauta_id, $aluno_login);
          $ins->execute();
          $msg = $ins->affected_rows > 0
            ? 'Aluno inserido na pauta com sucesso.'
            : 'O aluno já estava inserido nesta pauta.';
        }
      }
    }
  }
}

/* ---------------------------------------------------------------
   POST: Gravar notas numa pauta
--------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gravar_notas'])) {
  if (!$canEdit) {
    $error = 'Não tem permissão para editar notas.';
  } else {
    $pauta_id = (int)($_POST['pauta_id'] ?? 0);

    if ($pauta_id > 0) {
        // Verificar que a pauta existe
    $chk = $conn->prepare("SELECT pauta_id, curso_id, epoca FROM pautas WHERE pauta_id = ? LIMIT 1");
        $chk->bind_param("i", $pauta_id);
        $chk->execute();
    $pautaRow = $chk->get_result()->fetch_assoc();
    if ($pautaRow && funcionario_pode_gerir_curso($grupo, $cursosPermitidosFuncionario, (int)$pautaRow['curso_id'])) {
      $disciplinas = carregar_disciplinas_da_pauta($conn, $pauta_id);
      $notas_multi = $_POST['notas_multi'] ?? [];
      $obs_raw     = $_POST['obs'] ?? [];
      $obs_disc_raw = $_POST['obs_disc'] ?? [];
      $faz_exame_raw = $_POST['faz_exame'] ?? [];

      $alunosStmt = $conn->prepare("SELECT login FROM pauta_notas WHERE pauta_id = ?");
      $alunosStmt->bind_param('i', $pauta_id);
      $alunosStmt->execute();
      $alunosRes = $alunosStmt->get_result();

      while ($aluno = $alunosRes->fetch_assoc()) {
        $alunoLogin = (string)$aluno['login'];
        $mediasDisciplina = [];

        foreach ($disciplinas as $disc) {
          $discId = (int)$disc['ID'];
          $raw = $notas_multi[$alunoLogin][$discId] ?? [];
          $lista = parse_notas_multiplas($raw);
          $mediaDisc = media_notas($lista);
          $json = empty($lista) ? null : json_encode($lista, JSON_UNESCAPED_UNICODE);
          $obsDiscVal = trim((string)($obs_disc_raw[$alunoLogin][$discId] ?? ''));

          if ($mediaDisc !== null) {
            $mediasDisciplina[] = $mediaDisc;
          }

          $updDisc = $conn->prepare(
            "INSERT INTO pauta_notas_disciplinas (pauta_id, login, disciplina_id, notas_json, media, observacao, registado_por, registado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               notas_json = VALUES(notas_json),
               media = VALUES(media),
               observacao = VALUES(observacao),
               registado_por = VALUES(registado_por),
               registado_em = NOW()"
          );
          $updDisc->bind_param('isisdss', $pauta_id, $alunoLogin, $discId, $json, $mediaDisc, $obsDiscVal, $login);
          $updDisc->execute();
        }

        $notaFinal = media_notas($mediasDisciplina);
        $obsVal = trim((string)($obs_raw[$alunoLogin] ?? ''));

        $updAluno = $conn->prepare(
          "INSERT INTO pauta_notas (pauta_id, login, nota, observacao, registado_por, registado_em)
           VALUES (?, ?, ?, ?, ?, NOW())
           ON DUPLICATE KEY UPDATE
             nota = VALUES(nota),
             observacao = VALUES(observacao),
             registado_por = VALUES(registado_por),
             registado_em = NOW()"
        );
        $updAluno->bind_param('isdss', $pauta_id, $alunoLogin, $notaFinal, $obsVal, $login);
        $updAluno->execute();

        // Gravar se o aluno faz exame (apenas na época Normal)
        $pautaEpoca = $pautaRow['epoca'] ?? '';
        if ($pautaEpoca === 'Normal' && isset($faz_exame_raw[$alunoLogin])) {
          $fazExame = 1;
          $updFazExame = $conn->prepare(
            "UPDATE pauta_notas SET faz_exame = ? WHERE pauta_id = ? AND login = ?"
          );
          $updFazExame->bind_param('iis', $fazExame, $pauta_id, $alunoLogin);
          $updFazExame->execute();
        } elseif ($pautaEpoca === 'Normal') {
          $naoFazExame = 0;
          $updFazExame = $conn->prepare(
            "UPDATE pauta_notas SET faz_exame = ? WHERE pauta_id = ? AND login = ?"
          );
          $updFazExame->bind_param('iis', $naoFazExame, $pauta_id, $alunoLogin);
          $updFazExame->execute();
        }
      }
            $msg = 'Notas gravadas com sucesso.';
        } else {
      $error = 'Nao tem permissao para editar notas desta pauta.';
    }
    }
        }
}

/* ---------------------------------------------------------------
   POST: Eliminar pauta
--------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_pauta'])) {
  if (!$canEdit) {
    $error = 'Não tem permissão para eliminar pautas.';
  } else {
    $pauta_id = (int)($_POST['pauta_id'] ?? 0);
    if ($pauta_id > 0) {
        $chk = $conn->prepare("SELECT curso_id FROM pautas WHERE pauta_id = ? LIMIT 1");
        $chk->bind_param("i", $pauta_id);
        $chk->execute();
        $pautaMeta = $chk->get_result()->fetch_assoc();

        if (!$pautaMeta || !funcionario_pode_gerir_curso($grupo, $cursosPermitidosFuncionario, (int)$pautaMeta['curso_id'])) {
          $error = 'Nao tem permissao para eliminar esta pauta.';
        } else {
        $del = $conn->prepare("DELETE FROM pautas WHERE pauta_id = ?");
        $del->bind_param("i", $pauta_id);
        $del->execute();
        $msg = 'Pauta eliminada.';
        }
    }
  }
}

/* ---------------------------------------------------------------
   Dados para a página
--------------------------------------------------------------- */

// Cursos disponíveis
$cursos = [];
if ($grupo === 'FUNCIONARIO') {
  if (!empty($cursosPermitidosFuncionario)) {
    $placeholders = implode(',', array_fill(0, count($cursosPermitidosFuncionario), '?'));
    $types = str_repeat('i', count($cursosPermitidosFuncionario));
    $stmtCursos = $conn->prepare("SELECT ID, Nome FROM cursos WHERE ID IN ($placeholders) ORDER BY Nome");
    $stmtCursos->bind_param($types, ...$cursosPermitidosFuncionario);
    $stmtCursos->execute();
    $cursos = $stmtCursos->get_result()->fetch_all(MYSQLI_ASSOC);
  }
} else {
  $cursos_res = $conn->query("SELECT ID, Nome FROM cursos ORDER BY Nome");
  while ($row = $cursos_res->fetch_assoc()) {
      $cursos[] = $row;
  }
}

// Pautas existentes com nome do curso
if ($grupo === 'ALUNO') {
  if ($cursoFiltroId > 0) {
    $pautas_stmt = $conn->prepare(
      "SELECT DISTINCT p.pauta_id, p.curso_id, p.ano_letivo, p.semestre, p.epoca, p.tipo_avaliacao, p.criado_por, p.criado_em,
                c.Nome AS curso_nome
         FROM pautas p
         JOIN cursos c ON c.ID = p.curso_id
         JOIN matriculas m ON m.curso_id = p.curso_id
        WHERE m.login = ?
          AND m.status IN ('APPROVED', 'CANCEL_REJECTED')
          AND p.curso_id = ?
         ORDER BY p.criado_em DESC"
    );
    $pautas_stmt->bind_param("si", $login, $cursoFiltroId);
    $pautas_stmt->execute();
  } else {
    $pautas_stmt = $conn->prepare(
      "SELECT DISTINCT p.pauta_id, p.curso_id, p.ano_letivo, p.semestre, p.epoca, p.tipo_avaliacao, p.criado_por, p.criado_em,
                c.Nome AS curso_nome
         FROM pautas p
         JOIN cursos c ON c.ID = p.curso_id
         JOIN matriculas m ON m.curso_id = p.curso_id
        WHERE m.login = ? AND m.status IN ('APPROVED', 'CANCEL_REJECTED')
         ORDER BY p.criado_em DESC"
    );
    $pautas_stmt->bind_param("s", $login);
    $pautas_stmt->execute();
  }
} else {
  if ($grupo === 'FUNCIONARIO') {
    if ($cursoFiltroId > 0) {
      $pautas_stmt = $conn->prepare(
        "SELECT p.pauta_id, p.curso_id, p.ano_letivo, p.semestre, p.epoca, p.tipo_avaliacao, p.criado_por, p.criado_em,
                  c.Nome AS curso_nome
           FROM pautas p
           JOIN cursos c ON c.ID = p.curso_id
           JOIN funcionario_cursos fc ON fc.curso_id = p.curso_id
          WHERE fc.funcionario_login = ?
            AND p.curso_id = ?
           ORDER BY p.criado_em DESC"
      );
      $pautas_stmt->bind_param("si", $login, $cursoFiltroId);
      $pautas_stmt->execute();
    } else {
      $pautas_stmt = $conn->prepare(
        "SELECT p.pauta_id, p.curso_id, p.ano_letivo, p.semestre, p.epoca, p.tipo_avaliacao, p.criado_por, p.criado_em,
                  c.Nome AS curso_nome
           FROM pautas p
           JOIN cursos c ON c.ID = p.curso_id
           JOIN funcionario_cursos fc ON fc.curso_id = p.curso_id
          WHERE fc.funcionario_login = ?
           ORDER BY p.criado_em DESC"
      );
      $pautas_stmt->bind_param("s", $login);
      $pautas_stmt->execute();
    }
  } else {
    if ($cursoFiltroId > 0) {
      $pautas_stmt = $conn->prepare(
        "SELECT p.pauta_id, p.curso_id, p.ano_letivo, p.semestre, p.epoca, p.tipo_avaliacao, p.criado_por, p.criado_em,
                  c.Nome AS curso_nome
           FROM pautas p
           JOIN cursos c ON c.ID = p.curso_id
          WHERE p.curso_id = ?
           ORDER BY p.criado_em DESC"
      );
      $pautas_stmt->bind_param("i", $cursoFiltroId);
      $pautas_stmt->execute();
    } else {
      $pautas_stmt = $conn->prepare(
        "SELECT p.pauta_id, p.curso_id, p.ano_letivo, p.semestre, p.epoca, p.tipo_avaliacao, p.criado_por, p.criado_em,
                  c.Nome AS curso_nome
           FROM pautas p
           JOIN cursos c ON c.ID = p.curso_id
           ORDER BY p.criado_em DESC"
      );
      $pautas_stmt->execute();
    }
  }
}
$pautas_res = $pautas_stmt->get_result();
$pautas = [];
while ($row = $pautas_res->fetch_assoc()) {
    $pautas[] = $row;
}

$cursosFiltro = [];
foreach ($pautas as $pautaItem) {
  $cursoNome = trim((string)($pautaItem['curso_nome'] ?? ''));
  if ($cursoNome === '') {
    continue;
  }
  $cursosFiltro[strtolower($cursoNome)] = $cursoNome;
}
asort($cursosFiltro, SORT_NATURAL | SORT_FLAG_CASE);

$pautasPorEpoca = [];
foreach ($pautas as $pautaItem) {
  $ep = (string)($pautaItem['epoca'] ?? 'Normal');
  $tipo = (string)($pautaItem['tipo_avaliacao'] ?? 'Continua');
  $chave = $ep . '|' . $tipo;
  
  if (!isset($pautasPorEpoca[$chave])) {
    $pautasPorEpoca[$chave] = [
      'epoca' => $ep,
      'tipo_avaliacao' => $tipo,
      'pautas' => []
    ];
  }
  $pautasPorEpoca[$chave]['pautas'][] = $pautaItem;
}

// Lista de épocas para filtro
$ordemEpocas = ['Normal', 'Recurso', 'Especial'];

$pautasPermitidas = [];
foreach ($pautas as $pautaItem) {
    $pautasPermitidas[(int)$pautaItem['pauta_id']] = true;
}

// Notas da pauta expandida (se passado via GET)
$pauta_aberta = isset($_GET['pauta']) ? (int)$_GET['pauta'] : 0;
$pauta_aberta = isset($pautasPermitidas[$pauta_aberta]) ? $pauta_aberta : 0;
$notas_pauta  = [];
$disciplinas_pauta = [];
$notas_disciplina_pauta = [];
$epoca_pauta_aberta = '';
$tipo_avaliacao_pauta_aberta = 'Continua';
$alunos_disponiveis_pauta = [];
if ($pauta_aberta > 0) {
    if ($grupo === 'ALUNO') {
      $ns = $conn->prepare(
      "SELECT pn.login, pn.nota, pn.observacao, pn.registado_por, pn.registado_em, pn.faz_exame,
        a.nome AS aluno_nome, a.email AS aluno_email, a.telefone AS aluno_telefone, a.morada AS aluno_morada, a.foto_path AS aluno_foto
           FROM pauta_notas pn
       LEFT JOIN alunos a ON a.login = pn.login
           WHERE pn.pauta_id = ? AND pn.login = ?
           ORDER BY pn.login"
      );
      $ns->bind_param("is", $pauta_aberta, $login);
    } else {
      $ns = $conn->prepare(
      "SELECT pn.login, pn.nota, pn.observacao, pn.registado_por, pn.registado_em, pn.faz_exame,
        a.nome AS aluno_nome, a.email AS aluno_email, a.telefone AS aluno_telefone, a.morada AS aluno_morada, a.foto_path AS aluno_foto
           FROM pauta_notas pn
       LEFT JOIN alunos a ON a.login = pn.login
           WHERE pn.pauta_id = ?
           ORDER BY pn.login"
      );
      $ns->bind_param("i", $pauta_aberta);
    }
    $ns->execute();
    $notas_pauta = $ns->get_result()->fetch_all(MYSQLI_ASSOC);
    $disciplinas_pauta = carregar_disciplinas_da_pauta($conn, $pauta_aberta);
    $notas_disciplina_pauta = carregar_notas_por_disciplina($conn, $pauta_aberta);

    foreach ($pautas as $p) {
      if ((int)$p['pauta_id'] === $pauta_aberta) {
        $epoca_pauta_aberta = (string)$p['epoca'];
        $tipo_avaliacao_pauta_aberta = (string)($p['tipo_avaliacao'] ?? 'Continua');
        break;
      }
    }

    if ($canEdit && ($tipo_avaliacao_pauta_aberta === 'Exame' || in_array($epoca_pauta_aberta, ['Recurso', 'Especial'], true))) {
      $alunos_disponiveis_pauta = carregar_alunos_disponiveis_para_pauta($conn, $pauta_aberta);
    }
}
?>
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pautas de Avaliação</title>
  <style><?php include __DIR__ . '/assets/css/theme.css'; ?></style>
</head>
<body class='page-pautas'>
<div class="container">

  <!-- Header -->
  <div class="header">
    <div class="header-left">
      <a href="index.php" aria-label="Ir para o menu principal">
        <img class="logo" src="<?= htmlspecialchars(logo_asset_path()) ?>" alt="Logótipo">
      </a>
      <h2>Pautas de Avaliação</h2>
    </div>
    <a href="index.php" class="back-btn">← Voltar</a>
  </div>

  <!-- Mensagens de feedback -->
  <?php if ($msg):  ?><div class="msg-ok"><?= htmlspecialchars($msg)   ?></div><?php endif; ?>
  <?php if ($error):?><div class="msg-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="content-grid">
    <div class="main-column">
      <?php if ($canEdit): ?>
      <div class="card">
        <h3 class="section-title">Criar nova pauta</h3>
        <form method="post">
          <input type="hidden" name="nova_pauta" value="1">
          <div class="form-row">
            <div>
              <label for="curso_id">Curso</label>
              <select name="curso_id" id="curso_id" required>
                <option value="">— Selecionar curso —</option>
                <?php foreach ($cursos as $c): ?>
                  <option value="<?= (int)$c['ID'] ?>"><?= htmlspecialchars($c['Nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="ano_letivo">Ano letivo</label>
              <select id="ano_letivo" name="ano_letivo" required>
                <option value="">— Selecionar ano letivo —</option>
                <?php foreach ($anos_letivos as $ano): ?>
                  <option value="<?= htmlspecialchars($ano) ?>"><?= htmlspecialchars($ano) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="semestre">Semestre</label>
              <select name="semestre" id="semestre" required>
                <option value="">— Selecionar semestre —</option>
                <option value="1">1.º semestre</option>
                <option value="2">2.º semestre</option>
              </select>
            </div>
            <div>
              <label for="tipo_avaliacao">Tipo de Avaliação</label>
              <select name="tipo_avaliacao" id="tipo_avaliacao" onchange="toggleEpocaField()">
                <option value="Continua">Contínua</option>
                <option value="Exame">Exame</option>
              </select>
            </div>
            <div id="epoca_field" style="display:none;">
              <label for="epoca">Época</label>
              <select name="epoca" id="epoca">
                <option value="Normal">Normal</option>
                <option value="Recurso">Recurso</option>
                <option value="Especial">Especial</option>
              </select>
            </div>
          </div>
          <div style="margin-top:12px;text-align:right;">
            <button type="submit" class="btn-primary">+ Criar pauta</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($grupo === 'FUNCIONARIO' && !empty($cursosDisponivelsPedido)): ?>
      <div class="card">
        <h3 class="section-title">Solicitar Acesso a Novo Curso</h3>
        <form method="post">
          <div class="form-field">
            <label for="curso_id_pedido">Seleciona um curso</label>
            <select id="curso_id_pedido" name="curso_id" required>
              <option value="">— Escolhe um curso —</option>
              <?php foreach ($cursosDisponivelsPedido as $curso): ?>
                <option value="<?= (int)$curso['ID'] ?>"><?= htmlspecialchars($curso['Nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <input type="hidden" name="solicitar_curso" value="1">
          <div style="text-align:right;margin-top:12px;">
            <button type="submit" class="btn-primary">Enviar Pedido</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <div class="card">
        <h3 class="section-title">Pautas existentes</h3>
        <?php if (empty($pautas)): ?>
          <p class="empty-state">Ainda não foram criadas pautas.</p>
        <?php else: ?>
        <div class="search-bar" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:flex-end;">
          <div style="flex:1;min-width:220px;">
            <label for="search_pautas_curso" style="margin-bottom:4px;">Filtrar por curso</label>
            <select id="search_pautas_curso" onchange="filtrarPautas()">
              <option value="">- Todos os cursos -</option>
              <?php foreach ($cursosFiltro as $cursoValor => $cursoNome): ?>
                <option value="<?= htmlspecialchars($cursoValor) ?>"><?= htmlspecialchars($cursoNome) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="min-width:220px;">
            <label for="search_pautas_epoca" style="margin-bottom:4px;">Filtrar por época</label>
            <select id="search_pautas_epoca" onchange="filtrarPautas()">
              <option value="">- Todas as épocas -</option>
              <?php foreach ($ordemEpocas as $ep): ?>
                <option value="<?= htmlspecialchars(strtolower($ep)) ?>"><?= htmlspecialchars($ep) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="button" class="btn-sm" onclick="document.getElementById('search_pautas_curso').value='';document.getElementById('search_pautas_epoca').value='';filtrarPautas();" style="background:linear-gradient(135deg,#64748b,#475569);margin-bottom:0;">Limpar</button>
        </div>
        <div id="sem-pautas-filtro" style="display:none;padding:10px 0;color:#888;">Nenhuma pauta corresponde aos filtros.</div>
        <?php foreach ($pautasPorEpoca as $chaveEpoca => $epocaData): ?>
          <?php $listaEpoca = $epocaData['pautas'] ?? []; ?>
          <?php if (empty($listaEpoca)) { continue; } ?>
          <?php $epocaNome = $epocaData['epoca']; ?>
          <?php $tipoAvaliacao = $epocaData['tipo_avaliacao']; ?>
          <div class="pautas-epoca-bloco" data-epoca="<?= htmlspecialchars(strtolower($epocaNome)) ?>" style="margin-bottom:16px;">
            <h4 class="section-title" style="margin-bottom:8px;">
              <?= htmlspecialchars($tipoAvaliacao === 'Exame' ? 'Época ' . $epocaNome : 'Contínua') ?>
            </h4>
            <div style="overflow-x:auto;">
              <table>
                <thead>
                  <tr>
                    <th>Curso</th>
                    <th>Ano letivo</th>
                    <th>Semestre</th>
                    <?php if (!$isAlunoView): ?><th>Criado por</th><?php endif; ?>
                    <th>Data</th>
                    <?php if (!$isAlunoView): ?><th>Ações</th><?php endif; ?>
                    <th>Notas</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($listaEpoca as $p):
                    $pid        = (int)$p['pauta_id'];
                    $detailsId  = 'pauta_' . $pid;
                    $isOpen     = ($pauta_aberta === $pid);
                  ?>
                  <tr class="pauta-item-row" data-curso="<?= htmlspecialchars(strtolower((string)$p['curso_nome'])) ?>" data-epoca="<?= htmlspecialchars(strtolower((string)$epocaNome)) ?>">
                    <td data-label="Curso"><?= htmlspecialchars($p['curso_nome']) ?></td>
                    <td data-label="Ano letivo"><?= htmlspecialchars($p['ano_letivo']) ?></td>
                    <td data-label="Semestre"><?= htmlspecialchars(formatar_semestre((int)$p['semestre'])) ?></td>
                    <?php if (!$isAlunoView): ?><td data-label="Criado por"><?= htmlspecialchars($p['criado_por']) ?></td><?php endif; ?>
                    <td data-label="Data"><?= htmlspecialchars($p['criado_em']) ?></td>
                    <?php if (!$isAlunoView): ?>
                    <td data-label="Ações">
                      <?php if ($canEdit): ?>
                      <form method="post" style="display:inline;"
                            onsubmit="return confirm('Eliminar esta pauta e todas as notas associadas?')">
                        <input type="hidden" name="pauta_id" value="<?= $pid ?>">
                        <button type="submit" name="del_pauta" class="btn-sm btn-del">Eliminar</button>
                      </form>
                      <?php else: ?>
                        <span class="meta-small">—</span>
                      <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td data-label="Notas">
                      <a href="?pauta=<?= $pid ?>#pauta_notas_<?= $pid ?>" class="toggle-btn"
                         onclick="togglePauta('<?= $detailsId ?>', this, <?= $pid ?>); return false;">
                        <?= $isOpen ? 'Ocultar notas' : ($canEdit ? 'Ver / editar notas' : 'Ver notas') ?>
                      </a>
                    </td>
                  </tr>
                  <tr id="<?= $detailsId ?>" class="pauta-details-row<?= $isOpen ? ' open' : '' ?>" data-parent="<?= $pid ?>">
                    <td colspan="<?= $isAlunoView ? '5' : '7' ?>" class="pauta-details-cell">
                      <?php $notas_desta = ($pid === $pauta_aberta) ? $notas_pauta : null; ?>
                      <div id="notas_container_<?= $pid ?>" class="notas-container"
                           data-loaded="<?= ($notas_desta !== null) ? '1' : '0' ?>">
                        <?php if ($notas_desta !== null): ?>
                          <?php $mediaCursoPauta = ($epoca_pauta_aberta === 'Normal') ? calcular_media_curso_pauta($conn, $pid) : null; ?>
                          <?= renderInserirAlunoManual($pid, $canEdit, $epoca_pauta_aberta, $alunos_disponiveis_pauta, $tipo_avaliacao_pauta_aberta) ?>
                          <?= renderNotasForm($pid, $notas_desta, $canEdit, $disciplinas_pauta, $notas_disciplina_pauta, $epoca_pauta_aberta, $mediaCursoPauta) ?>
                        <?php else: ?>
                          <p style="color:#aaa;font-size:13px;">A carregar...</p>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
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
        <strong>Tipo de utilizador:</strong> <?= htmlspecialchars(traduz_grupo_nome($grupo)) ?><br>
        <strong>Utilizador:</strong> <?= htmlspecialchars($login) ?>
      </div>
    </div>
  </div>

</div>

<script>
const canEditPautas = <?= $canEdit ? 'true' : 'false' ?>;

function toggleEpocaField() {
  const tipoAvaliacao = document.getElementById('tipo_avaliacao').value;
  const epocaField = document.getElementById('epoca_field');
  if (tipoAvaliacao === 'Exame') {
    epocaField.style.display = '';
  } else {
    epocaField.style.display = 'none';
  }
}

function togglePauta(rowId, btn, pautaId) {
  const row = document.getElementById(rowId);
  if (!row) return;
  const isOpen = row.classList.contains('open');

  if (!isOpen) {
    // Abrir: carregar notas via fetch se ainda não carregou
    const container = document.getElementById('notas_container_' + pautaId);
    if (container && container.dataset.loaded !== '1') {
      container.dataset.loaded = '1';
      fetch('pautas_notas_ajax.php?pauta_id=' + pautaId)
        .then(r => r.text())
        .then(html => { container.innerHTML = html; });
    }
    row.classList.add('open');
    row.style.display = 'table-row';
    btn.textContent = 'Ocultar notas';
  } else {
    row.classList.remove('open');
    row.style.display = 'none';
    btn.textContent = canEditPautas ? 'Ver / editar notas' : 'Ver notas';
  }
}

function filtrarPautas() {
  const cursoSel = document.getElementById('search_pautas_curso').value.trim();
  const epocaSel = document.getElementById('search_pautas_epoca').value.trim();
  const blocos = document.querySelectorAll('.pautas-epoca-bloco');
  let totalVisiveis = 0;

  blocos.forEach(bloco => {
    let visiveisNoBloco = 0;
    const rows = bloco.querySelectorAll('tr.pauta-item-row');

    rows.forEach(row => {
      const curso = row.dataset.curso || '';
      const epoca = row.dataset.epoca || '';
      const cursoMatch = !cursoSel || curso === cursoSel;
      const epocaMatch = !epocaSel || epoca === epocaSel;
      const show = cursoMatch && epocaMatch;

      row.style.display = show ? '' : 'none';

      const detailsRow = row.nextElementSibling;
      if (detailsRow && detailsRow.classList.contains('pauta-details-row')) {
        if (!show) {
          detailsRow.style.display = 'none';
        } else {
          detailsRow.style.display = detailsRow.classList.contains('open') ? 'table-row' : 'none';
        }
      }

      if (show) {
        visiveisNoBloco++;
        totalVisiveis++;
      }
    });

    bloco.style.display = visiveisNoBloco > 0 ? '' : 'none';
  });

  document.getElementById('sem-pautas-filtro').style.display = totalVisiveis === 0 ? 'block' : 'none';
}

function toggleAlunoPerfil(rowId, btn) {
  const row = document.getElementById(rowId);
  if (!row) return;
  const isOpen = row.classList.contains('open');
  row.classList.toggle('open', !isOpen);
  btn.classList.toggle('open', !isOpen);

  const chevron = btn.querySelector('.aluno-name-chevron');
  if (chevron) {
    chevron.textContent = isOpen ? '▾' : '▴';
  }
}

function toggleAlunoNotas(rowId, btn) {
  const row = document.getElementById(rowId);
  if (!row) return;
  const isOpen = row.classList.contains('open');
  row.classList.toggle('open', !isOpen);
  btn.textContent = isOpen ? 'Ver notas' : 'Ocultar notas';
}

function toggleAddAlunoForm(panelId, btn) {
  const panel = document.getElementById(panelId);
  if (!panel) return;

  const isHidden = panel.style.display === 'none' || panel.style.display === '';
  panel.style.display = isHidden ? 'block' : 'none';
  btn.textContent = isHidden ? 'Cancelar' : 'Adicionar aluno';
}

function addNotaBox(button) {
  const wrapper = button.closest('.disc-notas-inputs');
  if (!wrapper) return;
  const boxes = wrapper.querySelector('.notas-boxes');
  if (!boxes) return;

  const input = document.createElement('input');
  input.type = 'number';
  input.className = 'nota-box';
  input.min = '0';
  input.max = '20';
  input.step = '0.1';
  input.placeholder = '0-20';
  input.name = boxes.getAttribute('data-name') || '';

  boxes.appendChild(input);
  input.focus();
}
</script>

<?php
/* Renderiza o formulário de notas inline */
function renderNotasForm(int $pauta_id, array $notas, bool $canEdit, array $disciplinas, array $notasDisciplina, string $epocaPauta = '', ?float $mediaCurso = null): string {
  global $grupo;
  $hideEditorNames = ($grupo === 'ALUNO');

    if (empty($notas)) {
        return '<p style="color:#888;font-style:italic;">Nenhum aluno inscrito nesta pauta. Verifique se existem matrículas aprovadas para o curso.</p>';
    }

    if (empty($disciplinas)) {
        return '<p style="color:#888;font-style:italic;">Este curso não tem disciplinas associadas no plano de estudos.</p>';
    }

  $html = '';
  if ($epocaPauta === 'Normal') {
    $mediaTxt = $mediaCurso !== null
      ? htmlspecialchars(number_format($mediaCurso, 1, '.', ''))
      : '—';
    $html .= '<div class="pauta-media-curso"><strong>Média do curso:</strong> ' . $mediaTxt . '</div>';
  }

  if ($canEdit) {
    $html .= '<form method="post">';
    $html .= '<input type="hidden" name="pauta_id" value="' . $pauta_id . '">';
  }
    $html .= '<table class="notas-table">';
    $html .= '<thead><tr><th>Aluno</th><th>Notas por disciplina</th><th>Média final</th><th>Observação geral</th>';
    if ($canEdit && $epocaPauta === 'Normal') {
      $html .= '<th>Faz exame?</th>';
    }
    $html .= '<th>Última alteração</th></tr></thead>';
    $html .= '<tbody>';

    foreach ($notas as $idx => $n) {
      $alLogin = (string)$n['login'];
      $al    = htmlspecialchars($alLogin);
      $nome  = trim((string)($n['aluno_nome'] ?? ''));
      $nomeVisivel = $nome !== '' ? htmlspecialchars($nome) : $al;
      $email = htmlspecialchars((string)($n['aluno_email'] ?? '-'));
      $telefone = htmlspecialchars((string)($n['aluno_telefone'] ?? '-'));
      $morada = htmlspecialchars((string)($n['aluno_morada'] ?? '-'));
      $fotoRaw = trim((string)($n['aluno_foto'] ?? ''));
      $foto = $fotoRaw !== '' ? htmlspecialchars($fotoRaw) : '';
        $nota  = $n['nota'] !== null ? htmlspecialchars((string)$n['nota']) : '';
        $obs   = htmlspecialchars($n['observacao'] ?? '');
        $regBy = htmlspecialchars($n['registado_por'] ?? '');
        $regEm = htmlspecialchars($n['registado_em'] ?? '');
      $alunoRowId = 'aluno_' . $pauta_id . '_' . $idx;
      $alunoNotasRowId = 'aluno_notas_' . $pauta_id . '_' . $idx;

        // Badge de nota
        if ($n['nota'] === null) {
            $badge = '<span class="nota-badge nota-nd">—</span>';
        } elseif ((float)$n['nota'] >= 10) {
            $badge = '<span class="nota-badge nota-pos">' . $nota . '</span>';
        } else {
            $badge = '<span class="nota-badge nota-neg">' . $nota . '</span>';
        }

        $htmlDisciplinas = '<div class="notas-disciplinas">';
        $htmlDisciplinas .= '<table class="disciplinas-table">';
        $htmlDisciplinas .= '<thead><tr><th>Disciplina</th><th>Notas</th><th>Média</th><th>Observação</th></tr></thead><tbody>';
        foreach ($disciplinas as $disc) {
            $discId = (int)$disc['ID'];
            $discNome = htmlspecialchars((string)$disc['Nome_disc']);
          $itemDisc = $notasDisciplina[$alLogin][$discId] ?? ['lista' => [], 'media' => null, 'observacao' => ''];
            $listaNotas = is_array($itemDisc['lista']) ? $itemDisc['lista'] : [];
            $mediaDisc = $itemDisc['media'];
          $obsDisc = htmlspecialchars((string)($itemDisc['observacao'] ?? ''));

          $htmlDisciplinas .= '<tr>';
          $htmlDisciplinas .= '<td><strong>' . $discNome . '</strong></td>';
          $htmlDisciplinas .= '<td>';

            if ($canEdit) {
            $htmlDisciplinas .= '<div class="disc-notas-inputs">';
            $htmlDisciplinas .= '<div class="notas-boxes" data-name="notas_multi[' . $alLogin . '][' . $discId . '][]">';
            if (empty($listaNotas)) {
              $listaNotas[] = null;
            }
            foreach ($listaNotas as $notaInd) {
              $valorBox = $notaInd === null ? '' : htmlspecialchars(number_format((float)$notaInd, 1, '.', ''));
              $htmlDisciplinas .= '<input type="number" class="nota-box" min="0" max="20" step="0.1" name="notas_multi[' . $alLogin . '][' . $discId . '][]" value="' . $valorBox . '" placeholder="0-20">';
            }
            $htmlDisciplinas .= '</div>';
            $htmlDisciplinas .= '<button type="button" class="add-nota-btn" onclick="addNotaBox(this)">+ nota</button>';
            $htmlDisciplinas .= '</div>';
            } else {
            if (empty($listaNotas)) {
              $htmlDisciplinas .= '<span class="meta-small">—</span>';
            } else {
              $htmlDisciplinas .= '<div class="notas-boxes readonly">';
              foreach ($listaNotas as $notaInd) {
                $htmlDisciplinas .= '<span class="nota-chip">' . htmlspecialchars(number_format((float)$notaInd, 1, '.', '')) . '</span>';
              }
              $htmlDisciplinas .= '</div>';
            }
            }
          $htmlDisciplinas .= '</td>';
          $htmlDisciplinas .= '<td><span class="meta-small">' . ($mediaDisc !== null ? htmlspecialchars(number_format((float)$mediaDisc, 1, '.', '')) : '—') . '</span></td>';
          if ($canEdit) {
            $htmlDisciplinas .= '<td><textarea class="obs-disc-mini" name="obs_disc[' . $alLogin . '][' . $discId . ']" rows="1" placeholder="Observação da disciplina">' . $obsDisc . '</textarea></td>';
          } else {
            $htmlDisciplinas .= '<td><span class="meta-small">' . ($obsDisc !== '' ? $obsDisc : '—') . '</span></td>';
          }
          $htmlDisciplinas .= '</tr>';
        }
        $htmlDisciplinas .= '</tbody></table></div>';

        $html .= '<tr>';
        $html .= '<td data-label="Aluno"><button type="button" class="aluno-name-toggle" onclick="toggleAlunoPerfil(\'' . $alunoRowId . '\', this)"><span class="aluno-name-chevron">▾</span>' . $nomeVisivel . '</button></td>';
        $html .= '<td data-label="Notas por disciplina"><button type="button" class="toggle-btn" onclick="toggleAlunoNotas(\'' . $alunoNotasRowId . '\', this)">Ver notas</button></td>';
        $html .= '<td data-label="Média final">' . $badge . '</td>';
        if ($canEdit) {
          $html .= '<td data-label="Observação geral"><textarea class="obs-mini" name="obs[' . $alLogin . ']" rows="1">' . $obs . '</textarea></td>';
        } else {
          $html .= '<td data-label="Observação geral"><span class="meta-small">' . ($obs !== '' ? $obs : '—') . '</span></td>';
        }
        if ($canEdit && $epocaPauta === 'Normal') {
          $fazExame = isset($n['faz_exame']) ? (bool)$n['faz_exame'] : true;
          $html .= '<td data-label="Faz exame?"><input type="checkbox" name="faz_exame[' . $alLogin . ']" value="1"' . ($fazExame ? ' checked' : '') . '></td>';
        }
        $html .= '<td data-label="Últ. alteração"><span class="meta-small">';
        if ($regBy || $regEm) {
            if ($hideEditorNames) {
              $html .= ($regEm !== '' ? $regEm : '—');
            } else {
              $html .= ($regBy ? $regBy : '') . ($regEm ? '<br>' . $regEm : '');
            }
        } else {
            $html .= '—';
        }
        $html .= '</span></td>';
        $html .= '</tr>';

        $html .= '<tr id="' . $alunoRowId . '" class="aluno-details-row">';
        $html .= '<td colspan="5" class="aluno-details-cell">';
        $html .= '<div class="aluno-details-grid">';
        if ($foto !== '') {
          $html .= '<div class="aluno-detail-item" style="grid-column: 1 / -1; display:flex; align-items:center; gap:10px;">';
          $html .= '<img src="' . $foto . '" alt="Foto do aluno" style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:2px solid #cbd5e1;">';
          $html .= '<span class="meta-small"><strong>' . $nomeVisivel . '</strong></span>';
          $html .= '</div>';
        }
        $html .= '<div class="aluno-detail-item"><strong>Login:</strong> ' . $al . '</div>';
        $html .= '<div class="aluno-detail-item"><strong>Nome:</strong> ' . $nomeVisivel . '</div>';
        $html .= '<div class="aluno-detail-item"><strong>Email:</strong> ' . $email . '</div>';
        $html .= '<div class="aluno-detail-item"><strong>Telefone:</strong> ' . $telefone . '</div>';
        $html .= '<div class="aluno-detail-item" style="grid-column: 1 / -1;"><strong>Morada:</strong> ' . $morada . '</div>';
        $html .= '</div>';
        $html .= '</td>';
        $html .= '</tr>';

        $html .= '<tr id="' . $alunoNotasRowId . '" class="aluno-details-row">';
        $html .= '<td colspan="5" class="aluno-details-cell">';
        $html .= '<div style="margin-top:2px;">' . $htmlDisciplinas . '</div>';
        $html .= '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';
  if ($canEdit) {
    $html .= '<div class="notas-actions"><button type="submit" name="gravar_notas" class="btn-sm btn-save">💾 Gravar notas</button></div>';
    $html .= '</form>';
  }

    return $html;
}
?>
<?php render_auto_logout_on_close_script(); ?>
</body>
</html>




