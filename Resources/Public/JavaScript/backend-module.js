/**
 * Site Commune RGAA - JavaScript du Module Backend TYPO3
 * Conforme Content Security Policy (CSP) sans gestionnaire d'événement inline.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        const btn = event.target.closest('.rgaa-tab-btn');
        if (!btn) {
            return;
        }

        event.preventDefault();

        const targetId = btn.getAttribute('data-target');
        if (!targetId) {
            return;
        }

        const container = btn.closest('.rgaa-backend-wrapper');
        if (!container) {
            return;
        }

        // Desactiver tous les onglets et panneaux
        const buttons = container.querySelectorAll('.rgaa-tab-btn');
        buttons.forEach(function (b) {
            b.classList.remove('active');
        });

        const panels = container.querySelectorAll('.rgaa-tab-panel');
        panels.forEach(function (p) {
            p.classList.remove('active');
        });

        // Activer l'onglet clique et le panneau correspondant
        btn.classList.add('active');
        const targetPanel = container.querySelector('#' + targetId);
        if (targetPanel) {
            targetPanel.classList.add('active');
        }
    });
})();
