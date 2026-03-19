<?php
/**
 * Teste de Notificações — Notificações de Curso
 *
 * Permite ao administrador enviar uma notificação de teste para si mesmo,
 * com suporte a dry-run (simulação sem envio real de e-mail).
 */

require_once(__DIR__ . '/bootstrap.php');

global $DB, $USER;

// ---------------------------------------------------------------------------
// Resultado do envio (processado via POST)
// ---------------------------------------------------------------------------
$send_result    = null;
$email_preview  = null;
$dry_run_active = false;

// ---------------------------------------------------------------------------
// POST: Executar teste
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $notification_type = trim($_POST['notification_type'] ?? 'start');
    $courseid          = (int)($_POST['courseid'] ?? 0);
    $dry_run_active    = !empty($_POST['dry_run']);

    // Validações básicas
    if (!in_array($notification_type, ['start', 'lesson', 'end'], true)) {
        $send_result = ['success' => false, 'error' => 'Tipo de notificação inválido.'];
    } elseif ($courseid <= 0) {
        $send_result = ['success' => false, 'error' => 'Selecione um curso.'];
    } else {
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            $send_result = ['success' => false, 'error' => 'Curso não encontrado.'];
        } else {
            // Para tipo "lesson", obtemos um agendamento recente do curso (se houver)
            $schedule = null;
            if ($notification_type === 'lesson') {
                $schedule = $DB->get_record_sql(
                    "SELECT * FROM {notifcourse_schedule}
                      WHERE courseid = :courseid
                      ORDER BY timecreated DESC",
                    ['courseid' => $courseid],
                    IGNORE_MISSING
                );
            }

            // Gerar preview renderizado antes do envio
            $variables = TemplateEngine::get_common_variables($USER, $course);
            if ($notification_type === 'start') {
                $variables  = array_merge($variables, TemplateEngine::get_start_variables($course));
                $cfg_sub    = $DB->get_field('notifcourse_config', 'config_value', ['config_key' => 'start_subject']) ?: '';
                $cfg_body   = $DB->get_field('notifcourse_config', 'config_value', ['config_key' => 'start_body'])    ?: '';
            } elseif ($notification_type === 'end') {
                // survey_url da agenda do curso ou fallback do config global.
                $end_sched = $DB->get_record('notifcourse_schedule', ['courseid' => $course->id, 'notification_type' => 'end']);
                $survey_url = ($end_sched && !empty($end_sched->survey_url))
                    ? $end_sched->survey_url
                    : ($DB->get_field('notifcourse_config', 'config_value', ['config_key' => 'end_survey_url']) ?: '');
                $variables  = array_merge($variables, TemplateEngine::get_end_variables($course, $survey_url));
                $cfg_sub    = $DB->get_field('notifcourse_config', 'config_value', ['config_key' => 'end_subject']) ?: '';
                $cfg_body   = $DB->get_field('notifcourse_config', 'config_value', ['config_key' => 'end_body'])    ?: '';
            } else {
                // lesson
                if ($schedule) {
                    $variables = array_merge($variables, TemplateEngine::get_lesson_variables($schedule));
                    $cfg_sub   = $schedule->subject ?? '';
                    $cfg_body  = $schedule->body    ?? '';
                } else {
                    $cfg_sub  = '';
                    $cfg_body = '(Nenhum agendamento encontrado para este curso — crie um agendamento primeiro)';
                }
            }

            $email_preview = [
                'subject' => TemplateEngine::render($cfg_sub,  $variables),
                'body'    => TemplateEngine::render($cfg_body, $variables),
            ];

            // Enviar para o próprio admin logado
            $send_result = Mailer::send_notification(
                $USER,
                $course,
                $schedule,
                $notification_type,
                'manual',
                $dry_run_active
            );
        }
    }
}

// ---------------------------------------------------------------------------
// Cursos disponíveis para o dropdown
// ---------------------------------------------------------------------------
$all_courses = $DB->get_records_sql(
    "SELECT id, fullname FROM {course} WHERE id <> 1 ORDER BY fullname ASC"
);

require_once(__DIR__ . '/includes/header.php');
?>

<!-- ======================================================================= -->
<!-- Cabeçalho da página                                                       -->
<!-- ======================================================================= -->
<div class="page-header">
    <h1 class="page-title">Teste de Notificações</h1>
    <p class="page-subtitle">
        Envie uma notificação de teste para o seu próprio e-mail de administrador
        (<strong><?= htmlspecialchars($USER->email, ENT_QUOTES) ?></strong>).
    </p>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:flex-start;"
     class="test-grid">

    <!-- =================================================================== -->
    <!-- Formulário de teste                                                   -->
    <!-- =================================================================== -->
    <div class="card">
        <h2 style="font-size:1rem;font-weight:700;margin-bottom:1.25rem;color:hsl(var(--foreground));">
            Parâmetros do Teste
        </h2>

        <form method="post" action="test.php">
            <input type="hidden" name="sesskey" value="<?= sesskey() ?>">

            <!-- Tipo de notificação -->
            <div class="form-group">
                <label class="form-label" for="notification_type">
                    Tipo de Notificação <span class="required">*</span>
                </label>
                <select id="notification_type" name="notification_type" class="form-select" required>
                    <option value="start"
                        <?= (($_POST['notification_type'] ?? '') === 'start' || !$send_result) ? 'selected' : '' ?>>
                        Início do Curso (Camada 1)
                    </option>
                    <option value="lesson"
                        <?= (($_POST['notification_type'] ?? '') === 'lesson') ? 'selected' : '' ?>>
                        Agenda de Aula (Camada 2)
                    </option>
                    <option value="end"
                        <?= (($_POST['notification_type'] ?? '') === 'end') ? 'selected' : '' ?>>
                        Conclusão do Curso (Camada 3)
                    </option>
                </select>
                <p class="form-hint">
                    Selecione qual template de e-mail será utilizado no teste.
                </p>
            </div>

            <!-- Curso -->
            <div class="form-group">
                <label class="form-label" for="courseid">
                    Curso <span class="required">*</span>
                </label>
                <select id="courseid" name="courseid" class="form-select" required>
                    <option value="">Selecione um curso...</option>
                    <?php foreach ($all_courses as $c): ?>
                    <option value="<?= (int)$c->id ?>"
                        <?= (isset($_POST['courseid']) && (int)$_POST['courseid'] === (int)$c->id) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c->fullname, ENT_QUOTES) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="form-hint">
                    Os dados do curso selecionado serão usados para preencher as variáveis do template.
                </p>
            </div>

            <!-- Dry-run -->
            <div class="form-group" style="margin-bottom:1.5rem;">
                <label style="display:flex;align-items:center;gap:.625rem;cursor:pointer;">
                    <input type="checkbox" name="dry_run" value="1"
                           <?= !empty($_POST['dry_run']) ? 'checked' : '' ?>
                           style="width:1.1rem;height:1.1rem;accent-color:hsl(var(--primary));">
                    <span class="form-label" style="margin:0;">
                        Dry-run <span style="font-weight:400;color:hsl(var(--muted-foreground));">(simulação sem envio real)</span>
                    </span>
                </label>
                <p class="form-hint" style="margin-left:1.7rem;">
                    Com dry-run ativado, o e-mail é gerado e exibido mas <strong>não é entregue</strong>
                    e <strong>nenhum registro é gravado no log</strong>.
                </p>
            </div>

            <!-- Destinatário (informativo) -->
            <div class="form-group">
                <p class="form-label">Destinatário</p>
                <div style="display:flex;align-items:center;gap:.625rem;padding:.5rem .75rem;
                             background:hsl(var(--muted));border-radius:var(--radius);
                             border:1px solid hsl(var(--border));">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                         stroke-linejoin="round" style="color:hsl(var(--muted-foreground));flex-shrink:0;" aria-hidden="true">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                    <div>
                        <p style="font-size:.875rem;font-weight:600;color:hsl(var(--foreground));">
                            <?= htmlspecialchars(fullname($USER), ENT_QUOTES) ?>
                        </p>
                        <p style="font-size:.8rem;color:hsl(var(--muted-foreground));">
                            <?= htmlspecialchars($USER->email, ENT_QUOTES) ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Botão -->
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true">
                    <line x1="22" x2="11" y1="2" y2="13"/>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>
                Enviar Teste
            </button>
        </form>
    </div>

    <!-- =================================================================== -->
    <!-- Painel de resultado                                                   -->
    <!-- =================================================================== -->
    <div>
        <?php if ($send_result === null): ?>
        <!-- Estado inicial: aguardando envio -->
        <div class="card" style="display:flex;flex-direction:column;align-items:center;
                                  justify-content:center;gap:1rem;padding:3rem 2rem;
                                  text-align:center;color:hsl(var(--muted-foreground));">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true" style="opacity:.35;">
                <line x1="22" x2="11" y1="2" y2="13"/>
                <polygon points="22 2 15 22 11 13 2 9 22 2"/>
            </svg>
            <p style="font-size:.9rem;">
                Preencha o formulário e clique em <strong>Enviar Teste</strong> para ver o resultado aqui.
            </p>
        </div>

        <?php elseif (!$send_result['success']): ?>
        <!-- Erro no envio -->
        <div class="alert alert-error mb-4" role="alert">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;margin-top:2px;">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" x2="12" y1="8" y2="12"/>
                <line x1="12" x2="12.01" y1="16" y2="16"/>
            </svg>
            <div>
                <strong>Falha no envio:</strong><br>
                <?= htmlspecialchars($send_result['error'] ?? 'Erro desconhecido.', ENT_QUOTES) ?>
            </div>
        </div>

        <?php else: ?>
        <!-- Sucesso -->
        <div class="alert alert-success mb-4" role="alert">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;margin-top:2px;">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            <div>
                <?php if ($dry_run_active): ?>
                    <strong>Simulação concluída.</strong>
                    O e-mail foi gerado com sucesso mas <strong>não foi entregue</strong> (dry-run ativo).
                <?php else: ?>
                    <strong>E-mail enviado com sucesso!</strong>
                    Verifique a caixa de entrada de <em><?= htmlspecialchars($USER->email, ENT_QUOTES) ?></em>.
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Preview do e-mail (sempre que disponível) -->
        <?php if ($email_preview): ?>
        <div class="card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                <h3 style="font-size:.95rem;font-weight:700;color:hsl(var(--foreground));">
                    Preview do E-mail
                </h3>
                <?php if ($dry_run_active): ?>
                    <span class="badge badge-pending">Simulação</span>
                <?php else: ?>
                    <span class="badge badge-sent">Enviado</span>
                <?php endif; ?>
            </div>

            <!-- Assunto -->
            <div style="margin-bottom:1rem;">
                <p style="font-size:.75rem;font-weight:700;text-transform:uppercase;
                           letter-spacing:.05em;color:hsl(var(--muted-foreground));margin-bottom:.35rem;">
                    Assunto
                </p>
                <p style="font-size:.9rem;font-weight:600;color:hsl(var(--foreground));
                           padding:.5rem .75rem;background:hsl(var(--muted));
                           border-radius:var(--radius);border:1px solid hsl(var(--border));">
                    <?= htmlspecialchars($email_preview['subject'], ENT_QUOTES) ?>
                </p>
            </div>

            <!-- Corpo -->
            <div>
                <p style="font-size:.75rem;font-weight:700;text-transform:uppercase;
                           letter-spacing:.05em;color:hsl(var(--muted-foreground));margin-bottom:.35rem;">
                    Corpo
                </p>
                <div style="border:1px solid hsl(var(--border));border-radius:var(--radius);
                             overflow:hidden;">
                    <?php
                    // Se o corpo contiver HTML, renderizá-lo num iframe seguro via srcdoc;
                    // caso contrário, exibir como texto pré-formatado.
                    $body_content = $email_preview['body'];
                    $is_html = (stripos($body_content, '<html') !== false
                             || stripos($body_content, '<body') !== false
                             || stripos($body_content, '<p')    !== false
                             || stripos($body_content, '<div')  !== false);
                    if ($is_html):
                    ?>
                        <iframe
                            srcdoc="<?= htmlspecialchars($body_content, ENT_QUOTES) ?>"
                            style="width:100%;min-height:320px;border:none;display:block;"
                            sandbox="allow-same-origin"
                            title="Preview do corpo do e-mail">
                        </iframe>
                    <?php else: ?>
                        <pre style="margin:0;padding:1rem;font-size:.84rem;font-family:inherit;
                                    white-space:pre-wrap;word-break:break-word;
                                    background:hsl(var(--background));color:hsl(var(--foreground));
                                    max-height:400px;overflow-y:auto;"><?=
                            htmlspecialchars($body_content, ENT_QUOTES)
                        ?></pre>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php elseif ($send_result !== null && $send_result['success']): ?>
        <!-- Enviado mas sem preview disponível -->
        <div class="card" style="padding:1.5rem;text-align:center;color:hsl(var(--muted-foreground));">
            <p style="font-size:.875rem;">Preview não disponível — o e-mail foi entregue com sucesso.</p>
        </div>
        <?php endif; ?>
    </div>

</div>

<style>
@media (max-width: 768px) {
    .test-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php require_once(__DIR__ . '/includes/footer.php'); ?>
