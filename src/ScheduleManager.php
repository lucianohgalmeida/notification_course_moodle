<?php
// This file is part of the TechEduConnect notification plugin.

defined('MOODLE_INTERNAL') || die();

/**
 * Classe responsável pelo gerenciamento de agendamentos de notificações de aulas.
 *
 * @package    notification_course
 */
class ScheduleManager {

    /** Nome da tabela de agendamentos */
    const TABLE = 'notifcourse_schedule';

    /** Tempo mínimo de cooldown entre envios manuais (segundos) */
    const MANUAL_COOLDOWN_SECONDS = 600; // 10 minutos

    // -------------------------------------------------------------------------
    // Criação e edição
    // -------------------------------------------------------------------------

    /**
     * Cria um novo agendamento de notificação de aula.
     *
     * O campo send_at é calculado automaticamente como lesson_date - (hours_before * 3600).
     * Ao criar um agendamento de aula (lesson), também cria automaticamente os
     * agendamentos de início e fim do curso, se ainda não existirem.
     *
     * @param int    $courseid     ID do curso
     * @param int    $lesson_date  Timestamp da data/hora da aula
     * @param int    $hours_before Horas de antecedência para o envio
     * @param string $link_aula    Link de acesso à aula (qualquer URL)
     * @param string $subject      Assunto do e-mail
     * @param string $body         Corpo do e-mail (aceita placeholders)
     * @return int ID do agendamento criado
     */
    public static function create(
        int $courseid,
        int $lesson_date,
        int $hours_before,
        string $link_aula,
        string $subject,
        string $body
    ): int {
        global $DB, $USER;

        $record = new stdClass();
        $record->courseid          = $courseid;
        $record->lesson_date       = $lesson_date;
        $record->send_at           = $lesson_date - ($hours_before * HOURSECS);
        $record->link_aula         = $link_aula;
        $record->subject           = $subject;
        $record->body              = $body;
        $record->notification_type = 'lesson';
        $record->status            = 'pending';
        $record->timecreated       = time();
        $record->createdby         = $USER->id;

        $id = $DB->insert_record(self::TABLE, $record);

        // Auto-criar agendamentos de início e fim do curso (com mesmo link_aula).
        self::ensure_start_end_schedules($courseid, $link_aula);

        return $id;
    }

    /**
     * Cria automaticamente agendamentos de início e fim de curso, se não existirem.
     *
     * Usa as datas do curso (startdate/enddate) e os templates configurados
     * em notifcourse_config (start_subject, start_body, end_subject, end_body).
     *
     * @param int $courseid ID do curso
     * @return void
     */
    public static function ensure_start_end_schedules(int $courseid, string $link_aula = ''): void {
        global $DB, $USER;

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return;
        }

        $now = time();

        // --- Início do Curso ---
        if (!empty($course->startdate) && $course->startdate > 0) {
            $exists_start = $DB->record_exists(self::TABLE, [
                'courseid' => $courseid,
                'notification_type' => 'start',
            ]);

            if (!$exists_start) {
                $hours_before = (int) self::get_config('start_hours_before', 24);
                $subject = self::get_config('start_subject', 'Seu curso {nome_curso} começa em breve!');
                $body = self::get_config('start_body',
                    "Olá, {nome_aluno}!\n\nO curso {nome_curso} tem início em {data_inicio}.\n\n" .
                    "Acesse o Moodle para se preparar: {login_moodle}\n\nBons estudos!"
                );

                $record = new stdClass();
                $record->courseid          = $courseid;
                $record->lesson_date       = (int) $course->startdate;
                $record->send_at           = (int) $course->startdate - ($hours_before * HOURSECS);
                $record->link_aula         = $link_aula;
                $record->subject           = $subject;
                $record->body              = $body;
                $record->notification_type = 'start';
                $record->status            = 'pending';
                $record->timecreated       = $now;
                $record->createdby         = $USER->id;

                $DB->insert_record(self::TABLE, $record);
            }
        }

        // --- Fim do Curso ---
        if (!empty($course->enddate) && $course->enddate > 0) {
            $exists_end = $DB->record_exists(self::TABLE, [
                'courseid' => $courseid,
                'notification_type' => 'end',
            ]);

            if (!$exists_end) {
                $hours_after = (int) self::get_config('end_hours_after', 24);
                $survey_url = self::get_config('end_survey_url', '');
                $subject = self::get_config('end_subject', 'O curso {nome_curso} foi encerrado');
                $body = self::get_config('end_body',
                    "Olá, {nome_aluno}!\n\nO curso {nome_curso} foi encerrado em {data_termino}.\n\n" .
                    "Link do curso: {link_aula}\n\n" .
                    ($survey_url ? "Por favor, responda nossa pesquisa de satisfação: {link_pesquisa}\n\n" : '') .
                    "Obrigado por participar!"
                );

                $record = new stdClass();
                $record->courseid          = $courseid;
                $record->lesson_date       = (int) $course->enddate;
                $record->send_at           = (int) $course->enddate + ($hours_after * HOURSECS);
                $record->link_aula         = $link_aula;
                $record->subject           = $subject;
                $record->body              = $body;
                $record->notification_type = 'end';
                $record->status            = 'pending';
                $record->timecreated       = $now;
                $record->createdby         = $USER->id;

                $DB->insert_record(self::TABLE, $record);
            }
        }
    }

    /**
     * Lê um valor da tabela de configuração.
     *
     * @param string $key     Chave da configuração
     * @param mixed  $default Valor padrão
     * @return string
     */
    private static function get_config(string $key, $default = ''): string {
        global $DB;

        $value = $DB->get_field('notifcourse_config', 'config_value', ['config_key' => $key]);
        return ($value !== false && $value !== null && $value !== '') ? (string) $value : (string) $default;
    }

    /**
     * Atualiza os dados de um agendamento.
     *
     * Somente é permitido atualizar agendamentos com status='pending'.
     * Se lesson_date ou hours_before forem fornecidos, send_at é recalculado.
     *
     * @param int   $id   ID do agendamento
     * @param array $data Campos a atualizar
     * @return bool True se atualizado com sucesso, false se não estava pendente
     */
    public static function update(int $id, array $data): bool {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);

        if ($record->status !== 'pending') {
            return false;
        }

        $allowed_fields = ['lesson_date', 'link_aula', 'subject', 'body', 'survey_url'];

        foreach ($allowed_fields as $field) {
            if (array_key_exists($field, $data)) {
                $record->$field = $data[$field];
            }
        }

        // Recalcula send_at se lesson_date ou hours_before (via send_at) mudaram.
        if (array_key_exists('hours_before', $data)) {
            $record->send_at = (int)$record->lesson_date - ((int)$data['hours_before'] * HOURSECS);
        } elseif (array_key_exists('lesson_date', $data)) {
            // Mantém a mesma antecedência original.
            $original_advance = $record->lesson_date - $record->send_at;
            $record->send_at = (int)$data['lesson_date'] - $original_advance;
        }

        $DB->update_record(self::TABLE, $record);

        return true;
    }

    /**
     * Cancela um agendamento pendente.
     *
     * @param int $id ID do agendamento
     * @return bool True se cancelado com sucesso, false se não estava pendente
     */
    public static function cancel(int $id): bool {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);

        if ($record->status !== 'pending') {
            return false;
        }

        $record->status = 'cancelled';

        $DB->update_record(self::TABLE, $record);

        return true;
    }

    /**
     * Exclui um agendamento permanentemente.
     *
     * @param int $id ID do agendamento
     * @return bool True se excluído com sucesso
     */
    public static function delete(int $id): bool {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        if (!$record) {
            return false;
        }

        $DB->delete_records(self::TABLE, ['id' => $id]);

        return true;
    }

    // -------------------------------------------------------------------------
    // Criação recorrente
    // -------------------------------------------------------------------------

    /**
     * Cria múltiplos agendamentos de aula com base em recorrência.
     *
     * @param int    $courseid     ID do curso
     * @param array  $weekdays     Dias da semana (1=Seg..7=Dom) — ISO 8601
     * @param string $time         Horário da aula (H:i, ex: "19:00")
     * @param int    $hours_before Horas de antecedência para envio
     * @param string $link_aula    Link de acesso à aula
     * @param string $subject      Assunto do e-mail
     * @param string $body         Corpo do e-mail
     * @param int    $start_from   Timestamp inicial (default: startdate do curso)
     * @param int    $end_at       Timestamp final (default: enddate do curso)
     * @return int Número de agendamentos criados
     */
    public static function create_recurring(
        int $courseid,
        array $weekdays,
        string $time,
        int $hours_before,
        string $link_aula,
        string $subject,
        string $body,
        int $start_from = 0,
        int $end_at = 0
    ): int {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return 0;
        }

        // Usar datas do curso como fallback.
        if ($start_from <= 0) {
            $start_from = (int) $course->startdate;
        }
        if ($end_at <= 0) {
            $end_at = (int) $course->enddate;
        }
        if ($start_from <= 0 || $end_at <= 0 || $end_at <= $start_from) {
            return 0;
        }

        // Validar dias da semana (1-7).
        $weekdays = array_filter($weekdays, function ($d) {
            return $d >= 1 && $d <= 7;
        });
        if (empty($weekdays)) {
            return 0;
        }

        // Parsear horário.
        $time_parts = explode(':', $time);
        if (count($time_parts) < 2) {
            return 0;
        }
        $hour   = (int) $time_parts[0];
        $minute = (int) $time_parts[1];

        // Timezone do Moodle.
        $tz_name = self::get_config('display_timezone', 'America/Sao_Paulo');
        $tz = new \DateTimeZone($tz_name);

        // Iterar dia a dia do período.
        $current = new \DateTime('@' . $start_from);
        $current->setTimezone($tz);
        $current->setTime(0, 0, 0);

        $end_dt = new \DateTime('@' . $end_at);
        $end_dt->setTimezone($tz);
        $end_dt->setTime(23, 59, 59);

        $created = 0;

        while ($current <= $end_dt) {
            $dow = (int) $current->format('N'); // 1=Seg, 7=Dom

            if (in_array($dow, $weekdays, true)) {
                $lesson_dt = clone $current;
                $lesson_dt->setTime($hour, $minute, 0);
                $lesson_ts = $lesson_dt->getTimestamp();

                // Só criar se a data da aula ainda não passou e não existe duplicata.
                if ($lesson_ts > time()) {
                    $exists = $DB->record_exists(self::TABLE, [
                        'courseid'          => $courseid,
                        'lesson_date'       => $lesson_ts,
                        'notification_type' => 'lesson',
                    ]);
                    if (!$exists) {
                        self::create(
                            $courseid,
                            $lesson_ts,
                            $hours_before,
                            $link_aula,
                            $subject,
                            $body
                        );
                        $created++;
                    }
                }
            }

            $current->modify('+1 day');
        }

        return $created;
    }

    // -------------------------------------------------------------------------
    // Consultas individuais
    // -------------------------------------------------------------------------

    /**
     * Retorna um agendamento pelo ID.
     *
     * @param int $id ID do agendamento
     * @return object|null Objeto do agendamento ou null se não encontrado
     */
    public static function get(int $id): ?object {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['id' => $id]);

        return $record ?: null;
    }

    /**
     * Retorna todos os agendamentos de um curso, ordenados por data da aula.
     *
     * @param int $courseid ID do curso
     * @return array Lista de objetos de agendamento
     */
    public static function get_by_course(int $courseid): array {
        global $DB;

        return array_values($DB->get_records(
            self::TABLE,
            ['courseid' => $courseid],
            'lesson_date ASC'
        ));
    }

    // -------------------------------------------------------------------------
    // Listagem paginada
    // -------------------------------------------------------------------------

    /**
     * Retorna a lista paginada de agendamentos com informações do curso.
     *
     * Filtros disponíveis: courseid, status.
     *
     * @param array $filters  Filtros de busca
     * @param int   $page     Número da página (base 0)
     * @param int   $perpage  Registros por página
     * @return array ['records' => array, 'total' => int]
     */
    public static function get_all(array $filters = [], int $page = 0, int $perpage = 20): array {
        global $DB;

        [$where, $params] = self::build_filters($filters);

        $sql_base = "FROM {" . self::TABLE . "} s
                     JOIN {course} c ON c.id = s.courseid
                    WHERE {$where}";

        $total = $DB->count_records_sql("SELECT COUNT(s.id) {$sql_base}", $params);

        $sql = "SELECT s.*,
                       c.fullname AS course_fullname
                {$sql_base}
             ORDER BY s.courseid ASC, s.lesson_date ASC";

        $records = array_values($DB->get_records_sql($sql, $params, $page * $perpage, $perpage));

        return [
            'records' => $records,
            'total'   => (int)$total,
        ];
    }

    // -------------------------------------------------------------------------
    // Envio automático
    // -------------------------------------------------------------------------

    /**
     * Retorna os agendamentos pendentes cujo send_at já passou.
     *
     * @return array Lista de objetos de agendamento
     */
    public static function get_pending_for_send(): array {
        global $DB;

        $sql = "SELECT *
                  FROM {" . self::TABLE . "}
                 WHERE status = 'pending'
                   AND send_at <= :now
              ORDER BY send_at ASC";

        return array_values($DB->get_records_sql($sql, ['now' => time()]));
    }

    /**
     * Marca um agendamento como enviado.
     *
     * @param int $id ID do agendamento
     * @return void
     */
    public static function mark_sent(int $id): void {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);

        $record->status = 'sent';

        $DB->update_record(self::TABLE, $record);
    }

    // -------------------------------------------------------------------------
    // Próximos agendamentos
    // -------------------------------------------------------------------------

    /**
     * Retorna os N próximos agendamentos pendentes com informações do curso.
     *
     * @param int $limit Número de registros a retornar
     * @return array Lista de objetos
     */
    public static function get_upcoming(int $limit = 5): array {
        global $DB;

        $sql = "SELECT s.*,
                       c.fullname AS course_fullname
                  FROM {" . self::TABLE . "} s
                  JOIN {course} c ON c.id = s.courseid
                 WHERE s.status = 'pending'
                   AND s.send_at > :now
              ORDER BY s.send_at ASC";

        return array_values($DB->get_records_sql($sql, ['now' => time()], 0, $limit));
    }

    // -------------------------------------------------------------------------
    // Sugestão de link da aula
    // -------------------------------------------------------------------------

    /**
     * Sugere um link de acesso para um novo agendamento.
     *
     * Primeiro tenta o link_aula do último agendamento do curso.
     * Se não encontrar, usa CourseChecker::get_link_aula_for_course().
     *
     * @param int $courseid ID do curso
     * @return string Link sugerido ou string vazia
     */
    public static function get_link_suggestion(int $courseid): string {
        global $CFG, $DB;

        // 1. Último agendamento do curso com link preenchido.
        $sql = "SELECT link_aula
                  FROM {" . self::TABLE . "}
                 WHERE courseid = :courseid
                   AND link_aula <> ''
              ORDER BY lesson_date DESC";

        $value = $DB->get_field_sql($sql, ['courseid' => $courseid]);

        if ($value) {
            return (string)$value;
        }

        // 2. Campo customizado 'link_zoom' do curso.
        $custom = CourseChecker::get_link_aula_for_course($courseid);
        if ($custom !== '') {
            return $custom;
        }

        // 3. Fallback: URL do curso no Moodle.
        return $CFG->wwwroot . '/course/view.php?id=' . $courseid;
    }

    // -------------------------------------------------------------------------
    // Cooldown de envio manual
    // -------------------------------------------------------------------------

    /**
     * Verifica se é possível enviar um reforço manual para um agendamento.
     *
     * Retorna true se o último envio manual foi há mais de 10 minutos
     * ou se nunca houve envio manual.
     *
     * @param int $schedule_id ID do agendamento
     * @return bool True se o envio é permitido
     */
    public static function can_send_reinforcement(int $schedule_id): bool {
        $last_send = NotifLog::get_last_manual_send($schedule_id);

        if ($last_send === null) {
            return true;
        }

        return (time() - $last_send) > self::MANUAL_COOLDOWN_SECONDS;
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    /**
     * Constrói a cláusula WHERE e parâmetros a partir de um array de filtros.
     *
     * @param array $filters Filtros: courseid, status
     * @return array [string $where, array $params]
     */
    /** Status válidos para filtro */
    const VALID_STATUSES = ['pending', 'sent', 'cancelled'];

    private static function build_filters(array $filters): array {
        $conditions = ['1=1'];
        $params     = [];

        if (!empty($filters['courseid'])) {
            $conditions[] = 's.courseid = :courseid';
            $params['courseid'] = (int)$filters['courseid'];
        }

        if (!empty($filters['status']) && in_array($filters['status'], self::VALID_STATUSES, true)) {
            $conditions[] = 's.status = :status';
            $params['status'] = $filters['status'];
        }

        return [implode(' AND ', $conditions), $params];
    }
}
