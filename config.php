<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'ipca';

$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0, // Sessão expira ao fechar o navegador
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (isset($_SESSION['auto_logout_request_ts'])) {
    $graceSeconds = 2;
    $requestedAt = (int)$_SESSION['auto_logout_request_ts'];
    $elapsed = time() - $requestedAt;

    if ($elapsed >= $graceSeconds) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool)$params['secure'],
                (bool)$params['httponly']
            );
        }
        session_destroy();
        session_start();
    }
}

function ensure_schema(mysqli $conn): void {
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    $gruposNecessarios = ['ADMIN', 'ALUNO', 'FUNCIONARIO', 'GESTOR'];
    foreach ($gruposNecessarios as $grupoNome) {
        $stmt = $conn->prepare("SELECT ID FROM grupos WHERE GRUPO = ? LIMIT 1");
        $stmt->bind_param("s", $grupoNome);
        $stmt->execute();

        if ($stmt->get_result()->num_rows === 0) {
            $stmt = $conn->prepare("INSERT INTO grupos (GRUPO) VALUES (?)");
            $stmt->bind_param("s", $grupoNome);
            $stmt->execute();
        }
    }

    $cursoDescricao = $conn->query("SHOW COLUMNS FROM cursos LIKE 'descricao'")->fetch_assoc();
    if (!$cursoDescricao) {
        $conn->query("ALTER TABLE cursos ADD COLUMN descricao TEXT DEFAULT NULL AFTER Nome");
    }

    $planoSemestre = $conn->query("SHOW COLUMNS FROM plano_estudos LIKE 'semestre'")->fetch_assoc();
    if (!$planoSemestre) {
        $conn->query("ALTER TABLE plano_estudos ADD COLUMN semestre TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER DISCIPLINA");
    }

    $userApproval = $conn->query("SHOW COLUMNS FROM users LIKE 'approval_status'")->fetch_assoc();
    if (!$userApproval) {
        $conn->query(
            "ALTER TABLE users
             ADD COLUMN approval_status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'APPROVED',
             ADD COLUMN approved_by VARCHAR(20) DEFAULT NULL,
             ADD COLUMN approved_at DATETIME DEFAULT NULL"
        );

        $conn->query("UPDATE users SET approval_status = 'APPROVED' WHERE approval_status IS NULL");
    }

    $matriculaStatus = $conn->query("SHOW COLUMNS FROM matriculas LIKE 'status'")->fetch_assoc();
    if (!$matriculaStatus) {
        $conn->query(
            "ALTER TABLE matriculas
             ADD COLUMN status ENUM('PENDING','APPROVED','REJECTED','CANCEL_PENDING','CANCELLED','CANCEL_REJECTED') NOT NULL DEFAULT 'PENDING',
             ADD COLUMN approved_by VARCHAR(20) DEFAULT NULL,
             ADD COLUMN approved_at DATETIME DEFAULT NULL"
        );
    } else {
        $matriculaStatusType = strtolower((string)($matriculaStatus['Type'] ?? ''));
        if (
            strpos($matriculaStatusType, "'cancel_pending'") === false ||
            strpos($matriculaStatusType, "'cancelled'") === false ||
            strpos($matriculaStatusType, "'cancel_rejected'") === false
        ) {
            $conn->query(
                "ALTER TABLE matriculas
                 MODIFY COLUMN status ENUM('PENDING','APPROVED','REJECTED','CANCEL_PENDING','CANCELLED','CANCEL_REJECTED') NOT NULL DEFAULT 'PENDING'"
            );
        }
    }

    $perfilRequests = $conn->query("SHOW TABLES LIKE 'perfil_pedidos'")->fetch_assoc();
    if (!$perfilRequests) {
        $conn->query(
            "CREATE TABLE perfil_pedidos (
                login VARCHAR(20) NOT NULL,
                nome VARCHAR(120) NOT NULL,
                email VARCHAR(120) DEFAULT NULL,
                telefone VARCHAR(30) DEFAULT NULL,
                morada VARCHAR(200) DEFAULT NULL,
                foto_path VARCHAR(255) DEFAULT NULL,
                status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_by VARCHAR(20) DEFAULT NULL,
                reviewed_at DATETIME DEFAULT NULL,
                PRIMARY KEY (login),
                CONSTRAINT fk_perfil_pedidos_users FOREIGN KEY (login) REFERENCES users (login) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    $alunosFoto = $conn->query("SHOW COLUMNS FROM alunos LIKE 'foto_path'")->fetch_assoc();
    if (!$alunosFoto) {
        $conn->query("ALTER TABLE alunos ADD COLUMN foto_path VARCHAR(255) DEFAULT NULL");
    }

    $perfilFoto = $conn->query("SHOW COLUMNS FROM perfil_pedidos LIKE 'foto_path'")->fetch_assoc();
    if (!$perfilFoto) {
        $conn->query("ALTER TABLE perfil_pedidos ADD COLUMN foto_path VARCHAR(255) DEFAULT NULL AFTER morada");
    }

    $adminPerfisTable = $conn->query("SHOW TABLES LIKE 'admin_perfis'")->fetch_assoc();
    if (!$adminPerfisTable) {
        $conn->query(
            "CREATE TABLE admin_perfis (
                login VARCHAR(20) NOT NULL,
                nome VARCHAR(120) NOT NULL,
                email VARCHAR(120) DEFAULT NULL,
                telefone VARCHAR(30) DEFAULT NULL,
                morada VARCHAR(200) DEFAULT NULL,
                foto_path VARCHAR(255) DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (login),
                CONSTRAINT fk_admin_perfis_users FOREIGN KEY (login) REFERENCES users (login) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

        $perfilObsNome = $conn->query("SHOW COLUMNS FROM perfil_pedidos LIKE 'obs_nome'")->fetch_assoc();
        if (!$perfilObsNome) {
            $conn->query(
                "ALTER TABLE perfil_pedidos
                 ADD COLUMN obs_nome TEXT DEFAULT NULL,
                 ADD COLUMN obs_telefone TEXT DEFAULT NULL,
                 ADD COLUMN obs_morada TEXT DEFAULT NULL,
                 ADD COLUMN obs_foto TEXT DEFAULT NULL,
                 ADD COLUMN obs_rejeicao TEXT DEFAULT NULL"
            );
        }

    // Observaï¿½ï¿½o no processo de aprovaï¿½ï¿½o/rejeiï¿½ï¿½o de matrï¿½culas
    $matriculaObs = $conn->query("SHOW COLUMNS FROM matriculas LIKE 'observacao'")->fetch_assoc();
    if (!$matriculaObs) {
        $conn->query("ALTER TABLE matriculas ADD COLUMN observacao TEXT DEFAULT NULL");
    }

    // Pautas de avaliaï¿½ï¿½o
    $pautasTable = $conn->query("SHOW TABLES LIKE 'pautas'")->fetch_assoc();
    if (!$pautasTable) {
        $conn->query(
            "CREATE TABLE pautas (
                pauta_id   INT NOT NULL AUTO_INCREMENT,
                curso_id   INT NOT NULL,
                ano_letivo VARCHAR(9) NOT NULL,
                semestre   TINYINT UNSIGNED NOT NULL DEFAULT 1,
                epoca      ENUM('Normal','Recurso','Especial') NOT NULL DEFAULT 'Normal',
                criado_por VARCHAR(20) NOT NULL,
                criado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (pauta_id),
                CONSTRAINT fk_pautas_curso FOREIGN KEY (curso_id) REFERENCES cursos (ID) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    $pautasSemestre = $conn->query("SHOW COLUMNS FROM pautas LIKE 'semestre'")->fetch_assoc();
    if (!$pautasSemestre) {
        $conn->query("ALTER TABLE pautas ADD COLUMN semestre TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER ano_letivo");
    }

    $pautasTipoAvaliacao = $conn->query("SHOW COLUMNS FROM pautas LIKE 'tipo_avaliacao'")->fetch_assoc();
    if (!$pautasTipoAvaliacao) {
        $conn->query("ALTER TABLE pautas ADD COLUMN tipo_avaliacao ENUM('Continua','Exame') NOT NULL DEFAULT 'Continua' AFTER epoca");
    }

    // Notas por pauta
    $pautaNotasTable = $conn->query("SHOW TABLES LIKE 'pauta_notas'")->fetch_assoc();
    if (!$pautaNotasTable) {
        $conn->query(
            "CREATE TABLE pauta_notas (
                pauta_id       INT NOT NULL,
                login          VARCHAR(20) NOT NULL,
                nota           DECIMAL(4,1) DEFAULT NULL,
                observacao     TEXT DEFAULT NULL,
                registado_por  VARCHAR(20) DEFAULT NULL,
                registado_em   DATETIME DEFAULT NULL,
                faz_exame      BOOLEAN NOT NULL DEFAULT TRUE,
                PRIMARY KEY (pauta_id, login),
                CONSTRAINT fk_pautas_notas_pauta FOREIGN KEY (pauta_id) REFERENCES pautas (pauta_id) ON DELETE CASCADE,
                CONSTRAINT fk_pautas_notas_user  FOREIGN KEY (login) REFERENCES users (login) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    $pautaNotasFazExame = $conn->query("SHOW COLUMNS FROM pauta_notas LIKE 'faz_exame'")->fetch_assoc();
    if (!$pautaNotasFazExame) {
        $conn->query("ALTER TABLE pauta_notas ADD COLUMN faz_exame BOOLEAN NOT NULL DEFAULT TRUE");
    }

    $pautaNotasDiscTable = $conn->query("SHOW TABLES LIKE 'pauta_notas_disciplinas'")->fetch_assoc();
    if (!$pautaNotasDiscTable) {
        $conn->query(
            "CREATE TABLE pauta_notas_disciplinas (
                pauta_id      INT NOT NULL,
                login         VARCHAR(20) NOT NULL,
                disciplina_id INT NOT NULL,
                notas_json    TEXT DEFAULT NULL,
                media         DECIMAL(4,1) DEFAULT NULL,
                observacao    TEXT DEFAULT NULL,
                registado_por VARCHAR(20) DEFAULT NULL,
                registado_em  DATETIME DEFAULT NULL,
                PRIMARY KEY (pauta_id, login, disciplina_id),
                CONSTRAINT fk_pnd_pauta      FOREIGN KEY (pauta_id) REFERENCES pautas (pauta_id) ON DELETE CASCADE,
                CONSTRAINT fk_pnd_login      FOREIGN KEY (login) REFERENCES users (login) ON DELETE CASCADE,
                CONSTRAINT fk_pnd_disciplina FOREIGN KEY (disciplina_id) REFERENCES disciplinas (ID) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    $pautaNotasDiscObs = $conn->query("SHOW COLUMNS FROM pauta_notas_disciplinas LIKE 'observacao'")->fetch_assoc();
    if (!$pautaNotasDiscObs) {
        $conn->query("ALTER TABLE pauta_notas_disciplinas ADD COLUMN observacao TEXT DEFAULT NULL AFTER media");
    }

    $funcionarioCursosTable = $conn->query("SHOW TABLES LIKE 'funcionario_cursos'")->fetch_assoc();
    if (!$funcionarioCursosTable) {
        $conn->query(
            "CREATE TABLE funcionario_cursos (
                funcionario_login VARCHAR(20) NOT NULL,
                curso_id          INT NOT NULL,
                assigned_by       VARCHAR(20) DEFAULT NULL,
                assigned_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (funcionario_login, curso_id),
                CONSTRAINT fk_funcionario_cursos_user FOREIGN KEY (funcionario_login) REFERENCES users (login) ON DELETE CASCADE,
                CONSTRAINT fk_funcionario_cursos_curso FOREIGN KEY (curso_id) REFERENCES cursos (ID) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    $funcionarioCursoPedidosTable = $conn->query("SHOW TABLES LIKE 'funcionario_curso_pedidos'")->fetch_assoc();
    if (!$funcionarioCursoPedidosTable) {
        $conn->query(
            "CREATE TABLE funcionario_curso_pedidos (
                id                 INT NOT NULL AUTO_INCREMENT,
                funcionario_login  VARCHAR(20) NOT NULL,
                curso_id           INT NOT NULL,
                status             VARCHAR(20) DEFAULT 'PENDING',
                solicitado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revisado_por       VARCHAR(20) DEFAULT NULL,
                revisado_em        DATETIME DEFAULT NULL,
                motivo_rejeicao    TEXT DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY unique_pedido (funcionario_login, curso_id),
                CONSTRAINT fk_func_curso_ped_user FOREIGN KEY (funcionario_login) REFERENCES users (login) ON DELETE CASCADE,
                CONSTRAINT fk_func_curso_ped_curso FOREIGN KEY (curso_id) REFERENCES cursos (ID) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    $alunoNotificacoesTable = $conn->query("SHOW TABLES LIKE 'aluno_notificacoes'")->fetch_assoc();
    if (!$alunoNotificacoesTable) {
        $conn->query(
            "CREATE TABLE aluno_notificacoes (
                id         INT NOT NULL AUTO_INCREMENT,
                login      VARCHAR(20) NOT NULL,
                mensagem   TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at    DATETIME DEFAULT NULL,
                dismissed_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_aluno_notificacoes_login_read (login, read_at),
                CONSTRAINT fk_aluno_notificacoes_users FOREIGN KEY (login) REFERENCES users (login) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    } else {
        $alunoNotificacoesDismissed = $conn->query("SHOW COLUMNS FROM aluno_notificacoes LIKE 'dismissed_at'")->fetch_assoc();
        if (!$alunoNotificacoesDismissed) {
            $conn->query("ALTER TABLE aluno_notificacoes ADD COLUMN dismissed_at DATETIME DEFAULT NULL AFTER read_at");
        }
    }
}

ensure_schema($conn);

// Helpers
function is_logged_in(): bool {
    return isset($_SESSION['user']);
}

function admin_profile_is_complete(mysqli $conn, string $login): bool {
    $stmt = $conn->prepare("SELECT nome, email, telefone, morada FROM admin_perfis WHERE login = ? LIMIT 1");
    $stmt->bind_param('s', $login);
    $stmt->execute();
    $perfil = $stmt->get_result()->fetch_assoc();

    if (!$perfil) {
        return false;
    }

    return trim((string)($perfil['nome'] ?? '')) !== ''
        && trim((string)($perfil['email'] ?? '')) !== ''
        && trim((string)($perfil['telefone'] ?? '')) !== ''
        && trim((string)($perfil['morada'] ?? '')) !== '';
}

function enforce_admin_profile_completion(mysqli $conn): void {
    if (!is_logged_in()) {
        return;
    }

    $grupo = $_SESSION['user']['grupo_nome'] ?? '';
    if ($grupo !== 'ADMIN') {
        return;
    }

    $login = (string)($_SESSION['user']['login'] ?? '');
    if ($login === '' || admin_profile_is_complete($conn, $login)) {
        return;
    }

    $currentPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowPages = ['perfil_admin.php', 'index.php', 'logout.php'];
    if (in_array($currentPage, $allowPages, true)) {
        return;
    }

    header('Location: perfil_admin.php?complete=1');
    exit;
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: frontpage.php');
        exit;
    }

    global $conn;
    enforce_admin_profile_completion($conn);
}

function require_group(array $allowed): void {
    require_login();
    $g = $_SESSION['user']['grupo_nome'] ?? '';
    if (!in_array($g, $allowed, true)) {
        http_response_code(403);
        echo "Acesso negado.";
        exit;
    }
}

function render_back_to_top_script(): void {
    $setaPath = 'assets/img/seta.png';
    $setaFsPath = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'seta.png';
    if (is_file($setaFsPath)) {
        $setaPath .= '?v=' . filemtime($setaFsPath);
    }
    $setaSrcJs = json_encode($setaPath, JSON_UNESCAPED_SLASHES);

        echo <<<HTML
<script>
(function () {
    if (document.getElementById('global-back-to-top')) return;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'global-back-to-top';
    btn.className = 'back-to-top';
    btn.setAttribute('aria-label', 'Voltar ao topo');
    btn.setAttribute('title', 'Voltar ao topo');

    const icon = document.createElement('img');
    icon.className = 'back-to-top-icon';
    icon.src = {$setaSrcJs};
    icon.alt = '';
    icon.setAttribute('aria-hidden', 'true');
    btn.appendChild(icon);

    function toggleVisibility() {
        btn.classList.toggle('is-visible', window.scrollY > 420);
    }

    btn.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.body.appendChild(btn);
        toggleVisibility();
    });

    window.addEventListener('scroll', toggleVisibility, { passive: true });
})();
</script>
HTML;
}

function render_auto_logout_on_close_script(): void {
        render_back_to_top_script();

        if (!is_logged_in()) {
                return;
        }

        echo <<<HTML
<script>
(function () {
    let internalNavigation = false;

    function sendKeepalive(url) {
        if (navigator.sendBeacon) {
            navigator.sendBeacon(url, '');
            return;
        }

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
        }).catch(function () {});
    }

    // Cancela um eventual pedido de auto-logout quando a navegação continua ativa.
    sendKeepalive('logout.php?silent=1&cancel=1');

    document.addEventListener('click', function (event) {
        const link = event.target.closest('a[href]');
        if (!link) return;
        if (link.target && link.target !== '_self') return;

        const href = link.getAttribute('href') || '';
        if (href.startsWith('#') || href.startsWith('javascript:')) return;

        try {
            const url = new URL(link.href, window.location.href);
            if (url.origin === window.location.origin) {
                internalNavigation = true;
            }
        } catch (e) {
            // Ignore malformed URLs.
        }
    }, true);

    document.addEventListener('submit', function () {
        internalNavigation = true;
    }, true);

    window.addEventListener('pagehide', function () {
        if (internalNavigation) return;

        sendKeepalive('logout.php?silent=1&defer=1');
    });
})();
</script>
HTML;
}

function criar_notificacao_aluno(mysqli $conn, string $login, string $mensagem): void {
    $stmt = $conn->prepare("INSERT INTO aluno_notificacoes (login, mensagem) VALUES (?, ?)");
    $stmt->bind_param('ss', $login, $mensagem);
    $stmt->execute();
}

function listar_notificacoes_aluno(mysqli $conn, string $login): array {
    $stmt = $conn->prepare(
        "SELECT id, mensagem, created_at, read_at
           FROM aluno_notificacoes
          WHERE login = ? AND dismissed_at IS NULL
          ORDER BY created_at DESC, id DESC"
    );
    $stmt->bind_param('s', $login);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function contar_notificacoes_nao_lidas(mysqli $conn, string $login): int {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
           FROM aluno_notificacoes
          WHERE login = ? AND read_at IS NULL AND dismissed_at IS NULL"
    );
    $stmt->bind_param('s', $login);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

function marcar_notificacoes_aluno_como_lidas(mysqli $conn, string $login): void {
    $stmt = $conn->prepare(
        "UPDATE aluno_notificacoes
            SET read_at = NOW()
          WHERE login = ? AND read_at IS NULL AND dismissed_at IS NULL"
    );
    $stmt->bind_param('s', $login);
    $stmt->execute();
}

function remover_notificacao_aluno(mysqli $conn, string $login, int $notificacaoId): void {
    $stmt = $conn->prepare(
        "UPDATE aluno_notificacoes
            SET dismissed_at = NOW(),
                read_at = COALESCE(read_at, NOW())
          WHERE id = ? AND login = ? AND dismissed_at IS NULL"
    );
    $stmt->bind_param('is', $notificacaoId, $login);
    $stmt->execute();
}

function nome_utilizador_por_login(mysqli $conn, ?string $login): string {
    $login = trim((string)$login);
    if ($login === '') {
        return '-';
    }

    static $cache = [];
    if (isset($cache[$login])) {
        return $cache[$login];
    }

    $stmt = $conn->prepare(
        "SELECT COALESCE(NULLIF(a.nome, ''), NULLIF(ap.nome, ''), u.login) AS nome
           FROM users u
      LEFT JOIN alunos a ON a.login = u.login
      LEFT JOIN admin_perfis ap ON ap.login = u.login
          WHERE u.login = ?
          LIMIT 1"
    );
    $stmt->bind_param('s', $login);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    $nome = trim((string)($row['nome'] ?? ''));
    if ($nome === '') {
        $nome = $login;
    }

    $cache[$login] = $nome;
    return $nome;
}

function logo_asset_path(): string {
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $filePath = __DIR__ . '/assets/img/logo.png';
    $version = @filemtime($filePath);

    if ($version === false) {
        $cached = 'assets/img/logo.png';
        return $cached;
    }

    $cached = 'assets/img/logo.png?v=' . (int)$version;
    return $cached;
}
