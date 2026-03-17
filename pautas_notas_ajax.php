<?php
require_once 'config.php';
require_group(['ADMIN', 'FUNCIONARIO', 'ALUNO']);

$login = $_SESSION['user']['login'];
$grupo = $_SESSION['user']['grupo_nome'] ?? '';
$canEdit = ($grupo === 'FUNCIONARIO');

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

$pauta_id = (int)($_GET['pauta_id'] ?? 0);
if ($pauta_id <= 0) { exit; }

function carregar_disciplinas_da_pauta_ajax(mysqli $conn, int $pautaId): array {
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

function carregar_notas_por_disciplina_ajax(mysqli $conn, int $pautaId): array {
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

function calcular_media_curso_pauta_ajax(mysqli $conn, int $pautaId): ?float {
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

function sincronizar_alunos_pauta_normal_ajax(mysqli $conn, int $pautaId): void {
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO pauta_notas (pauta_id, login)
         SELECT p.pauta_id, m.login
           FROM pautas p
           JOIN matriculas m ON m.curso_id = p.curso_id
          WHERE p.pauta_id = ?
            AND p.epoca = 'Normal'
                        AND m.status IN ('APPROVED', 'CANCEL_REJECTED')"
    );
    $stmt->bind_param('i', $pautaId);
    $stmt->execute();
}

function carregar_alunos_disponiveis_para_pauta_ajax(mysqli $conn, int $pautaId): array {
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

function renderInserirAlunoManualAjax(int $pautaId, bool $canEdit, string $epoca, array $alunosDisponiveis, string $tipoAvaliacao = 'Continua'): string {
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
        $html .= '<form method="post" action="pautas.php" style="margin:0;display:inline;">';
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

// Verificar que a pauta existe e que o utilizador tem acesso
if ($grupo === 'ALUNO') {
    $chk = $conn->prepare(
        "SELECT p.pauta_id
         FROM pautas p
         JOIN matriculas m ON m.curso_id = p.curso_id
            WHERE p.pauta_id = ? AND m.login = ? AND m.status IN ('APPROVED', 'CANCEL_REJECTED')
         LIMIT 1"
    );
    $chk->bind_param("is", $pauta_id, $login);
} elseif ($grupo === 'FUNCIONARIO') {
    $chk = $conn->prepare(
        "SELECT p.pauta_id
         FROM pautas p
         JOIN funcionario_cursos fc ON fc.curso_id = p.curso_id
        WHERE p.pauta_id = ? AND fc.funcionario_login = ?
         LIMIT 1"
    );
    $chk->bind_param("is", $pauta_id, $login);
} else {
    $chk = $conn->prepare("SELECT pauta_id FROM pautas WHERE pauta_id = ? LIMIT 1");
    $chk->bind_param("i", $pauta_id);
}
$chk->execute();
if ($chk->get_result()->num_rows === 0) { exit; }

$metaStmt = $conn->prepare("SELECT epoca, tipo_avaliacao FROM pautas WHERE pauta_id = ? LIMIT 1");
$metaStmt->bind_param('i', $pauta_id);
$metaStmt->execute();
$pautaMeta = $metaStmt->get_result()->fetch_assoc();
$epocaPauta = (string)($pautaMeta['epoca'] ?? '');
$tipoAvaliacao = (string)($pautaMeta['tipo_avaliacao'] ?? 'Continua');

if ($tipoAvaliacao === 'Continua' && $epocaPauta === 'Normal') {
    sincronizar_alunos_pauta_normal_ajax($conn, $pauta_id);
}

$ns = null;
if ($grupo === 'ALUNO') {
    $ns = $conn->prepare(
        "SELECT pn.login, pn.nota, pn.observacao, pn.registado_por, pn.registado_em,
                a.nome AS aluno_nome, a.email AS aluno_email, a.telefone AS aluno_telefone, a.morada AS aluno_morada, a.foto_path AS aluno_foto
         FROM pauta_notas pn
         LEFT JOIN alunos a ON a.login = pn.login
         WHERE pn.pauta_id = ? AND pn.login = ?
         ORDER BY pn.login"
    );
    $ns->bind_param("is", $pauta_id, $login);
} else {
    $ns = $conn->prepare(
        "SELECT pn.login, pn.nota, pn.observacao, pn.registado_por, pn.registado_em,
                a.nome AS aluno_nome, a.email AS aluno_email, a.telefone AS aluno_telefone, a.morada AS aluno_morada, a.foto_path AS aluno_foto
         FROM pauta_notas pn
         LEFT JOIN alunos a ON a.login = pn.login
         WHERE pn.pauta_id = ?
         ORDER BY pn.login"
    );
    $ns->bind_param("i", $pauta_id);
}
$ns->execute();
$notas = $ns->get_result()->fetch_all(MYSQLI_ASSOC);

$disciplinas = carregar_disciplinas_da_pauta_ajax($conn, $pauta_id);
$notasDisciplina = carregar_notas_por_disciplina_ajax($conn, $pauta_id);
$alunosDisponiveis = [];
$mostrarSelecaoManual = ($tipoAvaliacao === 'Exame') || ($tipoAvaliacao === 'Continua' && in_array($epocaPauta, ['Recurso', 'Especial'], true));
if ($canEdit && $mostrarSelecaoManual) {
    $alunosDisponiveis = carregar_alunos_disponiveis_para_pauta_ajax($conn, $pauta_id);
}

echo renderInserirAlunoManualAjax($pauta_id, $canEdit, $epocaPauta, $alunosDisponiveis, $tipoAvaliacao);
echo renderNotasForm(
    $pauta_id,
    $notas,
    $canEdit,
    $disciplinas,
    $notasDisciplina,
    $epocaPauta,
    $epocaPauta === 'Normal' ? calcular_media_curso_pauta_ajax($conn, $pauta_id) : null
);

function renderNotasForm(int $pauta_id, array $notas, bool $canEdit, array $disciplinas, array $notasDisciplina, string $epocaPauta = '', ?float $mediaCurso = null): string {
    global $grupo;
    $hideEditorNames = ($grupo === 'ALUNO');

    if (empty($notas)) {
        return '<p style="color:#888;font-style:italic;">Nenhum aluno inscrito nesta pauta. Verifique se existem matr&iacute;culas aprovadas para o curso.</p>';
    }

    if (empty($disciplinas)) {
        return '<p style="color:#888;font-style:italic;">Este curso não tem disciplinas associadas no plano de estudos.</p>';
    }

    $html = '';
    if ($epocaPauta === 'Normal') {
        $mediaTxt = $mediaCurso !== null
            ? htmlspecialchars(number_format($mediaCurso, 1, '.', ''))
            : '&mdash;';
        $html .= '<div class="pauta-media-curso"><strong>Média do curso:</strong> ' . $mediaTxt . '</div>';
    }

    if ($canEdit) {
        $html .= '<form method="post" action="pautas.php">';
        $html .= '<input type="hidden" name="pauta_id" value="' . $pauta_id . '">';
    }
    $html .= '<table class="notas-table">';
    $html .= '<thead><tr><th>Aluno</th><th>Notas por disciplina</th><th>Média final</th><th>Observação geral</th><th>Última alteração</th></tr></thead>';
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

        if ($n['nota'] === null) {
            $badge = '<span class="nota-badge nota-nd">&mdash;</span>';
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
                $htmlDisciplinas .= '<td><span class="meta-small">' . ($obsDisc !== '' ? $obsDisc : '&mdash;') . '</span></td>';
            }
            $htmlDisciplinas .= '</tr>';
        }
        $htmlDisciplinas .= '</tbody></table></div>';

        $html .= '<tr>';
        $html .= '<td data-label="Aluno"><button type="button" class="aluno-name-toggle" onclick="toggleAlunoPerfil(\'' . $alunoRowId . '\', this)"><span class="aluno-name-chevron">&#9662;</span>' . $nomeVisivel . '</button></td>';
        $html .= '<td data-label="Notas por disciplina"><button type="button" class="toggle-btn" onclick="toggleAlunoNotas(\'' . $alunoNotasRowId . '\', this)">Ver notas</button></td>';
        $html .= '<td data-label="Média final">' . $badge . '</td>';
        if ($canEdit) {
            $html .= '<td data-label="Observação geral"><textarea class="obs-mini" name="obs[' . $alLogin . ']" rows="1">' . $obs . '</textarea></td>';
        } else {
            $html .= '<td data-label="Observação geral"><span class="meta-small">' . ($obs !== '' ? $obs : '&mdash;') . '</span></td>';
        }
        $html .= '<td data-label="Últ. alteração"><span class="meta-small">';
        if ($regBy || $regEm) {
            if ($hideEditorNames) {
                $html .= ($regEm !== '' ? $regEm : '&mdash;');
            } else {
                $html .= ($regBy ? $regBy : '') . ($regEm ? '<br>' . $regEm : '');
            }
        } else {
            $html .= '&mdash;';
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
        $html .= '<div class="notas-actions"><button type="submit" name="gravar_notas" class="btn-sm btn-save">Gravar notas</button></div>';
        $html .= '</form>';
    }

    return $html;
}

