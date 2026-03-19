<?php
// This file is part of the TechEduConnect notification plugin.

defined('MOODLE_INTERNAL') || die();

/**
 * Classe responsável pelas consultas relacionadas a cursos e matrículas de alunos.
 *
 * @package    notification_course
 */
class CourseChecker {

    /** Nível de contexto de curso no Moodle */
    const CONTEXT_COURSE = 50;

    // -------------------------------------------------------------------------
    // Janelas de curso (início / fim)
    // -------------------------------------------------------------------------

    /**
     * Retorna cursos que precisam de agenda de início mas ainda não têm.
     *
     * Critério idempotente: curso inicia dentro de hours_before horas,
     * está em categoria ativa, e NÃO possui agenda 'start' em notifcourse_schedule.
     * Tolerante a atraso do cron — não depende de janela de 1 hora.
     *
     * @param int $hours_before Horas antes do início
     * @return array Lista de objetos de curso
     */
    public static function get_courses_starting(int $hours_before): array {
        global $DB;

        $now        = time();
        $window_end = $now + ($hours_before * HOURSECS);

        $sql = "SELECT c.*
                  FROM {course} c
                  JOIN {notifcourse_categories} nc ON nc.categoryid = c.category
             LEFT JOIN {notifcourse_schedule} ns ON ns.courseid = c.id AND ns.notification_type = 'start'
                 WHERE c.visible = 1
                   AND nc.active = 1
                   AND c.startdate > 0
                   AND c.startdate <= :window_end
                   AND c.startdate > :now
                   AND ns.id IS NULL
              ORDER BY c.startdate ASC";

        return array_values($DB->get_records_sql($sql, [
            'window_end' => $window_end,
            'now'        => $now,
        ]));
    }

    /**
     * Retorna cursos que precisam de agenda de fim mas ainda não têm.
     *
     * Critério idempotente: curso terminou dentro das últimas hours_after horas,
     * está em categoria ativa, e NÃO possui agenda 'end' em notifcourse_schedule.
     * Tolerante a atraso do cron — não depende de janela fixa.
     *
     * @param int $hours_after Horas após o término (lookback máximo)
     * @return array Lista de objetos de curso
     */
    public static function get_courses_ending(int $hours_after): array {
        global $DB;

        $now          = time();
        $window_start = $now - ($hours_after * HOURSECS);

        $sql = "SELECT c.*
                  FROM {course} c
                  JOIN {notifcourse_categories} nc ON nc.categoryid = c.category
             LEFT JOIN {notifcourse_schedule} ns ON ns.courseid = c.id AND ns.notification_type = 'end'
                 WHERE c.visible = 1
                   AND nc.active = 1
                   AND c.enddate > 0
                   AND c.enddate >= :window_start
                   AND c.enddate < :now
                   AND ns.id IS NULL
              ORDER BY c.enddate ASC";

        return array_values($DB->get_records_sql($sql, [
            'window_start' => $window_start,
            'now'          => $now,
        ]));
    }

    // -------------------------------------------------------------------------
    // Alunos matriculados
    // -------------------------------------------------------------------------

    /**
     * Retorna os alunos com inscrição ativa em um curso.
     *
     * IMPORTANTE: Este é o método usado para determinar os destinatários de
     * TODAS as notificações (start, lesson, end). O critério é a situação da
     * inscrição no curso (Ativo/Suspenso), NÃO o último acesso. Alunos que
     * nunca acessaram o curso mas possuem inscrição ativa DEVEM receber as
     * notificações. Alunos com inscrição suspensa (ue.status=1) são excluídos.
     *
     * Critérios:
     *   - Inscrição ativa no curso (mdl_user_enrolments.status=0, mdl_enrol.status=0)
     *   - Usuário não suspenso globalmente e não excluído
     *   - Usuário possui e-mail
     *   - Possui papel de estudante (archetype='student') no contexto do curso
     *
     * @param int $courseid ID do curso
     * @return array Lista de objetos de usuário
     */
    public static function get_active_students(int $courseid): array {
        global $DB;

        $sql = "SELECT DISTINCT u.id,
                       u.firstname,
                       u.lastname,
                       u.email,
                       u.username,
                       u.lang,
                       u.timezone,
                       u.mailformat
                  FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid AND e.status = 0
                  JOIN {context} ctx ON ctx.instanceid = e.courseid
                       AND ctx.contextlevel = :contextlevel
                  JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = ctx.id
                  JOIN {role} r ON r.id = ra.roleid AND r.archetype = 'student'
                 WHERE u.suspended = 0
                   AND u.deleted = 0
                   AND u.email <> ''
              ORDER BY u.lastname ASC, u.firstname ASC";

        return array_values($DB->get_records_sql($sql, [
            'courseid'     => $courseid,
            'contextlevel' => self::CONTEXT_COURSE,
        ]));
    }

    /**
     * Retorna os alunos ativos que já acessaram o curso ao menos uma vez.
     *
     * Mesmos critérios de get_active_students() acrescidos do JOIN com
     * mdl_user_lastaccess WHERE timeaccess > 0.
     *
     * @param int $courseid ID do curso
     * @return array Lista de objetos de usuário com campo timeaccess
     */
    public static function get_students_with_access(int $courseid): array {
        global $DB;

        $sql = "SELECT DISTINCT u.id,
                       u.firstname,
                       u.lastname,
                       u.email,
                       u.username,
                       u.lang,
                       u.timezone,
                       u.mailformat,
                       la.timeaccess
                  FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid AND e.status = 0
                  JOIN {context} ctx ON ctx.instanceid = e.courseid
                       AND ctx.contextlevel = :contextlevel
                  JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = ctx.id
                  JOIN {role} r ON r.id = ra.roleid AND r.archetype = 'student'
                  JOIN {user_lastaccess} la ON la.userid = u.id AND la.courseid = e.courseid
                       AND la.timeaccess > 0
                 WHERE u.suspended = 0
                   AND u.deleted = 0
                   AND u.email <> ''
              ORDER BY u.lastname ASC, u.firstname ASC";

        return array_values($DB->get_records_sql($sql, [
            'courseid'     => $courseid,
            'contextlevel' => self::CONTEXT_COURSE,
        ]));
    }

    // -------------------------------------------------------------------------
    // Cursos ativos
    // -------------------------------------------------------------------------

    /**
     * Retorna cursos visíveis em categorias ativas que ainda não terminaram
     * (usado no dropdown de criação de agendamentos).
     *
     * Inclui cursos futuros (startdate > now) para permitir agendamento
     * antecipado de notificações.
     *
     * @return array Lista de objetos de curso
     */
    public static function get_active_courses(): array {
        global $DB;

        $now = time();

        $sql = "SELECT c.*
                  FROM {course} c
                  JOIN {notifcourse_categories} nc ON nc.categoryid = c.category
                 WHERE c.visible = 1
                   AND nc.active = 1
                   AND c.startdate > 0
                   AND c.enddate > 0
                   AND c.enddate >= :now_end
              ORDER BY c.fullname ASC";

        return array_values($DB->get_records_sql($sql, [
            'now_end' => $now,
        ]));
    }

    // -------------------------------------------------------------------------
    // Link de acesso à aula
    // -------------------------------------------------------------------------

    /**
     * Lê o link de acesso a partir do campo personalizado 'link_zoom' do curso.
     *
     * @param int $courseid ID do curso
     * @return string Link de acesso ou string vazia se não encontrado
     */
    public static function get_link_aula_for_course(int $courseid): string {
        global $DB;

        $sql = "SELECT cfd.value
                  FROM {customfield_data} cfd
                  JOIN {customfield_field} cff ON cff.id = cfd.fieldid
                 WHERE cff.shortname = 'link_zoom'
                   AND cfd.instanceid = :courseid";

        $value = $DB->get_field_sql($sql, ['courseid' => $courseid]);

        return $value ? (string)$value : '';
    }
}
