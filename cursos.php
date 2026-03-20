<?php
require_once 'config.php';
require_group(['ADMIN']);

$grupo = $_SESSION['user']['grupo_nome'] ?? 'ADMIN';
$login = $_SESSION['user']['login'] ?? '';
$tipoUtilizador = match ($grupo) {
  'ADMIN' => 'Administrador',
  'FUNCIONARIO' => 'Funcionário',
  default => 'Aluno',
};

$perfilAdmin = [];
if ($login !== '') {
  $stmt = $conn->prepare("SELECT nome, email, telefone, morada, foto_path FROM admin_perfis WHERE login = ? LIMIT 1");
  $stmt->bind_param("s", $login);
  $stmt->execute();
  $perfilAdmin = $stmt->get_result()->fetch_assoc() ?: [];
}

$erro = null;
$ok = null;
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'already_exists') {
        $erro = "Este curso já existe.";
    } elseif ($_GET['error'] === 'empty') {
    $erro = "O nome do curso não pode estar vazio.";
    }
}
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'added') {
        $ok = "Curso adicionado com sucesso.";
  } elseif ($_GET['success'] === 'edited') {
    $ok = "Curso atualizado com sucesso.";
    }
}

$editCursoId = (int)($_GET['edit_curso'] ?? 0);
$editCurso = null;
if ($editCursoId > 0) {
  $stmt = $conn->prepare("SELECT ID, Nome, descricao FROM cursos WHERE ID = ? LIMIT 1");
  $stmt->bind_param("i", $editCursoId);
  $stmt->execute();
  $editCurso = $stmt->get_result()->fetch_assoc();

  if (!$editCurso) {
    $editCursoId = 0;
  }
}

$colSubmetidoPor = $conn->query("SHOW COLUMNS FROM cursos LIKE 'submetido_por'")->fetch_assoc();
if (!$colSubmetidoPor) {
  $conn->query("ALTER TABLE cursos ADD COLUMN submetido_por VARCHAR(20) DEFAULT NULL");
}
$colSubmetidoEm = $conn->query("SHOW COLUMNS FROM cursos LIKE 'submetido_em'")->fetch_assoc();
if (!$colSubmetidoEm) {
  $conn->query("ALTER TABLE cursos ADD COLUMN submetido_em DATETIME DEFAULT NULL");
}

$submetidoFallback = (string)($_SESSION['user']['login'] ?? 'gestor');
$stmtFillCursos = $conn->prepare(
  "UPDATE cursos
      SET submetido_por = CASE WHEN submetido_por IS NULL OR TRIM(submetido_por) = '' THEN ? ELSE submetido_por END,
          submetido_em = CASE WHEN submetido_em IS NULL THEN NOW() ELSE submetido_em END
    WHERE submetido_por IS NULL OR TRIM(submetido_por) = '' OR submetido_em IS NULL"
);
$stmtFillCursos->bind_param('s', $submetidoFallback);
$stmtFillCursos->execute();

$cursosRes = $conn->query("SELECT ID, Nome, descricao, submetido_por, submetido_em FROM cursos ORDER BY Nome");
$cursosLista = [];
while ($cursoItem = $cursosRes->fetch_assoc()) {
  $cursosLista[] = $cursoItem;
}
?>
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cursos</title>
  <style><?php include __DIR__ . '/assets/css/theme.css'; ?></style>
</head>
<body class='page-cursos'>
  <div class="container">
    <div class="header">
      <div class="header-left">
        <a href="index.php" aria-label="Ir para o menu principal">
          <img class="logo" src="<?= htmlspecialchars(logo_asset_path()) ?>" alt="Logótipo da escola">
        </a>
        <h2>Cursos</h2>
      </div>
      <a href="index.php" class="back-btn">← Voltar</a>
    </div>

    <div class="main">
      <div class="card">
        <div class="form-section">
          <h3><?= $editCurso ? 'Editar Curso' : 'Adicionar Novo Curso' ?></h3>

          <?php if ($erro): ?><div class="err"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
          <?php if ($ok): ?><div class="ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

          <form method="post" action="inserir.php">
            <div class="form-group">
              <div class="field-stack">
                <input name="nome" type="text" placeholder="Nome do curso" required value="<?= htmlspecialchars($editCurso['Nome'] ?? '') ?>">
                <textarea name="descricao" placeholder="Descrição do curso (opcional)"><?= htmlspecialchars($editCurso['descricao'] ?? '') ?></textarea>
              </div>
              <?php if ($editCurso): ?>
                <input type="hidden" name="curso_id" value="<?= (int)$editCurso['ID'] ?>">
                <div class="inline-actions-wrap">
                  <button type="submit" name="update_curso">Guardar</button>
                  <a class="action-btn action-btn-neutral no-right-margin" href="cursos.php">Cancelar</a>
                </div>
              <?php else: ?>
                <button type="submit" name="add_curso">Adicionar</button>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="table-section">
          <h3>Lista de Cursos</h3>
          <div class="search-bar cursos-filter-bar">
            <div class="filter-field-grow-220">
              <label for="search_curso_admin" class="filter-label-compact">Filtrar por curso</label>
              <select id="search_curso_admin" onchange="filtrarCursosAdmin()">
                <option value="">- Todos os cursos -</option>
                <?php foreach ($cursosLista as $curso): ?>
                  <option value="<?= htmlspecialchars(strtolower((string)$curso['Nome'])) ?>"><?= htmlspecialchars($curso['Nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="button" class="btn-sm btn-neutral btn-align-bottom" onclick="document.getElementById('search_curso_admin').value='';filtrarCursosAdmin();">Limpar</button>
          </div>
          <div id="sem-cursos-filtro" class="empty-filter-message">Nenhum curso corresponde ao filtro.</div>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Nome</th>
                  <th>Descrição</th>
                  <th>Submetido por</th>
                  <th>Data de Submissão</th>
                  <th>Ações</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($cursosLista as $r): ?>
                  <tr class="curso-item" data-curso="<?= htmlspecialchars(strtolower((string)$r['Nome'])) ?>">
                    <td data-label="ID"><?= (int)$r['ID'] ?></td>
                    <td data-label="Nome"><?= htmlspecialchars($r['Nome']) ?></td>
                    <td data-label="Descrição">
                      <?php $descricaoCurso = trim((string)($r['descricao'] ?? '')); ?>
                      <?php if ($descricaoCurso !== ''): ?>
                        <details>
                          <summary>Ver descrição</summary>
                          <div class="description-expanded-content"><?= nl2br(htmlspecialchars($descricaoCurso)) ?></div>
                        </details>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                    <td data-label="Submetido por (utilizador)">
                      <?php $submetidoPor = trim((string)($r['submetido_por'] ?? '')); ?>
                      <?php if ($submetidoPor !== '' && $submetidoPor !== '-'): ?>
                        <a href="alunos_admin.php?q=<?= urlencode($submetidoPor) ?>&open_login=<?= urlencode($submetidoPor) ?>" class="submitted-by-link">
                          <?= htmlspecialchars(nome_utilizador_por_login($conn, $submetidoPor)) ?>
                        </a>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                    <td data-label="Data de Submissão">
                      <span class="meta-small"><?= htmlspecialchars((string)($r['submetido_em'] ?? '-')) ?></span>
                    </td>
                    <td data-label="Ações">
                      <div class="cursos-action-btns">
                        <a class="action-btn edit" href="cursos.php?edit_curso=<?= (int)$r['ID'] ?>">Editar</a>
                        <a class="action-btn" href="inserir.php?del_curso=<?= (int)$r['ID'] ?>" onclick="return confirm('Tem a certeza que deseja excluir este curso?')">Excluir</a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (count($cursosLista) === 0): ?>
                  <tr>
                    <td colspan="6" class="empty-state">
                      <p>Nenhum curso registado</p>
                    </td>
                  </tr>
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
          <?php if (!empty($perfilAdmin['foto_path'])): ?>
            <img class="profile-photo" src="<?= htmlspecialchars((string)$perfilAdmin['foto_path']) ?>" alt="Fotografia de perfil">
          <?php endif; ?>
          <?php if (!empty($perfilAdmin['nome'])): ?><strong>Nome:</strong> <?= htmlspecialchars((string)$perfilAdmin['nome']) ?><br><?php endif; ?>
          <?php if (!empty($perfilAdmin['email'])): ?><strong>Email:</strong> <?= htmlspecialchars((string)$perfilAdmin['email']) ?><br><?php endif; ?>
          <?php if (!empty($perfilAdmin['telefone'])): ?><strong>Telefone:</strong> <?= htmlspecialchars((string)$perfilAdmin['telefone']) ?><br><?php endif; ?>
          <?php if (!empty($perfilAdmin['morada'])): ?><strong>Morada:</strong> <?= htmlspecialchars((string)$perfilAdmin['morada']) ?><br><?php endif; ?>
          <strong>Tipo de utilizador:</strong> <?= htmlspecialchars($tipoUtilizador) ?><br>
          <strong>Utilizador:</strong> <?= htmlspecialchars($_SESSION['user']['login']) ?>
        </div>
      </div>
    </div>
  </div>
  <script>
    function filtrarCursosAdmin() {
      const cursoSel = document.getElementById('search_curso_admin').value.trim();
      const rows = document.querySelectorAll('tr.curso-item');
      let visiveis = 0;

      rows.forEach(row => {
        const curso = row.dataset.curso || '';
        const match = !cursoSel || curso === cursoSel;
        row.style.display = match ? '' : 'none';
        if (match) visiveis++;
      });

      document.getElementById('sem-cursos-filtro').style.display = visiveis === 0 && rows.length > 0 ? 'block' : 'none';
    }
  </script>
  <?php render_auto_logout_on_close_script(); ?>
</body>
</html>



