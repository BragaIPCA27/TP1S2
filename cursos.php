<?php
require_once 'config.php';
require_group(['ADMIN']);

$grupo = $_SESSION['user']['grupo_nome'] ?? 'ADMIN';
$tipoUtilizador = match ($grupo) {
  'ADMIN' => 'Admin',
  'FUNCIONARIO' => 'Funcionário',
  default => 'Aluno',
};

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

$cursosRes = $conn->query("SELECT ID, Nome, descricao FROM cursos ORDER BY Nome");
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
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                  <button type="submit" name="update_curso">Guardar</button>
                  <a class="action-btn" href="cursos.php" style="background:linear-gradient(135deg,#64748b,#475569);margin-right:0;">Cancelar</a>
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
          <div class="search-bar" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:flex-end;">
            <div style="flex:1;min-width:220px;">
              <label for="search_curso_admin" style="margin-bottom:4px;">Filtrar por curso</label>
              <select id="search_curso_admin" onchange="filtrarCursosAdmin()">
                <option value="">- Todos os cursos -</option>
                <?php foreach ($cursosLista as $curso): ?>
                  <option value="<?= htmlspecialchars(strtolower((string)$curso['Nome'])) ?>"><?= htmlspecialchars($curso['Nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="button" class="btn-sm" onclick="document.getElementById('search_curso_admin').value='';filtrarCursosAdmin();" style="background:linear-gradient(135deg,#64748b,#475569);margin-bottom:0;">Limpar</button>
          </div>
          <div id="sem-cursos-filtro" style="display:none;padding:10px 0;color:#888;">Nenhum curso corresponde ao filtro.</div>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Nome</th>
                  <th>Descrição</th>
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
                          <div style="margin-top:8px;white-space:normal;"><?= nl2br(htmlspecialchars($descricaoCurso)) ?></div>
                        </details>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                    <td data-label="Ações">
                      <a class="action-btn edit" href="cursos.php?edit_curso=<?= (int)$r['ID'] ?>">Editar</a>
                      <a class="action-btn" href="inserir.php?del_curso=<?= (int)$r['ID'] ?>" onclick="return confirm('Tem a certeza que deseja excluir este curso?')">Excluir</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (count($cursosLista) === 0): ?>
                  <tr>
                    <td colspan="4" class="empty-state">
                      <p>Nenhum curso cadastrado</p>
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



