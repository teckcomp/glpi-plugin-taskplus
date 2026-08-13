<?php

namespace GlpiPlugin\Taskplus;

use Config as CoreConfig;
use CronTask;
use Migration;
use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use NotificationTemplate;
use NotificationTemplateTranslation;
use ProfileRight;

/**
 * Instalação / desinstalação do Task+.
 *
 * Cria APENAS tabelas próprias do plugin (prefixo glpi_plugin_taskplus_).
 * Nenhuma tabela nativa é alterada — as origens nativas (tarefas de
 * projeto e de chamado) serão apenas LIDAS a partir da Etapa 3.
 *
 * Padrões herdados do ProjectPlus (Etapa 6, Bloco 1 de lá):
 *  - charset/collation/sign resolvidos pelo core (bases atualizadas de
 *    versões antigas rodam em utf8 — fixar utf8mb4 dá erro 1267);
 *  - install() reconcilia o schema de bases antigas via ensureSchema();
 *  - uninstall() preserva dados E direitos, salvo "purge_on_uninstall".
 */
class Install
{
    /**
     * Tabelas próprias do plugin. Ordem estável (diagnóstico e purga).
     */
    public const TABLES = [
        'glpi_plugin_taskplus_routines',
        'glpi_plugin_taskplus_occurrences',
        'glpi_plugin_taskplus_phases',
        'glpi_plugin_taskplus_pendings',
        'glpi_plugin_taskplus_alerts',
        'glpi_plugin_taskplus_comments',
        'glpi_plugin_taskplus_comment_reads',
    ];

    /**
     * Direitos próprios do plugin. Fonte única para uninstall/diagnóstico.
     *
     * - `plugin_taskplus_task`: usuário comum — lança e conclui as
     *   próprias tarefas, vê as próprias telas;
     * - `plugin_taskplus_manage`: gestor — cria avulsas/recorrentes para
     *   outros usuários e acompanha a equipe.
     *
     * Ambos NASCEM DESMARCADOS para os perfis comuns (decisão de produto:
     * direito próprio desmarcado é o que diferencia papéis de verdade).
     * Só perfis com o direito nativo `config` UPDATE (super-admin) recebem
     * o valor cheio na instalação, para que dê para testar sem configurar
     * perfil nenhum. O ajuste fino é feito em Administração → Perfis.
     */
    public const RIGHTS = [
        'plugin_taskplus_task',
        'plugin_taskplus_manage',
        // 8b: página inicial por perfil — quem o tem cai na tela Hoje do
        // Task+ no lugar da Visão Geral (hook post_init).
        'plugin_taskplus_home',
    ];

    /**
     * Colunas esperadas em cada tabela, com o tipo SQL literal.
     *
     * Serve para bases criadas por versões antigas do plugin: a guarda
     * `tableExists` do install só protege a CRIAÇÃO; coluna acrescentada
     * depois nunca chegaria a quem já tem a tabela. `Migration::addField`
     * é no-op quando a coluna já existe.
     *
     * `%SIGN%` é resolvido em tempo de execução por `ensureSchema()` com
     * `DBConnection::getDefaultPrimaryKeySignOption()` (constante de
     * classe não aceita variável).
     *
     * Chave primária e índices UNIQUE ficam de fora de propósito: criar
     * UNIQUE em base com duplicata falha.
     */
    private const COLUMNS = [
        'glpi_plugin_taskplus_comments' => [
            // 8e-3: anexo do comentário via Document NATIVO do core
            'documents_id' => 'INT %SIGN% NOT NULL DEFAULT 0',
        ],
        // 9a-1: marca de leitura do diálogo, uma linha por (ocorrência,
        // leitor). A UNIQUE fica só no CREATE TABLE (regra do topo).
        'glpi_plugin_taskplus_comment_reads' => [
            'plugin_taskplus_occurrences_id' => 'INT %SIGN% NOT NULL DEFAULT 0',
            'users_id'                       => 'INT %SIGN% NOT NULL DEFAULT 0',
            'date_read'                      => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_taskplus_routines' => [
            'name'             => "VARCHAR(255) NOT NULL DEFAULT ''",
            'instructions'     => 'TEXT',
            'users_id'         => 'INT %SIGN% NOT NULL DEFAULT 0',
            'users_id_creator' => 'INT %SIGN% NOT NULL DEFAULT 0',
            'frequency'        => "VARCHAR(10) NOT NULL DEFAULT 'daily'",
            'only_workdays'    => 'TINYINT NOT NULL DEFAULT 0',
            'weekdays'         => "VARCHAR(20) NOT NULL DEFAULT ''",
            'monthday'         => 'INT NOT NULL DEFAULT 0',
            'monthweek'        => 'INT NOT NULL DEFAULT 0',
            'monthweekday'     => 'INT NOT NULL DEFAULT 0',
            'time_limit'       => 'TIME NULL DEFAULT NULL',
            'is_paused'        => 'TINYINT NOT NULL DEFAULT 0',
            'date_begin'       => 'DATE NULL DEFAULT NULL',
            'date_end'         => 'DATE NULL DEFAULT NULL',
            'is_deleted'       => 'TINYINT NOT NULL DEFAULT 0',
            'date_creation'    => 'TIMESTAMP NULL DEFAULT NULL',
            'date_mod'         => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_taskplus_occurrences' => [
            'plugin_taskplus_routines_id' => 'INT %SIGN% NULL DEFAULT NULL',
            'name'             => "VARCHAR(255) NOT NULL DEFAULT ''",
            'description'      => 'TEXT',
            'category'         => "VARCHAR(255) NOT NULL DEFAULT ''",
            'users_id'         => 'INT %SIGN% NOT NULL DEFAULT 0',
            'users_id_creator' => 'INT %SIGN% NOT NULL DEFAULT 0',
            'date'             => 'DATE NULL DEFAULT NULL',
            'time_limit'       => 'TIME NULL DEFAULT NULL',
            // 7a: trilha do alerta de horário-limite (NULL = nunca
            // alertou). Garante UMA tentativa por ocorrência.
            'date_alert_limit' => 'TIMESTAMP NULL DEFAULT NULL',
            'is_done'          => 'TINYINT NOT NULL DEFAULT 0',
            'done_date'        => 'TIMESTAMP NULL DEFAULT NULL',
            'users_id_done'    => 'INT %SIGN% NOT NULL DEFAULT 0',
            'is_skipped'       => 'TINYINT NOT NULL DEFAULT 0',
            'skip_reason'      => 'TEXT',
            'skip_date'        => 'TIMESTAMP NULL DEFAULT NULL',
            'users_id_skip'    => 'INT %SIGN% NOT NULL DEFAULT 0',
            'is_edited'        => 'TINYINT NOT NULL DEFAULT 0',
            'plugin_taskplus_phases_id' => 'INT %SIGN% NULL DEFAULT NULL',
            'is_deleted'       => 'TINYINT NOT NULL DEFAULT 0',
            'date_creation'    => 'TIMESTAMP NULL DEFAULT NULL',
            'date_mod'         => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_taskplus_phases' => [
            'name'          => "VARCHAR(255) NOT NULL DEFAULT ''",
            'color'         => "VARCHAR(20) NOT NULL DEFAULT '#5a6b7b'",
            'position'      => 'INT NOT NULL DEFAULT 0',
            'is_default'    => 'TINYINT NOT NULL DEFAULT 0',
            'is_system'     => 'TINYINT NOT NULL DEFAULT 0',
            'system_key'    => "VARCHAR(20) NOT NULL DEFAULT ''",
            // 4c-2: dono da fase. 0 = sistema (e legadas da 4c, "sem
            // setor"); >0 = grupo do GLPI. É a coluna que o T13 cobre:
            // base existente ganha via ensureSchema (install --force).
            'groups_id'     => 'INT %SIGN% NOT NULL DEFAULT 0',
            'is_deleted'    => 'TINYINT NOT NULL DEFAULT 0',
            'date_creation' => 'TIMESTAMP NULL DEFAULT NULL',
            'date_mod'      => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_taskplus_pendings' => [
            'itemtype'      => "VARCHAR(100) NOT NULL DEFAULT ''",
            'items_id'      => 'INT %SIGN% NOT NULL DEFAULT 0',
            'users_id'      => 'INT %SIGN% NOT NULL DEFAULT 0',
            // 5b-2: QUEM marcou a pendência — o gestor pode marcar pela
            // tela Equipe. 0 = linha anterior à coluna (foi o próprio dono).
            'users_id_creator' => 'INT %SIGN% NOT NULL DEFAULT 0',
            'reason'        => 'TEXT',
            'pending_until' => 'DATE NULL DEFAULT NULL',
            // Ajuste 2 do 4b: hora passa a ser obrigatória no formulário;
            // a coluna nasce com default para as pendências que já
            // existiam antes deste ajuste (fim de expediente).
            'pending_time'  => "TIME NOT NULL DEFAULT '18:00:00'",
            'is_active'     => 'TINYINT NOT NULL DEFAULT 1',
            'date_creation' => 'TIMESTAMP NULL DEFAULT NULL',
            'date_mod'      => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        // 7a: alertas internos do SINO (padrão trazido do ProjectPlus).
        // A UNIQUE de dedup fica só no CREATE TABLE (regra do topo).
        'glpi_plugin_taskplus_alerts' => [
            'users_id'      => 'INT %SIGN% NOT NULL DEFAULT 0',
            'itemtype'      => "VARCHAR(100) NOT NULL DEFAULT ''",
            'items_id'      => 'INT %SIGN% NOT NULL DEFAULT 0',
            'kind'          => "VARCHAR(30) NOT NULL DEFAULT ''",
            'message'       => 'TEXT',
            'is_read'       => 'TINYINT NOT NULL DEFAULT 0',
            'date_creation' => 'TIMESTAMP NULL DEFAULT NULL',
        ],
    ];

    /**
     * Fases que o plugin cria na instalação (Etapa 4b).
     *
     * As de sistema (`is_system`) espelham estados CALCULADOS e não podem
     * ser excluídas nem renomeadas para outra coisa: "atrasada" e
     * "concluída" são consequência de data e de conclusão, não escolha.
     * A padrão (`is_default`) é onde toda tarefa nasce — sem ela, tarefa
     * criada fora do quadro ficaria sem coluna.
     */
    private const DEFAULT_PHASES = [
        ['name' => 'Atrasadas',  'color' => '#c0392b', 'position' => 10, 'is_default' => 0, 'is_system' => 1, 'system_key' => 'late'],
        ['name' => 'Para hoje',  'color' => '#2a76a8', 'position' => 20, 'is_default' => 1, 'is_system' => 1, 'system_key' => 'today'],
        ['name' => 'Pendentes',  'color' => '#b8860b', 'position' => 30, 'is_default' => 0, 'is_system' => 1, 'system_key' => 'pending'],
        ['name' => 'Concluídas', 'color' => '#2f7d46', 'position' => 90, 'is_default' => 0, 'is_system' => 1, 'system_key' => 'done'],
    ];

    public static function install(): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $migration = new Migration(PLUGIN_TASKPLUS_VERSION);

        // Charset/collation/sign do CORE, nunca fixos (lição herdada do
        // ProjectPlus: base atualizada de GLPI antigo roda em utf8 e a
        // mistura de collations dá erro 1267 em qualquer JOIN com texto).
        $charset   = \DBConnection::getDefaultCharset();
        $collation = \DBConnection::getDefaultCollation();
        $sign      = \DBConnection::getDefaultPrimaryKeySignOption();

        // ------------------------------------------------------------------
        // 1) Rotinas recorrentes — a DEFINIÇÃO da recorrência.
        //
        //    frequency: 'daily' | 'weekly' | 'monthly'
        //    - daily   + only_workdays=1  → só dias úteis;
        //    - weekly  + weekdays='1,3,5' → ISO-8601 (1=segunda … 7=domingo);
        //    - monthly + monthday=15      → dia fixo do mês; OU
        //      monthly + monthweek=1..5|-1 + monthweekday=1..7
        //                                 → "1ª segunda", "última sexta"
        //                                   (monthweek -1 = última).
        //    time_limit: horário-limite do dia; is_paused: pausa/retomada;
        //    date_end: término opcional da rotina.
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_taskplus_routines')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_taskplus_routines` (
                    `id`               INT {$sign} NOT NULL AUTO_INCREMENT,
                    `name`             VARCHAR(255) NOT NULL DEFAULT '',
                    `instructions`     TEXT COMMENT 'como fazer',
                    `users_id`         INT {$sign} NOT NULL DEFAULT 0 COMMENT 'responsavel',
                    `users_id_creator` INT {$sign} NOT NULL DEFAULT 0 COMMENT 'quem criou (gestor ou o proprio)',
                    `frequency`        VARCHAR(10) NOT NULL DEFAULT 'daily' COMMENT 'daily|weekly|monthly',
                    `only_workdays`    TINYINT NOT NULL DEFAULT 0 COMMENT 'daily: so dias uteis',
                    `weekdays`         VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'weekly: dias ISO, ex 1,3,5',
                    `monthday`         INT NOT NULL DEFAULT 0 COMMENT 'monthly: dia fixo (0 = usa posicao)',
                    `monthweek`        INT NOT NULL DEFAULT 0 COMMENT 'monthly: 1..5 = 1a..5a semana, -1 = ultima',
                    `monthweekday`     INT NOT NULL DEFAULT 0 COMMENT 'monthly: dia ISO da posicao (1=seg)',
                    `time_limit`       TIME NULL DEFAULT NULL COMMENT 'horario-limite do dia',
                    `is_paused`        TINYINT NOT NULL DEFAULT 0,
                    `date_begin`       DATE NULL DEFAULT NULL,
                    `date_end`         DATE NULL DEFAULT NULL COMMENT 'termino opcional',
                    `is_deleted`       TINYINT NOT NULL DEFAULT 0,
                    `date_creation`    TIMESTAMP NULL DEFAULT NULL,
                    `date_mod`         TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `users_id` (`users_id`),
                    KEY `active` (`is_deleted`, `is_paused`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // 2) Ocorrências — as INSTÂNCIAS por dia.
        //
        //    Cobre também as tarefas AVULSAS: avulsa = ocorrência com
        //    `plugin_taskplus_routines_id` NULA (decisão de produto 02/08).
        //
        //    A chave UNIQUE (rotina, data) é a base da idempotência do cron
        //    `taskplusgen` (Etapa 2): rodar duas vezes não duplica. NULL não
        //    participa de UNIQUE no MySQL/MariaDB, então várias avulsas no
        //    mesmo dia continuam permitidas — é exatamente por isso que a
        //    coluna é NULL e não 0.
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_taskplus_occurrences')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_taskplus_occurrences` (
                    `id`               INT {$sign} NOT NULL AUTO_INCREMENT,
                    `plugin_taskplus_routines_id` INT {$sign} NULL DEFAULT NULL COMMENT 'NULL = tarefa avulsa',
                    `name`             VARCHAR(255) NOT NULL DEFAULT '',
                    `description`      TEXT,
                    `category`         VARCHAR(255) NOT NULL DEFAULT '',
                    `users_id`         INT {$sign} NOT NULL DEFAULT 0 COMMENT 'responsavel',
                    `users_id_creator` INT {$sign} NOT NULL DEFAULT 0,
                    `date`             DATE NULL DEFAULT NULL COMMENT 'dia da ocorrencia',
                    `time_limit`       TIME NULL DEFAULT NULL,
                    `is_done`          TINYINT NOT NULL DEFAULT 0,
                    `done_date`        TIMESTAMP NULL DEFAULT NULL COMMENT 'quando concluiu',
                    `users_id_done`    INT {$sign} NOT NULL DEFAULT 0 COMMENT 'quem concluiu',
                    `is_skipped`       TINYINT NOT NULL DEFAULT 0 COMMENT 'pulada (auditoria)',
                    `is_deleted`       TINYINT NOT NULL DEFAULT 0,
                    `date_creation`    TIMESTAMP NULL DEFAULT NULL,
                    `date_mod`         TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `routine_day` (`plugin_taskplus_routines_id`, `date`),
                    KEY `user_day` (`users_id`, `date`),
                    KEY `day_state` (`date`, `is_done`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // 3) Fases do quadro (Etapa 4b).
        //
        //    Colunas do kanban. As 4 de sistema nascem aqui; o usuário
        //    acrescenta as suas ("Em andamento", "Aguardando cliente") na
        //    tela de Configurações (Etapa 4c). `position` ordena as
        //    colunas; `is_default` marca onde a tarefa nasce.
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_taskplus_phases')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_taskplus_phases` (
                    `id`            INT {$sign} NOT NULL AUTO_INCREMENT,
                    `name`          VARCHAR(255) NOT NULL DEFAULT '',
                    `color`         VARCHAR(20) NOT NULL DEFAULT '#5a6b7b',
                    `position`      INT NOT NULL DEFAULT 0,
                    `is_default`    TINYINT NOT NULL DEFAULT 0 COMMENT 'onde a tarefa nasce',
                    `is_system`     TINYINT NOT NULL DEFAULT 0 COMMENT 'fase calculada, nao editavel',
                    `system_key`    VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'late|today|pending|done',
                    `groups_id`     INT {$sign} NOT NULL DEFAULT 0 COMMENT '0 = sistema; >0 = setor (grupo) dono (4c-2)',
                    `is_deleted`    TINYINT NOT NULL DEFAULT 0,
                    `date_creation` TIMESTAMP NULL DEFAULT NULL,
                    `date_mod`      TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `ordering` (`position`, `id`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // 4) Pendências (Etapa 4b).
        //
        //    Tabela SEPARADA, e não colunas na ocorrência, porque a
        //    pendência também vale para tarefa de chamado e de projeto —
        //    linhas do GLPI, onde o plugin não escreve (decisão nº 2). Por
        //    isso itemtype/items_id em vez de uma FK.
        //
        //    A pendência é POR USUÁRIO: marcar um chamado como pendente
        //    não muda nada para os outros técnicos nem no próprio chamado.
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_taskplus_pendings')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_taskplus_pendings` (
                    `id`            INT {$sign} NOT NULL AUTO_INCREMENT,
                    `itemtype`      VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Occurrence|TicketTask|ProjectTask',
                    `items_id`      INT {$sign} NOT NULL DEFAULT 0,
                    `users_id`      INT {$sign} NOT NULL DEFAULT 0 COMMENT 'de quem e a pendencia',
                    `users_id_creator` INT {$sign} NOT NULL DEFAULT 0 COMMENT 'quem marcou (5b-2: pode ser o gestor); 0 = legado',
                    `reason`        TEXT COMMENT 'motivo informado',
                    `pending_until` DATE NULL DEFAULT NULL COMMENT 'volta ao fluxo nesta data',
                    `pending_time`  TIME NOT NULL DEFAULT '18:00:00' COMMENT 'volta ao fluxo nesta hora (obrigatoria desde o ajuste 2 do 4b)',
                    `is_active`     TINYINT NOT NULL DEFAULT 1,
                    `date_creation` TIMESTAMP NULL DEFAULT NULL,
                    `date_mod`      TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `item` (`itemtype`, `items_id`, `users_id`),
                    KEY `user_active` (`users_id`, `is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // 5) Alertas internos — o SINO do plugin (Etapa 7a; padrão do
        //    ProjectPlus). Cada linha é um aviso para UM usuário; a
        //    UNIQUE `dedup` garante no banco que a mesma ocorrência não
        //    gera o mesmo tipo de alerta duas vezes para o mesmo dono
        //    (defesa em profundidade — a trilha date_alert_limit já
        //    segura isso na aplicação).
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_taskplus_alerts')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_taskplus_alerts` (
                    `id`            INT {$sign} NOT NULL AUTO_INCREMENT,
                    `users_id`      INT {$sign} NOT NULL DEFAULT 0 COMMENT 'para quem e o aviso',
                    `itemtype`      VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Occurrence',
                    `items_id`      INT {$sign} NOT NULL DEFAULT 0,
                    `kind`          VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'time_limit',
                    `message`       TEXT COMMENT 'texto pronto exibido no sino',
                    `is_read`       TINYINT NOT NULL DEFAULT 0,
                    `date_creation` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `user_unread` (`users_id`, `is_read`),
                    KEY `item` (`itemtype`, `items_id`),
                    UNIQUE KEY `dedup` (`users_id`, `itemtype`, `items_id`, `kind`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // 6) Diálogo das tarefas (Etapa 8e-1).
        //
        //    Comentários por ocorrência (avulsa ou dia de rotina). Quem
        //    interage = dono + criador quando distinto (decisão nº 28);
        //    a régua é validada no domínio (Comment.php), não aqui.
        //    Exclusão soft, como tudo no plugin.
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_taskplus_comments')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_taskplus_comments` (
                    `id`            INT {$sign} NOT NULL AUTO_INCREMENT,
                    `plugin_taskplus_occurrences_id` INT {$sign} NOT NULL DEFAULT 0,
                    `users_id`      INT {$sign} NOT NULL DEFAULT 0 COMMENT 'autor',
                    `content`       TEXT,
                    `documents_id`  INT {$sign} NOT NULL DEFAULT 0 COMMENT 'anexo (Document nativo), 0 = sem',
                    `is_deleted`    TINYINT NOT NULL DEFAULT 0,
                    `date_creation` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `occ_alive` (`plugin_taskplus_occurrences_id`, `is_deleted`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // 7) Marca de leitura do diálogo (Etapa 9a-1).
        //
        //    UMA linha por (ocorrência, leitor) com o instante da última
        //    vez que aquele leitor abriu a thread. O não lido é derivado
        //    daqui: comentário VIVO de OUTRO autor com date_creation
        //    posterior ao date_read (ou sem linha nenhuma = nunca abriu).
        //
        //    Tabela própria, e não coluna no comments: o mesmo comentário
        //    tem leitores diferentes (dono, criador e — pela Equipe —
        //    qualquer gestor do setor), então o estado é do PAR, nunca do
        //    comentário. A UNIQUE `occ_user` é o que torna a marcação um
        //    upsert seguro mesmo com dois cliques simultâneos.
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_taskplus_comment_reads')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_taskplus_comment_reads` (
                    `id`            INT {$sign} NOT NULL AUTO_INCREMENT,
                    `plugin_taskplus_occurrences_id` INT {$sign} NOT NULL DEFAULT 0,
                    `users_id`      INT {$sign} NOT NULL DEFAULT 0 COMMENT 'leitor',
                    `date_read`     TIMESTAMP NULL DEFAULT NULL COMMENT 'ultima abertura da thread',
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `occ_user` (`plugin_taskplus_occurrences_id`, `users_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // Reconciliação de schema: garante as colunas que passarem a
        // existir DEPOIS da criação da tabela (bases de versões antigas).
        // ------------------------------------------------------------------
        self::ensureSchema($migration);

        // Fases de sistema: idempotente por `system_key`, então rodar
        // plugin:install --force não duplica nem sobrescreve o que o
        // usuário renomeou.
        self::ensurePhases();

        // ------------------------------------------------------------------
        // Direitos.
        //
        // Lição 38 (ProjectPlus): Migration::addRight insere a linha para
        // TODOS os perfis que ainda não a têm — o valor pedido para quem
        // atende o pré-requisito, 0 para os demais — e só a PRIMEIRA
        // chamada por nome tem efeito. Nunca rebaixa valor existente:
        // reexecutar o install (plugin:install --force) é seguro.
        //
        // Pré-requisito `config` UPDATE = só o super-admin nasce com
        // acesso; os demais perfis nascem com 0 (desmarcados) e são
        // configurados à mão em Administração → Perfis.
        // ------------------------------------------------------------------
        $crudBits = READ | UPDATE | CREATE | DELETE; // 15 — sem PURGE (lição 38)

        $migration->addRight('plugin_taskplus_task', $crudBits, ['config' => UPDATE]);
        $migration->addRight('plugin_taskplus_manage', $crudBits, ['config' => UPDATE]);

        // 8b: página inicial. NASCE 0 PARA TODOS — inclusive super-admin:
        // trocar a página de entrada sem ninguém pedir seria surpresa, não
        // recurso. Detalhe do core validado no fonte (11.0.6): com
        // $requiredrights = [] o addRight daria o VALOR CHEIO a todos os
        // perfis ($reqmet = true) — por isso o valor pedido aqui é 0 e o
        // ajuste é feito perfil a perfil na aba Task+.
        $migration->addRight('plugin_taskplus_home', 0, []);

        // ------------------------------------------------------------------
        // Crons (registro apenas — a lógica chega nas Etapas 2 e 6).
        // CronTask::register é idempotente (ignora duplicata pelo nome).
        //
        // taskplusgen roda de hora em hora de propósito: rotina criada às
        // 10h precisa aparecer na tela Hoje do mesmo dia, não só amanhã.
        // A idempotência (UNIQUE routine_day) garante que rodar N vezes ao
        // dia não duplica nada.
        //
        // 9d: `mode` NÃO é informado — de propósito (decisão nº 42).
        // Validado no fonte do 11.0.6 (src/CronTask.php::register e
        // install/empty_data.php):
        //  - o default do schema é mode=1 (GLPI/interno) e allowmode=3,
        //    então a tarefa já nasce rodando SEM crontab do sistema —
        //    que é o que o core faz com as PRÓPRIAS tarefas nativas,
        //    inclusive a `queuednotification` que despacha nossos e-mails;
        //  - se a instalação tiver GLPI_SYSTEM_CRON ligada (pacote de
        //    distro que traz cron do sistema), o register() escolhe
        //    MODE_EXTERNAL sozinho — mas SÓ se o plugin não informar o
        //    modo. Fixar EXTERNAL aqui era justamente o que impedia
        //    isso e obrigava todo mundo a configurar crontab na mão.
        // Quem quer CLI troca em Configurar → Ações automáticas
        // (allowmode=3 permite os dois). Linha JÁ existente não é
        // tocada: register() não altera registro existente, e rebaixar
        // um ambiente que já tem crontab seria regressão.
        // ------------------------------------------------------------------
        CronTask::register(
            Cron::class,
            'taskplusgen',
            HOUR_TIMESTAMP,
            [
                'state'         => CronTask::STATE_WAITING,
                'logs_lifetime' => 30,
                'comment'       => 'Tarefas: materializa as ocorrências do dia (idempotente)',
            ]
        );
        CronTask::register(
            Cron::class,
            'taskplusalerts',
            10 * MINUTE_TIMESTAMP,
            [
                'state'         => CronTask::STATE_WAITING,
                'logs_lifetime' => 30,
                'comment'       => 'Tarefas: alertas de horário-limite e pendências',
            ]
        );

        // ------------------------------------------------------------------
        // 7a: register() é idempotente e NÃO altera linha existente — em
        // base instalada antes do 7a o taskplusalerts ficou registrado de
        // hora em hora, granularidade inútil para horário-limite. Ajusta
        // UMA vez, e SÓ se a frequência ainda for o default antigo (1h):
        // qualquer outro valor é ajuste do admin e fica intocado.
        // ------------------------------------------------------------------
        $alertTask = new CronTask();
        if (
            $alertTask->getFromDBbyName(Cron::class, 'taskplusalerts')
            && (int) $alertTask->fields['frequency'] === HOUR_TIMESTAMP
        ) {
            $alertTask->update([
                'id'        => $alertTask->getID(),
                'frequency' => 10 * MINUTE_TIMESTAMP,
            ]);
        }

        // 9d: renomeia a marca ANTES de semear. A âncora do modelo é
        // itemtype + NOME: se o seed rodasse primeiro com o nome novo,
        // não acharia o modelo antigo e criaria um DUPLICADO órfão (o
        // link de modo já aponta para o velho). Renomear antes faz o
        // ensureNotifications() achar tudo e não mexer em nada.
        self::migrateBrandNames();

        self::ensureNotifications();

        $migration->executeMigration();

        return true;
    }

    /**
     * Semeia as cadeias de notificação do plugin (modelo → tradução →
     * notificação → modo → destinatário), tudo via objetos nativos
     * (invariante: tabela nativa só se escreve pelo core) e idempotente:
     * cada elo é procurado antes de criado — reexecutar o install
     * (plugin:install --force) não duplica nada e PRESERVA edições do
     * admin (modelo alterado, notificação desativada etc.).
     *
     * Cadeias:
     *  - 7a: time_limit  → modo AJAX ("navegador", pop-up do SO);
     *  - 7b-1: end_of_day → modo MAILING (e-mail; o core enfileira em
     *    glpi_queuednotifications e o cron nativo `queuednotification`
     *    despacha — validado contra o fonte do 11.0.6: a constante é
     *    MODE_MAIL = 'mailing', e o gate de envio é o e-mail ligado em
     *    Configurar → Notificações).
     *
     * Liga/desliga fica na rota nativa: Administração → Notificações
     * (o evento) e as preferências do próprio usuário (opt-out).
     *
     * ÂNCORAS da idempotência: a notificação é achada por itemtype +
     * EVENTO (chave natural); o modelo por itemtype + NOME (com duas
     * cadeias no mesmo itemtype, procurar só por itemtype ficaria
     * ambíguo — aperto feito na 7b-1; os nomes batem com os semeados
     * na 7a, então nada duplica em base existente).
     */
    /**
     * 9d — mapa da renomeação "Task+" → "Tarefas" (decisão nº 41).
     *
     * Uma entrada por cadeia, com o par (antigo, novo) de cada campo que
     * carrega a marca. Os corpos legados são DERIVADOS do texto atual por
     * uma substituição de frase única e literal, em vez de duplicados
     * inteiros aqui: o que mudou foi uma frase por corpo, e repetir os
     * blocos ##FOREACH## nesta tabela só criaria uma segunda cópia para
     * sair de sincronia. Falha SEGURA: se um corpo for reescrito no
     * futuro, o legado derivado deixa de bater com o gravado e a migração
     * simplesmente não atualiza aquele campo — nunca corrompe.
     *
     * `null` = o campo não menciona a marca (nada a migrar).
     */
    private static function brandMigrationMap(): array
    {
        return [
            [
                'event'        => Alerts::EVENT_TIME_LIMIT,
                'cron'         => null,
                'template_old' => 'Task+ horario-limite',
                'template_new' => 'Tarefas horario-limite',
                'notif_old'    => 'Task+ — horário-limite estourado',
                'notif_new'    => 'Tarefas — horário-limite estourado',
                'subject_old'  => 'Task+: horário-limite estourado — ##taskplus.name##',
                'subject_new'  => 'Tarefas: horário-limite estourado — ##taskplus.name##',
                'text_old'     => null, // o corpo do sino não cita a marca
                'text_new'     => null,
                'html_old'     => null,
                'html_new'     => null,
            ],
            [
                'event'        => Emails::EVENT_END_OF_DAY,
                'cron'         => null,
                'template_old' => 'Task+ fim de dia',
                'template_new' => 'Tarefas fim de dia',
                'notif_old'    => 'Task+ — fim de dia (e-mail ao técnico)',
                'notif_new'    => 'Tarefas — fim de dia (e-mail ao técnico)',
                'subject_old'  => 'Task+: fim de dia ##taskplus.eod_date## — o que ficou para amanhã',
                'subject_new'  => 'Tarefas: fim de dia ##taskplus.eod_date## — o que ficou para amanhã',
                'text_old'     => self::legacyEodContentText(),
                'text_new'     => self::eodContentText(),
                'html_old'     => self::legacyEodContentHtml(),
                'html_new'     => self::eodContentHtml(),
            ],
            [
                'event'        => Emails::EVENT_MORNING_DIGEST,
                'cron'         => null,
                'template_old' => 'Task+ resumo matinal',
                'template_new' => 'Tarefas resumo matinal',
                'notif_old'    => 'Task+ — resumo matinal (e-mail ao gestor)',
                'notif_new'    => 'Tarefas — resumo matinal (e-mail ao gestor)',
                'subject_old'  => 'Task+: resumo matinal ##taskplus.digest_date## — sua equipe',
                'subject_new'  => 'Tarefas: resumo matinal ##taskplus.digest_date## — sua equipe',
                'text_old'     => self::legacyDigestContentText(),
                'text_new'     => self::digestContentText(),
                'html_old'     => self::legacyDigestContentHtml(),
                'html_new'     => self::digestContentHtml(),
            ],
        ];
    }

    /**
     * 9d — comentários legados das ações automáticas (mesma régua: só
     * troca se o gravado for idêntico ao que NÓS semeamos).
     */
    private const CRON_COMMENTS = [
        'taskplusgen' => [
            'Task+: materializa as ocorrências do dia (idempotente)',
            'Tarefas: materializa as ocorrências do dia (idempotente)',
        ],
        'taskplusalerts' => [
            'Task+: alertas de horário-limite e pendências',
            'Tarefas: alertas de horário-limite e pendências',
        ],
    ];

    /**
     * 9d — renomeia a marca em base JÁ INSTALADA (decisão nº 41).
     *
     * Régua (decisões nº 2 e 3):
     *  - NOME de modelo e de notificação: trocado sempre que casar pelo
     *    nome antigo EXATO. Se o admin renomeou, não casa e fica quieto;
     *  - ASSUNTO e CORPOS: trocados só quando o gravado for idêntico
     *    BYTE A BYTE ao que semeamos. Qualquer diferença = edição do
     *    admin, e edição do admin não se sobrescreve. Campo a campo: dá
     *    para ter mexido só no HTML e manter o texto puro.
     *
     * Idempotente por construção: casa pelo nome ANTIGO, que some na
     * primeira passada. Tudo por objeto nativo (invariante do projeto:
     * tabela do core só se escreve pelo core).
     */
    private static function migrateBrandNames(): void
    {
        $itemtype = OccurrenceAlert::class;

        foreach (self::brandMigrationMap() as $chain) {
            // 1) Modelo. Casa pelo nome ANTIGO **ou pelo novo**: uma
            // migração interrompida no meio (o install aborta na primeira
            // exceção) pode ter renomeado o modelo sem chegar à tradução.
            // Casar só pelo antigo deixaria essa cadeia órfã para sempre;
            // casar pelos dois torna a passada RETOMÁVEL. Não afrouxa a
            // régua: o que protege a edição do admin é a comparação byte
            // a byte lá dentro, não o nome.
            $template = new NotificationTemplate();
            $rows     = $template->find([
                'itemtype' => $itemtype,
                'name'     => [$chain['template_old'], $chain['template_new']],
            ]);
            if ($rows !== []) {
                $row         = reset($rows);
                $templatesId = (int) $row['id'];
                if ((string) $row['name'] !== $chain['template_new']) {
                    $template->update([
                        'id'   => $templatesId,
                        'name' => $chain['template_new'],
                    ]);
                }
                self::migrateTranslation($templatesId, $chain);
            }

            // 2) Notificação: âncora é itemtype + EVENTO; o nome é só
            // rótulo, então casa-se pelo nome antigo antes de trocar.
            $notification = new Notification();
            $rows         = $notification->find([
                'itemtype' => $itemtype,
                'event'    => $chain['event'],
                'name'     => $chain['notif_old'],
            ]);
            if ($rows !== []) {
                $notification->update([
                    'id'   => (int) reset($rows)['id'],
                    'name' => $chain['notif_new'],
                ]);
            }
        }

        self::migrateCronComments();
    }

    /**
     * Tradução default de um modelo: assunto e corpos, campo a campo,
     * só quando idênticos ao seed antigo.
     *
     * ATENÇÃO (9d, achado em homologação): o input do update tem de
     * trazer SEMPRE `content_html` e `content_text`, mesmo quando não
     * mudam. O `prepareInputForUpdate` do core chama
     * `NotificationTemplateTranslation::cleanContentHtml()`, que lê as
     * duas chaves sem verificar existência — update parcial estoura em
     * `RichText::getTextFromHtml(): Argument #1 must be of type string,
     * null given`. Por isso o input parte da linha lida e só sobrescreve
     * o que casou.
     */
    private static function migrateTranslation(int $templatesId, array $chain): void
    {
        $translation = new NotificationTemplateTranslation();
        $rows        = $translation->find([
            'notificationtemplates_id' => $templatesId,
            'language'                 => '',
        ]);
        if ($rows === []) {
            return;
        }

        $row    = reset($rows);
        $update = [
            'id'           => (int) $row['id'],
            'subject'      => (string) ($row['subject'] ?? ''),
            'content_text' => (string) ($row['content_text'] ?? ''),
            'content_html' => (string) ($row['content_html'] ?? ''),
        ];

        $pairs = [
            'subject'      => ['subject_old', 'subject_new'],
            'content_text' => ['text_old', 'text_new'],
            'content_html' => ['html_old', 'html_new'],
        ];
        $changed = false;
        foreach ($pairs as $field => [$oldKey, $newKey]) {
            if ($chain[$oldKey] === null) {
                continue; // campo sem marca
            }
            if ($update[$field] === $chain[$oldKey]) {
                $update[$field] = $chain[$newKey];
                $changed        = true;
            }
        }

        if ($changed) {
            $translation->update($update);
        }
    }

    /**
     * Comentário das ações automáticas (Configurar → Ações automáticas).
     * `CronTask::register` não altera linha existente, então a troca tem
     * de ser explícita — mesma régua do byte a byte.
     */
    private static function migrateCronComments(): void
    {
        foreach (self::CRON_COMMENTS as $name => [$old, $new]) {
            $task = new CronTask();
            if (!$task->getFromDBbyName(Cron::class, $name)) {
                continue;
            }
            if ((string) ($task->fields['comment'] ?? '') !== $old) {
                continue; // ausente ou editado pelo admin — não se toca
            }
            $task->update([
                'id'      => $task->getID(),
                'comment' => $new,
            ]);
        }
    }

    /**
     * Corpos legados (pré-9d) derivados do atual — ver docblock de
     * brandMigrationMap(). Cada um troca UMA frase literal.
     */
    private static function legacyEodContentText(): string
    {
        return str_replace(
            '— suas tarefas de hoje.',
            '— suas tarefas no Task+.',
            self::eodContentText()
        );
    }

    private static function legacyEodContentHtml(): string
    {
        return str_replace(
            '— suas tarefas de hoje.&lt;/p&gt;',
            '— suas tarefas no Task+.&lt;/p&gt;',
            self::eodContentHtml()
        );
    }

    private static function legacyDigestContentText(): string
    {
        return str_replace(
            '— situação da sua equipe ',
            '— situação da sua equipe no Task+ ',
            self::digestContentText()
        );
    }

    private static function legacyDigestContentHtml(): string
    {
        return str_replace(
            '— situação da sua equipe ',
            '— situação da sua equipe no Task+ ',
            self::digestContentHtml()
        );
    }

    private static function ensureNotifications(): void
    {
        self::ensureNotificationChain(
            'Tarefas horario-limite',
            Alerts::EVENT_TIME_LIMIT,
            'Tarefas — horário-limite estourado',
            Notification_NotificationTemplate::MODE_AJAX,
            'Tarefas: horário-limite estourado — ##taskplus.name##',
            "A tarefa ##taskplus.name## tinha horário-limite ##taskplus.limit## "
                . "de hoje (##taskplus.date##) e ainda está aberta.\n\n"
                . "##taskplus.description##\n\n"
                . "Abrir a tela Hoje: ##taskplus.url##",
            '&lt;p&gt;A tarefa &lt;strong&gt;##taskplus.name##&lt;/strong&gt; tinha '
                . 'horário-limite &lt;strong&gt;##taskplus.limit##&lt;/strong&gt; de hoje '
                . '(##taskplus.date##) e ainda está aberta.&lt;/p&gt;'
                . '&lt;p&gt;##taskplus.description##&lt;/p&gt;'
                . '&lt;p&gt;&lt;a href="##taskplus.url##"&gt;Abrir a tela Hoje&lt;/a&gt;&lt;/p&gt;'
        );

        self::ensureNotificationChain(
            'Tarefas fim de dia',
            Emails::EVENT_END_OF_DAY,
            'Tarefas — fim de dia (e-mail ao técnico)',
            Notification_NotificationTemplate::MODE_MAIL,
            'Tarefas: fim de dia ##taskplus.eod_date## — o que ficou para amanhã',
            self::eodContentText(),
            self::eodContentHtml()
        );

        // 7b-2: resumo matinal ao gestor. Destinatário = alvo CUSTOM
        // TARGET_MANAGER (AUTHOR não serve — a âncora do evento é uma
        // ocorrência de técnico; quem resolve o gestor é o
        // addSpecificTargets do target, lendo as $options do raise).
        self::ensureNotificationChain(
            'Tarefas resumo matinal',
            Emails::EVENT_MORNING_DIGEST,
            'Tarefas — resumo matinal (e-mail ao gestor)',
            Notification_NotificationTemplate::MODE_MAIL,
            'Tarefas: resumo matinal ##taskplus.digest_date## — sua equipe',
            self::digestContentText(),
            self::digestContentHtml(),
            Emails::TARGET_MANAGER
        );
    }

    /**
     * Texto puro do resumo matinal (7b-2): uma linha por técnico com o
     * trio agregado — sem listar tarefas (decisão nº 22). Sem ##IF## de
     * seção: "só envia se houver conteúdo" garante ao menos uma linha.
     */
    private static function digestContentText(): string
    {
        return "Resumo matinal ##taskplus.digest_date## — situação da sua equipe "
            . "(##taskplus.tech_count## técnico(s) com tarefas vivas).\n\n"
            . "##FOREACHtechs##"
            . " - ##digest.tech##: ##digest.open## aberta(s) hoje, "
            . "##digest.overdue## atrasada(s), ##digest.pending## pendente(s)\n"
            . "##ENDFOREACHtechs##\n"
            . "Abrir a tela Equipe: ##taskplus.team_url##";
    }

    /**
     * HTML do resumo matinal — gravado ESCAPADO (&lt;p&gt;…), formato do
     * seed do core 11 (mesmo padrão das cadeias da 7a/7b-1).
     */
    private static function digestContentHtml(): string
    {
        return '&lt;p&gt;Resumo matinal &lt;strong&gt;##taskplus.digest_date##&lt;/strong&gt; '
            . '— situação da sua equipe '
            . '(##taskplus.tech_count## técnico(s) com tarefas vivas).&lt;/p&gt;'
            . '&lt;ul&gt;##FOREACHtechs##&lt;li&gt;&lt;strong&gt;##digest.tech##&lt;/strong&gt;: '
            . '##digest.open## aberta(s) hoje, ##digest.overdue## atrasada(s), '
            . '##digest.pending## pendente(s)&lt;/li&gt;##ENDFOREACHtechs##&lt;/ul&gt;'
            . '&lt;p&gt;&lt;a href="##taskplus.team_url##"&gt;Abrir a tela Equipe&lt;/a&gt;&lt;/p&gt;';
    }

    /**
     * Texto puro do e-mail de fim de dia. As seções usam ##IFtag## com a
     * CONTAGEM (o motor do core trata '0' como falso — seção vazia some)
     * e ##FOREACHlista## para as linhas (tags ##eod.*## por linha).
     */
    private static function eodContentText(): string
    {
        return "Fim de dia ##taskplus.eod_date## — suas tarefas de hoje.\n\n"
            . "##IFtaskplus.open_count##"
            . "Abertas de hoje (##taskplus.open_count##):\n"
            . "##FOREACHopen##"
            . " - ##eod.name####IFeod.limit## (limite ##eod.limit##)##ENDIFeod.limit##"
            . "##IFeod.origin## [##eod.origin##]##ENDIFeod.origin##\n"
            . "##ENDFOREACHopen##\n"
            . "##ENDIFtaskplus.open_count##"
            . "##IFtaskplus.overdue_count##"
            . "Atrasadas (##taskplus.overdue_count##):\n"
            . "##FOREACHoverdue##"
            . " - ##eod.name## (desde ##eod.date##)"
            . "##IFeod.origin## [##eod.origin##]##ENDIFeod.origin##\n"
            . "##ENDFOREACHoverdue##\n"
            . "##ENDIFtaskplus.overdue_count##"
            . "##IFtaskplus.pending_count##"
            . "Pendentes (##taskplus.pending_count##):\n"
            . "##FOREACHpending##"
            . " - ##eod.name## — ##eod.pending##\n"
            . "##ENDFOREACHpending##\n"
            . "##ENDIFtaskplus.pending_count##"
            . "Abrir a tela Hoje: ##taskplus.url##";
    }

    /**
     * HTML do e-mail de fim de dia — gravado ESCAPADO (&lt;p&gt;…),
     * formato do seed do core 11 (mesmo padrão validado na 7a).
     */
    private static function eodContentHtml(): string
    {
        return '&lt;p&gt;Fim de dia &lt;strong&gt;##taskplus.eod_date##&lt;/strong&gt; '
            . '— suas tarefas de hoje.&lt;/p&gt;'
            . '##IFtaskplus.open_count##'
            . '&lt;p&gt;&lt;strong&gt;Abertas de hoje (##taskplus.open_count##):&lt;/strong&gt;&lt;/p&gt;'
            . '&lt;ul&gt;##FOREACHopen##&lt;li&gt;##eod.name##'
            . '##IFeod.limit## (limite ##eod.limit##)##ENDIFeod.limit##'
            . '##IFeod.origin## &lt;em&gt;[##eod.origin##]&lt;/em&gt;##ENDIFeod.origin##'
            . '&lt;/li&gt;##ENDFOREACHopen##&lt;/ul&gt;'
            . '##ENDIFtaskplus.open_count##'
            . '##IFtaskplus.overdue_count##'
            . '&lt;p&gt;&lt;strong&gt;Atrasadas (##taskplus.overdue_count##):&lt;/strong&gt;&lt;/p&gt;'
            . '&lt;ul&gt;##FOREACHoverdue##&lt;li&gt;##eod.name## (desde ##eod.date##)'
            . '##IFeod.origin## &lt;em&gt;[##eod.origin##]&lt;/em&gt;##ENDIFeod.origin##'
            . '&lt;/li&gt;##ENDFOREACHoverdue##&lt;/ul&gt;'
            . '##ENDIFtaskplus.overdue_count##'
            . '##IFtaskplus.pending_count##'
            . '&lt;p&gt;&lt;strong&gt;Pendentes (##taskplus.pending_count##):&lt;/strong&gt;&lt;/p&gt;'
            . '&lt;ul&gt;##FOREACHpending##&lt;li&gt;##eod.name## — ##eod.pending##'
            . '&lt;/li&gt;##ENDFOREACHpending##&lt;/ul&gt;'
            . '##ENDIFtaskplus.pending_count##'
            . '&lt;p&gt;&lt;a href="##taskplus.url##"&gt;Abrir a tela Hoje&lt;/a&gt;&lt;/p&gt;';
    }

    /**
     * Semeia UMA cadeia completa: modelo (itemtype+nome) → tradução
     * default → notificação (itemtype+evento, entidade raiz recursiva)
     * → modo → destinatário ($targetItemsId; AUTHOR por padrão, alvo
     * custom na cadeia do gestor). Cada elo procurado antes de criado.
     */
    private static function ensureNotificationChain(
        string $templateName,
        string $event,
        string $notificationName,
        string $mode,
        string $subject,
        string $contentText,
        string $contentHtml,
        int $targetItemsId = Notification::AUTHOR
    ): void {
        $itemtype = OccurrenceAlert::class;

        // 1) Modelo (itemtype + NOME — ver docblock de ensureNotifications)
        $template    = new NotificationTemplate();
        $templateRow = $template->find(['itemtype' => $itemtype, 'name' => $templateName]);
        if ($templateRow !== []) {
            $templatesId = (int) reset($templateRow)['id'];
        } else {
            $templatesId = (int) $template->add([
                'name'     => $templateName,
                'itemtype' => $itemtype,
            ]);
        }
        if ($templatesId <= 0) {
            return; // sem modelo não há cadeia — melhor parar que semear órfãos
        }

        // 2) Tradução default (language = '') com as tags do target
        $translation = new NotificationTemplateTranslation();
        if ($translation->find(['notificationtemplates_id' => $templatesId]) === []) {
            $translation->add([
                'notificationtemplates_id' => $templatesId,
                'language'                 => '',
                'subject'                  => $subject,
                'content_text'             => $contentText,
                'content_html'             => $contentHtml,
            ]);
        }

        // 3) Notificação do evento (entidade raiz, recursiva)
        $notification    = new Notification();
        $notificationRow = $notification->find([
            'itemtype' => $itemtype,
            'event'    => $event,
        ]);
        if ($notificationRow !== []) {
            $notificationsId = (int) reset($notificationRow)['id'];
        } else {
            $notificationsId = (int) $notification->add([
                'name'         => $notificationName,
                'entities_id'  => 0,
                'is_recursive' => 1,
                'itemtype'     => $itemtype,
                'event'        => $event,
                'is_active'    => 1,
            ]);
        }
        if ($notificationsId <= 0) {
            return;
        }

        // 4) Canal (ajax = sino/navegador; mailing = fila de e-mail)
        $modeLink = new Notification_NotificationTemplate();
        if (
            $modeLink->find([
                'notifications_id' => $notificationsId,
                'mode'             => $mode,
            ]) === []
        ) {
            $modeLink->add([
                'notifications_id'         => $notificationsId,
                'mode'                     => $mode,
                'notificationtemplates_id' => $templatesId,
            ]);
        }

        // 5) Destinatário: AUTHOR por padrão (users_id da linha âncora);
        // a cadeia do resumo matinal (7b-2) usa o alvo CUSTOM
        // Emails::TARGET_MANAGER, resolvido pelo addSpecificTargets.
        $target = new NotificationTarget();
        if (
            $target->find([
                'notifications_id' => $notificationsId,
                'type'             => Notification::USER_TYPE,
                'items_id'         => $targetItemsId,
            ]) === []
        ) {
            $target->add([
                'notifications_id' => $notificationsId,
                'type'             => Notification::USER_TYPE,
                'items_id'         => $targetItemsId,
            ]);
        }
    }

    /**
     * Garante que as tabelas existentes tenham todas as colunas atuais.
     * Idempotente: addField é no-op quando a coluna já existe.
     */
    private static function ensureSchema(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (self::COLUMNS as $table => $columns) {
            if (!$DB->tableExists($table)) {
                continue;
            }
            foreach ($columns as $field => $sqlType) {
                if (!$DB->fieldExists($table, $field)) {
                    $sqlType = str_replace(
                        '%SIGN%',
                        \DBConnection::getDefaultPrimaryKeySignOption(),
                        $sqlType
                    );
                    $migration->addField($table, $field, $sqlType);
                }
            }
        }
    }

    /**
     * Cria as fases de sistema que ainda não existem, casando por
     * `system_key`. Não mexe nas que já estão lá (nome e cor podem ter
     * sido ajustados pelo usuário na Etapa 4c).
     */
    private static function ensurePhases(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists('glpi_plugin_taskplus_phases')) {
            return;
        }

        $existing = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_taskplus_phases']) as $row) {
            $key = (string) ($row['system_key'] ?? '');
            if ($key !== '') {
                $existing[$key] = true;
            }
        }

        $now = date('Y-m-d H:i:s');
        foreach (self::DEFAULT_PHASES as $phase) {
            if (isset($existing[$phase['system_key']])) {
                continue;
            }
            $DB->insert('glpi_plugin_taskplus_phases', $phase + [
                'is_deleted'    => 0,
                'date_creation' => $now,
                'date_mod'      => $now,
            ]);
        }
    }

    public static function uninstall(): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Proteção de dados: por padrão tabelas, direitos e configuração
        // são MANTIDOS na desinstalação (rotinas e histórico sobrevivem a
        // um ciclo desinstalar/reinstalar). O expurgo completo só acontece
        // se o admin ativar "purge_on_uninstall" em Configurações.
        $config = Config::get();
        if (!empty($config['purge_on_uninstall'])) {
            foreach (self::TABLES as $table) {
                if ($DB->tableExists($table)) {
                    $DB->doQuery("DROP TABLE `{$table}`");
                }
            }

            ProfileRight::deleteProfileRights(self::RIGHTS);

            CoreConfig::deleteConfigurationValues(
                Config::CONTEXT,
                array_keys(Config::DEFAULTS)
            );
        }

        // Remove os crons (a classe deixa de existir com o plugin
        // desinstalado; register() os recria na reinstalação)
        CronTask::unregister('taskplus');

        return true;
    }
}
