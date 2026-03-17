<?php
require_once 'config.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$cursos = [];
$totais = [
    'cursos' => 0,
    'disciplinas' => 0,
];

$resCursos = $conn->query("SELECT ID, Nome, COALESCE(descricao, '') AS descricao FROM cursos ORDER BY Nome");
while ($curso = $resCursos->fetch_assoc()) {
    $cursoId = (int)$curso['ID'];
  $curso['disciplinas'] = [
    1 => [],
    2 => [],
  ];
    $cursos[$cursoId] = $curso;
}

if ($cursos !== []) {
    $resDisciplinas = $conn->query(
    "SELECT p.CURSOS AS curso_id, COALESCE(p.semestre, 1) AS semestre, d.Nome_disc
         FROM plano_estudos p
         JOIN disciplinas d ON d.ID = p.DISCIPLINA
     ORDER BY p.CURSOS, COALESCE(p.semestre, 1), d.Nome_disc"
    );

    while ($disc = $resDisciplinas->fetch_assoc()) {
        $cursoId = (int)$disc['curso_id'];
    $semestre = (int)($disc['semestre'] ?? 1);
    if ($semestre !== 2) {
      $semestre = 1;
    }
        if (isset($cursos[$cursoId])) {
      $cursos[$cursoId]['disciplinas'][$semestre][] = $disc['Nome_disc'];
            $totais['disciplinas']++;
        }
    }
}

$listaCursos = array_values($cursos);
$totais['cursos'] = count($listaCursos);
?>
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>IPCA - Oferta Formativa</title>
  <style><?php include __DIR__ . '/assets/css/theme.css'; ?></style>
</head>
<body class="page-front">
  <div class="front-glow front-glow-left" aria-hidden="true"></div>
  <div class="front-glow front-glow-right" aria-hidden="true"></div>

  <header class="front-header">
    <div class="container front-header-inner">
      <a class="front-brand" href="frontpage.php" aria-label="Página inicial pública">
        <img class="logo" src="<?= htmlspecialchars(logo_asset_path()) ?>" alt="Logótipo da universidade">
      </a>
      <nav class="front-actions" aria-label="Ações de acesso">
        <a class="front-link" href="registar.php">Criar conta</a>
        <a class="front-btn" href="login.php">Entrar</a>
      </nav>
    </div>
  </header>

  <main>
    <section class="front-hero container">
      <div class="front-hero-copy">
        <p class="front-eyebrow">Instituto Politécnico do Cávado e do Ave</p>
        <h1>Explora a nossa oferta de cursos e começa o teu percurso académico.</h1>
        <p class="front-intro">
          Esta plataforma reúne informação essencial sobre os cursos, áreas de estudo e disciplinas disponíveis.
          Consulta os detalhes, compara opções e entra para gerir matrículas, pautas e o teu plano de estudos.
        </p>
        <div class="front-hero-cta">
          <a class="front-btn" href="login.php">Aceder à plataforma</a>
          <a class="front-link" href="#cursos">Ver cursos</a>
        </div>
      </div>

      <aside class="front-stats" aria-label="Resumo da oferta formativa">
        <article class="front-stat-card">
          <span class="front-stat-value"><?= (int)$totais['cursos'] ?></span>
          <span class="front-stat-label">Cursos disponíveis</span>
        </article>
        <article class="front-stat-card">
          <span class="front-stat-value"><?= (int)$totais['disciplinas'] ?></span>
          <span class="front-stat-label">Disciplinas associadas</span>
        </article>
        <article class="front-stat-card front-stat-note">
          <span class="front-stat-label">Ensino orientado à prática</span>
          <p>Formação com foco em competências técnicas, projetos reais e preparação para o mercado de trabalho.</p>
        </article>
      </aside>
    </section>

    <section class="container front-about">
      <h2>Sobre a universidade</h2>
      <p>
        O IPCA promove um ensino superior próximo, inovador e alinhado com as necessidades da sociedade.
        A nossa comunidade académica combina rigor científico com ligação às empresas e instituições da região.
      </p>
      <p>
        Através desta plataforma, estudantes e candidatos podem acompanhar cursos, disciplinas e processos académicos
        num único local, com uma experiência simples e clara.
      </p>
    </section>

    <section class="container front-courses" id="cursos">
      <div class="front-courses-head">
        <h2>Cursos em destaque</h2>
        <p>Seleciona um curso para conhecer a descrição e algumas disciplinas do plano de estudos.</p>
      </div>

      <?php if ($listaCursos !== []): ?>
        <div class="front-course-picker-wrap">
          <label for="front-course-picker">Escolhe um curso</label>
          <select id="front-course-picker" class="front-course-picker" aria-controls="front-courses-grid">
            <option value="" selected disabled hidden>Seleciona um curso...</option>
            <option value="__all__">Mostrar todos</option>
            <?php foreach ($listaCursos as $curso): ?>
              <option value="<?= (int)$curso['ID'] ?>"><?= htmlspecialchars($curso['Nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <p class="front-course-select-hint" id="front-course-select-hint">Os cursos ficam visíveis após seleção.</p>

        <div class="front-courses-grid" id="front-courses-grid">
          <?php foreach ($listaCursos as $curso): ?>
            <?php
              $disciplinasSem1 = $curso['disciplinas'][1] ?? [];
              $disciplinasSem2 = $curso['disciplinas'][2] ?? [];
              $temDisciplinas = ($disciplinasSem1 !== [] || $disciplinasSem2 !== []);
            ?>
            <article class="front-course-card is-hidden" data-course-id="<?= (int)$curso['ID'] ?>">
              <h3><?= htmlspecialchars($curso['Nome']) ?></h3>

              <?php if ($curso['descricao'] !== ''): ?>
                <p class="front-course-desc"><?= htmlspecialchars($curso['descricao']) ?></p>
              <?php else: ?>
                <p class="front-course-desc">Descrição em atualização.</p>
              <?php endif; ?>

              <?php if ($temDisciplinas): ?>
                <h4>Disciplinas</h4>
                <?php if ($disciplinasSem1 !== []): ?>
                  <p class="front-semester-title">1.º semestre</p>
                  <ul>
                    <?php foreach ($disciplinasSem1 as $disc): ?>
                      <li><?= htmlspecialchars($disc) ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>

                <?php if ($disciplinasSem2 !== []): ?>
                  <p class="front-semester-title">2.º semestre</p>
                  <ul>
                    <?php foreach ($disciplinasSem2 as $disc): ?>
                      <li><?= htmlspecialchars($disc) ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              <?php else: ?>
                <p class="front-no-disc">Sem disciplinas associadas de momento.</p>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="front-empty">De momento não existem cursos disponíveis para apresentar.</p>
      <?php endif; ?>
    </section>
  </main>
  <script>
    (function () {
      const picker = document.getElementById('front-course-picker');
      const hint = document.getElementById('front-course-select-hint');
      if (!picker) {
        return;
      }

      const cards = document.querySelectorAll('.front-course-card[data-course-id]');

      function applyCourseFilter() {
        const selected = picker.value;
        const showAll = selected === '__all__';
        let visibleCount = 0;

        cards.forEach(function (card) {
          const isMatch = showAll || (selected !== '' && card.getAttribute('data-course-id') === selected);
          card.classList.toggle('is-hidden', !isMatch);
          if (isMatch) {
            visibleCount++;
          }
        });

        if (hint) {
          if (showAll) {
            hint.textContent = 'A mostrar todos os cursos disponíveis.';
          } else {
            hint.textContent = visibleCount > 0
              ? 'Curso selecionado. Podes escolher outro a qualquer momento.'
              : 'Os cursos ficam visíveis após seleção.';
          }
        }
      }

      picker.addEventListener('change', applyCourseFilter);
      applyCourseFilter();
    })();
  </script>
  <?php render_back_to_top_script(); ?>
</body>
</html>
