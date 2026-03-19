<?php
/**
 * Ponto de entrada do CRON — Notificações Automáticas de Cursos.
 *
 * Uso:
 *   php cron.php            # Execução normal
 *   php cron.php --dry-run  # Simulação sem envio real
 *
 * Segurança: aceita apenas execução via CLI.
 *
 * Arquitetura:
 *   Camada 1 — Garante existência de agendas start/end para cursos próximos.
 *   Camada 2 — Processa agendas pendentes (start, lesson, end) conforme send_at.
 *   Camada 3 — Garante agendas para cursos concluídos + retries de falhas.
 */

// Proteção de acesso — apenas CLI.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'Acesso negado. Execute via CLI.';
    exit(1);
}

// Lock exclusivo para evitar execuções sobrepostas.
$lockfile = __DIR__ . '/.cron.lock';
$lockfp = fopen($lockfile, 'c');
if (!$lockfp || !flock($lockfp, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s') . " [WARN] Outra instância do CRON já está em execução. Abortando.\n";
    exit(0);
}

// Detectar modo dry-run.
$dry_run = in_array('--dry-run', $argv ?? [], true);

// Bootstrap do Moodle (sem verificação de login/capability — é CLI).
define('CLI_SCRIPT', true);
define('NOTIFCOURSE_INTERNAL', true);

$moodle_config = __DIR__ . '/../config.php';
if (!file_exists($moodle_config)) {
    flock($lockfp, LOCK_UN);
    fclose($lockfp);
    echo "ERRO: config.php do Moodle não encontrado em: {$moodle_config}\n";
    exit(1);
}
require_once($moodle_config);

// Autoload das classes.
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/src/' . $class . '.php';
    if (file_exists($file)) {
        require_once($file);
    }
});

global $DB;

// Labels reutilizáveis para tipos de notificação.
$type_labels = [
    'start'  => 'Início',
    'lesson' => 'Aula',
    'end'    => 'Fim',
];

// Tipos válidos para filtros.
$valid_types = ['start', 'lesson', 'end'];

$mode = $dry_run ? '[DRY-RUN]' : '[PROD]';
$start_time = microtime(true);
echo date('Y-m-d H:i:s') . " {$mode} Iniciando ciclo CRON...\n";

// Ler configurações com validação.
$batch_size  = max(1, (int) cron_get_config('batch_size', 50));
$start_hours = max(1, (int) cron_get_config('start_hours_before', 24));
$end_hours   = max(1, (int) cron_get_config('end_hours_after', 24));

$total_sent = 0;
$total_failed = 0;

// try-finally garante que o lock é sempre liberado, mesmo em caso de erro.
try {

    // =========================================================================
    // CAMADA 1 — Garantir agendas de Início/Fim para cursos próximos
    // =========================================================================
    echo "\n--- Camada 1: Verificar agendas de Início de Curso ---\n";

    try {
        $courses = CourseChecker::get_courses_starting($start_hours);
        echo "Cursos com início próximo: " . count($courses) . "\n";

        foreach ($courses as $course) {
            ScheduleManager::ensure_start_end_schedules($course->id);
            echo "  Curso [{$course->id}] {$course->fullname}: agendas verificadas.\n";
        }
    } catch (Exception $e) {
        echo "ERRO Camada 1: " . $e->getMessage() . "\n";
    }

    echo "Camada 1 finalizada.\n";

    // =========================================================================
    // CAMADA 2 — Agendas Pendentes (único ponto de envio)
    // =========================================================================
    echo "\n--- Camada 2: Agendas Pendentes ---\n";
    $sent_layer2 = 0;

    try {
        $schedules = ScheduleManager::get_pending_for_send();
        echo "Agendas pendentes: " . count($schedules) . "\n";

        foreach ($schedules as $schedule) {
            if ($sent_layer2 >= $batch_size) {
                echo "Batch limit atingido para Camada 2.\n";
                break;
            }

            $course = $DB->get_record('course', ['id' => $schedule->courseid]);
            if (!$course) {
                echo "  Agenda [{$schedule->id}]: curso não encontrado.\n";
                continue;
            }

            $sched_type = isset($schedule->notification_type) ? $schedule->notification_type : 'lesson';
            if (!in_array($sched_type, $valid_types, true)) {
                echo "  Agenda [{$schedule->id}]: tipo desconhecido '{$sched_type}'.\n";
                continue;
            }

            // Enviar apenas para alunos com inscrição ativa no curso.
            // Critério: situação da inscrição (Ativo/Suspenso), NÃO último acesso.
            // Alunos com "Nunca" acessou mas inscrição ativa recebem normalmente.
            // Alunos com inscrição suspensa (inativa) são excluídos.
            $students = CourseChecker::get_active_students($schedule->courseid);
            $label = isset($type_labels[$sched_type]) ? $type_labels[$sched_type] : $sched_type;
            echo "  Agenda [{$schedule->id}] [{$label}] {$course->fullname}: " . count($students) . " alunos\n";

            $all_sent = true;
            foreach ($students as $student) {
                if ($sent_layer2 >= $batch_size) {
                    $all_sent = false;
                    break;
                }

                // Todos os tipos usam schedule_id na dedupe — permite recriar agendas.
                $dedupe_id = (int) $schedule->id;
                $dedupe_key = NotifLog::build_dedupe_key('auto', $sched_type, $dedupe_id, (int) $student->id);
                if (NotifLog::is_already_sent($dedupe_key)) {
                    continue;
                }

                $result = Mailer::send_notification($student, $course, $schedule, $sched_type, 'auto', $dry_run);
                if ($result['success']) {
                    $sent_layer2++;
                    $total_sent++;
                } else {
                    $all_sent = false;
                    $total_failed++;
                    echo "    [FALHA] {$student->email}: {$result['error']}\n";
                }
            }

            if ($all_sent && !$dry_run) {
                ScheduleManager::mark_sent($schedule->id);
                echo "  Agenda [{$schedule->id}] marcada como enviada.\n";
            }
        }

    } catch (Exception $e) {
        echo "ERRO Camada 2: " . $e->getMessage() . "\n";
    }

    echo "Camada 2 finalizada: {$sent_layer2} enviados.\n";

    // =========================================================================
    // CAMADA 3 — Garantir agendas de Fim para cursos concluídos
    // =========================================================================
    echo "\n--- Camada 3: Verificar agendas de Fim de Curso ---\n";

    try {
        $courses = CourseChecker::get_courses_ending($end_hours);
        echo "Cursos com término recente: " . count($courses) . "\n";

        foreach ($courses as $course) {
            ScheduleManager::ensure_start_end_schedules($course->id);
            echo "  Curso [{$course->id}] {$course->fullname}: agendas verificadas.\n";
        }
    } catch (Exception $e) {
        echo "ERRO Camada 3: " . $e->getMessage() . "\n";
    }

    echo "Camada 3 finalizada.\n";

    // =========================================================================
    // CAMADA 4 — Retries unificados (todos os tipos: start, lesson, end)
    // =========================================================================
    echo "\n--- Camada 4: Retries de envios falhos ---\n";
    $sent_retries = 0;

    try {
        foreach (['start', 'lesson', 'end'] as $retry_type) {
            $retry_budget = max(0, $batch_size - $sent_retries);
            if ($retry_budget <= 0) {
                echo "  Batch limit de retries atingido.\n";
                break;
            }

            $retries = NotifLog::get_failed_for_retry($retry_type, $retry_budget);
            if (!empty($retries)) {
                echo "  Retries ({$retry_type}): " . count($retries) . "\n";
                foreach ($retries as $retry) {
                    retry_send($retry, $dry_run, $sent_retries, $total_sent, $total_failed);
                }
            }
        }
    } catch (Exception $e) {
        echo "ERRO Camada 4: " . $e->getMessage() . "\n";
    }

    echo "Camada 4 finalizada: {$sent_retries} retries.\n";

    // =========================================================================
    // Resumo
    // =========================================================================
    $elapsed = round(microtime(true) - $start_time, 2);
    echo "\n=== Resumo do ciclo {$mode} ===\n";
    echo "Total enviados: {$total_sent}\n";
    echo "Total falhas: {$total_failed}\n";
    echo "Tempo: {$elapsed}s\n";
    echo date('Y-m-d H:i:s') . " Ciclo finalizado.\n";

} finally {
    // Sempre liberar o lock, mesmo se ocorrer exceção.
    flock($lockfp, LOCK_UN);
    fclose($lockfp);
}

// =============================================================================
// Helpers
// =============================================================================

/**
 * Retenta o envio de uma notificação que falhou.
 *
 * Envia diretamente via Mailer::send() e atualiza o registro existente no log
 * (evitando conflito de UNIQUE dedupe_key).
 *
 * @param object $retry        Registro de log com falha
 * @param bool   $dry_run      Modo simulação
 * @param int    &$sent        Contador por camada (passado por referência)
 * @param int    &$total_sent  Contador global de enviados
 * @param int    &$total_failed Contador global de falhas
 */
function retry_send(object $retry, bool $dry_run, int &$sent, int &$total_sent, int &$total_failed): void {
    global $DB;

    // Defense-in-depth: verificar next_retry_at mesmo que o SQL já filtre.
    if (!empty($retry->next_retry_at) && (int) $retry->next_retry_at > time()) {
        return;
    }

    $user = $DB->get_record('user', ['id' => $retry->userid]);
    $course = $DB->get_record('course', ['id' => $retry->courseid]);
    if (!$user || !$course) {
        return;
    }

    $schedule = !empty($retry->schedule_id) ? ScheduleManager::get((int) $retry->schedule_id) : null;

    // Montar variáveis e renderizar template.
    $variables = TemplateEngine::get_common_variables($user, $course);
    $subject = '';
    $body = '';

    switch ($retry->notification_type) {
        case 'start':
            $variables = array_merge($variables, TemplateEngine::get_start_variables($course));
            if ($schedule !== null && !empty($schedule->link_aula)) {
                $variables['link_aula'] = $schedule->link_aula;
                $variables['link_zoom'] = $schedule->link_aula; // backward compat
            }
            if ($schedule !== null && !empty($schedule->subject) && !empty($schedule->body)) {
                $subject = $schedule->subject;
                $body = $schedule->body;
            } else {
                $subject = $DB->get_field('notifcourse_config', 'config_value', ['config_key' => 'start_subject']);
                $body = $DB->get_field('notifcourse_config', 'config_value', ['config_key' => 'start_body']);
                $subject = ($subject !== false) ? (string) $subject : '';
                $body = ($body !== false) ? (string) $body : '';
            }
            break;

        case 'end':
            $survey_url = ($schedule !== null && !empty($schedule->survey_url))
                ? $schedule->survey_url
                : '';
            if ($survey_url === '') {
                $val = $DB->get_field('notifcourse_config', 'config_value', ['config_key' => 'end_survey_url']);
                $survey_url = ($val !== false) ? (string) $val : '';
            }
            $variables = array_merge($variables, TemplateEngine::get_end_variables($course, $survey_url));
            if ($schedule !== null && !empty($schedule->link_aula)) {
                $variables['link_aula'] = $schedule->link_aula;
                $variables['link_zoom'] = $schedule->link_aula; // backward compat
            }
            if ($schedule !== null && !empty($schedule->subject) && !empty($schedule->body)) {
                $subject = $schedule->subject;
                $body = $schedule->body;
            } else {
                $subject = $DB->get_field('notifcourse_config', 'config_value', ['config_key' => 'end_subject']);
                $body = $DB->get_field('notifcourse_config', 'config_value', ['config_key' => 'end_body']);
                $subject = ($subject !== false) ? (string) $subject : '';
                $body = ($body !== false) ? (string) $body : '';
            }
            break;

        case 'lesson':
            if ($schedule !== null) {
                $variables = array_merge($variables, TemplateEngine::get_lesson_variables($schedule));
                $subject = isset($schedule->subject) ? $schedule->subject : '';
                $body = isset($schedule->body) ? $schedule->body : '';
            } else {
                return;
            }
            break;

        default:
            return;
    }

    $rendered_subject = TemplateEngine::render($subject, $variables);
    $rendered_body = TemplateEngine::render($body, $variables);

    // Enviar diretamente (sem passar por send_notification que faria novo INSERT).
    $success = Mailer::send($user, $rendered_subject, $rendered_body, $dry_run);

    if ($dry_run) {
        // Dry-run: não alterar estado real do log — apenas logar no console.
        echo "    [RETRY DRY-RUN] {$user->email}\n";
        return;
    }

    if ($success) {
        NotifLog::mark_success((int) $retry->id);
        $sent++;
        $total_sent++;
        echo "    [RETRY OK] {$user->email}\n";
    } else {
        NotifLog::mark_retry((int) $retry->id, 'email_to_user() retornou false no retry.');
        $total_failed++;
        echo "    [RETRY FALHA] {$user->email}\n";
    }
}

/**
 * Lê um valor da tabela de configuração.
 *
 * @param string $key     Chave da configuração
 * @param mixed  $default Valor padrão
 * @return mixed
 */
function cron_get_config(string $key, $default = null) {
    global $DB;
    $value = $DB->get_field('notifcourse_config', 'config_value', ['config_key' => $key]);
    if ($value !== false && $value !== null && $value !== '') {
        return $value;
    }
    return $default;
}
