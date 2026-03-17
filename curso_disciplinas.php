<?php
require_once 'config.php';

$cursoId = (int)($_GET['curso_id'] ?? 0);
$curso = null;
$disciplinas = [];

if ($cursoId > 0) {
    $stmt = $conn->prepare("SELECT ID, Nome, COALESCE(descricao, '') AS descricao FROM cursos WHERE ID = ? LIMIT 1");
    $stmt->bind_param("i", $cursoId);
    $stmt->execute();
    $curso = $stmt->get_result()->fetch_assoc();

    if ($curso) {
        $stmt = $conn->prepare(
            "SELECT d.Nome_disc
             FROM plano_estudos p
             JOIN disciplinas d ON d.ID = p.DISCIPLINA
             WHERE p.CURSOS = ?
             ORDER BY d.Nome_disc"
        );
        $stmt->bind_param("i", $cursoId);
        $stmt->execute();
        $resDisciplinas = $stmt->get_result();

        while ($disc = $resDisciplinas->fetch_assoc()) {
            $disciplinas[] = $disc['Nome_disc'];
        }
    }
}

if (!$curso) {
    http_response_code(404);
}
?>
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Disciplinas do Curso</title>
  <style><?php include __DIR__ . '/assets/css/theme.css'; ?></style>
</head>
<body class='page-curso-disciplinas'>
  <div class="container">
    <div class="card">
      <?php if ($curso): ?>
        <h1><?= htmlspecialchars($curso['Nome']) ?></h1>
        <?php if ($curso['descricao'] !== ''): ?>
          <p class="descricao"><?= htmlspecialchars($curso['descricao']) ?></p>
        <?php endif; ?>

        <h2 class="section-title">Disciplinas deste curso</h2>
        <?php if ($disciplinas): ?>
          <ul>
            <?php foreach ($disciplinas as $disciplina): ?>
              <li><?= htmlspecialchars($disciplina) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="empty">Este curso ainda não tem disciplinas associadas.</p>
        <?php endif; ?>
      <?php else: ?>
        <h1>Curso não encontrado</h1>
        <p class="empty">O curso selecionado não existe ou foi removido.</p>
      <?php endif; ?>

      <a class="back" href="login.php">Voltar ao login</a>
    </div>
  </div>
  <?php render_back_to_top_script(); ?>
</body>
</html>




