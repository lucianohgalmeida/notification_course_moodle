<?php
/**
 * Histórico — Notificações de Curso
 *
 * Exibe o log agrupado por curso + tipo, com modal de detalhes por e-mail.
 */

require_once(__DIR__ . '/bootstrap.php');

global $DB;

// ---------------------------------------------------------------------------
// AJAX: Retorna disparos individuais de um grupo (curso + tipo)
// ---------------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'dispatches') {
    $courseid = (int)($_GET['courseid'] ?? 0);
    $type     = trim($_GET['type'] ?? '');

    if (!$courseid || !$type) {
        echo json_encode(['html' => '<p>Parâmetros inválidos.</p>']);
        exit;
    }

    $dispatches = NotifLog::get_dispatches_for_group($courseid, $type);

    if (empty($dispatches)) {
        echo json_encode(['html' => '<p style="text-align:center;color:hsl(var(--muted-foreground));padding:1rem;">Nenhum disparo encontrado.</p>']);
        exit;
    }

    $html = '<table class="table" style="margin:0;">';
    $html .= '<thead><tr>';
    $html .= '<th>Data/Hora</th><th>Aluno</th><th>E-mail</th><th>Origem</th><th>Status</th><th style="text-align:center;">Tent.</th><th>Erro</th>';
    $html .= '</tr></thead><tbody>';

    $status_map = [
        'success'   => '<span class="badge badge-sent">Enviado</span>',
        'failed'    => '<span class="badge badge-failed">Falhou</span>',
        'abandoned' => '<span class="badge badge-abandoned">Abandonado</span>',
    ];

    foreach ($dispatches as $d) {
        $date   = date('d/m/Y H:i', (int)$d->timesent);
        $name   = htmlspecialchars(trim($d->firstname . ' ' . $d->lastname), ENT_QUOTES);
        $email  = htmlspecialchars($d->email, ENT_QUOTES);
        $origin = $d->origin === 'manual'
            ? '<span class="badge badge-manual">Manual</span>'
            : '<span class="badge">Auto</span>';
        $status = $status_map[$d->status] ?? htmlspecialchars($d->status, ENT_QUOTES);
        $error  = htmlspecialchars($d->last_error ?? '—', ENT_QUOTES);

        $html .= '<tr>';
        $html .= '<td style="white-space:nowrap;font-size:.82rem;">' . $date . '</td>';
        $html .= '<td>' . $name . '</td>';
        $html .= '<td style="font-size:.82rem;">' . $email . '</td>';
        $html .= '<td>' . $origin . '</td>';
        $html .= '<td>' . $status . '</td>';
        $html .= '<td style="text-align:center;">' . (int)$d->attempts . '</td>';
        $html .= '<td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.8rem;" title="' . $error . '">' . $error . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';
    $html .= '<p style="font-size:.8rem;color:hsl(var(--muted-foreground));padding:.5rem 0 0;margin:0;">'
           . count($dispatches) . ' disparo(s)</p>';

    echo json_encode(['html' => $html]);
    exit;
}

// ---------------------------------------------------------------------------
// POST: Excluir histórico de um grupo (curso + tipo)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_group') {
    require_sesskey();
    $del_courseid = (int)($_POST['courseid'] ?? 0);
    $del_type     = trim($_POST['notification_type'] ?? '');
    if ($del_courseid && $del_type) {
        $DB->delete_records(NotifLog::TABLE, [
            'courseid'          => $del_courseid,
            'notification_type' => $del_type,
        ]);
        header('Location: history.php?msg=' . urlencode('Histórico excluído com sucesso.'));
    } else {
        header('Location: history.php?msg_error=' . urlencode('Parâmetros inválidos.'));
    }
    exit;
}

// ---------------------------------------------------------------------------
// Parâmetros de filtro
// ---------------------------------------------------------------------------
$filter_courseid = isset($_GET['courseid'])           ? (int)$_GET['courseid']                : 0;
$filter_type     = isset($_GET['notification_type'])  ? trim($_GET['notification_type'])      : '';
$filter_origin   = isset($_GET['origin'])             ? trim($_GET['origin'])                 : '';
$filter_status   = isset($_GET['status'])             ? trim($_GET['status'])                 : '';
$filter_date_from = isset($_GET['date_from']) && $_GET['date_from'] !== ''
    ? strtotime($_GET['date_from'] . ' 00:00:00') : 0;
$filter_date_to   = isset($_GET['date_to']) && $_GET['date_to'] !== ''
    ? strtotime($_GET['date_to'] . ' 23:59:59') : 0;

$filters = [];
if ($filter_courseid)  $filters['courseid']           = $filter_courseid;
if ($filter_type)      $filters['notification_type']  = $filter_type;
if ($filter_origin)    $filters['origin']             = $filter_origin;
if ($filter_status)    $filters['status']             = $filter_status;
if ($filter_date_from) $filters['date_from']          = $filter_date_from;
if ($filter_date_to)   $filters['date_to']            = $filter_date_to;

// ---------------------------------------------------------------------------
// Exportação CSV (mantém o export detalhado)
// ---------------------------------------------------------------------------
if (isset($_GET['format']) && $_GET['format'] === 'csv') {
    NotifLog::export_csv($filters);
    exit;
}

// ---------------------------------------------------------------------------
// Paginação
// ---------------------------------------------------------------------------
$perpage = 20;
$page    = max(0, (int)($_GET['page'] ?? 0));

$result  = NotifLog::get_history_grouped($filters, $page, $perpage);
$records = $result['records'];
$total   = $result['total'];
$pages   = $total > 0 ? (int)ceil($total / $perpage) : 1;

// ---------------------------------------------------------------------------
// Cursos para dropdown de filtro
// ---------------------------------------------------------------------------
$courses_for_filter = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.fullname
       FROM {course} c
       JOIN {" . NotifLog::TABLE . "} l ON l.courseid = c.id
      ORDER BY c.fullname ASC"
);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function hist_type_label(string $type): string {
    return ['start' => 'Início', 'lesson' => 'Aula', 'end' => 'Conclusão'][$type]
        ?? htmlspecialchars($type, ENT_QUOTES);
}

function hist_type_badge(string $type): string {
    $map = [
        'start'  => ['class' => 'badge-type-start',  'label' => 'Início'],
        'lesson' => ['class' => 'badge-type-lesson', 'label' => 'Aula'],
        'end'    => ['class' => 'badge-type-end',    'label' => 'Conclusão'],
    ];
    $entry = $map[$type] ?? ['class' => 'badge', 'label' => htmlspecialchars($type, ENT_QUOTES)];
    return '<span class="badge ' . $entry['class'] . '">' . $entry['label'] . '</span>';
}

function hist_filter_qs(array $extra = []): string {
    global $filter_courseid, $filter_type, $filter_origin, $filter_status;
    global $filter_date_from, $filter_date_to;

    $params = [];
    if ($filter_courseid)  $params['courseid']           = $filter_courseid;
    if ($filter_type)      $params['notification_type']  = $filter_type;
    if ($filter_origin)    $params['origin']             = $filter_origin;
    if ($filter_status)    $params['status']             = $filter_status;
    if ($filter_date_from) $params['date_from']          = date('Y-m-d', $filter_date_from);
    if ($filter_date_to)   $params['date_to']            = date('Y-m-d', $filter_date_to);

    return http_build_query(array_merge($params, $extra));
}

require_once(__DIR__ . '/includes/header.php');

$flash_msg = $_GET['msg'] ?? '';
$flash_error = $_GET['msg_error'] ?? '';
?>

<?php if ($flash_msg): ?>
<div class="alert alert-success mb-6" data-auto-dismiss="5000" role="alert">
    <?= htmlspecialchars($flash_msg, ENT_QUOTES) ?>
</div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div class="alert alert-error mb-6" data-auto-dismiss="7000" role="alert">
    <?= htmlspecialchars($flash_error, ENT_QUOTES) ?>
</div>
<?php endif; ?>

<!-- ======================================================================= -->
<!-- Cabeçalho da página                                                       -->
<!-- ======================================================================= -->
<div class="page-header">
    <div class="section-header" style="margin-bottom:0;">
        <div>
            <h1 class="page-title">Histórico de Notificações</h1>
            <p class="page-subtitle">Disparos agrupados por curso e tipo. Clique nos disparos para ver detalhes.</p>
        </div>
        <a href="history.php?<?= hist_filter_qs(['format' => 'csv']) ?>"
           class="btn btn-outline">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" x2="12" y1="15" y2="3"/>
            </svg>
            Exportar CSV
        </a>
    </div>
</div>

<!-- ======================================================================= -->
<!-- Barra de filtros                                                           -->
<!-- ======================================================================= -->
<div class="card mb-6">
    <form method="get" action="history.php"
          style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;align-items:flex-end;">

        <!-- Curso -->
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="f_courseid">Curso</label>
            <select id="f_courseid" name="courseid" class="form-select">
                <option value="">Todos os cursos</option>
                <?php foreach ($courses_for_filter as $c): ?>
                <option value="<?= (int)$c->id ?>"
                    <?= $filter_courseid === (int)$c->id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c->fullname, ENT_QUOTES) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Tipo de notificação -->
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="f_type">Tipo</label>
            <select id="f_type" name="notification_type" class="form-select">
                <option value="">Todos</option>
                <option value="start"  <?= $filter_type === 'start'  ? 'selected' : '' ?>>Início</option>
                <option value="lesson" <?= $filter_type === 'lesson' ? 'selected' : '' ?>>Aula</option>
                <option value="end"    <?= $filter_type === 'end'    ? 'selected' : '' ?>>Conclusão</option>
            </select>
        </div>

        <!-- Data de -->
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="f_date_from">Data de</label>
            <input type="date" id="f_date_from" name="date_from" class="form-input"
                   value="<?= $filter_date_from ? date('Y-m-d', $filter_date_from) : '' ?>">
        </div>

        <!-- Data até -->
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="f_date_to">Data até</label>
            <input type="date" id="f_date_to" name="date_to" class="form-input"
                   value="<?= $filter_date_to ? date('Y-m-d', $filter_date_to) : '' ?>">
        </div>

        <!-- Botões -->
        <div style="display:flex;gap:.5rem;align-items:flex-end;">
            <button type="submit" class="btn btn-primary" style="flex:1;">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" x2="16.65" y1="21" y2="16.65"/>
                </svg>
                Filtrar
            </button>
            <a href="history.php" class="btn btn-outline">Limpar</a>
        </div>

    </form>
</div>

<!-- ======================================================================= -->
<!-- Resumo dos resultados                                                      -->
<!-- ======================================================================= -->
<p style="font-size:.875rem;color:hsl(var(--muted-foreground));margin-bottom:.75rem;">
    <?= number_format($total, 0, ',', '.') ?> grupo(s) encontrado(s)
    <?= !empty($filters) ? ' com os filtros aplicados' : '' ?>
    &mdash; página <?= $page + 1 ?> de <?= $pages ?>
</p>

<!-- ======================================================================= -->
<!-- Tabela de histórico agrupado                                               -->
<!-- ======================================================================= -->
<div class="table-wrapper">
    <table class="table">
        <thead>
            <tr>
                <th>Curso</th>
                <th>Tipo</th>
                <th>Último Envio</th>
                <th style="text-align:center;">Enviados</th>
                <th style="text-align:center;">Falhas</th>
                <th style="text-align:center;">Total</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($records)): ?>
            <tr>
                <td colspan="7" style="text-align:center;color:hsl(var(--muted-foreground));padding:2.5rem 1rem;">
                    Nenhum registro encontrado com os filtros selecionados.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($records as $row): ?>
            <tr>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                    title="<?= htmlspecialchars($row->course_fullname, ENT_QUOTES) ?>">
                    <?= htmlspecialchars($row->course_fullname, ENT_QUOTES) ?>
                </td>
                <td><?= hist_type_badge($row->notification_type) ?></td>
                <td style="white-space:nowrap;font-size:.82rem;">
                    <?= date('d/m/Y H:i', (int)$row->last_sent) ?>
                </td>
                <td style="text-align:center;">
                    <span style="color:hsl(142 71% 45%);font-weight:600;"><?= (int)$row->total_success ?></span>
                </td>
                <td style="text-align:center;">
                    <?php if ((int)$row->total_failed > 0 || (int)$row->total_abandoned > 0): ?>
                        <span style="color:hsl(0 84% 60%);font-weight:600;"><?= (int)$row->total_failed + (int)$row->total_abandoned ?></span>
                    <?php else: ?>
                        <span style="color:hsl(var(--muted-foreground));">0</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;font-weight:600;"><?= (int)$row->total_dispatches ?></td>
                <td>
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                        <button type="button" class="btn btn-outline btn-sm nc-view-dispatches"
                                data-courseid="<?= (int)$row->courseid ?>"
                                data-type="<?= htmlspecialchars($row->notification_type, ENT_QUOTES) ?>"
                                data-course-name="<?= htmlspecialchars($row->course_fullname, ENT_QUOTES) ?>"
                                data-type-label="<?= hist_type_label($row->notification_type) ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            Ver
                        </button>
                        <form method="post" action="history.php" style="display:inline;">
                            <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                            <input type="hidden" name="action" value="delete_group">
                            <input type="hidden" name="courseid" value="<?= (int)$row->courseid ?>">
                            <input type="hidden" name="notification_type" value="<?= htmlspecialchars($row->notification_type, ENT_QUOTES) ?>">
                            <button type="submit" class="btn btn-destructive btn-sm"
                                    data-confirm="Excluir <?= (int)$row->total_dispatches ?> disparo(s) de <?= htmlspecialchars($row->course_fullname, ENT_QUOTES) ?> (<?= hist_type_label($row->notification_type) ?>)?">
                                Excluir
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ======================================================================= -->
<!-- Paginação                                                                  -->
<!-- ======================================================================= -->
<?php if ($pages > 1): ?>
<div style="display:flex;align-items:center;justify-content:center;gap:.5rem;margin-top:1.5rem;flex-wrap:wrap;">

    <?php if ($page > 0): ?>
        <a href="history.php?<?= hist_filter_qs(['page' => $page - 1]) ?>"
           class="btn btn-outline btn-sm">&larr; Anterior</a>
    <?php else: ?>
        <button class="btn btn-outline btn-sm" disabled>&larr; Anterior</button>
    <?php endif; ?>

    <?php
    $range_start = max(0, min($page - 3, $pages - 7));
    $range_end   = min($pages - 1, $range_start + 6);
    for ($p = $range_start; $p <= $range_end; $p++):
    ?>
        <?php if ($p === $page): ?>
            <span class="btn btn-primary btn-sm" aria-current="page"><?= $p + 1 ?></span>
        <?php else: ?>
            <a href="history.php?<?= hist_filter_qs(['page' => $p]) ?>"
               class="btn btn-outline btn-sm"><?= $p + 1 ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page < $pages - 1): ?>
        <a href="history.php?<?= hist_filter_qs(['page' => $page + 1]) ?>"
           class="btn btn-outline btn-sm">Próxima &rarr;</a>
    <?php else: ?>
        <button class="btn btn-outline btn-sm" disabled>Próxima &rarr;</button>
    <?php endif; ?>

</div>
<?php endif; ?>

<!-- ======================================================================= -->
<!-- Modal de disparos (Bootstrap 4 via Moodle RequireJS)                       -->
<!-- ======================================================================= -->
<script>
(function() {
    var modalReady = false;
    var _$ = null;

    function initDispatchModal() {
        if (typeof require === 'undefined') {
            setTimeout(initDispatchModal, 100);
            return;
        }

        require(['jquery', 'theme_boost/bootstrap/modal'], function($) {
            _$ = $;

            var modalHTML = '<div class="modal fade" id="ncDispatchModal" tabindex="-1" role="dialog" aria-hidden="true">'
                + '<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" role="document">'
                + '<div class="modal-content" style="background:hsl(var(--card));color:hsl(var(--foreground));border:1px solid hsl(var(--border));">'
                + '<div class="modal-header" style="border-bottom:1px solid hsl(var(--border));">'
                + '<h5 class="modal-title" id="ncDispatchTitle">Disparos</h5>'
                + '<button type="button" class="close" data-dismiss="modal" aria-label="Fechar" style="color:hsl(var(--foreground));"><span aria-hidden="true">&times;</span></button>'
                + '</div>'
                + '<div class="modal-body" id="ncDispatchBody" style="padding:0;max-height:70vh;overflow-y:auto;">'
                + '<p style="text-align:center;padding:2rem;">Carregando...</p>'
                + '</div>'
                + '<div class="modal-footer" style="border-top:1px solid hsl(var(--border));">'
                + '<button type="button" class="btn btn-secondary" data-dismiss="modal">Fechar</button>'
                + '</div></div></div></div>';

            $('body').append(modalHTML);
            modalReady = true;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDispatchModal);
    } else {
        initDispatchModal();
    }

    // Delegate clicks on "Ver e-mails" buttons.
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.nc-view-dispatches');
        if (!btn || !modalReady || !_$) return;

        var courseid   = btn.dataset.courseid;
        var type       = btn.dataset.type;
        var courseName = btn.dataset.courseName;
        var typeLabel  = btn.dataset.typeLabel;

        document.getElementById('ncDispatchTitle').textContent =
            courseName + ' — ' + typeLabel;
        document.getElementById('ncDispatchBody').innerHTML =
            '<p style="text-align:center;padding:2rem;color:hsl(var(--muted-foreground));">Carregando...</p>';

        _$('#ncDispatchModal').modal('show');

        fetch('history.php?ajax=dispatches&courseid=' + encodeURIComponent(courseid) + '&type=' + encodeURIComponent(type))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                document.getElementById('ncDispatchBody').innerHTML = data.html || '<p>Erro ao carregar.</p>';
            })
            .catch(function() {
                document.getElementById('ncDispatchBody').innerHTML = '<p style="text-align:center;padding:2rem;color:hsl(0 84% 60%);">Erro ao carregar os disparos.</p>';
            });
    });
})();
</script>

<?php require_once(__DIR__ . '/includes/footer.php'); ?>
