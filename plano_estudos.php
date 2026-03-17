<?php
require_once 'config.php';
require_group(['ADMIN','ALUNO']);

$grupo = $_SESSION['user']['grupo_nome'] ?? '';
$login = $_SESSION['user']['login'];

$perfil = null;
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

$erro = null;
$ok = null;
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'already_exists') {
        $erro = "Este vínculo já existe.";
    }
}
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'added') {
        $ok = "Vínculo adicionado com sucesso.";
    }
}

// Para ADMIN, mostrar todo o plano
// Para ALUNO, mostrar apenas dos cursos em que está matriculado
if ($grupo === 'ADMIN') {
  $plano = $conn->query("
    SELECT p.CURSOS AS curso_id, p.DISCIPLINA AS disciplina_id, COALESCE(p.semestre, 1) AS semestre,
           c.Nome AS curso, d.Nome_disc AS disciplina
    FROM plano_estudos p
    JOIN cursos c ON c.ID = p.CURSOS
    JOIN disciplinas d ON d.ID = p.DISCIPLINA
    ORDER BY c.Nome, COALESCE(p.semestre, 1), d.Nome_disc
  ");
} else {
  // ALUNO: só mostra plano dos cursos em que está matriculado
  $stmt = $conn->prepare("
    SELECT p.CURSOS AS curso_id, p.DISCIPLINA AS disciplina_id, COALESCE(p.semestre, 1) AS semestre,
           c.Nome AS curso, d.Nome_disc AS disciplina
    FROM plano_estudos p
    JOIN cursos c ON c.ID = p.CURSOS
    JOIN disciplinas d ON d.ID = p.DISCIPLINA
    JOIN matriculas m ON m.curso_id = c.ID
    WHERE m.login = ?
      AND COALESCE(m.status, 'APPROVED') IN ('APPROVED', 'CANCEL_REJECTED')
    ORDER BY c.Nome, COALESCE(p.semestre, 1), d.Nome_disc
  ");
  $stmt->bind_param("s", $login);
  $stmt->execute();
  $plano = $stmt->get_result();
}

$cursos_list = null;
if ($grupo === 'ADMIN') {
  $cursos_list = $conn->query("SELECT ID, Nome FROM cursos ORDER BY Nome");
} else {
  $stmtCursosFiltro = $conn->prepare(
    "SELECT DISTINCT c.ID, c.Nome
     FROM cursos c
     JOIN matriculas m ON m.curso_id = c.ID
     WHERE m.login = ?
       AND COALESCE(m.status, 'APPROVED') IN ('APPROVED', 'CANCEL_REJECTED')
     ORDER BY c.Nome"
  );
  $stmtCursosFiltro->bind_param("s", $login);
  $stmtCursosFiltro->execute();
  $cursos_list = $stmtCursosFiltro->get_result();
}
$disc_list = null;
if ($grupo === 'ADMIN') {
  $disc_list = $conn->query("SELECT ID, Nome_disc FROM disciplinas ORDER BY Nome_disc");
} else {
  $stmtDiscFiltro = $conn->prepare(
    "SELECT DISTINCT d.ID, d.Nome_disc
     FROM disciplinas d
     JOIN plano_estudos p ON p.DISCIPLINA = d.ID
     JOIN matriculas m ON m.curso_id = p.CURSOS
     WHERE m.login = ?
       AND COALESCE(m.status, 'APPROVED') IN ('APPROVED', 'CANCEL_REJECTED')
     ORDER BY d.Nome_disc"
  );
  $stmtDiscFiltro->bind_param("s", $login);
  $stmtDiscFiltro->execute();
  $disc_list = $stmtDiscFiltro->get_result();
}
?>
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Plano de Estudos</title>
  <style><?php include __DIR__ . '/assets/css/theme.css'; ?></style>
</head>
<body class='page-plano-estudos'>
  <div class="container">
    <div class="header">
      <div class="header-left">
        <a href="index.php" aria-label="Ir para o menu principal">
          <img class="logo" src="<?= htmlspecialchars(logo_asset_path()) ?>" alt="Logótipo da escola">
        </a>
        <h2>Plano de Estudos</h2>
      </div>
      <a href="index.php" class="back-btn">← Voltar</a>
    </div>

    <div class="main">
      <?php if ($grupo === 'ADMIN'): ?>
        <div class="card">
          <div class="form-section">
            <h3>Adicionar Vínculo</h3>

            <?php if ($erro): ?><div class="err"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
            <?php if ($ok): ?><div class="ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

            <form method="post" action="inserir.php">
              <div class="form-row">
                <select name="curso_id" required>
                  <option value="">Selecione um Curso</option>
                  <?php 
                  $cursos_list->data_seek(0);
                  while($c = $cursos_list->fetch_assoc()): ?>
                    <option value="<?= (int)$c['ID'] ?>"><?= htmlspecialchars($c['Nome']) ?></option>
                  <?php endwhile; ?>
                </select>

                <select name="disciplina_id" required>
                  <option value="">Selecione uma Disciplina</option>
                  <?php 
                  $disc_list->data_seek(0);
                  while($d = $disc_list->fetch_assoc()): ?>
                    <option value="<?= (int)$d['ID'] ?>"><?= htmlspecialchars($d['Nome_disc']) ?></option>
                  <?php endwhile; ?>
                </select>

                <select name="semestre" required>
                  <option value="1">1.º semestre</option>
                  <option value="2">2.º semestre</option>
                </select>

                <button type="submit" name="add_plano">Adicionar</button>
              </div>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="table-section">
          <h3><?= $grupo === 'ADMIN' ? 'Disciplinas por Curso' : 'Disciplinas dos teus Cursos' ?></h3>
          <div class="search-bar" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:flex-end;">
            <div style="flex:1;min-width:200px;">
              <label for="search_plano_curso" style="margin-bottom:4px;">Filtrar por curso</label>
              <select id="search_plano_curso" onchange="filtrarPlano()">
                <option value="">— Todos os cursos —</option>
                <?php $cursos_list->data_seek(0); while ($cl = $cursos_list->fetch_assoc()): ?>
                  <option value="<?= htmlspecialchars(strtolower($cl['Nome'])) ?>"><?= htmlspecialchars($cl['Nome']) ?></option>
                <?php endwhile; $cursos_list->data_seek(0); ?>
              </select>
            </div>
            <div style="flex:1;min-width:200px;">
              <label for="search_plano_disc" style="margin-bottom:4px;">Filtrar por disciplina</label>
              <select id="search_plano_disc" onchange="filtrarPlano()">
                <option value="">— Todas as disciplinas —</option>
                <?php $disc_list->data_seek(0); while ($dl = $disc_list->fetch_assoc()): ?>
                  <option value="<?= htmlspecialchars(strtolower($dl['Nome_disc'])) ?>"><?= htmlspecialchars($dl['Nome_disc']) ?></option>
                <?php endwhile; $disc_list->data_seek(0); ?>
              </select>
            </div>
            <div style="flex:1;min-width:170px;">
              <label for="search_plano_sem" style="margin-bottom:4px;">Filtrar por semestre</label>
              <select id="search_plano_sem" onchange="filtrarPlano()">
                <option value="">— Todos os semestres —</option>
                <option value="1">1.º semestre</option>
                <option value="2">2.º semestre</option>
              </select>
            </div>
            <button type="button" onclick="document.getElementById('search_plano_curso').value='';document.getElementById('search_plano_disc').value='';document.getElementById('search_plano_sem').value='';filtrarPlano();" class="btn-sm" style="background:linear-gradient(135deg,#64748b,#475569);margin-bottom:0;">Limpar</button>
          </div>
          <div id="sem-resultados-plano" style="display:none;padding:16px;text-align:center;color:#888;">Nenhum resultado encontrado.</div>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Cursos e disciplinas</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $cursosPlano = [];
                while ($r = $plano->fetch_assoc()) {
                  $cursoId = (int)$r['curso_id'];
                  $semestreNum = ((int)($r['semestre'] ?? 1) === 2) ? 2 : 1;

                  if (!isset($cursosPlano[$cursoId])) {
                    $cursosPlano[$cursoId] = [
                      'curso' => (string)$r['curso'],
                      'curso_nome_lower' => strtolower((string)$r['curso']),
                      'semestres' => [
                        1 => [],
                        2 => [],
                      ],
                    ];
                  }

                  $cursosPlano[$cursoId]['semestres'][$semestreNum][] = [
                    'curso_id' => $cursoId,
                    'disciplina_id' => (int)$r['disciplina_id'],
                    'disciplina' => (string)$r['disciplina'],
                    'disciplina_lower' => strtolower((string)$r['disciplina']),
                    'semestre' => $semestreNum,
                  ];
                }

                $cursosPlanoLista = array_values($cursosPlano);
                if ($cursosPlanoLista === []):
                ?>
                  <tr>
                    <td class="empty-state">
                      <p><?= $grupo === 'ADMIN' ? 'Nenhum plano de estudos configurado' : 'Não há disciplinas para os teus cursos' ?></p>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($cursosPlanoLista as $idx => $cursoData): ?>
                    <?php $curso_index = $idx + 1; ?>
                    <tr class="curso-row" id="curso-header-<?= $curso_index ?>" data-curso-nome="<?= htmlspecialchars($cursoData['curso_nome_lower']) ?>">
                      <td>
                        <button type="button" class="course-trigger" onclick="toggleCurso(<?= $curso_index ?>, this)" aria-expanded="false">
                          <span class="course-label">
                            <span class="course-title"><?= htmlspecialchars($cursoData['curso']) ?></span>
                            <span class="course-hint">Inspecionar disciplinas</span>
                          </span>
                          <span class="course-chevron">▼</span>
                        </button>
                      </td>
                    </tr>
                    <tr class="curso-panels-row" id="curso-panels-<?= $curso_index ?>">
                      <td>
                        <div class="semestres-grid">
                          <?php foreach ([1, 2] as $semestreNum): ?>
                            <?php $disciplinasSemestre = $cursoData['semestres'][$semestreNum] ?? []; ?>
                            <section class="semestre-panel semestre-panel-<?= $semestreNum ?>" data-semestre-panel="<?= $semestreNum ?>">
                              <h4><?= $semestreNum ?>.º semestre</h4>
                              <?php if ($disciplinasSemestre !== []): ?>
                                <ul class="semestre-list" aria-label="<?= $semestreNum ?>.º semestre">
                                  <?php foreach ($disciplinasSemestre as $disc): ?>
                                    <li class="disciplina-row curso-<?= $curso_index ?> semestre-disc-<?= $semestreNum ?>" data-disc-nome="<?= htmlspecialchars($disc['disciplina_lower']) ?>" data-disc-label="<?= htmlspecialchars($disc['disciplina']) ?>" data-semestre="<?= $semestreNum ?>">
                                      <span class="disciplina-item-name"><?= htmlspecialchars($disc['disciplina']) ?></span>
                                      <?php if ($grupo === 'ADMIN'): ?>
                                        <span class="semestre-action-col">
                                          <a class="action-btn" href="inserir.php?del_plano_curso=<?= (int)$disc['curso_id'] ?>&del_plano_disciplina=<?= (int)$disc['disciplina_id'] ?>" onclick="return confirm('Tem a certeza que deseja remover este vínculo?')">Remover</a>
                                        </span>
                                      <?php endif; ?>
                                    </li>
                                  <?php endforeach; ?>
                                </ul>
                              <?php else: ?>
                                <p class="semestre-empty">Sem disciplinas neste semestre.</p>
                              <?php endif; ?>
                            </section>
                          <?php endforeach; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
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

  <script>
    function atualizarPaineisSemestre(cursoId) {
      const detailRow = document.getElementById('curso-panels-' + cursoId);
      if (!detailRow) return;

      const panels = detailRow.querySelectorAll('[data-semestre-panel]');
      panels.forEach(function (panel) {
        const sem = panel.getAttribute('data-semestre-panel') || '';
        const rows = detailRow.querySelectorAll('.disciplina-row[data-semestre="' + sem + '"]');
        let hasVisible = false;

        rows.forEach(function (row) {
          if (row.style.display !== 'none') {
            hasVisible = true;
          }
        });

        panel.style.display = hasVisible ? 'block' : 'none';
      });
    }

    function atualizarFiltrosDependentes() {
      const cursoSelect = document.getElementById('search_plano_curso');
      const discSelect = document.getElementById('search_plano_disc');
      const semSelect = document.getElementById('search_plano_sem');

      if (!cursoSelect || !discSelect || !semSelect) return;

      const cursoSelecionado = cursoSelect.value.trim();

      const todosRows = Array.from(document.querySelectorAll('.disciplina-row[data-disc-nome]'));

      const rowsCurso = cursoSelecionado
        ? todosRows.filter(function (row) {
            const classes = Array.from(row.classList);
            const cursoClass = classes.find(function (c) { return c.indexOf('curso-') === 0; });
            if (!cursoClass) return false;
            const header = document.getElementById('curso-header-' + cursoClass.replace('curso-', ''));
            if (!header) return false;
            return (header.dataset.cursoNome || '') === cursoSelecionado;
          })
        : todosRows;

      const disciplinasMap = new Map();
      const semestresSet = new Set();

      rowsCurso.forEach(function (row) {
        const discNome = row.dataset.discNome || '';
        const discLabel = row.dataset.discLabel || row.dataset.discNome || '';
        if (discNome !== '' && !disciplinasMap.has(discNome)) {
          disciplinasMap.set(discNome, discLabel);
        }
        const sem = row.dataset.semestre || '';
        if (sem === '1' || sem === '2') {
          semestresSet.add(sem);
        }
      });

      const valorDiscAtual = discSelect.value;
      const valorSemAtual = semSelect.value;

      discSelect.innerHTML = '<option value="">— Todas as disciplinas —</option>';
      Array.from(disciplinasMap.entries())
        .sort(function (a, b) { return a[1].localeCompare(b[1], 'pt'); })
        .forEach(function (entry) {
          const opt = document.createElement('option');
          opt.value = entry[0];
          opt.textContent = entry[1];
          discSelect.appendChild(opt);
        });

      semSelect.innerHTML = '<option value="">— Todos os semestres —</option>';
      ['1', '2'].forEach(function (sem) {
        if (semestresSet.has(sem)) {
          const opt = document.createElement('option');
          opt.value = sem;
          opt.textContent = sem + '.º semestre';
          semSelect.appendChild(opt);
        }
      });

      if (valorDiscAtual && disciplinasMap.has(valorDiscAtual)) {
        discSelect.value = valorDiscAtual;
      }

      if (valorSemAtual && semestresSet.has(valorSemAtual)) {
        semSelect.value = valorSemAtual;
      }
    }

    function sincronizarCursosPlano() {
      const headers = document.querySelectorAll('tr.curso-row[id^="curso-header-"]');

      headers.forEach(header => {
        const cursoId = header.id.replace('curso-header-', '');
        const detailRow = document.getElementById('curso-panels-' + cursoId);
        const rows = detailRow ? detailRow.querySelectorAll('.disciplina-row') : [];
        const hasOpen = !!(detailRow && detailRow.classList.contains('open'));

        rows.forEach(row => {
          const isMatch = row.dataset.match !== '0';
          row.style.display = hasOpen && isMatch ? '' : 'none';
        });

        if (detailRow) {
          detailRow.style.display = hasOpen ? 'table-row' : 'none';
        }

        atualizarPaineisSemestre(cursoId);

        header.classList.toggle('is-open', hasOpen);
        const button = header.querySelector('.course-trigger');
        if (button) {
          button.setAttribute('aria-expanded', hasOpen ? 'true' : 'false');
          const hint = button.querySelector('.course-hint');
          if (hint) {
            hint.textContent = hasOpen ? 'Ocultar disciplinas' : 'Inspecionar disciplinas';
          }
        }
      });
    }

    function filtrarPlano() {
      atualizarFiltrosDependentes();
      const termCurso = document.getElementById('search_plano_curso').value.trim();
      const termDisc  = document.getElementById('search_plano_disc').value.trim();
      const termSem   = document.getElementById('search_plano_sem').value.trim();
      const headers = document.querySelectorAll('tr.curso-row[data-curso-nome]');
      let visiveis = 0;

      headers.forEach(header => {
        const cursoNome = header.dataset.cursoNome || '';
        const cursoId = header.id.replace('curso-header-', '');
        const detailRow = document.getElementById('curso-panels-' + cursoId);
        const discRows = detailRow ? detailRow.querySelectorAll('.disciplina-row') : [];

        if (!termCurso && !termDisc && !termSem) {
          header.style.display = '';
          const aberto = !!(detailRow && detailRow.classList.contains('open'));
          if (detailRow) {
            detailRow.style.display = aberto ? 'table-row' : 'none';
          }
          discRows.forEach(r => {
            r.dataset.match = '1';
            r.style.display = aberto ? '' : 'none';
          });
          atualizarPaineisSemestre(cursoId);
          visiveis++;
          return;
        }

        const cursoMatch = !termCurso || cursoNome === termCurso;
        let anyDiscMatch = false;
        discRows.forEach(r => {
          const semestreMatch = !termSem || (r.dataset.semestre || '') === termSem;
          const discMatch = cursoMatch && semestreMatch && (!termDisc || (r.dataset.discNome || '') === termDisc);
          r.dataset.match = discMatch ? '1' : '0';
          r.style.display = discMatch ? '' : 'none';
          if (discMatch) anyDiscMatch = true;
        });

        const show = cursoMatch && anyDiscMatch;
        header.style.display = show ? '' : 'none';
        if (detailRow) {
          detailRow.style.display = show ? 'table-row' : 'none';
          detailRow.classList.toggle('open', show);
        }
        atualizarPaineisSemestre(cursoId);
        if (show) visiveis++;
      });

      document.getElementById('sem-resultados-plano').style.display = visiveis === 0 ? 'block' : 'none';
    }

    function toggleCurso(index, button) {
      var detailRow = document.getElementById('curso-panels-' + index);
      var header = document.getElementById('curso-header-' + index);
      if (!detailRow) return;

      var shouldOpen = !detailRow.classList.contains('open');
      detailRow.classList.toggle('open', shouldOpen);
      detailRow.style.display = shouldOpen ? 'table-row' : 'none';

      var rows = detailRow.querySelectorAll('.disciplina-row');
      for (var i = 0; i < rows.length; i++) {
        var match = rows[i].dataset.match !== '0';
        rows[i].style.display = shouldOpen && match ? '' : 'none';
      }

      atualizarPaineisSemestre(index);

      if (header) {
        header.classList.toggle('is-open', shouldOpen);
      }

      if (button) {
        button.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        var hint = button.querySelector('.course-hint');
        if (hint) {
          hint.textContent = shouldOpen ? 'Ocultar disciplinas' : 'Inspecionar disciplinas';
        }
      }
    }

    window.addEventListener('DOMContentLoaded', function () {
      sincronizarCursosPlano();
      atualizarFiltrosDependentes();
    });
  </script>
  <?php render_auto_logout_on_close_script(); ?>
</body>
</html>



