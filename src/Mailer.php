<?php
// This file is part of the TechEduConnect notification plugin.

defined('MOODLE_INTERNAL') || die();

/**
 * Classe responsável pelo envio de e-mails de notificação de cursos.
 *
 * @package    notification_course
 */
class Mailer {

    /** Nome da tabela de configuração */
    const CONFIG_TABLE = 'notifcourse_config';

    // -------------------------------------------------------------------------
    // Envio de e-mail
    // -------------------------------------------------------------------------

    /**
     * Envia um e-mail para o usuário informado.
     *
     * Em modo dry_run, retorna true sem efetuar o envio real.
     * Envolve o corpo em HTML básico caso não seja HTML.
     *
     * @param object $user    Objeto usuário do Moodle (destinatário)
     * @param string $subject Assunto do e-mail
     * @param string $body    Corpo do e-mail (texto ou HTML)
     * @param bool   $dry_run Se true, simula o envio sem enviar de fato
     * @return bool True em caso de sucesso, false em caso de falha
     */
    public static function send(object $user, string $subject, string $body, bool $dry_run = false): bool {
        if ($dry_run) {
            return true;
        }

        // Sanitizar subject contra header injection (remover \r \n).
        $safe_subject = str_replace(["\r", "\n"], ' ', $subject);

        $support = core_user::get_support_user();

        // Envolve em HTML básico se não for HTML.
        $html_body = self::ensure_html($body);

        $result = email_to_user(
            $user,
            $support,
            $safe_subject,
            html_to_text($html_body),
            $html_body
        );

        return (bool)$result;
    }

    // -------------------------------------------------------------------------
    // Orquestração do envio completo
    // -------------------------------------------------------------------------

    /**
     * Orquestra o processo completo de envio de uma notificação:
     *   1. Monta as variáveis via TemplateEngine
     *   2. Obtém assunto e corpo da configuração (start/end) ou do agendamento (lesson)
     *   3. Renderiza o template
     *   4. Envia o e-mail via send()
     *   5. Registra no log via NotifLog
     *
     * @param object      $user               Objeto usuário destinatário
     * @param object      $course             Objeto curso
     * @param object|null $schedule           Objeto de agendamento (obrigatório para type='lesson')
     * @param string      $type               Tipo: 'start', 'lesson', 'end'
     * @param string      $origin             Origem: 'auto', 'manual'
     * @param bool        $dry_run            Se true, simula sem enviar nem registrar como sucesso real
     * @param string|null $manual_dispatch_id ID de despacho manual (para deduplicação)
     * @return array ['success' => bool, 'error' => ?string, 'log_id' => int]
     */
    public static function send_notification(
        object $user,
        object $course,
        ?object $schedule,
        string $type,
        string $origin,
        bool $dry_run = false,
        ?string $manual_dispatch_id = null
    ): array {
        // --- Monta variáveis do template ---
        $variables = TemplateEngine::get_common_variables($user, $course);

        switch ($type) {
            case 'start':
                $variables = array_merge($variables, TemplateEngine::get_start_variables($course));
                // Incluir link_aula da agenda se disponível.
                if ($schedule !== null && !empty($schedule->link_aula)) {
                    $variables['link_aula'] = $schedule->link_aula;
                    $variables['link_zoom'] = $schedule->link_aula; // backward compat
                }
                // Usar subject/body do schedule se disponível, senão do config.
                if ($schedule !== null && !empty($schedule->subject) && !empty($schedule->body)) {
                    $subject = $schedule->subject;
                    $body    = $schedule->body;
                } else {
                    [$subject, $body] = self::get_config_template('start');
                }
                break;

            case 'end':
                // survey_url vem da agenda (por curso) ou fallback do config global.
                $survey_url = ($schedule !== null && !empty($schedule->survey_url))
                    ? $schedule->survey_url
                    : (self::get_config_value('end_survey_url') ?? '');
                $variables  = array_merge($variables, TemplateEngine::get_end_variables($course, $survey_url));
                // Incluir link_aula da agenda se disponível.
                if ($schedule !== null && !empty($schedule->link_aula)) {
                    $variables['link_aula'] = $schedule->link_aula;
                    $variables['link_zoom'] = $schedule->link_aula; // backward compat
                }
                // Usar subject/body do schedule se disponível, senão do config.
                if ($schedule !== null && !empty($schedule->subject) && !empty($schedule->body)) {
                    $subject = $schedule->subject;
                    $body    = $schedule->body;
                } else {
                    [$subject, $body] = self::get_config_template('end');
                }
                break;

            case 'lesson':
                if ($schedule === null) {
                    return [
                        'success' => false,
                        'error'   => 'Agendamento não informado para notificação do tipo lesson.',
                        'log_id'  => 0,
                    ];
                }
                $variables = array_merge($variables, TemplateEngine::get_lesson_variables($schedule));
                $subject   = $schedule->subject ?? '';
                $body      = $schedule->body ?? '';
                break;

            default:
                return [
                    'success' => false,
                    'error'   => "Tipo de notificação desconhecido: {$type}",
                    'log_id'  => 0,
                ];
        }

        // --- Renderiza o template ---
        $rendered_subject = TemplateEngine::render($subject, $variables);
        $rendered_body    = TemplateEngine::render($body, $variables);

        // --- Chave de deduplicação ---
        // Usa schedule_id para todos os tipos — permite recriar agendas do mesmo curso.
        $id1 = ($schedule !== null) ? (int)$schedule->id : (int)$course->id;
        $dedupe_key = NotifLog::build_dedupe_key($origin, $type, $id1, (int)$user->id, $manual_dispatch_id);

        // Verifica deduplicação (apenas para envios automáticos não simulados)
        if (!$dry_run && $origin === 'auto' && NotifLog::is_already_sent($dedupe_key)) {
            return [
                'success' => false,
                'error'   => 'Notificação já enviada anteriormente (dedupe).',
                'log_id'  => 0,
            ];
        }

        // --- Envia o e-mail ---
        // Para start/end sem schedule explícito (Camadas 1/3), buscar a agenda correspondente.
        if ($schedule === null && ($type === 'start' || $type === 'end')) {
            global $DB;
            $found = $DB->get_record('notifcourse_schedule', [
                'courseid' => $course->id,
                'notification_type' => $type,
            ]);
            if ($found) {
                $schedule_id = (int)$found->id;
            } else {
                $schedule_id = null;
            }
        } else {
            $schedule_id = ($schedule !== null) ? (int)$schedule->id : null;
        }
        $error       = null;
        $success     = false;

        try {
            $success = self::send($user, $rendered_subject, $rendered_body, $dry_run);
            if (!$success) {
                $error = 'email_to_user() retornou false.';
            }
        } catch (Throwable $e) {
            $success = false;
            $error   = $e->getMessage();
        }

        // --- Registra no log ---
        // Dry-run não grava no log para não conflitar com UNIQUE(dedupe_key).
        if ($dry_run) {
            return [
                'success' => true,
                'error'   => null,
                'log_id'  => 0,
            ];
        }

        $status = $success ? 'success' : 'failed';

        $log_id = NotifLog::log_send(
            (int)$user->id,
            (int)$course->id,
            $schedule_id,
            $type,
            $origin,
            $status,
            $dedupe_key,
            $manual_dispatch_id,
            $error,
            $dry_run
        );

        return [
            'success' => $success,
            'error'   => $error,
            'log_id'  => $log_id,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    /**
     * Retorna o assunto e corpo de um template da configuração.
     *
     * @param string $type 'start' ou 'end'
     * @return array [string $subject, string $body]
     */
    private static function get_config_template(string $type): array {
        global $DB;

        // get_field() retorna false quando não encontra — ?? não cobre false.
        $subject = $DB->get_field(self::CONFIG_TABLE, 'config_value', ['config_key' => "{$type}_subject"]);
        $body    = $DB->get_field(self::CONFIG_TABLE, 'config_value', ['config_key' => "{$type}_body"]);

        return [
            ($subject !== false) ? (string) $subject : '',
            ($body !== false) ? (string) $body : '',
        ];
    }

    /**
     * Lê um valor da tabela de configuração pelo nome.
     *
     * @param string $name Nome da configuração
     * @return string|null Valor encontrado ou null
     */
    private static function get_config_value(string $name): ?string {
        global $DB;

        $value = $DB->get_field(self::CONFIG_TABLE, 'config_value', ['config_key' => $name]);

        return $value !== false ? (string)$value : null;
    }

    /**
     * Envolve o conteúdo em HTML básico caso não seja um documento HTML.
     *
     * @param string $content Conteúdo original
     * @return string Conteúdo em HTML
     */
    private static function ensure_html(string $content): string {
        // Verifica se já contém tags HTML.
        if (preg_match('/<[a-z][\s\S]*>/i', $content)) {
            return $content;
        }

        // Converte quebras de linha em <br> e envolve em estrutura HTML mínima.
        $escaped = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));

        // Logo no topo (se configurado).
        $logo_html = self::get_logo_html();

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; font-size: 14px; color: #333; margin: 0; padding: 0;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px; margin: 0 auto;">
{$logo_html}
<tr><td style="padding: 20px 24px;">
{$escaped}
</td></tr>
</table>
</body>
</html>
HTML;
    }

    /**
     * Gera o HTML do logo para o topo do e-mail, se configurado.
     */
    private static function get_logo_html(): string {
        global $DB;

        $logo_url = $DB->get_field('notifcourse_config', 'config_value', ['config_key' => 'logo_url']);
        if (empty($logo_url) || strpos($logo_url, 'http') !== 0) {
            return '';
        }

        $safe_url = htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<tr><td style="padding: 24px 24px 0; text-align: center;">
<img src="{$safe_url}" alt="Logo" width="200" style="width: 200px; max-width: 50%; height: auto; display: inline-block;">
</td></tr>
HTML;
    }
}
