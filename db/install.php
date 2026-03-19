<?php
/**
 * Instalador das tabelas notifcourse_* usando XMLDB do Moodle.
 *
 * Compatível com MySQL/MariaDB e PostgreSQL automaticamente.
 *
 * CLI:
 *   php install.php          # Cria tabelas e dados default
 *   php install.php --force  # Recria tabelas (apaga dados existentes!)
 *
 * Web: admin acessa via navegador, confirma a instalacao com botao.
 */

// Detectar modo de execucao.
$is_cli = (php_sapi_name() === 'cli');

if ($is_cli) {
    define('CLI_SCRIPT', true);
    define('NOTIFCOURSE_INTERNAL', true);
    $moodle_config = __DIR__ . '/../../config.php';
    if (!file_exists($moodle_config)) {
        echo "ERRO: config.php do Moodle nao encontrado em: {$moodle_config}\n";
        exit(1);
    }
    require_once($moodle_config);
} else {
    require_once(__DIR__ . '/../bootstrap.php');
}

global $DB, $OUTPUT, $PAGE;

$dbman = $DB->get_manager();

// ---------------------------------------------------------------------------
// CLI mode — run directly
// ---------------------------------------------------------------------------
if ($is_cli) {
    $force = in_array('--force', $argv ?? [], true);
    run_install($dbman, $force);
    exit(0);
}

// ---------------------------------------------------------------------------
// Web mode — show confirmation page or run install
// ---------------------------------------------------------------------------
$PAGE->set_title('Instalador — Notificacoes de Curso');

// Check current state of tables.
$tables_status = check_tables($dbman);
$all_installed = !in_array(false, $tables_status, true);

// Handle POST — run the install.
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $messages = run_install_web($dbman);
    // Re-check after install.
    $tables_status = check_tables($dbman);
    $all_installed = !in_array(false, $tables_status, true);
}

// Render the page.
require_once(__DIR__ . '/../includes/header.php');
?>

<div class="page-header">
    <h1 class="page-title">Instalador de Banco de Dados</h1>
    <p class="page-subtitle">Gerenciamento das tabelas <code>notifcourse_*</code> necessarias para a aplicacao.</p>
</div>

<!-- Status das tabelas -->
<div class="card mb-6">
    <h2 style="font-size:1rem;font-weight:700;margin-bottom:1rem;">Status das Tabelas</h2>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Tabela</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tables_status as $tname => $exists): ?>
                <tr>
                    <td><code>mdl_<?= htmlspecialchars($tname) ?></code></td>
                    <td>
                        <?php if ($exists): ?>
                            <span class="badge badge-sent">Instalada</span>
                        <?php else: ?>
                            <span class="badge badge-failed">Nao encontrada</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Mensagens do install -->
<?php if (!empty($messages)): ?>
<div class="alert alert-success mb-6" role="alert">
    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
         stroke-linejoin="round" style="flex-shrink:0;margin-top:2px">
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
        <polyline points="22 4 12 14.01 9 11.01"/>
    </svg>
    <div>
        <strong>Instalacao concluida!</strong>
        <ul style="margin:.5rem 0 0 1rem;list-style:disc;">
            <?php foreach ($messages as $msg): ?>
                <li><?= htmlspecialchars($msg) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<!-- Acoes -->
<div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
    <?php if ($all_installed): ?>
        <a href="<?= (new moodle_url('/notification_course/'))->out() ?>" class="btn btn-primary">
            Acessar Dashboard
        </a>
        <form method="post" action="">
            <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
            <input type="hidden" name="run_migrations" value="1">
            <button type="submit" class="btn btn-outline">
                Executar Migrations
            </button>
        </form>
        <span style="color:hsl(var(--muted-foreground));font-size:.875rem;">
            Todas as tabelas estao instaladas. Use "Executar Migrations" para aplicar atualizacoes pendentes.
        </span>
    <?php else: ?>
        <form method="post" action="">
            <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
            <button type="submit" class="btn btn-primary">
                Instalar Tabelas
            </button>
        </form>
        <span style="color:hsl(var(--muted-foreground));font-size:.875rem;">
            Clique para criar as tabelas ausentes no banco de dados.
        </span>
    <?php endif; ?>
</div>

<?php
require_once(__DIR__ . '/../includes/footer.php');

// =============================================================================
// Functions
// =============================================================================

/**
 * Check which tables exist.
 */
function check_tables($dbman): array {
    $tables = ['notifcourse_schedule', 'notifcourse_log', 'notifcourse_config', 'notifcourse_categories'];
    $status = [];
    foreach ($tables as $tname) {
        $status[$tname] = $dbman->table_exists(new xmldb_table($tname));
    }
    return $status;
}

/**
 * Run install via web — returns array of status messages.
 */
function run_install_web($dbman): array {
    $messages = [];

    // 1. notifcourse_schedule
    $table = build_schedule_table();
    $messages[] = create_table_safe($dbman, $table);

    // 2. notifcourse_log
    $table = build_log_table();
    $messages[] = create_table_safe($dbman, $table);

    // 3. notifcourse_config
    $table = build_config_table();
    $messages[] = create_table_safe($dbman, $table);

    // Seed defaults.
    $messages[] = seed_config_defaults();

    // 4. notifcourse_categories
    $table = build_categories_table();
    $messages[] = create_table_safe($dbman, $table);

    // 5. Migrations (add columns to existing tables).
    $messages[] = run_migrations($dbman);

    return $messages;
}

/**
 * Run install via CLI.
 */
function run_install($dbman, bool $force): void {
    echo "=== Instalador de tabelas notifcourse_* ===\n";

    $tables = [
        build_schedule_table(),
        build_log_table(),
        build_config_table(),
        build_categories_table(),
    ];

    foreach ($tables as $table) {
        $name = $table->getName();
        echo "\n--- {$name} ---\n";

        if ($dbman->table_exists($table)) {
            if ($force) {
                echo "  Tabela {$name} ja existe — removendo (--force)...\n";
                $dbman->drop_table($table);
                $dbman->create_table($table);
                echo "  Tabela {$name} recriada.\n";
            } else {
                echo "  Tabela {$name} ja existe — pulando.\n";
            }
        } else {
            $dbman->create_table($table);
            echo "  Tabela {$name} criada.\n";
        }
    }

    // Seed config defaults (only after config table exists).
    echo "\n--- Configuracoes padrao ---\n";
    echo "  " . seed_config_defaults() . "\n";

    // Migrations.
    echo "\n--- Migrations ---\n";
    echo "  " . run_migrations($dbman) . "\n";

    echo "\n=== Instalacao concluida com sucesso! ===\n";
}

/**
 * Create a table if it doesn't exist. Returns status message.
 */
function create_table_safe($dbman, xmldb_table $table): string {
    $name = $table->getName();

    if ($dbman->table_exists($table)) {
        return "Tabela {$name} ja existe — pulando.";
    }

    $dbman->create_table($table);
    return "Tabela {$name} criada com sucesso.";
}

/**
 * Seed default config values. Returns status message.
 */
function seed_config_defaults(): string {
    global $DB;

    $defaults = [
        'start_subject'      => null,
        'start_body'         => null,
        'start_hours_before' => '24',
        'end_subject'        => null,
        'end_body'           => null,
        'end_hours_after'    => '24',
        'end_survey_url'     => null,
        'logo_url'           => null,
        'batch_size'         => '50',
        'max_attempts'       => '3',
        'display_timezone'   => 'America/Sao_Paulo',
    ];

    $now = time();
    $inserted = 0;

    foreach ($defaults as $key => $value) {
        if (!$DB->record_exists('notifcourse_config', ['config_key' => $key])) {
            $record = new stdClass();
            $record->config_key   = $key;
            $record->config_value = $value;
            $record->timemodified = $now;
            $DB->insert_record('notifcourse_config', $record);
            $inserted++;
        }
    }

    $skipped = count($defaults) - $inserted;
    return "Configuracoes padrao: {$inserted} inseridas, {$skipped} ja existiam.";
}

// =============================================================================
// Table builders
// =============================================================================

function build_schedule_table(): xmldb_table {
    $table = new xmldb_table('notifcourse_schedule');
    $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
    $table->add_field('courseid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
    $table->add_field('lesson_date', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
    $table->add_field('send_at',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
    $table->add_field('link_aula',   XMLDB_TYPE_TEXT,    null,  null, null);
    $table->add_field('subject',     XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL);
    $table->add_field('body',        XMLDB_TYPE_TEXT,    null,  null, XMLDB_NOTNULL);
    $table->add_field('notification_type', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'lesson');
    $table->add_field('survey_url',  XMLDB_TYPE_TEXT,    null,  null, null);
    $table->add_field('status',      XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, 'pending');
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
    $table->add_field('createdby',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('idx_schedule_courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
    $table->add_index('idx_schedule_send_at',  XMLDB_INDEX_NOTUNIQUE, ['send_at']);
    $table->add_index('idx_schedule_status',   XMLDB_INDEX_NOTUNIQUE, ['status']);
    $table->add_index('idx_schedule_type',     XMLDB_INDEX_NOTUNIQUE, ['notification_type']);
    return $table;
}

function build_log_table(): xmldb_table {
    $table = new xmldb_table('notifcourse_log');
    $table->add_field('id',                  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
    $table->add_field('userid',              XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
    $table->add_field('courseid',            XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
    $table->add_field('schedule_id',         XMLDB_TYPE_INTEGER, '10',  null, null);
    $table->add_field('notification_type',   XMLDB_TYPE_CHAR,    '10',  null, XMLDB_NOTNULL);
    $table->add_field('origin',              XMLDB_TYPE_CHAR,    '10',  null, XMLDB_NOTNULL, null, 'auto');
    $table->add_field('manual_dispatch_id',  XMLDB_TYPE_CHAR,    '64',  null, null);
    $table->add_field('dedupe_key',          XMLDB_TYPE_CHAR,    '191', null, XMLDB_NOTNULL);
    $table->add_field('timesent',            XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
    $table->add_field('status',              XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL);
    $table->add_field('attempts',            XMLDB_TYPE_INTEGER, '3',   null, XMLDB_NOTNULL, null, '1');
    $table->add_field('next_retry_at',       XMLDB_TYPE_INTEGER, '10',  null, null);
    $table->add_field('last_error',          XMLDB_TYPE_TEXT,    null,   null, null);
    $table->add_field('is_simulation',       XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, '0');
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('uq_log_dedupe_key',           XMLDB_INDEX_UNIQUE,    ['dedupe_key']);
    $table->add_index('idx_log_courseid_timesent',    XMLDB_INDEX_NOTUNIQUE, ['courseid', 'timesent']);
    $table->add_index('idx_log_schedule_id_origin',   XMLDB_INDEX_NOTUNIQUE, ['schedule_id', 'origin']);
    $table->add_index('idx_log_status_next_retry_at', XMLDB_INDEX_NOTUNIQUE, ['status', 'next_retry_at']);
    $table->add_index('idx_log_userid',               XMLDB_INDEX_NOTUNIQUE, ['userid']);
    return $table;
}

function build_config_table(): xmldb_table {
    $table = new xmldb_table('notifcourse_config');
    $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
    $table->add_field('config_key',   XMLDB_TYPE_CHAR,    '100', null, XMLDB_NOTNULL);
    $table->add_field('config_value', XMLDB_TYPE_TEXT,    null,   null, null);
    $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('uq_config_key', XMLDB_INDEX_UNIQUE, ['config_key']);
    return $table;
}

/**
 * Run schema migrations for existing installations.
 */
function run_migrations($dbman): string {
    $table = new xmldb_table('notifcourse_schedule');
    $msgs = [];

    // Migration 1: Add notification_type column if missing.
    $field = new xmldb_field('notification_type', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'lesson', 'body');
    if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
        $dbman->add_field($table, $field);
        $msgs[] = 'notification_type adicionado a notifcourse_schedule';
    }

    // Migration 2: Add survey_url column if missing.
    $field2 = new xmldb_field('survey_url', XMLDB_TYPE_TEXT, null, null, null, null, null, 'notification_type');
    if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field2)) {
        $dbman->add_field($table, $field2);
        $msgs[] = 'survey_url adicionado a notifcourse_schedule';
    }

    // Migration 3: Add manual_dispatch_id to notifcourse_log if missing.
    $log_table = new xmldb_table('notifcourse_log');
    if ($dbman->table_exists($log_table)) {
        $field3 = new xmldb_field('manual_dispatch_id', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'origin');
        if (!$dbman->field_exists($log_table, $field3)) {
            $dbman->add_field($log_table, $field3);
            $msgs[] = 'manual_dispatch_id adicionado a notifcourse_log';
        }

        // Migration 4: Add is_simulation to notifcourse_log if missing.
        $field4 = new xmldb_field('is_simulation', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'last_error');
        if (!$dbman->field_exists($log_table, $field4)) {
            $dbman->add_field($log_table, $field4);
            $msgs[] = 'is_simulation adicionado a notifcourse_log';
        }

        // Migration 5: Add attempts to notifcourse_log if missing.
        $field5 = new xmldb_field('attempts', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '1', 'status');
        if (!$dbman->field_exists($log_table, $field5)) {
            $dbman->add_field($log_table, $field5);
            $msgs[] = 'attempts adicionado a notifcourse_log';
        }

        // Migration 6: Add next_retry_at to notifcourse_log if missing.
        $field6 = new xmldb_field('next_retry_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'attempts');
        if (!$dbman->field_exists($log_table, $field6)) {
            $dbman->add_field($log_table, $field6);
            $msgs[] = 'next_retry_at adicionado a notifcourse_log';
        }

        // Migration 7: Add last_error to notifcourse_log if missing.
        $field7 = new xmldb_field('last_error', XMLDB_TYPE_TEXT, null, null, null, null, null, 'next_retry_at');
        if (!$dbman->field_exists($log_table, $field7)) {
            $dbman->add_field($log_table, $field7);
            $msgs[] = 'last_error adicionado a notifcourse_log';
        }

        // Migration 8: Add schedule_id to notifcourse_log if missing.
        $field8 = new xmldb_field('schedule_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'courseid');
        if (!$dbman->field_exists($log_table, $field8)) {
            $dbman->add_field($log_table, $field8);
            $msgs[] = 'schedule_id adicionado a notifcourse_log';
        }

        // Migration 9: Add origin to notifcourse_log if missing.
        $field9 = new xmldb_field('origin', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'auto', 'notification_type');
        if (!$dbman->field_exists($log_table, $field9)) {
            $dbman->add_field($log_table, $field9);
            $msgs[] = 'origin adicionado a notifcourse_log';
        }
    }

    // Migration 10: Rename zoom_link to link_aula in notifcourse_schedule.
    if ($dbman->table_exists($table)) {
        $old_field = new xmldb_field('zoom_link', XMLDB_TYPE_TEXT, null, null, null, null, null, 'send_at');
        $new_field = new xmldb_field('link_aula', XMLDB_TYPE_TEXT, null, null, null, null, null, 'send_at');
        if ($dbman->field_exists($table, $old_field) && !$dbman->field_exists($table, $new_field)) {
            $dbman->rename_field($table, $old_field, 'link_aula');
            $msgs[] = 'zoom_link renomeado para link_aula em notifcourse_schedule';
        }
    }

    // =========================================================================
    // Migrations 11-21: Garantir índices para instalações legadas.
    // =========================================================================

    // --- notifcourse_schedule ---
    if ($dbman->table_exists($table)) {
        $idx_pairs = [
            ['idx_schedule_courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']],
            ['idx_schedule_send_at',  XMLDB_INDEX_NOTUNIQUE, ['send_at']],
            ['idx_schedule_status',   XMLDB_INDEX_NOTUNIQUE, ['status']],
            ['idx_schedule_type',     XMLDB_INDEX_NOTUNIQUE, ['notification_type']],
        ];
        foreach ($idx_pairs as [$name, $type, $cols]) {
            $idx = new xmldb_index($name, $type, $cols);
            if (!$dbman->index_exists($table, $idx)) {
                $dbman->add_index($table, $idx);
                $msgs[] = "{$name} adicionado a notifcourse_schedule";
            }
        }
    }

    // --- notifcourse_log ---
    if ($dbman->table_exists($log_table)) {
        $log_idx_pairs = [
            ['idx_log_courseid_timesent',    XMLDB_INDEX_NOTUNIQUE, ['courseid', 'timesent']],
            ['idx_log_schedule_id_origin',   XMLDB_INDEX_NOTUNIQUE, ['schedule_id', 'origin']],
            ['idx_log_status_next_retry_at', XMLDB_INDEX_NOTUNIQUE, ['status', 'next_retry_at']],
            ['idx_log_userid',               XMLDB_INDEX_NOTUNIQUE, ['userid']],
        ];
        foreach ($log_idx_pairs as [$name, $type, $cols]) {
            $idx = new xmldb_index($name, $type, $cols);
            if (!$dbman->index_exists($log_table, $idx)) {
                $dbman->add_index($log_table, $idx);
                $msgs[] = "{$name} adicionado a notifcourse_log";
            }
        }

        // UNIQUE dedupe_key — limpar duplicatas antes de criar.
        $uq_dedupe = new xmldb_index('uq_log_dedupe_key', XMLDB_INDEX_UNIQUE, ['dedupe_key']);
        if (!$dbman->index_exists($log_table, $uq_dedupe)) {
            // Remover duplicatas mantendo o registro mais recente (maior id).
            global $DB;
            $dupes_sql = "SELECT dedupe_key FROM {notifcourse_log}
                          GROUP BY dedupe_key HAVING COUNT(*) > 1";
            $dupes = $DB->get_records_sql($dupes_sql);
            foreach ($dupes as $dupe) {
                $max_id = $DB->get_field_sql(
                    "SELECT MAX(id) FROM {notifcourse_log} WHERE dedupe_key = :dk",
                    ['dk' => $dupe->dedupe_key]
                );
                $DB->delete_records_select('notifcourse_log',
                    "dedupe_key = :dk AND id < :maxid",
                    ['dk' => $dupe->dedupe_key, 'maxid' => $max_id]
                );
            }
            $dbman->add_index($log_table, $uq_dedupe);
            $msgs[] = 'uq_log_dedupe_key adicionado a notifcourse_log';
        }
    }

    // --- notifcourse_config ---
    $config_table = new xmldb_table('notifcourse_config');
    if ($dbman->table_exists($config_table)) {
        $uq_config = new xmldb_index('uq_config_key', XMLDB_INDEX_UNIQUE, ['config_key']);
        if (!$dbman->index_exists($config_table, $uq_config)) {
            $dbman->add_index($config_table, $uq_config);
            $msgs[] = 'uq_config_key adicionado a notifcourse_config';
        }
    }

    // --- notifcourse_categories ---
    $cat_table = new xmldb_table('notifcourse_categories');
    if ($dbman->table_exists($cat_table)) {
        $uq_cat = new xmldb_index('uq_categories_categoryid', XMLDB_INDEX_UNIQUE, ['categoryid']);
        if (!$dbman->index_exists($cat_table, $uq_cat)) {
            $dbman->add_index($cat_table, $uq_cat);
            $msgs[] = 'uq_categories_categoryid adicionado a notifcourse_categories';
        }
    }

    return empty($msgs) ? 'Nenhuma migration necessaria.' : implode('; ', $msgs);
}

function build_categories_table(): xmldb_table {
    $table = new xmldb_table('notifcourse_categories');
    $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
    $table->add_field('categoryid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
    $table->add_field('active',      XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '1');
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('uq_categories_categoryid', XMLDB_INDEX_UNIQUE, ['categoryid']);
    return $table;
}
