<?php

namespace GlpiPlugin\Taskplus;

use Config as CoreConfig;
use CronTask;
use Migration;
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
            'is_done'          => 'TINYINT NOT NULL DEFAULT 0',
            'done_date'        => 'TIMESTAMP NULL DEFAULT NULL',
            'users_id_done'    => 'INT %SIGN% NOT NULL DEFAULT 0',
            'is_skipped'       => 'TINYINT NOT NULL DEFAULT 0',
            'is_deleted'       => 'TINYINT NOT NULL DEFAULT 0',
            'date_creation'    => 'TIMESTAMP NULL DEFAULT NULL',
            'date_mod'         => 'TIMESTAMP NULL DEFAULT NULL',
        ],
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
        // Reconciliação de schema: garante as colunas que passarem a
        // existir DEPOIS da criação da tabela (bases de versões antigas).
        // ------------------------------------------------------------------
        self::ensureSchema($migration);

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

        // ------------------------------------------------------------------
        // Crons (registro apenas — a lógica chega nas Etapas 2 e 6).
        // CronTask::register é idempotente (ignora duplicata pelo nome).
        //
        // taskplusgen roda de hora em hora de propósito: rotina criada às
        // 10h precisa aparecer na tela Hoje do mesmo dia, não só amanhã.
        // A idempotência (UNIQUE routine_day) garante que rodar N vezes ao
        // dia não duplica nada.
        // ------------------------------------------------------------------
        CronTask::register(
            Cron::class,
            'taskplusgen',
            HOUR_TIMESTAMP,
            [
                'state'         => CronTask::STATE_WAITING,
                'mode'          => CronTask::MODE_EXTERNAL,
                'logs_lifetime' => 30,
                'comment'       => 'Task+: materializa as ocorrências do dia (idempotente)',
            ]
        );
        CronTask::register(
            Cron::class,
            'taskplusalerts',
            HOUR_TIMESTAMP,
            [
                'state'         => CronTask::STATE_WAITING,
                'mode'          => CronTask::MODE_EXTERNAL,
                'logs_lifetime' => 30,
                'comment'       => 'Task+: alertas de horário-limite e pendências',
            ]
        );

        $migration->executeMigration();

        return true;
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
