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
            return;
        }

        // 4. Bouton de copie dans le presse-papier
        const copyBtn = event.target.closest('[data-copy-clipboard]');
        if (copyBtn) {
            event.preventDefault();
            const textToCopy = copyBtn.getAttribute('data-copy-clipboard');
            if (textToCopy && navigator.clipboard) {
                navigator.clipboard.writeText(textToCopy).then(function () {
                    const originalHtml = copyBtn.innerHTML;
                    copyBtn.innerHTML = '✓ Copié !';
                    copyBtn.classList.add('btn-success');
                    setTimeout(function () {
                        copyBtn.innerHTML = originalHtml;
                        copyBtn.classList.remove('btn-success');
                    }, 2000);
                }).catch(function () {
                    // Fallback
                    const tempInput = document.createElement('textarea');
                    tempInput.value = textToCopy;
                    document.body.appendChild(tempInput);
                    tempInput.select();
                    document.execCommand('copy');
                    document.body.removeChild(tempInput);
                });
            }
        }
    });

    // 5. Gestionnaire d'événement 'change' (Commune active, Wizard & Accès utilisateurs)
    document.addEventListener('change', function (event) {
        // Interrupteur de création des utilisateurs (Étape 5 du Wizard)
        const userCreateToggle = event.target.closest('#userCreateToggle');
        if (userCreateToggle) {
            const usersBox = document.getElementById('rgaa-users-config-box');
            if (usersBox) {
                if (userCreateToggle.checked) {
                    usersBox.style.opacity = '1';
                    usersBox.style.pointerEvents = 'auto';
                } else {
                    usersBox.style.opacity = '0.4';
                    usersBox.style.pointerEvents = 'none';
                }
            }
            return;
        }
        // Changement de la commune active (Administration Multi-Sites)
        const siteSelect = event.target.closest('#siteSelect, [data-site-selector]');
        if (siteSelect) {
            const selectedOption = siteSelect.options[siteSelect.selectedIndex];
            if (selectedOption) {
                const targetUrl = selectedOption.getAttribute('data-url');
                if (targetUrl && targetUrl !== '' && targetUrl !== window.location.href) {
                    window.location.href = targetUrl;
                    return;
                }
                if (siteSelect.form) {
                    siteSelect.form.submit();
                    return;
                }
            }
        }

        // Cascade Parent <-> Sous-pages lors du clic sur une case à cocher (Étape 4 du Wizard)
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

    // 6. Gestion dynamique & sécurité anti-doublon (Étape 1 du Wizard)
    function initStep1Validation() {
        const form = document.getElementById('rgaa-wizard-step1-form');
        if (!form) return;

        const nameInput = document.getElementById('communeNameInput');
        const identInput = document.getElementById('communeIdentifierInput');
        const alertBox = document.getElementById('rgaa-duplicate-alert');
        const alertCode = document.getElementById('rgaa-duplicate-ident-code');
        const submitBtn = document.getElementById('step1SubmitBtn');

        let existingIdentifiers = [];
        try {
            const raw = form.getAttribute('data-existing-identifiers') || '[]';
            existingIdentifiers = JSON.parse(raw);
        } catch (e) {
            existingIdentifiers = [];
        }

        function slugifyText(text) {
            if (!text) return '';
            return text
                .toString()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .trim()
                .replace(/[^a-z0-9 -]/g, '')
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-');
        }

        function checkDuplicate() {
            if (!identInput) return;
            const currentSlug = slugifyText(identInput.value.trim() || (nameInput ? nameInput.value : ''));
            const isDuplicate = currentSlug !== '' && existingIdentifiers.includes(currentSlug);

            if (isDuplicate) {
                if (alertBox) {
                    alertBox.classList.remove('d-none');
                    if (alertCode) alertCode.textContent = currentSlug;
                }
                identInput.classList.add('rgaa-input-error');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.setAttribute('title', 'Veuillez modifier l\'identifiant pour débloquer le déploiement');
                }
            } else {
                if (alertBox) {
                    alertBox.classList.add('d-none');
                }
                identInput.classList.remove('rgaa-input-error');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.removeAttribute('title');
                }
            }
        }

        if (nameInput && identInput) {
            nameInput.addEventListener('input', function () {
                if (!identInput.dataset.manuallyEdited || identInput.value.trim() === '') {
                    identInput.value = slugifyText(nameInput.value);
                }
                checkDuplicate();
            });

            identInput.addEventListener('input', function () {
                identInput.dataset.manuallyEdited = 'true';
                checkDuplicate();
            });
        }

        // Vérification initiale
        checkDuplicate();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initStep1Validation);
    } else {
        initStep1Validation();
    }
})();
