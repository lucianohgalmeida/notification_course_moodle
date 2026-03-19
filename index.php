<?php
/**
 * Dashboard — Notificações de Curso
 *
 * Exibe estatísticas gerais, próximos envios agendados e os últimos disparos
 * registrados no log.
 */

require_once(__DIR__ . '/bootstrap.php');

global $DB, $CFG;

// ---------------------------------------------------------------------------
// Dados do dashboard
// ---------------------------------------------------------------------------
$stats    = NotifLog::get_dashboard_stats();
$upcoming = ScheduleManager::get_upcoming(5);
$recent   = NotifLog::get_recent(10);

// Alerta se houver falhas ou abandonos pendentes
$has_alert = ($stats['failed'] > 0 || $stats['abandoned'] > 0);

// ---------------------------------------------------------------------------
// Helper: badge de status
// ---------------------------------------------------------------------------
function notifcourse_status_badge(string $status): string {
    $map = [
        'pending'   => ['class' => 'badge-pending',   'label' => 'Pendente'],
        'sent'      => ['class' => 'badge-sent',      'label' => 'Enviado'],
        'success'   => ['class' => 'badge-sent',      'label' => 'Enviado'],
        'failed'    => ['class' => 'badge-failed',    'label' => 'Falhou'],
        'abandoned' => ['class' => 'badge-abandoned', 'label' => 'Abandonado'],
        'cancelled' => ['class' => 'badge-cancelled', 'label' => 'Cancelado'],
        'manual'    => ['class' => 'badge-manual',    'label' => 'Manual'],
        'auto'      => ['class' => 'badge',           'label' => 'Auto'],
    ];
    $entry = $map[$status] ?? ['class' => 'badge', 'label' => htmlspecialchars($status, ENT_QUOTES)];
    return '<span class="badge ' . $entry['class'] . '">' . $entry['label'] . '</span>';
}

// ---------------------------------------------------------------------------
// Helper: label legível para tipo de notificação
// ---------------------------------------------------------------------------
function notifcourse_type_label(string $type): string {
    $map = [
        'start'  => 'Início',
        'lesson' => 'Aula',
        'end'    => 'Conclusão',
    ];
    return $map[$type] ?? htmlspecialchars($type, ENT_QUOTES);
}

// ---------------------------------------------------------------------------
// Helper: label legível para origem
// ---------------------------------------------------------------------------
function notifcourse_origin_label(string $origin): string {
    $map = [
        'auto'   => 'Automático',
        'manual' => 'Manual',
    ];
    return $map[$origin] ?? htmlspecialchars($origin, ENT_QUOTES);
}

require_once(__DIR__ . '/includes/header.php');
?>

<!-- ======================================================================= -->
<!-- Cabeçalho da página                                                       -->
<!-- ======================================================================= -->
<div class="page-header">
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle">Visão geral das notificações enviadas e agendadas.</p>
</div>

<!-- ======================================================================= -->
<!-- Banner de alerta — falhas ou abandonos pendentes                          -->
<!-- ======================================================================= -->
<?php if ($has_alert): ?>
<div class="alert alert-error mb-6" data-auto-dismiss="8000" role="alert">
    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
         stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;margin-top:2px">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" x2="12" y1="8" y2="12"/>
        <line x1="12" x2="12.01" y1="16" y2="16"/>
    </svg>
    <div>
        <strong>Atenção:</strong>
        <?php if ($stats['failed'] > 0): ?>
            Há <strong><?= (int)$stats['failed'] ?></strong> notificação(ões) com falha aguardando reenvio.
        <?php endif; ?>
        <?php if ($stats['abandoned'] > 0): ?>
            <?= $stats['failed'] > 0 ? ' e ' : '' ?>
            <strong><?= (int)$stats['abandoned'] ?></strong> notificação(ões) foram abandonadas após esgotar as tentativas.
        <?php endif; ?>
        <a href="history.php?status=failed" class="ml-2 underline font-semibold">Ver Histórico</a>
    </div>
</div>
<?php endif; ?>

<!-- ======================================================================= -->
<!-- Cards de estatísticas                                                      -->
<!-- ======================================================================= -->
<div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:1rem; margin-bottom:2rem;">

    <!-- Enviados Hoje -->
    <div class="card" style="display:flex;flex-direction:column;gap:.5rem;">
        <p class="page-subtitle" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;">Enviados Hoje</p>
        <p style="font-size:2.25rem;font-weight:700;color:hsl(var(--primary));line-height:1;">
            <?= (int)$stats['sent_today'] ?>
        </p>
        <p style="font-size:.8rem;color:hsl(var(--muted-foreground));">notificações enviadas hoje</p>
    </div>

    <!-- Enviados na Semana -->
    <div class="card" style="display:flex;flex-direction:column;gap:.5rem;">
        <p class="page-subtitle" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;">Enviados na Semana</p>
        <p style="font-size:2.25rem;font-weight:700;color:hsl(142.1 76.2% 36.3%);line-height:1;">
            <?= (int)$stats['sent_week'] ?>
        </p>
        <p style="font-size:.8rem;color:hsl(var(--muted-foreground));">últimos 7 dias</p>
    </div>

    <!-- Com Falha -->
    <div class="card" style="display:flex;flex-direction:column;gap:.5rem;">
        <p class="page-subtitle" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;">Com Falha</p>
        <p style="font-size:2.25rem;font-weight:700;color:hsl(0 84.2% 60.2%);line-height:1;">
            <?= (int)$stats['failed'] ?>
        </p>
        <p style="font-size:.8rem;color:hsl(var(--muted-foreground));">aguardando reenvio</p>
    </div>

    <!-- Abandonados -->
    <div class="card" style="display:flex;flex-direction:column;gap:.5rem;">
        <p class="page-subtitle" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;">Abandonados</p>
        <p style="font-size:2.25rem;font-weight:700;color:hsl(270 50% 50%);line-height:1;">
            <?= (int)$stats['abandoned'] ?>
        </p>
        <p style="font-size:.8rem;color:hsl(var(--muted-foreground));">tentativas esgotadas</p>
    </div>

</div>

<!-- ======================================================================= -->
<!-- Seção: Próximos Envios                                                    -->
<!-- ======================================================================= -->
<div class="section-header" style="margin-top:0;">
    <div>
        <h2 style="font-size:1.125rem;font-weight:700;color:hsl(var(--foreground));">Próximos Envios</h2>
        <p class="page-subtitle">Agendamentos pendentes de disparo automático.</p>
    </div>
    <a href="schedules.php" class="btn btn-outline btn-sm">Ver todas as agendas</a>
</div>

<div class="table-wrapper mb-8">
    <table class="table">
        <thead>
            <tr>
                <th>Curso</th>
                <th>Data da Aula</th>
                <th>Envio Previsto</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($upcoming)): ?>
            <tr>
                <td colspan="4" style="text-align:center;color:hsl(var(--muted-foreground));padding:2rem 1rem;">
                    Nenhum agendamento pendente encontrado.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($upcoming as $sched): ?>
            <tr>
                <td>
                    <a href="<?= $CFG->wwwroot ?>/course/view.php?id=<?= (int)$sched->courseid ?>"
                       target="_blank" rel="noopener noreferrer"
                       title="Abrir curso no Moodle">
                        <?= htmlspecialchars($sched->course_fullname ?? '(curso não encontrado)', ENT_QUOTES) ?>
                    </a>
                </td>
                <td><?= date('d/m/Y H:i', (int)$sched->lesson_date) ?></td>
                <td><?= date('d/m/Y H:i', (int)$sched->send_at) ?></td>
                <td><?= notifcourse_status_badge($sched->status) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ======================================================================= -->
<!-- Seção: Últimos Disparos                                                   -->
<!-- ======================================================================= -->
<div class="section-header">
    <div>
        <h2 style="font-size:1.125rem;font-weight:700;color:hsl(var(--foreground));">Últimos Disparos</h2>
        <p class="page-subtitle">As 10 notificações disparadas mais recentemente.</p>
    </div>
    <a href="history.php" class="btn btn-outline btn-sm">Ver histórico completo</a>
</div>

<div class="table-wrapper">
    <table class="table">
        <thead>
            <tr>
                <th>Data</th>
                <th>Curso</th>
                <th>Tipo</th>
                <th>Aluno</th>
                <th>Origem</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($recent)): ?>
            <tr>
                <td colspan="6" style="text-align:center;color:hsl(var(--muted-foreground));padding:2rem 1rem;">
                    Nenhuma notificação registrada ainda.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($recent as $log): ?>
            <tr>
                <td style="white-space:nowrap;"><?= date('d/m/Y H:i', (int)$log->timesent) ?></td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                    title="<?= htmlspecialchars($log->course_fullname, ENT_QUOTES) ?>">
                    <a href="<?= $CFG->wwwroot ?>/course/view.php?id=<?= (int)$log->courseid ?>"
                       target="_blank" rel="noopener noreferrer">
                        <?= htmlspecialchars($log->course_fullname, ENT_QUOTES) ?>
                    </a>
                </td>
                <td><?= notifcourse_type_label($log->notification_type) ?></td>
                <td style="white-space:nowrap;">
                    <?= htmlspecialchars(trim($log->firstname . ' ' . $log->lastname), ENT_QUOTES) ?>
                </td>
                <td>
                    <?php if ($log->origin === 'manual'): ?>
                        <span class="badge badge-manual">Manual</span>
                    <?php else: ?>
                        <span class="badge">Auto</span>
                    <?php endif; ?>
                </td>
                <td><?= notifcourse_status_badge($log->status) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once(__DIR__ . '/includes/footer.php'); ?>
