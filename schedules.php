<?php
/**
 * Agendas — Notificações de Curso
 *
 * CRUD completo de agendamentos de aula (Camada 2).
 * Suporta listagem, criação, edição, cancelamento, reforço manual e log.
 */

require_once(__DIR__ . '/bootstrap.php');

global $DB, $USER, $CFG;

$appBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

// ---------------------------------------------------------------------------
// Helpers compartilhados
// ---------------------------------------------------------------------------

/**
 * Valida e converte um valor de <input type="datetime-local"> para timestamp.
 * Formato esperado: "YYYY-MM-DDTHH:MM". Retorna null se inválido.
 */
function validate_datetime_local(string $input): ?int {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $input);
    if ($dt === false || $dt->format('Y-m-d\TH:i') !== $input) {
        return null;
    }
    return $dt->getTimestamp();
}

/**
 * Valida um horário no formato HH:MM (00:00 a 23:59).
 */
function validate_time(string $input): bool {
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $input);
}

/**
 * Retorna um badge HTML para o status do agendamento.
 */
function sched_status_badge(string $status): string {
    $map = [
        'pending'   => ['class' => 'badge-pending',   'label' => 'Pendente'],
        'sent'      => ['class' => 'badge-sent',      'label' => 'Enviado'],
        'failed'    => ['class' => 'badge-failed',    'label' => 'Falhou'],
        'abandoned' => ['class' => 'badge-abandoned', 'label' => 'Abandonado'],
        'cancelled' => ['class' => 'badge-cancelled', 'label' => 'Cancelado'],
    ];
    $entry = $map[$status] ?? ['class' => 'badge', 'label' => htmlspecialchars($status, ENT_QUOTES)];
    return '<span class="badge ' . $entry['class'] . '">' . $entry['label'] . '</span>';
}

/**
 * Retorna um badge HTML para o tipo de notificação.
 */
function sched_type_badge(string $type): string {
    $map = [
        'start'  => ['class' => 'badge-type-start',  'label' => 'Início'],
        'lesson' => ['class' => 'badge-type-lesson', 'label' => 'Aula'],
        'end'    => ['class' => 'badge-type-end',    'label' => 'Fim'],
    ];
    $entry = $map[$type] ?? ['class' => 'badge', 'label' => htmlspecialchars($type, ENT_QUOTES)];
    return '<span class="badge ' . $entry['class'] . '">' . $entry['label'] . '</span>';
}

// ---------------------------------------------------------------------------
// Roteamento
// ---------------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ---------------------------------------------------------------------------
// POST: Salvar (criar/editar)
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'save') {
    require_sesskey();

    $schedule_id  = isset($_POST['schedule_id']) ? (int)$_POST['schedule_id'] : 0;
    $courseid     = (int)($_POST['courseid'] ?? 0);
    $raw_date     = trim($_POST['lesson_date'] ?? '');
    $lesson_date  = validate_datetime_local($raw_date);
    $hours_before = (int)($_POST['hours_before'] ?? 2);
    $link_aula    = clean_param(trim($_POST['link_aula'] ?? ''), PARAM_URL);
    $survey_url   = clean_param(trim($_POST['survey_url'] ?? ''), PARAM_URL);
    $subject      = trim($_POST['subject'] ?? '');
    $body         = trim($_POST['body'] ?? '');

    // Campos de recorrência.
    $is_recurring = !empty($_POST['recurring']) && $_POST['recurring'] === '1';
    $recurring_days = isset($_POST['recurring_days']) ? array_map('intval', (array) $_POST['recurring_days']) : [];
    $recurring_time = trim($_POST['recurring_time'] ?? '');

    if (!$courseid || !$subject || !$body) {
        $flash_error = 'Preencha todos os campos obrigatórios.';
    } elseif ($is_recurring && (empty($recurring_days) || empty($recurring_time) || !validate_time($recurring_time))) {
        $flash_error = 'Para agendamento recorrente, selecione ao menos um dia da semana e informe um horário válido (HH:MM).';
    } elseif (!$is_recurring && $lesson_date === null) {
        $flash_error = 'Data e hora inválidas. Use o formato esperado (ex: 2025-06-15T19:00).';
    } else {
        if ($is_recurring && $schedule_id === 0) {
            // Criar agendamentos recorrentes.
            $created = ScheduleManager::create_recurring(
                $courseid,
                $recurring_days,
                $recurring_time,
                $hours_before,
                $link_aula,
                $subject,
                $body
            );

            if ($created > 0) {
                $flash_success = "{$created} agendamento(s) criado(s) com sucesso.";
            } else {
                $flash_error = 'Nenhum agendamento criado. Verifique as datas do curso e os dias selecionados.';
            }
        } elseif ($schedule_id > 0) {
            // Atualizar existente
            $existing = ScheduleManager::get($schedule_id);
            if ($existing && $existing->status === 'pending') {
                ScheduleManager::update($schedule_id, [
                    'lesson_date'  => $lesson_date,
                    'hours_before' => $hours_before,
                    'link_aula'    => $link_aula,
                    'survey_url'   => $survey_url,
                    'subject'      => $subject,
                    'body'         => $body,
                ]);
                $flash_success = 'Agendamento atualizado com sucesso.';
            } else {
                $flash_error = 'Não é possível editar este agendamento (status não é pendente).';
            }
        } else {
            // Criar novo único
            $send_at = $lesson_date - ($hours_before * HOURSECS);
            ScheduleManager::create(
                $courseid,
                $lesson_date,
                $hours_before,
                $link_aula,
                $subject,
                $body
            );
            $flash_success = 'Agendamento criado com sucesso.';
        }

        header('Location: schedules.php?msg=' . urlencode($flash_success ?? $flash_error ?? ''));
        exit;
    }
}

// ---------------------------------------------------------------------------
// POST: Excluir
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'delete' && $id > 0) {
    require_sesskey();

    if (ScheduleManager::delete($id)) {
        header('Location: schedules.php?msg=' . urlencode('Agendamento excluído com sucesso.'));
    } else {
        header('Location: schedules.php?msg_error=' . urlencode('Agendamento não encontrado.'));
    }
    exit;
}

// ---------------------------------------------------------------------------
// POST: Cancelar
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'cancel' && $id > 0) {
    require_sesskey();

    $schedule = ScheduleManager::get($id);
    if ($schedule && $schedule->status === 'pending') {
        ScheduleManager::cancel($id);
        header('Location: schedules.php?msg=' . urlencode('Agendamento cancelado.'));
    } else {
        header('Location: schedules.php?msg_error=' . urlencode('Agendamento não encontrado ou não está pendente.'));
    }
    exit;
}

// ---------------------------------------------------------------------------
// POST: Enviar Reforço
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'reinforce' && $id > 0) {
    require_sesskey();

    $schedule = ScheduleManager::get($id);
    if (!$schedule) {
        header('Location: schedules.php?msg_error=' . urlencode('Agendamento não encontrado.'));
        exit;
    }

    // Verificar cooldown
    if (!ScheduleManager::can_send_reinforcement($id)) {
        header('Location: schedules.php?msg_error=' . urlencode('O e-mail de reforço já foi enviado recentemente para este agendamento. Aguarde 10 minutos entre cada envio.'));
        exit;
    }

    // Buscar curso e alunos
    $course   = $DB->get_record('course', ['id' => $schedule->courseid]);
    $students = $course ? CourseChecker::get_active_students($schedule->courseid) : [];

    if (!$course || empty($students)) {
        header('Location: schedules.php?msg_error=' . urlencode('Curso não encontrado ou sem alunos ativos.'));
        exit;
    }

    $dispatch_id = uniqid('reinforce_', true);
    $sent_count  = 0;
    $fail_count  = 0;
    $sched_type  = isset($schedule->notification_type) ? $schedule->notification_type : 'lesson';

    foreach ($students as $student) {
        $result = Mailer::send_notification($student, $course, $schedule, $sched_type, 'manual', false, $dispatch_id);
        if ($result['success']) {
            $sent_count++;
        } else {
            $fail_count++;
        }
    }

    $summary = "Reforço enviado: {$sent_count} entregue(s), {$fail_count} falha(s).";
    header('Location: schedules.php?msg=' . urlencode($summary));
    exit;
}

// ---------------------------------------------------------------------------
// GET: Formulário de criação / edição
// ---------------------------------------------------------------------------
if ($method === 'GET' && in_array($action, ['create', 'edit'], true)) {
    $courses      = CourseChecker::get_active_courses();
    $edit_sched   = null;
    $link_suggest = '';

    if ($action === 'edit' && $id > 0) {
        $edit_sched = ScheduleManager::get($id);
        if (!$edit_sched || $edit_sched->status !== 'pending') {
            header('Location: schedules.php?msg_error=' . urlencode('Agendamento não encontrado ou não editável.'));
            exit;
        }
        $link_suggest = $edit_sched->link_aula ?? '';
    } else {
        // Sugestão de link para criação (usa o primeiro curso, se disponível)
        $first_course = !empty($courses) ? $courses[0] : null;
        if ($first_course) {
            $link_suggest = ScheduleManager::get_link_suggestion($first_course->id);
        }
    }

    require_once(__DIR__ . '/includes/header.php');
    $page_title = ($action === 'edit') ? 'Editar Agendamento' : 'Novo Agendamento';
    ?>

    <div class="page-header">
        <div style="display:flex;align-items:center;gap:.75rem;">
            <a href="schedules.php" class="btn btn-ghost btn-sm" style="padding:.3rem .5rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true">
                    <path d="M15 18l-6-6 6-6"/>
                </svg>
            </a>
            <div>
                <h1 class="page-title"><?= htmlspecialchars($page_title, ENT_QUOTES) ?></h1>
                <p class="page-subtitle">Preencha os dados da aula para agendar o disparo automático.</p>
            </div>
        </div>
    </div>

    <div class="card nc-form-card">
        <form method="post" action="schedules.php?action=save">
            <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
            <?php if ($action === 'edit'): ?>
                <input type="hidden" name="schedule_id" value="<?= (int)$edit_sched->id ?>">
            <?php endif; ?>

            <!-- Curso (combobox com busca) -->
            <?php
                $courses_json = json_encode(array_map(function($c) {
                    return ['id' => (int)$c->id, 'name' => $c->fullname];
                }, $courses), JSON_HEX_APOS | JSON_HEX_QUOT);
                $preselected_id   = ($edit_sched) ? (int)$edit_sched->courseid : 0;
                $preselected_name = '';
                if ($edit_sched) {
                    foreach ($courses as $c) {
                        if ((int)$c->id === (int)$edit_sched->courseid) {
                            $preselected_name = $c->fullname;
                            break;
                        }
                    }
                    // Se o curso não está na lista ativa, buscar diretamente
                    if (!$preselected_name) {
                        $edit_course = $DB->get_record('course', ['id' => $edit_sched->courseid]);
                        if ($edit_course) {
                            $preselected_name = $edit_course->fullname;
                            // Adicionar ao JSON para que apareça na lista
                            $courses_json = json_encode(array_merge(
                                [['id' => (int)$edit_course->id, 'name' => $edit_course->fullname]],
                                json_decode($courses_json, true)
                            ), JSON_HEX_APOS | JSON_HEX_QUOT);
                        }
                    }
                }
            ?>
            <div class="form-group" x-data="courseCombobox()" x-init="init()">
                <label class="form-label" for="course_search">
                    Curso <span class="required">*</span>
                </label>
                <input type="hidden" name="courseid" :value="selectedId" x-ref="hiddenInput">
                <div style="position:relative;">
                    <input type="text"
                           id="course_search"
                           class="form-input"
                           placeholder="Digite para buscar um curso..."
                           autocomplete="off"
                           x-model="query"
                           @input="open = true"
                           @focus="open = true"
                           @click="open = true"
                           @keydown.escape="open = false"
                           @keydown.arrow-down.prevent="highlightNext()"
                           @keydown.arrow-up.prevent="highlightPrev()"
                           @keydown.enter.prevent="selectHighlighted()"
                           :class="{ 'combobox-selected': selectedId > 0 }">
                    <!-- Botao limpar -->
                    <button type="button"
                            x-show="selectedId > 0"
                            @click="clear()"
                            style="position:absolute;right:10px;top:50%;transform:translateY(-50%);
                                   background:none;border:none;cursor:pointer;color:hsl(var(--muted-foreground));
                                   font-size:1.1rem;line-height:1;padding:2px 6px;"
                            title="Limpar seleção">&times;</button>
                    <!-- Dropdown -->
                    <div x-show="open && filtered.length > 0"
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="opacity-0 transform scale-95"
                         x-transition:enter-end="opacity-100 transform scale-100"
                         @click.outside="open = false"
                         style="position:absolute;z-index:50;width:100%;margin-top:4px;
                                max-height:240px;overflow-y:auto;
                                background:hsl(var(--background));
                                border:1px solid hsl(var(--border));
                                border-radius:var(--radius);
                                box-shadow:0 4px 12px rgba(0,0,0,.1);">
                        <template x-for="(course, idx) in filtered" :key="course.id">
                            <div @click="select(course)"
                                 @mouseenter="highlighted = idx"
                                 :class="{ 'combobox-item-active': highlighted === idx }"
                                 class="combobox-item"
                                 x-text="course.name"></div>
                        </template>
                    </div>
                    <!-- Sem resultados -->
                    <div x-show="open && query.length > 0 && filtered.length === 0"
                         style="position:absolute;z-index:50;width:100%;margin-top:4px;padding:12px 16px;
                                background:hsl(var(--background));
                                border:1px solid hsl(var(--border));
                                border-radius:var(--radius);
                                color:hsl(var(--muted-foreground));font-size:.875rem;">
                        Nenhum curso encontrado.
                    </div>
                </div>
            </div>

            <script>
            function courseCombobox() {
                return {
                    courses: <?= $courses_json ?>,
                    query: '<?= addslashes($preselected_name) ?>',
                    selectedId: <?= $preselected_id ?>,
                    open: false,
                    highlighted: -1,
                    get filtered() {
                        if (!this.query || this.selectedId > 0) return this.courses;
                        var q = this.query.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                        return this.courses.filter(function(c) {
                            var name = c.name.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                            return name.indexOf(q) !== -1;
                        });
                    },
                    init() {
                        var self = this;
                        this.$watch('query', function(val) {
                            if (self.selectedId > 0) {
                                var found = self.courses.find(function(c) { return c.id === self.selectedId; });
                                if (found && val !== found.name) {
                                    self.selectedId = 0;
                                }
                            }
                            self.highlighted = -1;
                        });
                    },
                    select(course) {
                        this.selectedId = course.id;
                        this.query = course.name;
                        this.open = false;
                        fetchLinkSuggestion(course.id);
                    },
                    clear() {
                        this.selectedId = 0;
                        this.query = '';
                        this.open = false;
                        this.$nextTick(function() {
                            document.getElementById('course_search').focus();
                        });
                    },
                    highlightNext() {
                        if (this.highlighted < this.filtered.length - 1) this.highlighted++;
                    },
                    highlightPrev() {
                        if (this.highlighted > 0) this.highlighted--;
                    },
                    selectHighlighted() {
                        if (this.highlighted >= 0 && this.highlighted < this.filtered.length) {
                            this.select(this.filtered[this.highlighted]);
                        }
                    }
                };
            }
            </script>

            <!-- Recorrência (apenas no modo criação) -->
            <?php if ($action !== 'edit'): ?>
            <div class="form-group">
                <label class="form-label">Repetir agendamento?</label>
                <div style="display:flex;gap:1rem;align-items:center;margin-top:.25rem;">
                    <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;">
                        <input type="radio" name="recurring" value="0" checked
                               onchange="toggleRecurring(false)"> Não (data única)
                    </label>
                    <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;">
                        <input type="radio" name="recurring" value="1"
                               onchange="toggleRecurring(true)"> Sim (recorrente)
                    </label>
                </div>
            </div>
            <?php endif; ?>

            <!-- Data e hora da aula (modo único) -->
            <div class="form-group" id="single_date_group">
                <label class="form-label" for="lesson_date">
                    Data e Hora da Aula <span class="required">*</span>
                </label>
                <input type="datetime-local" id="lesson_date" name="lesson_date"
                       class="form-input"
                       value="<?= $edit_sched ? date('Y-m-d\TH:i', (int)$edit_sched->lesson_date) : '' ?>">
            </div>

            <!-- Campos de recorrência (ocultos por padrão) -->
            <?php if ($action !== 'edit'): ?>
            <div id="recurring_group" style="display:none;">
                <div class="form-group">
                    <label class="form-label">
                        Dias da semana <span class="required">*</span>
                    </label>
                    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:.25rem;">
                        <?php
                        $days = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
                        foreach ($days as $num => $label):
                        ?>
                        <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer;
                                      padding:.4rem .6rem;border:1px solid hsl(var(--border));
                                      border-radius:var(--radius);font-size:.875rem;"
                               class="recurring-day-label">
                            <input type="checkbox" name="recurring_days[]" value="<?= $num ?>"
                                   class="recurring-day-check">
                            <?= $label ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="recurring_time">
                        Horário da aula <span class="required">*</span>
                    </label>
                    <input type="time" id="recurring_time" name="recurring_time"
                           class="form-input" style="max-width:200px;" value="19:00">
                    <p class="form-hint">Todas as aulas recorrentes serão neste horário. Usa as datas de início e fim do curso selecionado.</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Antecedência do envio -->
            <div class="form-group">
                <label class="form-label" for="hours_before">
                    Enviar com antecedência de <span class="required">*</span>
                </label>
                <select id="hours_before" name="hours_before" class="form-select" required>
                    <?php
                    $selected_hours = $edit_sched
                        ? (int)round(((int)$edit_sched->lesson_date - (int)$edit_sched->send_at) / HOURSECS)
                        : 2;
                    foreach ([1 => '1 hora', 2 => '2 horas', 6 => '6 horas', 12 => '12 horas', 24 => '24 horas'] as $val => $label):
                    ?>
                    <option value="<?= $val ?>" <?= $selected_hours === $val ? 'selected' : '' ?>>
                        <?= $label ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Link do Curso -->
            <div class="form-group">
                <label class="form-label" for="link_aula">Link do Curso</label>
                <input type="url" id="link_aula" name="link_aula" class="form-input"
                       placeholder="https://..."
                       value="<?= htmlspecialchars($link_suggest, ENT_QUOTES) ?>">
                <p class="form-hint">Qualquer URL de acesso (Zoom, Meet, Teams, página do curso, etc). Preenchido automaticamente a partir do último agendamento.</p>
            </div>

            <!-- URL da Pesquisa de Satisfação (visível apenas para agendas tipo Fim) -->
            <div class="form-group" id="survey_url_group" style="display:none;">
                <label class="form-label" for="survey_url">URL da Pesquisa de Satisfação</label>
                <input type="url" id="survey_url" name="survey_url" class="form-input"
                       placeholder="https://forms.google.com/..."
                       value="<?= $edit_sched ? htmlspecialchars($edit_sched->survey_url ?? '', ENT_QUOTES) : '' ?>">
                <p class="form-hint">Link da pesquisa específica deste curso. Será substituída na variável <code>{link_pesquisa}</code>.</p>
            </div>

            <!-- Assunto do e-mail -->
            <div class="form-group">
                <label class="form-label" for="subject">
                    Assunto do E-mail <span class="required">*</span>
                </label>
                <input type="text" id="subject" name="subject" class="form-input" required
                       maxlength="255"
                       value="<?= $edit_sched ? htmlspecialchars($edit_sched->subject, ENT_QUOTES) : 'Lembrete: Sua aula {nome_curso}!' ?>">
            </div>

            <!-- Corpo do e-mail -->
            <div class="form-group">
                <label class="form-label" for="body">
                    Corpo do E-mail <span class="required">*</span>
                </label>
                <textarea id="body" name="body" class="form-textarea" required
                          rows="10"><?php
if ($edit_sched) {
    echo htmlspecialchars($edit_sched->body, ENT_QUOTES);
} else {
    echo htmlspecialchars(
        "Olá, {nome_aluno}!\n\n" .
        "Sua próxima aula do curso {nome_curso} está agendada para {data_aula} às {hora_aula}.\n\n" .
        "Link do curso: {link_aula}\n\n" .
        "Se tiver dúvidas sobre o acesso, utilize o link abaixo para recuperar sua senha:\n" .
        "{link_esqueci_senha}\n\n" .
        "Bons estudos!",
        ENT_QUOTES
    );
}
?></textarea>
                <p class="form-hint">
                    Variáveis disponíveis: <code>{nome_aluno}</code>, <code>{nome_curso}</code>,
                    <code>{data_aula}</code>, <code>{hora_aula}</code>, <code>{link_aula}</code>,
                    <code>{link_pesquisa}</code>, <code>{login_moodle}</code>, <code>{link_esqueci_senha}</code>, <code>{logo_url}</code>
                    <br><small style="color:hsl(var(--muted-foreground));">O logo configurado em Configurações é inserido automaticamente no topo do e-mail.</small>
                </p>
            </div>

            <!-- Ações -->
            <div class="nc-form-actions" style="display:flex;gap:.75rem;justify-content:flex-end;">
                <a href="schedules.php" class="btn btn-outline">Cancelar</a>
                <button type="submit" class="btn btn-primary">
                    <?= $action === 'edit' ? 'Salvar Alterações' : 'Criar Agendamento' ?>
                </button>
            </div>
        </form>
    </div>

    <script>
    /**
     * Busca a sugestão de link de acesso para o curso selecionado via AJAX.
     * Faz GET para schedules.php?action=link_api&courseid=X
     */
    function fetchLinkSuggestion(courseId) {
        if (!courseId) return;
        fetch('schedules.php?action=link_api&courseid=' + encodeURIComponent(courseId))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.link_aula) {
                    document.getElementById('link_aula').value = data.link_aula;
                }
            })
            .catch(function() { /* silencioso */ });
    }

    // Mostrar campo survey_url quando editando agenda tipo 'end'.
    (function() {
        var editType = '<?= $edit_sched ? ($edit_sched->notification_type ?? 'lesson') : 'lesson' ?>';
        var surveyGroup = document.getElementById('survey_url_group');
        if (editType === 'end' && surveyGroup) {
            surveyGroup.style.display = '';
        }
    })();

    // Toggle entre modo único e recorrente.
    function toggleRecurring(isRecurring) {
        var singleGroup = document.getElementById('single_date_group');
        var recurGroup  = document.getElementById('recurring_group');
        var lessonInput = document.getElementById('lesson_date');

        if (isRecurring) {
            singleGroup.style.display = 'none';
            recurGroup.style.display = '';
            if (lessonInput) lessonInput.removeAttribute('required');
        } else {
            singleGroup.style.display = '';
            recurGroup.style.display = 'none';
            if (lessonInput) lessonInput.setAttribute('required', 'required');
        }
    }

    // Estilo visual para checkboxes de dias selecionados.
    document.querySelectorAll('.recurring-day-check').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var label = this.closest('.recurring-day-label');
            if (this.checked) {
                label.style.background = 'hsl(var(--primary))';
                label.style.color = 'hsl(var(--primary-foreground))';
                label.style.borderColor = 'hsl(var(--primary))';
            } else {
                label.style.background = '';
                label.style.color = '';
                label.style.borderColor = '';
            }
        });
    });
    </script>

    <?php
    require_once(__DIR__ . '/includes/footer.php');
    exit;
}

// ---------------------------------------------------------------------------
// GET: API de sugestão de link de acesso (chamado via fetch/JS)
// ---------------------------------------------------------------------------
if ($method === 'GET' && ($action === 'link_api' || $action === 'zoom_api')) {
    $courseid = isset($_GET['courseid']) ? (int)$_GET['courseid'] : 0;
    header('Content-Type: application/json; charset=utf-8');
    if ($courseid > 0) {
        $link = ScheduleManager::get_link_suggestion($courseid);
        echo json_encode(['link_aula' => $link]);
    } else {
        echo json_encode(['link_aula' => '']);
    }
    exit;
}

// ---------------------------------------------------------------------------
// GET: Log de um agendamento específico
// ---------------------------------------------------------------------------
if ($method === 'GET' && $action === 'log' && $id > 0) {
    $schedule = ScheduleManager::get($id);
    if (!$schedule) {
        header('Location: schedules.php?msg_error=' . urlencode('Agendamento não encontrado.'));
        exit;
    }
    $course = $DB->get_record('course', ['id' => $schedule->courseid]);

    // Para start/end, o log pode ter sido criado pelas Camadas 1/3 (schedule_id=NULL).
    // Nesse caso, buscar também por courseid + notification_type.
    $sched_type = $schedule->notification_type ?? 'lesson';
    if ($sched_type === 'start' || $sched_type === 'end') {
        $logs = array_values($DB->get_records_sql(
            "SELECT l.*, u.firstname, u.lastname, u.email
               FROM {" . NotifLog::TABLE . "} l
               JOIN {user} u ON u.id = l.userid
              WHERE (l.schedule_id = :sid OR (l.schedule_id IS NULL AND l.courseid = :cid AND l.notification_type = :ntype))
              ORDER BY l.timesent DESC",
            ['sid' => $id, 'cid' => $schedule->courseid, 'ntype' => $sched_type],
            0,
            200
        ));
    } else {
        $logs = array_values($DB->get_records_sql(
            "SELECT l.*, u.firstname, u.lastname, u.email
               FROM {" . NotifLog::TABLE . "} l
               JOIN {user} u ON u.id = l.userid
              WHERE l.schedule_id = :sid
              ORDER BY l.timesent DESC",
            ['sid' => $id],
            0,
            200
        ));
    }

    require_once(__DIR__ . '/includes/header.php');
    ?>

    <div class="page-header">
        <div style="display:flex;align-items:center;gap:.75rem;">
            <a href="schedules.php" class="btn btn-ghost btn-sm" style="padding:.3rem .5rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true">
                    <path d="M15 18l-6-6 6-6"/>
                </svg>
            </a>
            <div>
                <h1 class="page-title">Log do Agendamento #<?= (int)$id ?></h1>
                <p class="page-subtitle">
                    <?= $course ? htmlspecialchars($course->fullname, ENT_QUOTES) : '(curso não encontrado)' ?>
                    &mdash; <?= ['start' => 'Início', 'lesson' => 'Aula', 'end' => 'Fim'][$sched_type] ?? $sched_type ?> em <?= date('d/m/Y H:i', (int)$schedule->lesson_date) ?>
                </p>
            </div>
        </div>
    </div>

    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Data/Hora</th>
                    <th>Aluno</th>
                    <th>E-mail</th>
                    <th>Origem</th>
                    <th>Status</th>
                    <th>Tentativas</th>
                    <th>Erro</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="7" style="text-align:center;color:hsl(var(--muted-foreground));padding:2rem 1rem;">
                        Nenhum disparo registrado para este agendamento.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="white-space:nowrap;"><?= date('d/m/Y H:i', (int)$log->timesent) ?></td>
                    <td><?= htmlspecialchars(trim($log->firstname . ' ' . $log->lastname), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($log->email, ENT_QUOTES) ?></td>
                    <td>
                        <?= $log->origin === 'manual'
                            ? '<span class="badge badge-manual">Manual</span>'
                            : '<span class="badge">Auto</span>' ?>
                    </td>
                    <td><?= sched_status_badge($log->status) ?></td>
                    <td style="text-align:center;"><?= (int)$log->attempts ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.8rem;color:hsl(var(--muted-foreground));"
                        title="<?= htmlspecialchars($log->last_error ?? '', ENT_QUOTES) ?>">
                        <?= htmlspecialchars($log->last_error ?? '—', ENT_QUOTES) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($logs)): ?>
            <tfoot>
                <tr>
                    <td colspan="7"><?= count($logs) ?> registro(s) exibido(s)</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <?php
    require_once(__DIR__ . '/includes/footer.php');
    exit;
}

// ---------------------------------------------------------------------------
// GET padrão: Listagem com paginação
// ---------------------------------------------------------------------------
$perpage      = 20;
$page         = max(0, (int)($_GET['page'] ?? 0));
$all_result   = ScheduleManager::get_all([], $page, $perpage);
$all          = $all_result['records'];
$total        = $all_result['total'];
$pages        = $total > 0 ? (int)ceil($total / $perpage) : 1;

$flash_success = $_GET['msg']       ?? null;
$flash_error   = $_GET['msg_error'] ?? null;

require_once(__DIR__ . '/includes/header.php');
?>

<!-- ======================================================================= -->
<!-- Cabeçalho da página                                                       -->
<!-- ======================================================================= -->
<div class="page-header">
    <div class="section-header" style="margin-bottom:0;">
        <div>
            <h1 class="page-title">Agendas</h1>
            <p class="page-subtitle">Gerencie os agendamentos de aula para disparo de notificações.</p>
        </div>
        <a href="schedules.php?action=create" class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <line x1="12" x2="12" y1="5" y2="19"/>
                <line x1="5" x2="19" y1="12" y2="12"/>
            </svg>
            Novo Agendamento
        </a>
    </div>
</div>

<!-- Mensagens flash -->
<?php if ($flash_success): ?>
<div class="alert alert-success mb-4" data-auto-dismiss="5000" role="alert">
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
<div class="alert alert-error mb-4" data-auto-dismiss="6000" role="alert">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
         stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="10"/>
        <line x1="15" x2="9" y1="9" y2="15"/>
        <line x1="9" x2="15" y1="9" y2="15"/>
    </svg>
    <?= htmlspecialchars($flash_error, ENT_QUOTES) ?>
</div>
<?php endif; ?>

<!-- ======================================================================= -->
<!-- Tabela de agendamentos                                                     -->
<!-- ======================================================================= -->
<div class="table-wrapper">
    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Tipo</th>
                <th>Curso</th>
                <th>Data</th>
                <th>Envio Previsto</th>
                <th>Link do Curso</th>
                <th>Status</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($all)): ?>
            <tr>
                <td colspan="8" style="text-align:center;color:hsl(var(--muted-foreground));padding:2rem 1rem;">
                    Nenhum agendamento cadastrado. <a href="schedules.php?action=create">Criar o primeiro</a>.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($all as $sched): ?>
            <?php
                $course = $DB->get_record('course', ['id' => $sched->courseid]);
                $courseName  = $course ? htmlspecialchars($course->fullname, ENT_QUOTES) : '(curso removido)';
                $link_short  = $sched->link_aula
                    ? htmlspecialchars(mb_strimwidth($sched->link_aula, 0, 35, '…'), ENT_QUOTES)
                    : '<span style="color:hsl(var(--muted-foreground));">—</span>';
                $is_pending   = $sched->status === 'pending';
                $sched_type   = $sched->notification_type ?? 'lesson';
                $is_auto_type = in_array($sched_type, ['start', 'end'], true);
            ?>
            <tr>
                <td style="color:hsl(var(--muted-foreground));font-size:.8rem;"><?= (int)$sched->id ?></td>
                <td><?= sched_type_badge($sched_type) ?></td>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                    title="<?= $courseName ?>">
                    <a href="<?= $CFG->wwwroot ?>/course/view.php?id=<?= (int)$sched->courseid ?>"
                       target="_blank" rel="noopener noreferrer">
                        <?= $courseName ?>
                    </a>
                </td>
                <td style="white-space:nowrap;"><?= date('d/m/Y H:i', (int)$sched->lesson_date) ?></td>
                <td style="white-space:nowrap;"><?= date('d/m/Y H:i', (int)$sched->send_at) ?></td>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?php if ($sched->link_aula): ?>
                        <a href="<?= htmlspecialchars($sched->link_aula, ENT_QUOTES) ?>"
                           target="_blank" rel="noopener noreferrer"
                           title="<?= htmlspecialchars($sched->link_aula, ENT_QUOTES) ?>">
                            <?= $link_short ?>
                        </a>
                    <?php else: ?>
                        <span style="color:hsl(var(--muted-foreground));">—</span>
                    <?php endif; ?>
                </td>
                <td><?= sched_status_badge($sched->status) ?></td>
                <td>
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                        <!-- Editar (apenas pending) -->
                        <?php if ($is_pending): ?>
                        <a href="schedules.php?action=edit&id=<?= (int)$sched->id ?>"
                           class="btn btn-outline btn-sm">Editar</a>
                        <?php endif; ?>

                        <!-- Cancelar (apenas pending) -->
                        <?php if ($is_pending): ?>
                        <form method="post" action="schedules.php?action=cancel&id=<?= (int)$sched->id ?>"
                              style="display:inline;">
                            <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                            <button type="submit" class="btn btn-destructive btn-sm"
                                    data-confirm="Tem certeza que deseja cancelar este agendamento?">
                                Cancelar
                            </button>
                        </form>
                        <?php endif; ?>

                        <!-- Enviar Reforço (qualquer status) -->
                        <form method="post" action="schedules.php?action=reinforce&id=<?= (int)$sched->id ?>"
                              style="display:inline;">
                            <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                            <button type="submit" class="btn btn-outline btn-sm"
                                    style="color:hsl(var(--primary));border-color:hsl(var(--primary));"
                                    data-confirm="Enviar e-mail de reforço para todos os alunos deste agendamento?">
                                Reforço
                            </button>
                        </form>

                        <!-- Ver Log -->
                        <a href="schedules.php?action=log&id=<?= (int)$sched->id ?>"
                           class="btn btn-ghost btn-sm">Log</a>

                        <!-- Excluir -->
                        <form method="post" action="schedules.php?action=delete&id=<?= (int)$sched->id ?>"
                              style="display:inline;">
                            <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                            <button type="submit" class="btn btn-destructive btn-sm"
                                    data-confirm="Tem certeza que deseja excluir este agendamento? Esta ação não pode ser desfeita.">
                                Excluir
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
        <?php if ($total > 0): ?>
        <tfoot>
            <tr>
                <td colspan="8">
                    <?= $total ?> agendamento(s) no total
                    &mdash; página <?= $page + 1 ?> de <?= $pages ?>
                </td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<!-- ======================================================================= -->
<!-- Paginação                                                                  -->
<!-- ======================================================================= -->
<?php if ($pages > 1): ?>
<div style="display:flex;align-items:center;justify-content:center;gap:.5rem;margin-top:1.5rem;">
    <?php if ($page > 0): ?>
        <a href="schedules.php?page=<?= $page - 1 ?>" class="btn btn-outline btn-sm">&larr; Anterior</a>
    <?php else: ?>
        <button class="btn btn-outline btn-sm" disabled>&larr; Anterior</button>
    <?php endif; ?>

    <?php for ($p = 0; $p < $pages; $p++): ?>
        <?php if ($p === $page): ?>
            <span class="btn btn-primary btn-sm" aria-current="page"><?= $p + 1 ?></span>
        <?php else: ?>
            <a href="schedules.php?page=<?= $p ?>" class="btn btn-outline btn-sm"><?= $p + 1 ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page < $pages - 1): ?>
        <a href="schedules.php?page=<?= $page + 1 ?>" class="btn btn-outline btn-sm">Próxima &rarr;</a>
    <?php else: ?>
        <button class="btn btn-outline btn-sm" disabled>Próxima &rarr;</button>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once(__DIR__ . '/includes/footer.php'); ?>
