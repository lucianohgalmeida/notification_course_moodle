<?php
/**
 * HTML footer template.
 *
 * Closes the layout wrappers opened by includes/header.php and outputs
 * the Moodle standard footer (which includes the theme footer).
 */

defined('NOTIFCOURSE_INTERNAL') || die('Acesso direto não permitido.');

global $OUTPUT;
?>

    </div><!-- /.nc-main -->
</div><!-- /.nc-app -->

<!-- Alpine.js for inline interactions -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

<script>
    // Auto-dismiss flash / alert messages after 5s.
    document.addEventListener('DOMContentLoaded', function () {
        var alerts = document.querySelectorAll('[data-auto-dismiss]');
        alerts.forEach(function (el) {
            var delay = parseInt(el.dataset.autoDismiss, 10) || 5000;
            setTimeout(function () {
                el.style.transition = 'opacity 400ms ease';
                el.style.opacity    = '0';
                setTimeout(function () { el.remove(); }, 420);
            }, delay);
        });
    });

    // Confirm modal for destructive actions (replaces window.confirm).
    // Uses Bootstrap 4 + jQuery (Moodle's stack).
    (function () {
        var pendingEl = null;
        var modalReady = false;
        var _$ = null; // jQuery reference stored after require() loads.

        // Block form submission for [data-confirm] elements right away (capture phase).
        document.addEventListener('click', function (e) {
            var el = e.target.closest('[data-confirm]');
            if (!el) return;

            // If confirm was already approved (flag set), let it through.
            if (el.dataset.ncConfirmed === 'true') {
                delete el.dataset.ncConfirmed;
                return;
            }

            e.preventDefault();
            e.stopImmediatePropagation();

            if (!modalReady || !_$) {
                // Fallback: confirm nativo quando Bootstrap modal não carregou.
                var msg = el.dataset.confirm || 'Tem certeza que deseja continuar?';
                if (window.confirm(msg)) {
                    var form = el.closest('form');
                    if (form) {
                        form.submit();
                    } else if (el.tagName === 'A') {
                        window.location.href = el.href;
                    }
                }
                return;
            }

            var msgEl = document.getElementById('ncConfirmMsg');
            var okBtn = document.getElementById('ncConfirmOk');
            var msg = el.dataset.confirm || 'Tem certeza que deseja continuar?';
            msgEl.textContent = msg;

            // Style OK button based on action type (destructive = red).
            var isDestructive = el.classList.contains('btn-destructive') || el.classList.contains('btn-danger');
            okBtn.className = isDestructive ? 'btn btn-danger' : 'btn btn-primary';
            okBtn.textContent = isDestructive ? 'Sim, excluir' : 'Confirmar';

            pendingEl = el;
            _$('#ncConfirmModal').modal('show');
        }, true);

        // Load jQuery + Bootstrap modal via Moodle's RequireJS.
        function initModal() {
            if (typeof require === 'undefined') {
                setTimeout(initModal, 100);
                return;
            }

            require(['jquery', 'theme_boost/bootstrap/modal'], function ($) {
                _$ = $;

                var modalHTML = '<div class="modal fade" id="ncConfirmModal" tabindex="-1" role="dialog" aria-hidden="true">'
                    + '<div class="modal-dialog modal-dialog-centered" role="document">'
                    + '<div class="modal-content">'
                    + '<div class="modal-header">'
                    + '<h5 class="modal-title">Confirmação</h5>'
                    + '<button type="button" class="close" data-dismiss="modal" aria-label="Fechar"><span aria-hidden="true">&times;</span></button>'
                    + '</div>'
                    + '<div class="modal-body"><p id="ncConfirmMsg"></p></div>'
                    + '<div class="modal-footer">'
                    + '<button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>'
                    + '<button type="button" class="btn btn-primary" id="ncConfirmOk">Confirmar</button>'
                    + '</div></div></div></div>';
                $('body').append(modalHTML);
                modalReady = true;

                $(document).on('click', '#ncConfirmOk', function () {
                    $('#ncConfirmModal').modal('hide');
                    if (!pendingEl) return;
                    // Submit the form directly instead of re-clicking the button,
                    // to avoid the block listener that was added on the first click.
                    var form = pendingEl.closest('form');
                    if (form) {
                        form.submit();
                    } else if (pendingEl.tagName === 'A') {
                        window.location.href = pendingEl.href;
                    }
                    pendingEl = null;
                });
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initModal);
        } else {
            initModal();
        }
    })();
</script>

<?php echo $OUTPUT->footer(); ?>
