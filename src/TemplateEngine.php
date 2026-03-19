<?php
// This file is part of the TechEduConnect notification plugin.

defined('MOODLE_INTERNAL') || die();

/**
 * Classe responsável pela renderização de templates de e-mail com substituição
 * de variáveis no formato {nome_variavel}.
 *
 * @package    notification_course
 */
class TemplateEngine {

    // -------------------------------------------------------------------------
    // Renderização
    // -------------------------------------------------------------------------

    /**
     * Substitui placeholders {nome_variavel} no template pelos valores do array.
     *
     * Placeholders sem correspondência no array são PRESERVADOS como estão.
     *
     * @param string $template  Texto do template com placeholders {nome_variavel}
     * @param array  $variables Mapa de nome => valor para substituição
     * @return string Texto com as substituições realizadas
     */
    public static function render(string $template, array $variables): string {
        return preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)\}/',
            function (array $matches) use ($variables): string {
                $key = $matches[1];
                // Se a variável existe no array, substitui; caso contrário, preserva o placeholder.
                return array_key_exists($key, $variables) ? (string)$variables[$key] : $matches[0];
            },
            $template
        );
    }

    // -------------------------------------------------------------------------
    // Variáveis comuns
    // -------------------------------------------------------------------------

    /**
     * Retorna variáveis de template comuns a qualquer tipo de notificação.
     *
     * @param object $user   Objeto usuário do Moodle
     * @param object $course Objeto curso do Moodle
     * @return array
     */
    public static function get_common_variables(object $user, object $course): array {
        global $CFG, $DB;

        $logo_url = $DB->get_field('notifcourse_config', 'config_value', ['config_key' => 'logo_url']);

        $vars = [
            'nome_aluno'         => trim($user->firstname . ' ' . $user->lastname),
            'login_moodle'       => $user->username,
            'nome_curso'         => $course->fullname,
            'link_esqueci_senha' => $CFG->wwwroot . '/login/forgot_password.php',
            'logo_url'           => $logo_url ?: '',
        ];

        // Disponibilizar data_inicio e data_termino em todos os tipos de notificação.
        // Sempre definir as chaves para evitar que o placeholder {data_inicio}/{data_termino}
        // apareça literalmente no e-mail quando o curso não possui datas configuradas.
        $vars['data_inicio'] = (!empty($course->startdate) && (int)$course->startdate > 0)
            ? self::format_date((int)$course->startdate)
            : '';
        $vars['data_termino'] = (!empty($course->enddate) && (int)$course->enddate > 0)
            ? self::format_date((int)$course->enddate)
            : '';

        return $vars;
    }

    // -------------------------------------------------------------------------
    // Variáveis por tipo de notificação
    // -------------------------------------------------------------------------

    /**
     * Retorna variáveis específicas para o e-mail de início de curso.
     *
     * @param object $course Objeto curso do Moodle
     * @return array
     */
    public static function get_start_variables(object $course): array {
        return [
            'data_inicio' => self::format_date((int)$course->startdate),
        ];
    }

    /**
     * Retorna variáveis específicas para o e-mail de lembrete de aula agendada.
     *
     * @param object $schedule Objeto de agendamento (notifcourse_schedule)
     * @return array
     */
    public static function get_lesson_variables(object $schedule): array {
        return [
            'data_aula'  => self::format_date((int)$schedule->lesson_date),
            'hora_aula'  => self::format_time((int)$schedule->lesson_date),
            'link_aula'  => $schedule->link_aula ?? '',
            'link_zoom'  => $schedule->link_aula ?? '', // backward compat
        ];
    }

    /**
     * Retorna variáveis específicas para o e-mail de encerramento de curso.
     *
     * @param object $course     Objeto curso do Moodle
     * @param string $survey_url URL da pesquisa de satisfação
     * @return array
     */
    public static function get_end_variables(object $course, string $survey_url): array {
        return [
            'data_termino'  => self::format_date((int)$course->enddate),
            'link_pesquisa' => $survey_url,
        ];
    }

    // -------------------------------------------------------------------------
    // Formatação de data e hora
    // -------------------------------------------------------------------------

    /**
     * Formata um timestamp Unix no padrão de data brasileiro (d/m/Y).
     *
     * @param int $timestamp Timestamp Unix
     * @return string Data formatada
     */
    public static function format_date(int $timestamp): string {
        return userdate($timestamp, '%d/%m/%Y');
    }

    /**
     * Formata um timestamp Unix como hora no padrão H:i.
     *
     * @param int $timestamp Timestamp Unix
     * @return string Hora formatada
     */
    public static function format_time(int $timestamp): string {
        return userdate($timestamp, '%H:%M');
    }
}
