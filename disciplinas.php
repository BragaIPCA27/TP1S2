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
        $erro = "Esta disciplina já existe.";
    } elseif ($_GET['error'] === 'empty') {
        $erro = "O nome da disciplina não pode estar vazio.";
    }
}
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'added') {
        $ok = "Disciplina adicionada com sucesso.";
  } elseif ($_GET['success'] === 'edited') {
    $ok = "Disciplina atualizada com sucesso.";
    }
}

$editDisciplinaId = (int)($_GET['edit_disciplina'] ?? 0);
$editDisciplina = null;
if ($editDisciplinaId > 0) {
  $stmt = $conn->prepare("SELECT ID, Nome_disc FROM disciplinas WHERE ID = ? LIMIT 1");
  $stmt->bind_param("i", $editDisciplinaId);
  $stmt->execute();
  $editDisciplina = $stmt->get_result()->fetch_assoc();

  if (!$editDisciplina) {
    $editDisciplinaId = 0;
  }
}

$colSubmetidoPor = $conn->query("SHOW COLUMNS FROM disciplinas LIKE 'submetido_por'")->fetch_assoc();
if (!$colSubmetidoPor) {
  $conn->query("ALTER TABLE disciplinas ADD COLUMN submetido_por VARCHAR(20) DEFAULT NULL");
}
$colSubmetidoEm = $conn->query("SHOW COLUMNS FROM disciplinas LIKE 'submetido_em'")->fetch_assoc();
if (!$colSubmetidoEm) {
  $conn->query("ALTER TABLE disciplinas ADD COLUMN submetido_em DATETIME DEFAULT NULL");
}

$submetidoFallback = (string)($_SESSION['user']['login'] ?? 'gestor');
$stmtFillDisciplinas = $conn->prepare(
  "UPDATE disciplinas
      SET submetido_por = CASE WHEN submetido_por IS NULL OR TRIM(submetido_por) = '' THEN ? ELSE submetido_por END,
          submetido_em = CASE WHEN submetido_em IS NULL THEN NOW() ELSE submetido_em END
    WHERE submetido_por IS NULL OR TRIM(submetido_por) = '' OR submetido_em IS NULL"
);
$stmtFillDisciplinas->bind_param('s', $submetidoFallback);
$stmtFillDisciplinas->execute();

$disciplinas = $conn->query("SELECT ID, Nome_disc, submetido_por, submetido_em FROM disciplinas ORDER BY Nome_disc");
?>
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Disciplinas</title>
  <style><?php include __DIR__ . '/assets/css/theme.css'; ?></style>
</head>
<body class='page-disciplinas'>
  <div class="container">
    <div class="header">
      <div class="header-left">
        <a href="index.php" aria-label="Ir para o menu principal">
          <img class="logo" src="<?= htmlspecialchars(logo_asset_path()) ?>" alt="Logótipo da escola">
        </a>
        <h2>Disciplinas</h2>
      </div>
      <a href="index.php" class="back-btn">← Voltar</a>
    </div>

    <div class="main">
      <div class="card">
        <div class="form-section">
          <h3><?= $editDisciplina ? 'Editar Disciplina' : 'Adicionar Nova Disciplina' ?></h3>

          <?php if ($erro): ?><div class="err"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
          <?php if ($ok): ?><div class="ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

          <form method="post" action="inserir.php">
            <div class="form-group">
              <input name="nome" type="text" placeholder="Nome da disciplina" required value="<?= htmlspecialchars($editDisciplina['Nome_disc'] ?? '') ?>">
              <?php if ($editDisciplina): ?>
                <input type="hidden" name="disciplina_id" value="<?= (int)$editDisciplina['ID'] ?>">
                <button type="submit" name="update_disciplina">Guardar</button>
                <a class="cancel-link" href="disciplinas.php">Cancelar</a>
              <?php else: ?>
                <button type="submit" name="add_disciplina">Adicionar</button>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="table-section">
          <h3>Lista de Disciplinas</h3>
          <div class="search-bar disciplinas-filter-bar">
            <div class="filter-field-grow-200">
              <label for="search_disciplina" class="filter-label-compact">Pesquisar</label>
              <label for="search_disciplina" class="filter-label-compact">Filtrar por disciplina</label>
              <select id="search_disciplina" onchange="filtrarDisciplinas()">
                <option value="">— Todas as disciplinas —</option>
                <?php $disciplinas->data_seek(0); while ($rd = $disciplinas->fetch_assoc()): ?>
                  <option value="<?= htmlspecialchars(strtolower($rd['Nome_disc'])) ?>"><?= htmlspecialchars($rd['Nome_disc']) ?></option>
                <?php endwhile; $disciplinas->data_seek(0); ?>
              </select>
            </div>
            <button type="button" onclick="document.getElementById('search_disciplina').value='';filtrarDisciplinas();" class="btn-sm btn-neutral btn-align-bottom">Limpar</button>
          </div>
          <div id="sem-disciplinas" class="empty-search-message">Nenhuma disciplina encontrada.</div>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Nome</th>
                  <th>Submetido por</th>
                  <th>Data de Submissão</th>
                  <th>Ações</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $count = 0;
                while($r = $disciplinas->fetch_assoc()): 
                  $count++;
                ?>
                  <tr class="disciplina-item" data-nome="<?= htmlspecialchars(strtolower($r['Nome_disc'])) ?>">
                    <td data-label="ID"><?= (int)$r['ID'] ?></td>
                    <td data-label="Nome"><?= htmlspecialchars($r['Nome_disc']) ?></td>
                    <td data-label="Submetido por (utilizador)">
                      <?php $submetidoPor = trim((string)($r['submetido_por'] ?? '')); ?>
                      <?php if ($submetidoPor !== '' && $submetidoPor !== '-'): ?>
                        <a href="alunos_admin.php?q=<?= urlencode($submetidoPor) ?>&open_login=<?= urlencode($submetidoPor) ?>" class="submitted-by-link" style="display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:999px;border:1px solid #bfdbfe;background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 100%);color:#1e3a8a;font-size:12px;font-weight:700;text-decoration:none;box-shadow:0 4px 10px rgba(30,58,138,0.12);transition:transform 0.18s,box-shadow 0.18s,background 0.18s,color 0.18s,border-color 0.18s;">
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
                      <a class="action-btn edit" href="disciplinas.php?edit_disciplina=<?= (int)$r['ID'] ?>">Editar</a>
                      <a class="action-btn" href="inserir.php?del_disciplina=<?= (int)$r['ID'] ?>" onclick="return confirm('Tem a certeza que deseja excluir esta disciplina?')">Excluir</a>
                    </td>
                  </tr>
                <?php endwhile; ?>
                <?php if ($count === 0): ?>
                  <tr>
                    <td colspan="5" class="empty-state">
                      <p>Nenhuma disciplina cadastrada</p>
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
  function filtrarDisciplinas() {
    const term = document.getElementById('search_disciplina').value.trim();
    const rows = document.querySelectorAll('tr.disciplina-item');
    let visiveis = 0;
    rows.forEach(row => {
      const match = !term || (row.dataset.nome || '').includes(term);
      row.style.display = match ? '' : 'none';
      if (match) visiveis++;
    });
    document.getElementById('sem-disciplinas').style.display = visiveis === 0 ? 'block' : 'none';
  }
  </script>
  <?php render_auto_logout_on_close_script(); ?>
</body>
</html>



