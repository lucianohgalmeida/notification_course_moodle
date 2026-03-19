<?php
// This file is part of the TechEduConnect notification plugin.

defined('MOODLE_INTERNAL') || die();

/**
 * Classe responsável pelo registro e consulta de logs de notificações de cursos.
 *
 * @package    notification_course
 */
class NotifLog {

    /** Nome da tabela de log */
    const TABLE = 'notifcourse_log';

    /** Nome da tabela de configuração */
    const CONFIG_TABLE = 'notifcourse_config';

    /** Número padrão de tentativas máximas */
    const DEFAULT_MAX_ATTEMPTS = 3;

    // -------------------------------------------------------------------------
    // Escrita / inserção
    // -------------------------------------------------------------------------

    /**
     * Registra o envio de uma notificação no log.
     *
     * @param int         $userid             ID do usuário destinatário
     * @param int         $courseid           ID do curso
     * @param int|null    $schedule_id        ID do agendamento (pode ser null para início/fim)
     * @param string      $type               Tipo: 'start', 'lesson', 'end'
     * @param string      $origin             Origem: 'auto', 'manual'
     * @param string      $status             Status: 'success', 'failed', 'abandoned'
     * @param string      $dedupe_key         Chave de deduplicação
     * @param string|null $manual_dispatch_id ID de despacho manual
     * @param string|null $error              Mensagem de erro (se houver)
     * @param bool        $is_simulation      Se true, é um envio simulado (dry run)
     * @return int ID do registro inserido
     */
    public static function log_send(
        int $userid,
        int $courseid,
        ?int $schedule_id,
        string $type,
        string $origin,
        string $status,
        string $dedupe_key,
        ?string $manual_dispatch_id = null,
        ?string $error = null,
        bool $is_simulation = false
    ): int {
        global $DB;

        // Se já existe registro com mesma dedupe_key (ex: falha anterior), atualiza
        // em vez de inserir — evita conflito com UNIQUE(dedupe_key).
        $existing = $DB->get_record(self::TABLE, ['dedupe_key' => $dedupe_key]);

        if ($existing) {
            $existing->status             = $status;
            $existing->last_error         = $error;
            $existing->attempts           = (int) $existing->attempts + 1;
            $existing->timesent           = time();
            $existing->is_simulation      = $is_simulation ? 1 : 0;

            if ($status === 'failed') {
                $hours = pow(2, $existing->attempts - 1);
                $existing->next_retry_at = time() + ($hours * HOURSECS);
            } else {
                $existing->next_retry_at = null;
            }

            $DB->update_record(self::TABLE, $existing);
            return (int) $existing->id;
        }

        $record = new stdClass();
        $record->userid             = $userid;
        $record->courseid           = $courseid;
        $record->schedule_id        = $schedule_id;
        $record->notification_type  = $type;
        $record->origin             = $origin;
        $record->status             = $status;
        $record->dedupe_key         = $dedupe_key;
        $record->manual_dispatch_id = $manual_dispatch_id;
        $record->last_error         = $error;
        $record->is_simulation      = $is_simulation ? 1 : 0;
        $record->attempts           = 1;
        $record->next_retry_at      = null;
        $record->timesent           = time();

        if ($status === 'failed') {
            $record->next_retry_at = time() + HOURSECS;
        }

        return $DB->insert_record(self::TABLE, $record);
    }

    // -------------------------------------------------------------------------
    // Deduplicação
    // -------------------------------------------------------------------------

    /**
     * Verifica se uma notificação com a chave informada já foi enviada com sucesso.
     *
     * @param string $dedupe_key Chave de deduplicação
     * @return bool True se já existe registro com status='success'
     */
    public static function is_already_sent(string $dedupe_key): bool {
        global $DB;

        return $DB->record_exists(self::TABLE, [
            'dedupe_key' => $dedupe_key,
            'status'     => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // Retry (reenvio)
    // -------------------------------------------------------------------------

    /**
     * Retorna registros com falha elegíveis para reenvio.
     *
     * @param string $type       Tipo de notificação
     * @param int    $batch_size Quantidade máxima de registros a retornar
     * @return array Lista de objetos de log
     */
    public static function get_failed_for_retry(string $type, int $batch_size): array {
        global $DB;

        $max_attempts = self::get_max_attempts();
        $now = time();

        $sql = "SELECT *
                  FROM {" . self::TABLE . "}
                 WHERE notification_type = :type
                   AND status = 'failed'
                   AND next_retry_at <= :now
                   AND attempts < :max_attempts
              ORDER BY next_retry_at ASC";

        $params = [
            'type'         => $type,
            'now'          => $now,
            'max_attempts' => $max_attempts,
        ];

        return array_values($DB->get_records_sql($sql, $params, 0, $batch_size));
    }

    /**
     * Marca um registro de log como enviado com sucesso (usado em retries).
     *
     * @param int $log_id ID do registro de log
     * @return void
     */
    public static function mark_success(int $log_id): void {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['id' => $log_id], '*', MUST_EXIST);
        $record->status        = 'success';
        $record->next_retry_at = null;
        $record->last_error    = null;
        $record->attempts      = (int)$record->attempts + 1;
        $record->timesent      = time();

        $DB->update_record(self::TABLE, $record);
    }

    /**
     * Atualiza o log após uma nova tentativa de envio.
     *
     * Incrementa o contador de tentativas, recalcula o próximo retry com backoff
     * progressivo (1h, 2h, 4h) e, se atingir o limite máximo, define status='abandoned'.
     *
     * @param int         $log_id ID do registro de log
     * @param string|null $error  Mensagem de erro da tentativa
     * @return void
     */
    public static function mark_retry(int $log_id, ?string $error = null): void {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['id' => $log_id], '*', MUST_EXIST);

        $record->attempts      = (int)$record->attempts + 1;
        $record->last_error    = $error;
        $max_attempts          = self::get_max_attempts();

        if ($record->attempts >= $max_attempts) {
            $record->status        = 'abandoned';
            $record->next_retry_at = null;
        } else {
            // Backoff progressivo: 1h, 2h, 4h (dobra a cada tentativa a partir da 2ª)
            $hours = pow(2, $record->attempts - 1); // tentativa 1 => 1h, 2 => 2h, 3 => 4h
            $record->next_retry_at = time() + ($hours * HOURSECS);
        }

        $DB->update_record(self::TABLE, $record);
    }

    // -------------------------------------------------------------------------
    // Consultas / histórico
    // -------------------------------------------------------------------------

    /**
     * Retorna o histórico paginado de notificações com informações de usuário e curso.
     *
     * Filtros disponíveis: courseid, notification_type, origin, status, date_from, date_to.
     *
     * @param array $filters  Filtros de busca
     * @param int   $page     Número da página (base 0)
     * @param int   $perpage  Registros por página
     * @return array ['records' => array, 'total' => int]
     */
    public static function get_history(array $filters, int $page, int $perpage): array {
        global $DB;

        [$where, $params] = self::build_filters($filters);

        $sql_base = "FROM {" . self::TABLE . "} l
                     JOIN {user} u ON u.id = l.userid
                     JOIN {course} c ON c.id = l.courseid
                    WHERE {$where}";

        $total = $DB->count_records_sql("SELECT COUNT(l.id) {$sql_base}", $params);

        $sql = "SELECT l.*,
                       u.firstname,
                       u.lastname,
                       u.email,
                       c.fullname AS course_fullname
                {$sql_base}
             ORDER BY l.timesent DESC";

        $records = array_values($DB->get_records_sql($sql, $params, $page * $perpage, $perpage));

        return [
            'records' => $records,
            'total'   => (int)$total,
        ];
    }

    /**
     * Retorna estatísticas resumidas para o painel de controle.
     *
     * @return array ['sent_today' => int, 'sent_week' => int, 'failed' => int, 'abandoned' => int]
     */
    public static function get_dashboard_stats(): array {
        global $DB;

        $now        = time();
        $today_start = mktime(0, 0, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
        $week_start  = $today_start - (6 * DAYSECS);

        $sent_today = $DB->count_records_sql(
            "SELECT COUNT(id) FROM {" . self::TABLE . "}
              WHERE status = 'success' AND timesent >= :today",
            ['today' => $today_start]
        );

        $sent_week = $DB->count_records_sql(
            "SELECT COUNT(id) FROM {" . self::TABLE . "}
              WHERE status = 'success' AND timesent >= :week",
            ['week' => $week_start]
        );

        $failed = $DB->count_records(self::TABLE, ['status' => 'failed']);

        $abandoned = $DB->count_records(self::TABLE, ['status' => 'abandoned']);

        return [
            'sent_today' => (int)$sent_today,
            'sent_week'  => (int)$sent_week,
            'failed'     => (int)$failed,
            'abandoned'  => (int)$abandoned,
        ];
    }

    /**
     * Retorna as N notificações mais recentes com informações de usuário e curso.
     *
     * @param int $limit Número de registros a retornar
     * @return array Lista de objetos
     */
    public static function get_recent(int $limit = 10): array {
        global $DB;

        $sql = "SELECT l.*,
                       u.firstname,
                       u.lastname,
                       u.email,
                       c.fullname AS course_fullname
                  FROM {" . self::TABLE . "} l
                  JOIN {user} u ON u.id = l.userid
                  JOIN {course} c ON c.id = l.courseid
              ORDER BY l.timesent DESC";

        return array_values($DB->get_records_sql($sql, [], 0, $limit));
    }

    /**
     * Retorna o timestamp do último envio manual para um agendamento (para cooldown).
     *
     * @param int $schedule_id ID do agendamento
     * @return int|null Timestamp do último envio manual ou null se não houver
     */
    public static function get_last_manual_send(int $schedule_id): ?int {
        global $DB;

        $sql = "SELECT MAX(timesent)
                  FROM {" . self::TABLE . "}
                 WHERE schedule_id = :schedule_id
                   AND origin = 'manual'
                   AND status = 'success'";

        $result = $DB->get_field_sql($sql, ['schedule_id' => $schedule_id]);

        return $result ? (int)$result : null;
    }

    // -------------------------------------------------------------------------
    // Chave de deduplicação
    // -------------------------------------------------------------------------

    /**
     * Constrói a chave de deduplicação conforme o padrão:
     *   - auto:start:{courseid}:{userid}
     *   - auto:lesson:{scheduleid}:{userid}
     *   - auto:end:{courseid}:{userid}
     *   - manual:lesson:{scheduleid}:{userid}:{dispatch_id}
     *
     * @param string      $origin      'auto' ou 'manual'
     * @param string      $type        'start', 'lesson' ou 'end'
     * @param int         $id1         courseid (para start/end) ou scheduleid (para lesson)
     * @param int         $id2         userid
     * @param string|null $dispatch_id ID de despacho (obrigatório para manual:lesson)
     * @return string
     */
    public static function build_dedupe_key(
        string $origin,
        string $type,
        int $id1,
        int $id2,
        ?string $dispatch_id = null
    ): string {
        $key = "{$origin}:{$type}:{$id1}:{$id2}";

        if ($origin === 'manual' && $dispatch_id !== null) {
            $key .= ":{$dispatch_id}";
        }

        return $key;
    }

    // -------------------------------------------------------------------------
    // Exportação CSV
    // -------------------------------------------------------------------------

    /**
     * Envia os dados de log como arquivo CSV para download.
     *
     * @param array $filters Mesmos filtros aceitos por get_history()
     * @return void
     */
    public static function export_csv(array $filters): void {
        global $DB;

        [$where, $params] = self::build_filters($filters);

        $sql = "SELECT l.*,
                       u.firstname,
                       u.lastname,
                       u.email,
                       c.fullname AS course_fullname
                  FROM {" . self::TABLE . "} l
                  JOIN {user} u ON u.id = l.userid
                  JOIN {course} c ON c.id = l.courseid
                 WHERE {$where}
              ORDER BY l.timesent DESC";

        $filename = 'notificacoes_' . date('Y-m-d_H-i-s') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // BOM para UTF-8 (compatibilidade com Excel)
        fwrite($output, "\xEF\xBB\xBF");

        // Cabeçalho
        fputcsv($output, [
            'ID',
            'Usuário',
            'E-mail',
            'Curso',
            'Tipo',
            'Origem',
            'Status',
            'Simulação',
            'Tentativas',
            'Erro',
            'Chave Dedupe',
            'Data/Hora',
        ], ';');

        $records = $DB->get_recordset_sql($sql, $params);

        foreach ($records as $row) {
            fputcsv($output, [
                $row->id,
                trim($row->firstname . ' ' . $row->lastname),
                $row->email,
                $row->course_fullname,
                $row->notification_type,
                $row->origin,
                $row->status,
                $row->is_simulation ? 'Sim' : 'Não',
                $row->attempts,
                $row->last_error ?? '',
                $row->dedupe_key,
                date('d/m/Y H:i:s', $row->timesent),
            ], ';');
        }

        $records->close();
        fclose($output);
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    /**
     * Lê o número máximo de tentativas da tabela de configuração.
     *
     * @return int
     */
    private static function get_max_attempts(): int {
        global $DB;

        $value = $DB->get_field(self::CONFIG_TABLE, 'config_value', ['config_key' => 'max_attempts']);

        return $value ? (int)$value : self::DEFAULT_MAX_ATTEMPTS;
    }

    /**
     * Retorna o histórico agrupado por curso + tipo de notificação.
     *
     * Cada linha representa um grupo (curso + tipo) com contadores de disparos.
     *
     * @param array $filters Mesmos filtros aceitos por get_history()
     * @param int   $page    Página (base 0)
     * @param int   $perpage Itens por página
     * @return array ['records' => array, 'total' => int]
     */
    public static function get_history_grouped(array $filters, int $page, int $perpage): array {
        global $DB;

        [$where, $params] = self::build_filters($filters);

        $sql_base = "FROM {" . self::TABLE . "} l
                     JOIN {course} c ON c.id = l.courseid
                    WHERE {$where}";

        $total = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT CONCAT(l.courseid, ':', l.notification_type)) {$sql_base}",
            $params
        );

        $sql = "SELECT CONCAT(l.courseid, ':', l.notification_type) AS grp_key,
                       l.courseid,
                       l.notification_type,
                       c.fullname AS course_fullname,
                       COUNT(l.id) AS total_dispatches,
                       SUM(CASE WHEN l.status = 'success' THEN 1 ELSE 0 END) AS total_success,
                       SUM(CASE WHEN l.status = 'failed' THEN 1 ELSE 0 END) AS total_failed,
                       SUM(CASE WHEN l.status = 'abandoned' THEN 1 ELSE 0 END) AS total_abandoned,
                       MIN(l.timesent) AS first_sent,
                       MAX(l.timesent) AS last_sent
                {$sql_base}
             GROUP BY l.courseid, l.notification_type, c.fullname
             ORDER BY MAX(l.timesent) DESC";

        $records = array_values($DB->get_records_sql($sql, $params, $page * $perpage, $perpage));

        return [
            'records' => $records,
            'total'   => (int)$total,
        ];
    }

    /**
     * Retorna os disparos individuais de um grupo (curso + tipo).
     *
     * @param int    $courseid ID do curso
     * @param string $type     Tipo de notificação
     * @return array Lista de objetos de log
     */
    public static function get_dispatches_for_group(int $courseid, string $type): array {
        global $DB;

        $sql = "SELECT l.*, u.firstname, u.lastname, u.email
                  FROM {" . self::TABLE . "} l
                  JOIN {user} u ON u.id = l.userid
                 WHERE l.courseid = :courseid
                   AND l.notification_type = :ntype
              ORDER BY l.timesent DESC";

        return array_values($DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'ntype'    => $type,
        ], 0, 200));
    }

    /** Valores válidos para filtros de enum */
    const VALID_TYPES   = ['start', 'lesson', 'end'];
    const VALID_ORIGINS = ['auto', 'manual'];
    const VALID_STATUSES = ['success', 'failed', 'abandoned'];

    /**
     * Constrói a cláusula WHERE e parâmetros a partir de um array de filtros.
     *
     * @param array $filters Filtros: courseid, notification_type, origin, status, date_from, date_to
     * @return array [string $where, array $params]
     */
    private static function build_filters(array $filters): array {
        $conditions = ['1=1'];
        $params     = [];

        if (!empty($filters['courseid'])) {
            $conditions[] = 'l.courseid = :courseid';
            $params['courseid'] = (int)$filters['courseid'];
        }

        if (!empty($filters['notification_type']) && in_array($filters['notification_type'], self::VALID_TYPES, true)) {
            $conditions[] = 'l.notification_type = :notification_type';
            $params['notification_type'] = $filters['notification_type'];
        }

        if (!empty($filters['origin']) && in_array($filters['origin'], self::VALID_ORIGINS, true)) {
            $conditions[] = 'l.origin = :origin';
            $params['origin'] = $filters['origin'];
        }

        if (!empty($filters['status']) && in_array($filters['status'], self::VALID_STATUSES, true)) {
            $conditions[] = 'l.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = 'l.timesent >= :date_from';
            $params['date_from'] = (int)$filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = 'l.timesent <= :date_to';
            $params['date_to'] = (int)$filters['date_to'];
        }

        return [implode(' AND ', $conditions), $params];
    }
}
