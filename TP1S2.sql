-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 18, 2026 at 12:14 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ipca`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_perfis`
--

CREATE TABLE `admin_perfis` (
  `login` varchar(20) NOT NULL,
  `nome` varchar(120) NOT NULL,
  `email` varchar(120) DEFAULT NULL,
  `telefone` varchar(30) DEFAULT NULL,
  `morada` varchar(200) DEFAULT NULL,
  `foto_path` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_perfis`
--

INSERT INTO `admin_perfis` (`login`, `nome`, `email`, `telefone`, `morada`, `foto_path`, `updated_at`) VALUES
('gestor', 'Rui Cancelo', 'rui@rui.pt', '9145066708', 'Rua do Ano', 'assets/img/perfis/perfil_df6de4603d898b2e5ee5ffdb843735b0.jpg', '2026-03-17 19:50:28');

-- --------------------------------------------------------

--
-- Table structure for table `alunos`
--

CREATE TABLE `alunos` (
  `login` varchar(20) NOT NULL,
  `matricula` varchar(30) NOT NULL,
  `nome` varchar(120) NOT NULL,
  `email` varchar(120) DEFAULT NULL,
  `telefone` varchar(30) DEFAULT NULL,
  `morada` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `foto_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `alunos`
--

INSERT INTO `alunos` (`login`, `matricula`, `nome`, `email`, `telefone`, `morada`, `created_at`, `updated_at`, `foto_path`) VALUES
('aluno', 'aluno', 'Fabio Borges', 'abc@abc.pt', '3189898982', 'avenida 2', '2026-03-17 11:51:05', NULL, 'assets/img/perfis/perfil_a62595a657923ecfbea3d6f030379ea6.jpg'),
('aluno1', 'aluno1', 'Jorge Miguel', 'frota@frota.pt', '9145066759', 'Rua do Mes', '2026-03-13 19:23:35', '2026-03-14 02:29:27', 'assets/img/perfis/perfil_45538e9fbb988c4e446c20ad44419a91.jpg'),
('func', 'func', 'Pedro Borges', 'pedro@pedro.pt', '3798989898', 'avenida', '2026-03-13 16:53:26', '2026-03-17 20:07:27', 'assets/img/perfis/perfil_bbbca0037a53b2c50904c0b827ebfd0c.jpg');

-- --------------------------------------------------------

--
-- Table structure for table `aluno_notificacoes`
--

CREATE TABLE `aluno_notificacoes` (
  `id` int(11) NOT NULL,
  `login` varchar(20) NOT NULL,
  `mensagem` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  `dismissed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `aluno_notificacoes`
--

INSERT INTO `aluno_notificacoes` (`id`, `login`, `mensagem`, `created_at`, `read_at`, `dismissed_at`) VALUES
(1, 'aluno1', 'A tua matrícula no curso Comércio Eletrónico foi eliminada pelos serviços académicos.', '2026-03-14 02:19:40', '2026-03-14 02:19:42', '2026-03-14 02:35:14'),
(2, 'aluno1', 'O teu pedido de cancelamento da matrícula no curso Comércio Eletrónico foi recusado pelos serviços académicos.', '2026-03-14 02:26:34', '2026-03-14 02:26:39', '2026-03-14 02:35:13'),
(3, 'aluno1', 'A tua matrícula no curso Comércio Eletrónico foi reposta ao estado aprovado pelos serviços académicos.', '2026-03-14 02:26:37', '2026-03-14 02:26:39', '2026-03-14 02:35:13'),
(4, 'aluno1', 'O teu pedido de cancelamento da matrícula no curso Comércio Eletrónico foi recusado pelos serviços académicos.', '2026-03-14 02:27:22', '2026-03-14 02:27:23', '2026-03-14 02:35:12'),
(5, 'aluno1', 'A tua matrícula no curso Comércio Eletrónico foi reposta ao estado aprovado pelos serviços académicos.', '2026-03-14 02:29:14', '2026-03-14 02:29:30', '2026-03-14 02:35:12'),
(6, 'aluno1', 'O teu pedido de alteração de perfil foi aprovado pelos serviços académicos.', '2026-03-14 02:29:27', '2026-03-14 02:29:30', '2026-03-14 02:35:11'),
(7, 'aluno1', 'O teu pedido de alteração de perfil foi aprovado pelos serviços académicos.', '2026-03-14 02:33:04', '2026-03-14 02:34:57', '2026-03-14 02:35:10'),
(8, 'aluno1', 'O teu pedido de alteração de perfil foi aprovado pelos serviços académicos.', '2026-03-14 02:40:54', '2026-03-14 02:42:32', '2026-03-14 02:42:43'),
(9, 'aluno1', 'O teu pedido de cancelamento da matrícula no curso Comércio Eletrónico foi aprovado pelos serviços académicos.', '2026-03-14 02:42:20', '2026-03-14 02:42:32', '2026-03-14 02:42:43'),
(10, 'aluno1', 'O teu pedido de matrícula no curso Comércio Eletrónico foi aprovado pelos serviços académicos.', '2026-03-14 02:43:45', '2026-03-14 02:43:57', '2026-03-14 02:43:58'),
(12, 'func', 'Seu pedido para o curso Análise de Dados foi aprovado.', '2026-03-14 17:45:42', '2026-03-14 17:45:48', NULL),
(13, 'func', 'Seu pedido para o curso Desenvolvimento Web e Multimédia foi aprovado.', '2026-03-14 17:45:43', '2026-03-14 17:45:48', NULL),
(14, 'func', 'Seu pedido para o curso Engenharia de Software foi aprovado.', '2026-03-14 17:59:38', '2026-03-14 18:17:35', NULL),
(15, 'aluno1', 'A tua matrícula no curso Comércio Eletrónico foi eliminada pelos serviços académicos.', '2026-03-17 11:49:00', '2026-03-17 11:49:30', '2026-03-17 11:49:33'),
(16, 'aluno1', 'O teu pedido de matrícula no curso Comércio Eletrónico foi aprovado pelos serviços académicos.', '2026-03-17 11:50:17', '2026-03-17 20:00:30', '2026-03-17 20:20:58'),
(17, 'aluno', 'A tua conta foi aprovada pelos serviços académicos. Bem-vindo a plataforma do IPCA.', '2026-03-17 11:53:31', '2026-03-17 11:54:23', '2026-03-17 11:54:43'),
(18, 'aluno', 'O teu pedido de matrícula no curso Redes de Computadores foi aprovado pelos serviços académicos.', '2026-03-17 11:54:00', '2026-03-17 11:54:23', '2026-03-17 11:54:42'),
(19, 'aluno', 'O teu pedido de matrícula no curso Comércio Eletrónico foi aprovado pelos serviços académicos.', '2026-03-17 11:57:04', '2026-03-17 20:28:52', NULL),
(20, 'aluno1', 'O teu pedido de alteração de perfil foi aprovado pelos serviços académicos.', '2026-03-17 20:06:49', '2026-03-17 20:20:56', '2026-03-17 20:20:57'),
(21, 'func', 'O teu pedido de alteração de perfil foi aprovado pelos serviços académicos.', '2026-03-17 20:07:27', '2026-03-17 20:21:09', NULL),
(22, 'aluno1', 'O teu pedido de matrícula no curso Administração de Sistemas e Redes foi recusado pelos serviços académicos.', '2026-03-17 21:46:09', '2026-03-17 21:46:26', '2026-03-17 21:46:31'),
(23, 'aluno1', 'O teu pedido de matrícula no curso Administração de Sistemas e Redes foi aprovado pelos serviços académicos.', '2026-03-17 22:03:14', '2026-03-18 10:01:38', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `cursos`
--

CREATE TABLE `cursos` (
  `ID` int(11) NOT NULL,
  `Nome` text NOT NULL,
  `descricao` text DEFAULT NULL,
  `submetido_por` varchar(20) DEFAULT NULL,
  `submetido_em` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cursos`
--

INSERT INTO `cursos` (`ID`, `Nome`, `descricao`, `submetido_por`, `submetido_em`) VALUES
(1, 'Desenvolvimento Web e Multimédia', 'O Curso de Desenvolvimento Web e Multimédia ensina a criar websites modernos e conteúdos digitais interativos. Os alunos aprendem tecnologias essenciais como HTML, CSS e JavaScript, além de ferramentas de design e produção multimédia. Ao longo do curso, desenvolvem projetos práticos que combinam programação, criatividade e design, preparando-os para trabalhar na área do desenvolvimento web e da criação de conteúdos digitais.', 'gestor', '2026-03-17 19:24:22'),
(2, 'Comércio Eletrónico', 'O Curso de Comércio Eletrónico prepara os participantes para criar e gerir negócios online de forma eficaz. Ao longo da formação, são abordados os principais conceitos do e-commerce, incluindo criação de lojas virtuais, gestão de produtos, métodos de pagamento, logística e estratégias de marketing digital. No final, os formandos estarão aptos a desenvolver e administrar uma loja online, aumentando a presença e as vendas no ambiente digital.', 'gestor', '2026-03-17 19:24:22'),
(3, 'Redes de Computadores', 'O Curso de Redes de Computadores ensina os fundamentos da comunicação entre sistemas e dispositivos numa rede. Os alunos aprendem a configurar, gerir e proteger redes informáticas, explorando conceitos como endereçamento IP, protocolos de rede, segurança e administração de sistemas. A formação inclui atividades práticas que preparam os participantes para trabalhar na instalação e manutenção de infraestruturas de rede.', 'gestor', '2026-03-17 19:24:22'),
(6, 'Engenharia de Software', 'O curso de Engenharia de Software prepara os estudantes para analisar, projetar, desenvolver e manter aplicações com qualidade. Inclui práticas de arquitetura, testes, controlo de versões e trabalho colaborativo em equipa. No final, os alunos ficam aptos a construir soluções robustas para contextos empresariais.', 'gestor', '2026-03-17 19:24:22'),
(7, 'Análise de Dados', 'O curso de Análise de Dados desenvolve competências em recolha, limpeza, exploração e interpretação de dados. Abrange estatística aplicada, bases de dados e visualização para apoiar a tomada de decisão. A formação combina fundamentos técnicos com projetos práticos orientados a problemas reais.', 'gestor', '2026-03-17 19:24:22'),
(8, 'Administração de Sistemas e Redes', 'O curso de Administração de Sistemas e Redes foca-se na configuração, monitorização e proteção de infraestruturas tecnológicas. Os estudantes aprendem a gerir sistemas operativos, serviços de rede, segurança e continuidade operacional. O percurso privilegia uma abordagem prática para ambientes profissionais.', 'gestor', '2026-03-17 19:24:22'),
(9, 'Cibersegurança e Auditoria de Sistemas', 'O curso de Cibersegurança e Auditoria de Sistemas prepara profissionais para proteger infraestruturas tecnológicas, identificar vulnerabilidades e responder a incidentes de segurança. A formação inclui práticas de monitorização, análise de riscos, conformidade e auditoria técnica em ambientes reais.', 'gestor', '2026-03-17 19:24:22'),
(10, 'Inteligência Artificial e Ciência de Dados', 'O curso de Inteligência Artificial e Ciência de Dados combina programação, matemática e estatística para desenvolver soluções baseadas em dados. Os estudantes aprendem a construir modelos preditivos, interpretar resultados e aplicar técnicas de aprendizagem automática em contextos empresariais e científicos.', 'gestor', '2026-03-17 19:24:22'),
(11, 'Gestão de Projetos Digitais', 'O curso de Gestão de Projetos Digitais foca-se no planeamento, coordenação e entrega de produtos tecnológicos com qualidade. Abrange metodologias ágeis, análise de requisitos, comunicação com stakeholders e gestão de equipas multidisciplinares para garantir resultados sustentáveis.', 'gestor', '2026-03-17 19:24:22');

-- --------------------------------------------------------

--
-- Table structure for table `disciplinas`
--

CREATE TABLE `disciplinas` (
  `ID` int(11) NOT NULL,
  `Nome_disc` text NOT NULL,
  `submetido_por` varchar(20) DEFAULT NULL,
  `submetido_em` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `disciplinas`
--

INSERT INTO `disciplinas` (`ID`, `Nome_disc`, `submetido_por`, `submetido_em`) VALUES
(1, 'Matemática', 'gestor', '2026-03-17 19:22:13'),
(2, 'Programação Web I', 'gestor', '2026-03-17 19:22:13'),
(3, 'Linguagens de Programação', 'gestor', '2026-03-17 19:22:13'),
(4, 'Português', 'gestor', '2026-03-17 19:22:13'),
(9, 'Programação Web II', 'gestor', '2026-03-17 19:22:13'),
(10, 'Bases de Dados', 'gestor', '2026-03-17 19:22:13'),
(11, 'Segurança Informática', 'gestor', '2026-03-17 19:22:13'),
(12, 'Estatística Aplicada', 'gestor', '2026-03-17 19:22:13'),
(13, 'Engenharia de Software', 'gestor', '2026-03-17 19:22:13'),
(14, 'Sistemas Operativos', 'gestor', '2026-03-17 19:22:13'),
(15, 'Redes Avançadas', 'gestor', '2026-03-17 19:22:13'),
(16, 'Visualização de Dados', 'gestor', '2026-03-17 19:22:13'),
(17, 'Arquitetura de Computadores', 'gestor', '2026-03-17 19:22:13'),
(18, 'Desenvolvimento de APIs', 'gestor', '2026-03-17 19:22:13'),
(19, 'UX e Design de Interfaces', 'gestor', '2026-03-17 19:22:13'),
(20, 'Computação em Nuvem', 'gestor', '2026-03-17 19:22:13'),
(21, 'Administração de Bases de Dados', 'gestor', '2026-03-17 19:22:13'),
(22, 'Ética e Deontologia Profissional', 'gestor', '2026-03-17 19:22:13'),
(23, 'Aprendizagem Automática', 'gestor', '2026-03-17 19:22:13'),
(24, 'Gestão de Projetos de Software', 'gestor', '2026-03-17 19:22:13'),
(25, 'Marketing Digital', 'gestor', '2026-03-17 19:22:13');

-- --------------------------------------------------------

--
-- Table structure for table `funcionario_cursos`
--

CREATE TABLE `funcionario_cursos` (
  `funcionario_login` varchar(20) NOT NULL,
  `curso_id` int(11) NOT NULL,
  `assigned_by` varchar(20) DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `funcionario_cursos`
--

INSERT INTO `funcionario_cursos` (`funcionario_login`, `curso_id`, `assigned_by`, `assigned_at`) VALUES
('func', 1, 'gestor', '2026-03-14 17:45:43'),
('func', 2, 'gestor', '2026-03-14 17:09:19'),
('func', 6, 'gestor', '2026-03-14 17:59:38'),
('func', 7, 'gestor', '2026-03-14 17:45:42'),
('func', 8, 'gestor', '2026-03-14 17:44:00');

-- --------------------------------------------------------

--
-- Table structure for table `funcionario_curso_pedidos`
--

CREATE TABLE `funcionario_curso_pedidos` (
  `id` int(11) NOT NULL,
  `funcionario_login` varchar(20) NOT NULL,
  `curso_id` int(11) NOT NULL,
  `status` varchar(20) DEFAULT 'PENDING',
  `solicitado_em` datetime NOT NULL DEFAULT current_timestamp(),
  `revisado_por` varchar(20) DEFAULT NULL,
  `revisado_em` datetime DEFAULT NULL,
  `motivo_rejeicao` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `funcionario_curso_pedidos`
--

INSERT INTO `funcionario_curso_pedidos` (`id`, `funcionario_login`, `curso_id`, `status`, `solicitado_em`, `revisado_por`, `revisado_em`, `motivo_rejeicao`) VALUES
(1, 'func', 8, 'APPROVED', '2026-03-14 17:41:58', 'gestor', '2026-03-14 17:44:00', NULL),
(2, 'func', 7, 'APPROVED', '2026-03-14 17:44:52', 'gestor', '2026-03-14 17:45:42', NULL),
(3, 'func', 1, 'APPROVED', '2026-03-14 17:45:32', 'gestor', '2026-03-14 17:45:43', NULL),
(4, 'func', 6, 'APPROVED', '2026-03-14 17:56:33', 'gestor', '2026-03-14 17:59:38', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `grupos`
--

CREATE TABLE `grupos` (
  `ID` int(11) NOT NULL,
  `GRUPO` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `grupos`
--

INSERT INTO `grupos` (`ID`, `GRUPO`) VALUES
(1, 'ADMIN'),
(2, 'ALUNO'),
(3, 'FUNCIONARIO'),
(4, 'GESTOR');

-- --------------------------------------------------------

--
-- Table structure for table `matriculas`
--

CREATE TABLE `matriculas` (
  `login` varchar(20) NOT NULL,
  `curso_id` int(11) NOT NULL,
  `data_matricula` date NOT NULL DEFAULT curdate(),
  `status` enum('PENDING','APPROVED','REJECTED','CANCEL_PENDING','CANCELLED','CANCEL_REJECTED') NOT NULL DEFAULT 'PENDING',
  `approved_by` varchar(20) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `observacao` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `matriculas`
--

INSERT INTO `matriculas` (`login`, `curso_id`, `data_matricula`, `status`, `approved_by`, `approved_at`, `observacao`) VALUES
('aluno', 2, '2026-03-17', 'APPROVED', 'gestor', '2026-03-17 11:57:04', ''),
('aluno', 3, '2026-03-17', 'APPROVED', 'gestor', '2026-03-17 11:54:00', ''),
('aluno1', 2, '2026-03-17', 'APPROVED', 'gestor', '2026-03-17 11:50:17', ''),
('aluno1', 8, '2026-03-17', 'APPROVED', 'gestor', '2026-03-17 22:03:14', '');

-- --------------------------------------------------------

--
-- Table structure for table `pautas`
--

CREATE TABLE `pautas` (
  `pauta_id` int(11) NOT NULL,
  `curso_id` int(11) NOT NULL,
  `ano_letivo` varchar(9) NOT NULL,
  `semestre` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `epoca` enum('Normal','Recurso','Especial') NOT NULL DEFAULT 'Normal',
  `tipo_avaliacao` enum('Continua','Exame') NOT NULL DEFAULT 'Continua',
  `criado_por` varchar(20) NOT NULL,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pautas`
--

INSERT INTO `pautas` (`pauta_id`, `curso_id`, `ano_letivo`, `semestre`, `epoca`, `tipo_avaliacao`, `criado_por`, `criado_em`) VALUES
(24, 2, '2025/2026', 1, 'Normal', 'Continua', 'func', '2026-03-14 19:08:51'),
(25, 2, '2025/2026', 1, 'Normal', 'Exame', 'func', '2026-03-17 11:15:06'),
(26, 2, '2025/2026', 2, 'Normal', 'Exame', 'func', '2026-03-17 11:15:52');

-- --------------------------------------------------------

--
-- Table structure for table `pauta_notas`
--

CREATE TABLE `pauta_notas` (
  `pauta_id` int(11) NOT NULL,
  `login` varchar(20) NOT NULL,
  `nota` decimal(4,1) DEFAULT NULL,
  `observacao` text DEFAULT NULL,
  `registado_por` varchar(20) DEFAULT NULL,
  `registado_em` datetime DEFAULT NULL,
  `faz_exame` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pauta_notas`
--

INSERT INTO `pauta_notas` (`pauta_id`, `login`, `nota`, `observacao`, `registado_por`, `registado_em`, `faz_exame`) VALUES
(24, 'aluno', NULL, NULL, NULL, NULL, 1),
(24, 'aluno1', NULL, NULL, NULL, NULL, 1),
(25, 'aluno', NULL, NULL, NULL, NULL, 1),
(25, 'aluno1', NULL, NULL, NULL, NULL, 1),
(26, 'aluno', NULL, NULL, NULL, NULL, 1),
(26, 'aluno1', NULL, NULL, NULL, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `pauta_notas_disciplinas`
--

CREATE TABLE `pauta_notas_disciplinas` (
  `pauta_id` int(11) NOT NULL,
  `login` varchar(20) NOT NULL,
  `disciplina_id` int(11) NOT NULL,
  `notas_json` text DEFAULT NULL,
  `media` decimal(4,1) DEFAULT NULL,
  `observacao` text DEFAULT NULL,
  `registado_por` varchar(20) DEFAULT NULL,
  `registado_em` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `perfil_pedidos`
--

CREATE TABLE `perfil_pedidos` (
  `login` varchar(20) NOT NULL,
  `nome` varchar(120) NOT NULL,
  `email` varchar(120) DEFAULT NULL,
  `telefone` varchar(30) DEFAULT NULL,
  `morada` varchar(200) DEFAULT NULL,
  `foto_path` varchar(255) DEFAULT NULL,
  `status` enum('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` varchar(20) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `obs_nome` text DEFAULT NULL,
  `obs_telefone` text DEFAULT NULL,
  `obs_morada` text DEFAULT NULL,
  `obs_foto` text DEFAULT NULL,
  `obs_rejeicao` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `perfil_pedidos`
--

INSERT INTO `perfil_pedidos` (`login`, `nome`, `email`, `telefone`, `morada`, `foto_path`, `status`, `requested_at`, `reviewed_by`, `reviewed_at`, `obs_nome`, `obs_telefone`, `obs_morada`, `obs_foto`, `obs_rejeicao`) VALUES
('aluno1', 'Jorge Miguel', 'frota@frota.pt', '9145066759', 'Rua do Mes', 'assets/img/perfis/perfil_45538e9fbb988c4e446c20ad44419a91.jpg', 'APPROVED', '2026-03-17 20:04:18', 'gestor', '2026-03-17 20:06:49', '', '', '', '', NULL),
('func', 'Pedro Borges', 'pedro@pedro.pt', '3798989898', 'avenida', 'assets/img/perfis/perfil_bbbca0037a53b2c50904c0b827ebfd0c.jpg', 'APPROVED', '2026-03-17 20:04:44', 'gestor', '2026-03-17 20:07:27', '', '', '', '', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `plano_estudos`
--

CREATE TABLE `plano_estudos` (
  `CURSOS` int(11) NOT NULL,
  `DISCIPLINA` int(11) NOT NULL,
  `semestre` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `submetido_por` varchar(20) DEFAULT NULL,
  `submetido_em` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `plano_estudos`
--

INSERT INTO `plano_estudos` (`CURSOS`, `DISCIPLINA`, `semestre`, `submetido_por`, `submetido_em`) VALUES
(1, 1, 1, 'gestor', '2026-03-17 19:21:52'),
(2, 3, 1, 'gestor', '2026-03-17 19:21:52'),
(2, 4, 1, 'gestor', '2026-03-17 19:21:52'),
(6, 10, 1, 'gestor', '2026-03-17 19:21:52'),
(6, 13, 1, 'gestor', '2026-03-17 19:21:52'),
(7, 10, 1, 'gestor', '2026-03-17 19:21:52'),
(7, 12, 2, 'gestor', '2026-03-17 19:21:52'),
(7, 16, 2, 'gestor', '2026-03-17 19:21:52'),
(8, 11, 2, 'gestor', '2026-03-17 19:21:52'),
(8, 14, 2, 'gestor', '2026-03-17 19:21:52'),
(8, 15, 2, 'gestor', '2026-03-17 19:21:52'),
(6, 1, 2, 'gestor', '2026-03-17 19:21:52'),
(6, 3, 2, 'gestor', '2026-03-17 19:21:52'),
(6, 9, 2, 'gestor', '2026-03-17 19:21:52'),
(7, 1, 2, 'gestor', '2026-03-17 19:21:52'),
(7, 4, 2, 'gestor', '2026-03-17 19:21:52'),
(8, 1, 1, 'gestor', '2026-03-17 19:21:52'),
(1, 2, 2, 'gestor', '2026-03-17 19:21:52'),
(1, 9, 2, 'gestor', '2026-03-17 19:21:52'),
(1, 10, 1, 'gestor', '2026-03-17 19:21:52'),
(1, 19, 2, 'gestor', '2026-03-17 19:21:52'),
(1, 18, 1, 'gestor', '2026-03-17 19:21:52'),
(2, 2, 2, 'gestor', '2026-03-17 19:21:52'),
(2, 9, 2, 'gestor', '2026-03-17 19:21:52'),
(2, 10, 1, 'gestor', '2026-03-17 19:21:52'),
(2, 25, 1, 'gestor', '2026-03-17 19:21:52'),
(2, 19, 2, 'gestor', '2026-03-17 19:21:52'),
(3, 14, 2, 'gestor', '2026-03-17 19:21:52'),
(3, 15, 1, 'gestor', '2026-03-17 19:21:52'),
(3, 11, 2, 'gestor', '2026-03-17 19:21:52'),
(3, 20, 1, 'gestor', '2026-03-17 19:21:52'),
(3, 17, 1, 'gestor', '2026-03-17 19:21:52'),
(6, 18, 1, 'gestor', '2026-03-17 19:21:52'),
(6, 24, 2, 'gestor', '2026-03-17 19:21:52'),
(6, 21, 1, 'gestor', '2026-03-17 19:21:52'),
(6, 22, 1, 'gestor', '2026-03-17 19:21:52'),
(7, 23, 1, 'gestor', '2026-03-17 19:21:52'),
(7, 21, 1, 'gestor', '2026-03-17 19:21:52'),
(7, 18, 1, 'gestor', '2026-03-17 19:21:52'),
(8, 20, 1, 'gestor', '2026-03-17 19:21:52'),
(8, 17, 1, 'gestor', '2026-03-17 19:21:52'),
(8, 21, 1, 'gestor', '2026-03-17 19:21:52'),
(9, 11, 2, 'gestor', '2026-03-17 19:21:52'),
(9, 15, 1, 'gestor', '2026-03-17 19:21:52'),
(9, 14, 2, 'gestor', '2026-03-17 19:21:52'),
(9, 20, 1, 'gestor', '2026-03-17 19:21:52'),
(9, 22, 1, 'gestor', '2026-03-17 19:21:52'),
(10, 1, 2, 'gestor', '2026-03-17 19:21:52'),
(10, 12, 2, 'gestor', '2026-03-17 19:21:52'),
(10, 10, 1, 'gestor', '2026-03-17 19:21:52'),
(10, 16, 2, 'gestor', '2026-03-17 19:21:52'),
(10, 23, 1, 'gestor', '2026-03-17 19:21:52'),
(10, 21, 1, 'gestor', '2026-03-17 19:21:52'),
(11, 4, 2, 'gestor', '2026-03-17 19:21:52'),
(11, 25, 2, 'gestor', '2026-03-17 19:21:52'),
(11, 19, 2, 'gestor', '2026-03-17 19:21:52'),
(11, 24, 1, 'gestor', '2026-03-17 19:21:52'),
(11, 18, 1, 'gestor', '2026-03-17 19:21:52'),
(11, 22, 1, 'gestor', '2026-03-17 19:21:52');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `login` varchar(20) NOT NULL,
  `pwd` varchar(250) NOT NULL,
  `grupo` int(11) NOT NULL,
  `approval_status` enum('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'APPROVED',
  `approved_by` varchar(20) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`login`, `pwd`, `grupo`, `approval_status`, `approved_by`, `approved_at`) VALUES
('aluno', '81dc9bdb52d04dc20036dbd8313ed055', 2, 'APPROVED', 'gestor', '2026-03-17 11:53:31'),
('aluno1', '81dc9bdb52d04dc20036dbd8313ed055', 2, 'APPROVED', 'gestor', '2026-03-13 19:23:47'),
('func', '81dc9bdb52d04dc20036dbd8313ed055', 3, 'APPROVED', 'gestor', '2026-03-13 15:30:25'),
('gestor', '21232f297a57a5a743894a0e4a801fc3', 1, 'APPROVED', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_perfis`
--
ALTER TABLE `admin_perfis`
  ADD PRIMARY KEY (`login`);

--
-- Indexes for table `alunos`
--
ALTER TABLE `alunos`
  ADD PRIMARY KEY (`login`),
  ADD UNIQUE KEY `uq_alunos_matricula` (`matricula`),
  ADD UNIQUE KEY `uq_alunos_email` (`email`),
  ADD UNIQUE KEY `uq_alunos_telefone` (`telefone`);

--
-- Indexes for table `aluno_notificacoes`
--
ALTER TABLE `aluno_notificacoes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_aluno_notificacoes_login_read` (`login`,`read_at`);

--
-- Indexes for table `cursos`
--
ALTER TABLE `cursos`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `uq_nome_curso` (`Nome`(191));

--
-- Indexes for table `disciplinas`
--
ALTER TABLE `disciplinas`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `uq_nome_disc` (`Nome_disc`(191));

--
-- Indexes for table `funcionario_cursos`
--
ALTER TABLE `funcionario_cursos`
  ADD PRIMARY KEY (`funcionario_login`,`curso_id`),
  ADD KEY `fk_funcionario_cursos_curso` (`curso_id`);

--
-- Indexes for table `funcionario_curso_pedidos`
--
ALTER TABLE `funcionario_curso_pedidos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_pedido` (`funcionario_login`,`curso_id`),
  ADD KEY `fk_func_curso_ped_curso` (`curso_id`);

--
-- Indexes for table `grupos`
--
ALTER TABLE `grupos`
  ADD PRIMARY KEY (`ID`);

--
-- Indexes for table `matriculas`
--
ALTER TABLE `matriculas`
  ADD PRIMARY KEY (`login`,`curso_id`),
  ADD KEY `fk_matriculas_cursos` (`curso_id`);

--
-- Indexes for table `pautas`
--
ALTER TABLE `pautas`
  ADD PRIMARY KEY (`pauta_id`),
  ADD KEY `fk_pautas_curso` (`curso_id`);

--
-- Indexes for table `pauta_notas`
--
ALTER TABLE `pauta_notas`
  ADD PRIMARY KEY (`pauta_id`,`login`),
  ADD KEY `fk_pautas_notas_user` (`login`);

--
-- Indexes for table `pauta_notas_disciplinas`
--
ALTER TABLE `pauta_notas_disciplinas`
  ADD PRIMARY KEY (`pauta_id`,`login`,`disciplina_id`),
  ADD KEY `fk_pnd_login` (`login`),
  ADD KEY `fk_pnd_disciplina` (`disciplina_id`);

--
-- Indexes for table `perfil_pedidos`
--
ALTER TABLE `perfil_pedidos`
  ADD PRIMARY KEY (`login`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`login`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `aluno_notificacoes`
--
ALTER TABLE `aluno_notificacoes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `cursos`
--
ALTER TABLE `cursos`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `disciplinas`
--
ALTER TABLE `disciplinas`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `funcionario_curso_pedidos`
--
ALTER TABLE `funcionario_curso_pedidos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `grupos`
--
ALTER TABLE `grupos`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `pautas`
--
ALTER TABLE `pautas`
  MODIFY `pauta_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_perfis`
--
ALTER TABLE `admin_perfis`
  ADD CONSTRAINT `fk_admin_perfis_users` FOREIGN KEY (`login`) REFERENCES `users` (`login`) ON DELETE CASCADE;

--
-- Constraints for table `alunos`
--
ALTER TABLE `alunos`
  ADD CONSTRAINT `fk_alunos_users` FOREIGN KEY (`login`) REFERENCES `users` (`login`) ON DELETE CASCADE;

--
-- Constraints for table `aluno_notificacoes`
--
ALTER TABLE `aluno_notificacoes`
  ADD CONSTRAINT `fk_aluno_notificacoes_users` FOREIGN KEY (`login`) REFERENCES `users` (`login`) ON DELETE CASCADE;

--
-- Constraints for table `funcionario_cursos`
--
ALTER TABLE `funcionario_cursos`
  ADD CONSTRAINT `fk_funcionario_cursos_curso` FOREIGN KEY (`curso_id`) REFERENCES `cursos` (`ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_funcionario_cursos_user` FOREIGN KEY (`funcionario_login`) REFERENCES `users` (`login`) ON DELETE CASCADE;

--
-- Constraints for table `funcionario_curso_pedidos`
--
ALTER TABLE `funcionario_curso_pedidos`
  ADD CONSTRAINT `fk_func_curso_ped_curso` FOREIGN KEY (`curso_id`) REFERENCES `cursos` (`ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_func_curso_ped_user` FOREIGN KEY (`funcionario_login`) REFERENCES `users` (`login`) ON DELETE CASCADE;

--
-- Constraints for table `matriculas`
--
ALTER TABLE `matriculas`
  ADD CONSTRAINT `fk_matriculas_cursos` FOREIGN KEY (`curso_id`) REFERENCES `cursos` (`ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_matriculas_users` FOREIGN KEY (`login`) REFERENCES `users` (`login`) ON DELETE CASCADE;

--
-- Constraints for table `pautas`
--
ALTER TABLE `pautas`
  ADD CONSTRAINT `fk_pautas_curso` FOREIGN KEY (`curso_id`) REFERENCES `cursos` (`ID`) ON DELETE CASCADE;

--
-- Constraints for table `pauta_notas`
--
ALTER TABLE `pauta_notas`
  ADD CONSTRAINT `fk_pautas_notas_pauta` FOREIGN KEY (`pauta_id`) REFERENCES `pautas` (`pauta_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pautas_notas_user` FOREIGN KEY (`login`) REFERENCES `users` (`login`) ON DELETE CASCADE;

--
-- Constraints for table `pauta_notas_disciplinas`
--
ALTER TABLE `pauta_notas_disciplinas`
  ADD CONSTRAINT `fk_pnd_disciplina` FOREIGN KEY (`disciplina_id`) REFERENCES `disciplinas` (`ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pnd_login` FOREIGN KEY (`login`) REFERENCES `users` (`login`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pnd_pauta` FOREIGN KEY (`pauta_id`) REFERENCES `pautas` (`pauta_id`) ON DELETE CASCADE;

--
-- Constraints for table `perfil_pedidos`
--
ALTER TABLE `perfil_pedidos`
  ADD CONSTRAINT `fk_perfil_pedidos_users` FOREIGN KEY (`login`) REFERENCES `users` (`login`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
