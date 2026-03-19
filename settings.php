<?php
/**
 * Configurações — Notificações de Curso
 *
 * Permite ajustar parâmetros das Camadas 1 e 3, configurações gerais de envio
 * e quais categorias de curso participam do sistema de notificações.
 */

require_once(__DIR__ . '/bootstrap.php');

global $DB;

// ---------------------------------------------------------------------------
// Helper: lê um valor de notifcourse_config pelo nome da chave.
// ---------------------------------------------------------------------------
function notifcourse_get_config(string $key, $default = ''): string {
    global $DB;
    $record = $DB->get_record('notifcourse_config', ['config_key' => $key]);
    if ($record && $record->config_value !== null && $record->config_value !== '') {
        return (string)$record->config_value;
    }
    return (string)$default;
}

// ---------------------------------------------------------------------------
// Helper: grava (insert ou update) um valor em notifcourse_config.
// ---------------------------------------------------------------------------
function notifcourse_set_config(string $key, string $value): void {
    global $DB;
    $existing = $DB->get_record('notifcourse_config', ['config_key' => $key]);
    if ($existing) {
        $DB->set_field('notifcourse_config', 'config_value', $value, ['config_key' => $key]);
        $DB->set_field('notifcourse_config', 'timemodified', time(), ['config_key' => $key]);
    } else {
        $rec                = new stdClass();
        $rec->config_key    = $key;
        $rec->config_value  = $value;
        $rec->timemodified  = time();
        $DB->insert_record('notifcourse_config', $rec);
    }
}

// ---------------------------------------------------------------------------
// POST: Salvar configurações
// ---------------------------------------------------------------------------
$flash_success = null;
$flash_error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    try {
        // --- Camada 1: Início do Curso ---
        notifcourse_set_config('start_hours_before', (string)(int)($_POST['start_hours_before'] ?? 24));
        notifcourse_set_config('start_subject',      trim($_POST['start_subject'] ?? ''));
        notifcourse_set_config('start_body',         trim($_POST['start_body']    ?? ''));

        // --- Camada 3: Conclusão do Curso ---
        notifcourse_set_config('end_hours_after',  (string)(int)($_POST['end_hours_after'] ?? 24));
        notifcourse_set_config('end_subject',      trim($_POST['end_subject'] ?? ''));
        notifcourse_set_config('end_body',         trim($_POST['end_body']    ?? ''));
        // --- Geral ---
        notifcourse_set_config('logo_url',      clean_param(trim($_POST['logo_url'] ?? ''), PARAM_URL));
        notifcourse_set_config('batch_size',    (string)(int)($_POST['batch_size']    ?? 50));
        notifcourse_set_config('max_attempts',  (string)(int)($_POST['max_attempts']  ?? 3));

        // --- Categorias participantes ---
        // Obter todas as categorias existentes
        $all_cats = $DB->get_records('course_categories', [], 'name ASC', 'id,name');
        $selected_cats = isset($_POST['categories']) && is_array($_POST['categories'])
            ? array_map('intval', $_POST['categories'])
            : [];

        foreach ($all_cats as $cat) {
            $active  = in_array((int)$cat->id, $selected_cats, true) ? 1 : 0;
            $existing = $DB->get_record('notifcourse_categories', ['categoryid' => $cat->id]);
            if ($existing) {
                $DB->set_field('notifcourse_categories', 'active', $active, ['categoryid' => $cat->id]);
            } else {
                $rec              = new stdClass();
                $rec->categoryid  = (int)$cat->id;
                $rec->active      = $active;
                $rec->timecreated = time();
                $DB->insert_record('notifcourse_categories', $rec);
            }
        }

        $flash_success = 'Configurações salvas com sucesso.';
    } catch (Exception $e) {
        $flash_error = 'Erro ao salvar configurações: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Ler configurações atuais
// ---------------------------------------------------------------------------
$cfg = [
    'start_hours_before' => notifcourse_get_config('start_hours_before', '24'),
    'start_subject'      => notifcourse_get_config('start_subject', 'Seu curso {nome_curso} começa em breve!'),
    'start_body'         => notifcourse_get_config('start_body',
        "Olá, {nome_aluno}!\n\nSeu curso {nome_curso} começa em {data_inicio}.\n\n" .
        "Acesse a plataforma com seu login: {login_moodle}\n{link_esqueci_senha}\n\nBoa sorte!"
    ),
    'end_hours_after'    => notifcourse_get_config('end_hours_after', '24'),
    'end_subject'        => notifcourse_get_config('end_subject', 'Parabéns pela conclusão do curso {nome_curso}!'),
    'end_body'           => notifcourse_get_config('end_body',
        "Olá, {nome_aluno}!\n\nParabéns por concluir o curso {nome_curso} (encerrado em {data_termino}).\n\n" .
        "Link do curso: {link_aula}\n\n" .
        "Ficamos felizes com sua participação. Deixe sua avaliação: {link_pesquisa}\n\nAté logo!"
    ),
    'logo_url'           => notifcourse_get_config('logo_url', ''),
    'batch_size'         => notifcourse_get_config('batch_size', '50'),
    'max_attempts'       => notifcourse_get_config('max_attempts', '3'),
];

// Categorias
$all_categories  = $DB->get_records('course_categories', [], 'name ASC', 'id,name');
$active_cat_ids  = [];
foreach ($DB->get_records('notifcourse_categories', ['active' => 1], '', 'categoryid') as $row) {
    $active_cat_ids[] = (int)$row->categoryid;
}

require_once(__DIR__ . '/includes/header.php');
?>

<!-- ======================================================================= -->
<!-- Cabeçalho da página                                                       -->
<!-- ======================================================================= -->
<div class="page-header">
    <h1 class="page-title">Configurações</h1>
    <p class="page-subtitle">Personalize os templates e parâmetros de cada camada de notificação.</p>
</div>

<!-- Mensagens flash -->
<?php if ($flash_success): ?>
<div class="alert alert-success mb-6" data-auto-dismiss="5000" role="alert">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
         stroke-linejoin="round" aria-hidden="true">
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
        <polyline points="22 4 12 14.01 9 11.01"/>
    </svg>
    <?= htmlspecialchars($flash_success, ENT_QUOTES) ?>
</div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div class="alert alert-error mb-6" data-auto-dismiss="7000" role="alert">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
         stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" x2="12" y1="8" y2="12"/>
        <line x1="12" x2="12.01" y1="16" y2="16"/>
    </svg>
    <?= htmlspecialchars($flash_error, ENT_QUOTES) ?>
</div>
<?php endif; ?>

<form method="post" action="settings.php">
    <input type="hidden" name="sesskey" value="<?= sesskey() ?>">

    <!-- =================================================================== -->
    <!-- Seção: Camada 1 — Início do Curso                                    -->
    <!-- =================================================================== -->
    <div class="card mb-6">
        <h2 style="font-size:1.05rem;font-weight:700;margin-bottom:1.25rem;color:hsl(var(--foreground));
                   padding-bottom:.75rem;border-bottom:1px solid hsl(var(--border));">
            Camada 1 &mdash; Início do Curso
        </h2>
        <p class="page-subtitle" style="margin-bottom:1.25rem;">
            Enviada automaticamente para alunos matriculados antes do início do curso.
            Variáveis: <code>{nome_aluno}</code>, <code>{nome_curso}</code>, <code>{data_inicio}</code>,
            <code>{link_aula}</code>, <code>{login_moodle}</code>, <code>{link_esqueci_senha}</code>
        </p>

        <div class="form-group">
            <label class="form-label" for="start_hours_before">
                Horas antes do início para disparar <span class="required">*</span>
            </label>
            <input type="number" id="start_hours_before" name="start_hours_before"
                   class="form-input" style="max-width:180px;" min="1" max="168" required
                   value="<?= htmlspecialchars($cfg['start_hours_before'], ENT_QUOTES) ?>">
            <p class="form-hint">Exemplo: 24 = envio 24 horas antes da data de início do curso.</p>
        </div>

        <div class="form-group">
            <label class="form-label" for="start_subject">
                Assunto do E-mail <span class="required">*</span>
            </label>
            <input type="text" id="start_subject" name="start_subject"
                   class="form-input" maxlength="255" required
                   value="<?= htmlspecialchars($cfg['start_subject'], ENT_QUOTES) ?>">
        </div>

        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="start_body">
                Corpo do E-mail <span class="required">*</span>
            </label>
            <textarea id="start_body" name="start_body" class="form-textarea" rows="8" required><?=
                htmlspecialchars($cfg['start_body'], ENT_QUOTES)
            ?></textarea>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- Seção: Camada 3 — Conclusão do Curso                                 -->
    <!-- =================================================================== -->
    <div class="card mb-6">
        <h2 style="font-size:1.05rem;font-weight:700;margin-bottom:1.25rem;color:hsl(var(--foreground));
                   padding-bottom:.75rem;border-bottom:1px solid hsl(var(--border));">
            Camada 3 &mdash; Conclusão do Curso
        </h2>
        <p class="page-subtitle" style="margin-bottom:1.25rem;">
            Enviada para todos os alunos matriculados ativos após o encerramento do curso.
            Variáveis: <code>{nome_aluno}</code>, <code>{nome_curso}</code>, <code>{data_termino}</code>,
            <code>{link_aula}</code>, <code>{link_pesquisa}</code>, <code>{login_moodle}</code>, <code>{link_esqueci_senha}</code>
        </p>

        <div class="form-group">
            <label class="form-label" for="end_hours_after">
                Horas após o término para disparar <span class="required">*</span>
            </label>
            <input type="number" id="end_hours_after" name="end_hours_after"
                   class="form-input" style="max-width:180px;" min="1" max="720" required
                   value="<?= htmlspecialchars($cfg['end_hours_after'], ENT_QUOTES) ?>">
            <p class="form-hint">Exemplo: 24 = envio até 24 horas após a data de encerramento do curso.</p>
        </div>

        <div class="form-group">
            <label class="form-label" for="end_subject">
                Assunto do E-mail <span class="required">*</span>
            </label>
            <input type="text" id="end_subject" name="end_subject"
                   class="form-input" maxlength="255" required
                   value="<?= htmlspecialchars($cfg['end_subject'], ENT_QUOTES) ?>">
        </div>

        <div class="form-group">
            <label class="form-label" for="end_body">
                Corpo do E-mail <span class="required">*</span>
            </label>
            <textarea id="end_body" name="end_body" class="form-textarea" rows="8" required><?=
                htmlspecialchars($cfg['end_body'], ENT_QUOTES)
            ?></textarea>
        </div>

        <div class="form-group" style="margin-bottom:0;">
            <p class="form-hint" style="background:hsl(var(--muted));padding:.75rem 1rem;border-radius:var(--radius);border:1px solid hsl(var(--border));">
                A URL da pesquisa de satisfação (<code>{link_pesquisa}</code>) é configurada individualmente
                por curso, na agenda do tipo <strong>Fim</strong> em <a href="schedules.php">Agendas</a>.
            </p>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- Seção: Geral                                                          -->
    <!-- =================================================================== -->
    <div class="card mb-6">
        <h2 style="font-size:1.05rem;font-weight:700;margin-bottom:1.25rem;color:hsl(var(--foreground));
                   padding-bottom:.75rem;border-bottom:1px solid hsl(var(--border));">
            Geral
        </h2>

        <div class="form-group">
            <label class="form-label" for="logo_url">URL do Logo</label>
            <input type="url" id="logo_url" name="logo_url"
                   class="form-input" placeholder="https://exemplo.com/logo.png"
                   value="<?= htmlspecialchars($cfg['logo_url'], ENT_QUOTES) ?>">
            <p class="form-hint">
                Exibido no topo de todos os e-mails enviados. Use uma URL pública acessível (PNG ou JPG, recomendado até 600px de largura).
            </p>
            <?php if (!empty($cfg['logo_url'])): ?>
            <div style="margin-top:.75rem;padding:.75rem;border:1px solid hsl(var(--border));border-radius:var(--radius);background:hsl(var(--muted));text-align:center;">
                <img src="<?= htmlspecialchars($cfg['logo_url'], ENT_QUOTES) ?>" alt="Preview do logo"
                     style="max-width:300px;max-height:80px;">
            </div>
            <?php endif; ?>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.25rem;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="batch_size">
                    Tamanho do Lote (batch_size) <span class="required">*</span>
                </label>
                <input type="number" id="batch_size" name="batch_size"
                       class="form-input" min="1" max="500" required
                       value="<?= htmlspecialchars($cfg['batch_size'], ENT_QUOTES) ?>">
                <p class="form-hint">Máximo de e-mails enviados por camada por execução do CRON.</p>
            </div>

            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="max_attempts">
                    Máximo de Tentativas (max_attempts) <span class="required">*</span>
                </label>
                <input type="number" id="max_attempts" name="max_attempts"
                       class="form-input" min="1" max="10" required
                       value="<?= htmlspecialchars($cfg['max_attempts'], ENT_QUOTES) ?>">
                <p class="form-hint">Após esgotar as tentativas, o status passa para "Abandonado".</p>
            </div>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- Seção: Categorias Participantes                                       -->
    <!-- =================================================================== -->
    <div class="card mb-6">
        <h2 style="font-size:1.05rem;font-weight:700;margin-bottom:.5rem;color:hsl(var(--foreground));
                   padding-bottom:.75rem;border-bottom:1px solid hsl(var(--border));">
            Categorias Participantes
        </h2>
        <p class="page-subtitle" style="margin-bottom:1.25rem;">
            Apenas cursos pertencentes às categorias marcadas abaixo receberão notificações automáticas.
        </p>

        <?php if (empty($all_categories)): ?>
            <p style="color:hsl(var(--muted-foreground));font-size:.875rem;">
                Nenhuma categoria de curso encontrada no Moodle.
            </p>
        <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.5rem;">
            <?php foreach ($all_categories as $cat): ?>
            <label style="display:flex;align-items:center;gap:.625rem;padding:.5rem .75rem;
                           border:1px solid hsl(var(--border));border-radius:var(--radius);
                           cursor:pointer;transition:background-color .1s ease;"
                   onmouseover="this.style.backgroundColor='hsl(var(--muted))'"
                   onmouseout="this.style.backgroundColor=''"
            >
                <input type="checkbox"
                       name="categories[]"
                       value="<?= (int)$cat->id ?>"
                       <?= in_array((int)$cat->id, $active_cat_ids, true) ? 'checked' : '' ?>
                       style="width:1rem;height:1rem;accent-color:hsl(var(--primary));flex-shrink:0;">
                <span style="font-size:.875rem;color:hsl(var(--foreground));">
                    <?= htmlspecialchars($cat->name, ENT_QUOTES) ?>
                </span>
            </label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Botão de salvar -->
    <div class="nc-form-actions" style="display:flex;justify-content:flex-end;gap:.75rem;">
        <button type="reset" class="btn btn-outline">Desfazer Alterações</button>
        <button type="submit" class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                <polyline points="17 21 17 13 7 13 7 21"/>
                <polyline points="7 3 7 8 15 8"/>
            </svg>
            Salvar Configurações
        </button>
    </div>

</form>

<?php require_once(__DIR__ . '/includes/footer.php'); ?>
