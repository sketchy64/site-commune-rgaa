/**
 * Site Commune RGAA - JavaScript du Module Backend TYPO3
 * Conforme Content Security Policy (CSP) sans gestionnaire d'événement inline.
 */
(function () {
    'use strict';

    function ensureAncestorsChecked(checkbox) {
        let parentKey = checkbox.getAttribute('data-parent');
        while (parentKey) {
            const parentCheckbox = document.querySelector('.rgaa-tree-checkbox[data-key="' + parentKey + '"]');
            if (!parentCheckbox) {
                break;
            }
            parentCheckbox.checked = true;
            parentKey = parentCheckbox.getAttribute('data-parent');
        }
    }

    document.addEventListener('click', function (event) {
        // 1. Navigation par onglets (Module Administration)
        const tabBtn = event.target.closest('.rgaa-tab-btn');
        if (tabBtn) {
            event.preventDefault();

            const targetId = tabBtn.getAttribute('data-target');
            if (!targetId) {
                return;
            }

            const container = tabBtn.closest('.rgaa-backend-wrapper');
            if (!container) {
                return;
            }

            const buttons = container.querySelectorAll('.rgaa-tab-btn');
            buttons.forEach(function (b) {
                b.classList.remove('active');
            });

            const panels = container.querySelectorAll('.rgaa-tab-panel');
            panels.forEach(function (p) {
                p.classList.remove('active');
            });

            tabBtn.classList.add('active');
            const targetPanel = container.querySelector('#' + targetId);
            if (targetPanel) {
                targetPanel.classList.add('active');
            }
            return;
        }

        // 2. Boutons "Tous / Aucun" de l'arborescence (Étape 4 du Wizard)
        const treeBtn = event.target.closest('[data-tree-action]');
        if (treeBtn) {
            event.preventDefault();
            event.stopPropagation();

            const action = treeBtn.getAttribute('data-tree-action');
            if (action === 'check-scope' || action === 'uncheck-scope') {
                const shouldCheck = (action === 'check-scope');
                const scope = treeBtn.closest('.rgaa-tree-scope');
                if (scope) {
                    scope.querySelectorAll('.rgaa-tree-checkbox').forEach(function (cb) {
                        cb.checked = shouldCheck;
                        if (shouldCheck) {
                            ensureAncestorsChecked(cb);
                        }
                    });
                }
            }
            return;
        }

        // 3. Accordéon des dossiers (Menu principal & Menu en pied de page - Étape 4 du Wizard)
        const accordionHeader = event.target.closest('.rgaa-accordion-trigger, .rgaa-tree-folder-header');
        if (accordionHeader) {
            const card = accordionHeader.closest('.rgaa-accordion-item');
            if (!card) {
                return;
            }
            const trigger = card.querySelector('.rgaa-accordion-trigger');
            const panel = card.querySelector('.rgaa-tree-folder-body');
            if (!trigger || !panel) {
                return;
            }

            event.preventDefault();
            const isExpanded = trigger.getAttribute('aria-expanded') === 'true';
            if (isExpanded) {
                trigger.setAttribute('aria-expanded', 'false');
                panel.setAttribute('hidden', 'hidden');
                card.classList.remove('is-open');
            } else {
                trigger.setAttribute('aria-expanded', 'true');
                panel.removeAttribute('hidden');
                card.classList.add('is-open');
            }
        }
    });

    // 4. Cascade Parent <-> Sous-pages lors du clic sur une case à cocher
    document.addEventListener('change', function (event) {
        const checkbox = event.target.closest('.rgaa-tree-checkbox');
        if (!checkbox) {
            return;
        }

        const isChecked = checkbox.checked;
        const nodeContainer = checkbox.closest('.rgaa-tree-node');

        // Cascade descendante : cocher/décocher toutes les sous-pages enfants
        if (nodeContainer) {
            nodeContainer.querySelectorAll('.rgaa-tree-checkbox').forEach(function (childCb) {
                if (childCb !== checkbox) {
                    childCb.checked = isChecked;
                }
            });
        }

        // Cascade ascendante : si une sous-page est cochée, cocher automatiquement ses pages parentes
        if (isChecked) {
            ensureAncestorsChecked(checkbox);
        }
    });
})();
