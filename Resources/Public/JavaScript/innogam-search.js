/**
 * Site Commune RGAA - Recherche et Synchronisation Innogam
 * Compatible Content Security Policy (CSP), ARIA 1.2 Combobox, navigation clavier et RGAA 4.1.
 * Optimisé avec filtrage instantané en mémoire client (0 ms) identique à l'annuaire de référence.
 */
(function () {
    'use strict';

    /**
     * Utilitaires
     */
    function debounce(fn, delay) {
        let timer = null;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function removeAccents(str) {
        if (!str) return '';
        return str.toString().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function slugify(text) {
        if (!text) return '';
        return removeAccents(text)
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9 -]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-');
    }

    /**
     * Moteur de recherche et de filtrage instantané côté client (In-Memory).
     * Supporte la recherche multi-mots, les codes postaux / INSEE / SIREN, les acronymes (nom court)
     * et l'insensibilité complète à la casse et aux accents.
     */
    function filterCollectivites(items, query, limit = 12) {
        if (!Array.isArray(items) || items.length === 0) return [];
        const trimmed = (query || '').trim();
        if (trimmed.length < 2) return [];

        const cleanSearch = removeAccents(trimmed).toLowerCase();
        const searchTerms = cleanSearch.split(/\s+/).filter(w => w.length > 0);

        return items.filter(item => {
            const cleanNom = removeAccents(item.nom || '').toLowerCase();
            const cleanNomCourt = removeAccents(item.nom_court || '').toLowerCase();
            const id = String(item.id || '');

            return searchTerms.every(term =>
                cleanNom.includes(term) || cleanNomCourt.includes(term) || id.includes(term)
            );
        }).slice(0, limit);
    }

    /**
     * Gestionnaire de cache client pour le référentiel complet des collectivités Innogam.
     * Mémorise en RAM et dans sessionStorage (TTL 24h) pour un affichage instantané (0 ms).
     */
    const InnogamDataStore = {
        data: null,
        loadingPromise: null,
        storageKey: 'site_commune_rgaa_innogam_collectivites_v1',
        ttl: 24 * 60 * 60 * 1000, // 24 heures

        isReady() {
            return Array.isArray(this.data) && this.data.length > 0;
        },

        async getCollectivites(listUrl, fallbackSearchUrl) {
            // 1. Déjà en mémoire vive RAM
            if (this.isReady()) {
                return this.data;
            }

            // 2. Vérification dans sessionStorage
            try {
                const cachedRaw = sessionStorage.getItem(this.storageKey);
                if (cachedRaw) {
                    const parsed = JSON.parse(cachedRaw);
                    if (parsed && parsed.timestamp && (Date.now() - parsed.timestamp < this.ttl) && Array.isArray(parsed.items) && parsed.items.length > 0) {
                        this.data = parsed.items;
                        return this.data;
                    }
                }
            } catch (e) {
                // Ignore les erreurs de quota ou de navigation privée
            }

            // 3. Déjà une requête réseau en cours (partage de la promesse)
            if (this.loadingPromise) {
                return this.loadingPromise;
            }

            // 4. Téléchargement unique via l'URL de liste
            const targetUrl = listUrl || fallbackSearchUrl;
            if (!targetUrl) return [];

            this.loadingPromise = (async () => {
                try {
                    const res = await fetch(targetUrl, { credentials: 'same-origin' });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const json = await res.json();
                    const items = json.collectivites || json.results || [];
                    if (Array.isArray(items) && items.length > 0) {
                        this.data = items;
                        try {
                            sessionStorage.setItem(this.storageKey, JSON.stringify({
                                timestamp: Date.now(),
                                items: items
                            }));
                        } catch (e) {}
                        return items;
                    }
                } catch (err) {
                    console.warn('[Innogam] Impossible de précharger la liste des collectivités :', err);
                } finally {
                    this.loadingPromise = null;
                }
                return [];
            })();

            return this.loadingPromise;
        }
    };

    /**
     * Initialisation du composant de recherche dans l'Assistant de Déploiement (Wizard)
     */
    function initWizardInnogam() {
        const container = document.getElementById('rgaa-wizard-innogam-box');
        if (!container) return;

        const input = document.getElementById('innogam-wizard-search-input');
        const listbox = document.getElementById('innogam-wizard-suggestions');
        const liveRegion = document.getElementById('innogam-wizard-live-region');
        const listUrl = container.getAttribute('data-list-url');
        const searchUrl = container.getAttribute('data-search-url');
        const detailUrl = container.getAttribute('data-detail-url');
        const loadingIndicator = document.getElementById('innogam-wizard-loading');
        const resultNotice = document.getElementById('innogam-wizard-result-notice');

        if (!input || !listbox || !detailUrl) return;

        let activeIndex = -1;
        let currentSuggestions = [];

        // Préchargement immédiat et silencieux du référentiel dès l'arrivée sur l'étape 1
        InnogamDataStore.getCollectivites(listUrl, searchUrl);

        function announce(message) {
            if (liveRegion) {
                liveRegion.textContent = message;
            }
        }

        function closeSuggestions() {
            listbox.innerHTML = '';
            listbox.setAttribute('hidden', 'hidden');
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            activeIndex = -1;
            currentSuggestions = [];
        }

        function highlightOption(index) {
            const options = listbox.querySelectorAll('[role="option"]');
            options.forEach((opt, idx) => {
                if (idx === index) {
                    opt.classList.add('rgaa-combobox-active');
                    opt.setAttribute('aria-selected', 'true');
                    input.setAttribute('aria-activedescendant', opt.id);
                    opt.scrollIntoView({ block: 'nearest' });
                } else {
                    opt.classList.remove('rgaa-combobox-active');
                    opt.setAttribute('aria-selected', 'false');
                }
            });
            activeIndex = index;
        }

        function renderSuggestions(items) {
            currentSuggestions = items;
            listbox.innerHTML = '';

            if (items.length === 0) {
                listbox.innerHTML = '<div class="rgaa-combobox-empty p-3 text-muted text-center">Aucune collectivité trouvée dans l\'annuaire pour cette recherche.</div>';
                listbox.removeAttribute('hidden');
                input.setAttribute('aria-expanded', 'true');
                announce('Aucune collectivité trouvée.');
                return;
            }

            items.forEach((item, idx) => {
                const opt = document.createElement('div');
                opt.id = 'innogam-wizard-opt-' + idx;
                opt.setAttribute('role', 'option');
                opt.setAttribute('aria-selected', 'false');
                opt.className = 'rgaa-combobox-option d-flex justify-content-between align-items-center p-2 border-bottom';

                const isCommune = item.type === 'commune';
                const badgeClass = isCommune ? 'rgaa-badge-commune' : 'rgaa-badge-interco';
                const icon = isCommune ? '🏛️' : '🏢';

                opt.innerHTML = `
                    <div class="rgaa-combobox-col-info">
                        <span class="rgaa-combobox-icon me-2" aria-hidden="true">${icon}</span>
                        <strong class="rgaa-combobox-title">${escapeHtml(item.nom)}</strong>
                        ${item.nom_court ? `<span class="rgaa-combobox-sub text-muted ms-2">(${escapeHtml(item.nom_court)})</span>` : ''}
                    </div>
                    <div>
                        <span class="rgaa-combobox-badge ${badgeClass}">${escapeHtml(item.type_label)}</span>
                        <span class="rgaa-combobox-id text-muted ms-2" style="font-size:0.8rem;">#${escapeHtml(item.id)}</span>
                    </div>
                `;

                opt.addEventListener('click', function () {
                    selectSuggestion(item);
                });

                listbox.appendChild(opt);
            });

            listbox.removeAttribute('hidden');
            input.setAttribute('aria-expanded', 'true');
            announce(`${items.length} collectivités trouvées. Utilisez les flèches haut et bas pour naviguer.`);
        }

        // Fallback serveur avec debounce si la liste complète n'a pu être chargée
        const doFallbackServerSearch = debounce(async function (query) {
            if (query.trim().length < 2) {
                closeSuggestions();
                return;
            }
            if (!searchUrl) return;

            if (loadingIndicator) loadingIndicator.classList.remove('d-none');
            try {
                const url = searchUrl + (searchUrl.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(query);
                const res = await fetch(url, { credentials: 'same-origin' });
                if (!res.ok) throw new Error('Erreur réseau');
                const data = await res.json();
                if (data.success && Array.isArray(data.results)) {
                    renderSuggestions(data.results);
                } else {
                    closeSuggestions();
                }
            } catch (err) {
                announce('Erreur lors de la recherche sur l\'annuaire Innogam.');
                closeSuggestions();
            } finally {
                if (loadingIndicator) loadingIndicator.classList.add('d-none');
            }
        }, 200);

        /**
         * Recherche instantanée (0 ms) en mémoire vive client
         */
        async function performSearch(query) {
            const trimmed = query.trim();
            if (trimmed.length < 2) {
                closeSuggestions();
                return;
            }

            // 1. Si le référentiel est déjà en mémoire RAM, filtrage instantané < 1 ms
            if (InnogamDataStore.isReady()) {
                const matches = filterCollectivites(InnogamDataStore.data, trimmed, 12);
                renderSuggestions(matches);
                return;
            }

            // 2. Si le préchargement est en cours, attendre sa résolution
            if (loadingIndicator) loadingIndicator.classList.remove('d-none');
            try {
                const items = await InnogamDataStore.getCollectivites(listUrl, searchUrl);
                if (Array.isArray(items) && items.length > 0) {
                    const matches = filterCollectivites(items, input.value.trim(), 12);
                    renderSuggestions(matches);
                    return;
                }
            } catch (e) {
                // Échec préchargement
            } finally {
                if (loadingIndicator) loadingIndicator.classList.add('d-none');
            }

            // 3. Fallback sur la recherche distante côté serveur
            doFallbackServerSearch(trimmed);
        }

        async function selectSuggestion(item) {
            closeSuggestions();
            input.value = item.nom;
            if (loadingIndicator) loadingIndicator.classList.remove('d-none');
            announce(`Chargement des données de ${item.nom}...`);

            try {
                const url = detailUrl + (detailUrl.includes('?') ? '&' : '?') +
                    'id=' + encodeURIComponent(item.id) +
                    '&type=' + encodeURIComponent(item.type);

                const res = await fetch(url, { credentials: 'same-origin' });
                if (!res.ok) throw new Error('Erreur de chargement');
                const data = await res.json();

                if (data.success && data.detail) {
                    applyDetailToWizard(data.detail, data.settings);
                } else {
                    alert('Impossible de charger les données détaillées : ' + (data.error || 'Erreur inconnue'));
                }
            } catch (err) {
                alert('Erreur de connexion lors de la récupération des données : ' + err.message);
            } finally {
                if (loadingIndicator) loadingIndicator.classList.add('d-none');
            }
        }

        function applyDetailToWizard(detail, settings) {
            // 1. Nom de la collectivité (sans préfixe imposé)
            const nameInput = document.querySelector('input[name="wizardData[name]"]');
            if (nameInput) {
                nameInput.value = detail.nom;
                highlightField(nameInput);
            }

            // 2. Identifiant unique (nom de la commune uniquement, sans préfixe "commune")
            const identInput = document.querySelector('input[name="wizardData[identifier]"]');
            if (identInput) {
                identInput.value = slugify(detail.nom);
                highlightField(identInput);
                identInput.dispatchEvent(new Event('input', { bubbles: true }));
            }

            // 3. Coordonnées & Horaires
            const addrInput = document.querySelector('input[name="wizardData[adresse]"]');
            if (addrInput && detail.adresse_formatee) {
                addrInput.value = detail.adresse_formatee;
                highlightField(addrInput);
            }

            const telInput = document.querySelector('input[name="wizardData[telephone]"]');
            if (telInput && detail.tel) {
                telInput.value = detail.tel;
                highlightField(telInput);
            }

            const emailInput = document.querySelector('input[name="wizardData[email]"]');
            if (emailInput && detail.email) {
                emailInput.value = detail.email;
                highlightField(emailInput);
            }

            const horairesInput = document.querySelector('textarea[name="wizardData[horaires]"]');
            if (horairesInput && detail.horaires) {
                horairesInput.value = detail.horaires;
                highlightField(horairesInput);
            }

            // 4. Identifiants techniques Innogam
            const innogamIdInput = document.querySelector('input[name="wizardData[innogam_id]"]');
            if (innogamIdInput) innogamIdInput.value = detail.id;

            const innogamTypeInput = document.querySelector('input[name="wizardData[innogam_type]"]');
            if (innogamTypeInput) innogamTypeInput.value = detail.type;

            // 5. Pré-remplissage des accès backend : groupe officiel en majuscules & email secrétariat
            const cleanUpperName = (detail.nom || '').trim().toUpperCase();
            const cleanSlug = slugify(cleanUpperName);

            const groupTitleInput = document.querySelector('input[name="wizardData[commune_group_title]"]');
            if (groupTitleInput) {
                groupTitleInput.value = cleanUpperName;
            }

            const secEmailInput = document.querySelector('input[name="wizardData[user_secretariat_email]"]');
            if (secEmailInput && detail.email) {
                secEmailInput.value = detail.email;
            }

            const secUserInput = document.querySelector('input[name="wizardData[user_secretariat_username]"]');
            if (secUserInput) {
                secUserInput.value = 'secretariat.' + cleanSlug;
            }

            const secRealInput = document.querySelector('input[name="wizardData[user_secretariat_realname]"]');
            if (secRealInput) {
                secRealInput.value = 'Secrétariat ' + cleanUpperName;
            }

            const eluUserInput = document.querySelector('input[name="wizardData[user_elu_username]"]');
            if (eluUserInput) {
                eluUserInput.value = 'elu.' + cleanSlug;
            }

            const eluRealInput = document.querySelector('input[name="wizardData[user_elu_realname]"]');
            if (eluRealInput) {
                eluRealInput.value = 'Élu ' + cleanUpperName;
            }

            // 6. Affichage d'un panneau de confirmation visuel & accessible
            if (resultNotice) {
                let extraInfo = [];
                if (detail.gentille) extraInfo.push(`Gentilé : <strong>${escapeHtml(detail.gentille)}</strong>`);
                if (detail.population_totale) extraInfo.push(`Population : <strong>${detail.population_totale.toLocaleString('fr-FR')} hab.</strong>`);
                if (detail.nom_intercommunalite) extraInfo.push(`Intercommunalité : <strong>${escapeHtml(detail.nom_intercommunalite)}</strong>`);

                resultNotice.innerHTML = `
                    <div class="rgaa-alert rgaa-alert-success mt-3 mb-0">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="fs-5 me-2">✨</span>
                                <strong>Collectivité chargée depuis l'annuaire Innogam : ${escapeHtml(detail.nom)}</strong>
                                (${escapeHtml(detail.type === 'commune' ? 'Commune' : 'Intercommunalité')} - Code #${escapeHtml(detail.id)})
                            </div>
                            <span class="badge bg-success">Données synchronisées</span>
                        </div>
                        <div class="mt-2" style="font-size:0.9rem;">
                            Les champs Nom, Adresse postale, Téléphone, Courriel et Horaires ont été renseignés automatiquement.
                            ${extraInfo.length ? `<div class="mt-1 text-muted">${extraInfo.join(' • ')}</div>` : ''}
                            <div class="mt-1"><em>Vous pouvez modifier librement chaque champ ci-dessous avant de poursuivre vers l'étape suivante.</em></div>
                        </div>
                    </div>
                `;
                resultNotice.classList.remove('d-none');
            }

            announce(`Données de ${detail.nom} pré-remplies avec succès dans le formulaire.`);
        }

        function highlightField(elem) {
            elem.classList.add('rgaa-input-highlight');
            setTimeout(() => {
                elem.classList.remove('rgaa-input-highlight');
            }, 2500);
        }

        // Événements du champ combobox (instantané à la frappe)
        input.addEventListener('input', function () {
            performSearch(input.value);
        });

        input.addEventListener('focus', function () {
            if (input.value.trim().length >= 2) {
                performSearch(input.value);
            }
        });

        input.addEventListener('keydown', function (e) {
            const options = listbox.querySelectorAll('[role="option"]');
            if (!options.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                const nextIndex = (activeIndex + 1) % options.length;
                highlightOption(nextIndex);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                const prevIndex = (activeIndex - 1 + options.length) % options.length;
                highlightOption(prevIndex);
            } else if (e.key === 'Enter') {
                if (activeIndex >= 0 && activeIndex < currentSuggestions.length) {
                    e.preventDefault();
                    selectSuggestion(currentSuggestions[activeIndex]);
                }
            } else if (e.key === 'Escape') {
                closeSuggestions();
            }
        });

        // Synchronisation à la saisie manuelle (Nom en majuscules pour le groupe & Email secrétariat)
        const manualName = document.querySelector('input[name="wizardData[name]"]');
        if (manualName) {
            manualName.addEventListener('input', function () {
                const upper = manualName.value.trim().toUpperCase();
                const groupTitle = document.querySelector('input[name="wizardData[commune_group_title]"]');
                if (groupTitle) groupTitle.value = upper;
            });
        }
        const manualEmail = document.querySelector('input[name="wizardData[email]"]');
        if (manualEmail) {
            manualEmail.addEventListener('input', function () {
                const secEmail = document.querySelector('input[name="wizardData[user_secretariat_email]"]');
                if (secEmail) secEmail.value = manualEmail.value.trim();
            });
        }

        // Fermeture au clic extérieur
        document.addEventListener('click', function (e) {
            if (!container.contains(e.target)) {
                closeSuggestions();
            }
        });
    }

    /**
     * Initialisation du composant de synchronisation et comparaison dans l'Administration (Index)
     */
    function initIndexInnogamSync() {
        const syncCard = document.getElementById('rgaa-innogam-sync-card');
        if (!syncCard) return;

        const listUrl = syncCard.getAttribute('data-list-url');
        const searchUrl = syncCard.getAttribute('data-search-url');
        const detailUrl = syncCard.getAttribute('data-detail-url');
        const siteIdentifier = syncCard.getAttribute('data-site-identifier');
        const currentInnogamId = syncCard.getAttribute('data-innogam-id');
        const currentInnogamType = syncCard.getAttribute('data-innogam-type') || 'commune';

        const searchInput = document.getElementById('innogam-sync-search-input');
        const suggestionsBox = document.getElementById('innogam-sync-suggestions');
        const reloadBtn = document.getElementById('innogam-sync-reload-btn');
        const changeBtn = document.getElementById('innogam-sync-change-btn');
        const searchWrapper = document.getElementById('innogam-sync-search-wrapper');
        const diffCard = document.getElementById('innogam-sync-diff-card');
        const diffBody = document.getElementById('innogam-sync-diff-body');
        const applyBtn = document.getElementById('innogam-sync-apply-btn');
        const cancelBtn = document.getElementById('innogam-sync-cancel-btn');
        const loadingSpinner = document.getElementById('innogam-sync-loading');

        let lastFetchedData = null;
        let activeIdx = -1;
        let suggestions = [];

        // Préchargement immédiat et silencieux du référentiel
        InnogamDataStore.getCollectivites(listUrl, searchUrl);

        function closeSuggestions() {
            if (!suggestionsBox) return;
            suggestionsBox.innerHTML = '';
            suggestionsBox.setAttribute('hidden', 'hidden');
            if (searchInput) searchInput.setAttribute('aria-expanded', 'false');
            activeIdx = -1;
            suggestions = [];
        }

        // 1. Bouton "Recharger les données"
        if (reloadBtn) {
            reloadBtn.addEventListener('click', function () {
                const idToFetch = syncCard.getAttribute('data-innogam-id');
                const typeToFetch = syncCard.getAttribute('data-innogam-type') || 'commune';
                if (!idToFetch) return;
                fetchAndCompare(idToFetch, typeToFetch);
            });
        }

        // 2. Bouton "Changer de collectivité liée"
        if (changeBtn && searchWrapper) {
            changeBtn.addEventListener('click', function () {
                searchWrapper.classList.toggle('d-none');
                if (!searchWrapper.classList.contains('d-none') && searchInput) {
                    searchInput.focus();
                }
            });
        }

        // 3. Recherche dans le panneau de synchronisation (Instantanée)
        if (searchInput && suggestionsBox) {
            const doFallbackSyncServer = debounce(async function (query) {
                if (query.trim().length < 2) {
                    closeSuggestions();
                    return;
                }
                if (!searchUrl) return;

                if (loadingSpinner) loadingSpinner.classList.remove('d-none');
                try {
                    const url = searchUrl + (searchUrl.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(query);
                    const res = await fetch(url, { credentials: 'same-origin' });
                    if (!res.ok) throw new Error('Erreur réseau');
                    const data = await res.json();
                    if (data.success && Array.isArray(data.results)) {
                        renderSyncSuggestions(data.results);
                    } else {
                        closeSuggestions();
                    }
                } catch (err) {
                    closeSuggestions();
                } finally {
                    if (loadingSpinner) loadingSpinner.classList.add('d-none');
                }
            }, 200);

            async function performSyncSearch(query) {
                const trimmed = query.trim();
                if (trimmed.length < 2) {
                    closeSuggestions();
                    return;
                }

                if (InnogamDataStore.isReady()) {
                    const matches = filterCollectivites(InnogamDataStore.data, trimmed, 10);
                    renderSyncSuggestions(matches);
                    return;
                }

                if (loadingSpinner) loadingSpinner.classList.remove('d-none');
                try {
                    const items = await InnogamDataStore.getCollectivites(listUrl, searchUrl);
                    if (Array.isArray(items) && items.length > 0) {
                        const matches = filterCollectivites(items, searchInput.value.trim(), 10);
                        renderSyncSuggestions(matches);
                        return;
                    }
                } catch (e) {
                    // Échec
                } finally {
                    if (loadingSpinner) loadingSpinner.classList.add('d-none');
                }

                doFallbackSyncServer(trimmed);
            }

            searchInput.addEventListener('input', function () {
                performSyncSearch(searchInput.value);
            });

            searchInput.addEventListener('focus', function () {
                if (searchInput.value.trim().length >= 2) {
                    performSyncSearch(searchInput.value);
                }
            });

            searchInput.addEventListener('keydown', function (e) {
                const options = suggestionsBox.querySelectorAll('[role="option"]');
                if (!options.length) return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIdx = (activeIdx + 1) % options.length;
                    highlightSyncOption(options, activeIdx);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIdx = (activeIdx - 1 + options.length) % options.length;
                    highlightSyncOption(options, activeIdx);
                } else if (e.key === 'Enter') {
                    if (activeIdx >= 0 && activeIdx < suggestions.length) {
                        e.preventDefault();
                        const sel = suggestions[activeIdx];
                        closeSuggestions();
                        fetchAndCompare(sel.id, sel.type);
                    }
                } else if (e.key === 'Escape') {
                    closeSuggestions();
                }
            });

            document.addEventListener('click', function (e) {
                if (searchWrapper && !searchWrapper.contains(e.target)) {
                    closeSuggestions();
                }
            });
        }

        function highlightSyncOption(options, index) {
            options.forEach((opt, idx) => {
                if (idx === index) {
                    opt.classList.add('rgaa-combobox-active');
                    opt.setAttribute('aria-selected', 'true');
                    opt.scrollIntoView({ block: 'nearest' });
                } else {
                    opt.classList.remove('rgaa-combobox-active');
                    opt.setAttribute('aria-selected', 'false');
                }
            });
        }

        function renderSyncSuggestions(items) {
            suggestions = items;
            suggestionsBox.innerHTML = '';

            if (items.length === 0) {
                suggestionsBox.innerHTML = '<div class="p-3 text-muted text-center">Aucune collectivité trouvée.</div>';
                suggestionsBox.removeAttribute('hidden');
                if (searchInput) searchInput.setAttribute('aria-expanded', 'true');
                return;
            }

            items.forEach((item, idx) => {
                const opt = document.createElement('div');
                opt.setAttribute('role', 'option');
                opt.setAttribute('aria-selected', 'false');
                opt.className = 'rgaa-combobox-option d-flex justify-content-between align-items-center p-2 border-bottom';

                const isCommune = item.type === 'commune';
                const badgeClass = isCommune ? 'rgaa-badge-commune' : 'rgaa-badge-interco';
                const icon = isCommune ? '🏛️' : '🏢';

                opt.innerHTML = `
                    <div>
                        <span class="me-2">${icon}</span>
                        <strong>${escapeHtml(item.nom)}</strong>
                        ${item.nom_court ? `<span class="text-muted ms-1">(${escapeHtml(item.nom_court)})</span>` : ''}
                    </div>
                    <div>
                        <span class="rgaa-combobox-badge ${badgeClass}">${escapeHtml(item.type_label)}</span>
                        <span class="text-muted ms-2" style="font-size:0.8rem;">#${escapeHtml(item.id)}</span>
                    </div>
                `;

                opt.addEventListener('click', function () {
                    closeSuggestions();
                    fetchAndCompare(item.id, item.type);
                });

                suggestionsBox.appendChild(opt);
            });

            suggestionsBox.removeAttribute('hidden');
            if (searchInput) searchInput.setAttribute('aria-expanded', 'true');
        }

        async function fetchAndCompare(id, type) {
            if (loadingSpinner) loadingSpinner.classList.remove('d-none');
            try {
                const url = detailUrl + (detailUrl.includes('?') ? '&' : '?') +
                    'id=' + encodeURIComponent(id) +
                    '&type=' + encodeURIComponent(type) +
                    '&siteIdentifier=' + encodeURIComponent(siteIdentifier);

                const res = await fetch(url, { credentials: 'same-origin' });
                if (!res.ok) throw new Error('Erreur réseau');
                const data = await res.json();

                if (data.success && data.detail && Array.isArray(data.comparison)) {
                    lastFetchedData = data;
                    renderComparisonTable(data.detail, data.comparison);
                } else {
                    alert('Erreur : ' + (data.error || 'Impossible de récupérer la fiche'));
                }
            } catch (err) {
                alert('Erreur lors du chargement des données : ' + err.message);
            } finally {
                if (loadingSpinner) loadingSpinner.classList.add('d-none');
            }
        }

        function renderComparisonTable(detail, comparison) {
            if (!diffCard || !diffBody) return;

            let html = `
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                    <div>
                        <h4 class="h6 mb-1 text-primary fw-bold">
                            🏛️ Collectivité ciblée : ${escapeHtml(detail.nom)}
                            <span class="badge bg-secondary ms-2">${escapeHtml(detail.type === 'commune' ? 'Commune' : 'Intercommunalité')}</span>
                        </h4>
                        <div class="text-muted small">
                            Code #${escapeHtml(detail.id)}
                            ${detail.gentille ? ` • Gentilé : ${escapeHtml(detail.gentille)}` : ''}
                            ${detail.population_totale ? ` • Population : ${detail.population_totale.toLocaleString('fr-FR')} hab.` : ''}
                        </div>
                    </div>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="diff-check-all">Tout cocher</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="diff-uncheck-all">Tout décocher</button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle rgaa-diff-table mb-0">
                        <caption class="visually-hidden">Tableau comparatif des paramètres entre le site TYPO3 et l'annuaire Innogam</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="width:22%;">Champ</th>
                                <th scope="col" style="width:33%;">Valeur actuelle sur ce site</th>
                                <th scope="col" style="width:35%;">Valeur officielle (Innogam)</th>
                                <th scope="col" class="text-center" style="width:10%;">Importer ?</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            let diffCount = 0;

            comparison.forEach((row, idx) => {
                if (row.is_different) diffCount++;
                const isDiff = row.is_different;
                const statusBadge = isDiff
                    ? '<span class="badge bg-warning text-dark me-2">Différent</span>'
                    : '<span class="badge bg-light text-muted border me-2">Identique</span>';

                // Pré-cocher seulement si différent et si valeur proposée non vide
                const isChecked = isDiff && Boolean(row.proposed.trim());

                html += `
                    <tr class="${isDiff ? 'table-warning-subtle' : ''}">
                        <td>
                            <div class="fw-bold text-dark">${escapeHtml(row.label)}</div>
                            <div class="text-muted" style="font-size:0.75rem;"><code>${escapeHtml(row.key)}</code></div>
                        </td>
                        <td>
                            <div class="rgaa-diff-val rgaa-diff-current text-break">${row.current ? escapeHtml(row.current).replace(/\n/g, '<br>') : '<em class="text-muted">(non renseigné)</em>'}</div>
                        </td>
                        <td>
                            <div class="d-flex align-items-start justify-content-between">
                                <div class="rgaa-diff-val rgaa-diff-proposed fw-medium text-break">${row.proposed ? escapeHtml(row.proposed).replace(/\n/g, '<br>') : '<em class="text-muted">(non renseigné)</em>'}</div>
                                ${statusBadge}
                            </div>
                        </td>
                        <td class="text-center rgaa-diff-check-cell">
                            <label class="rgaa-diff-check-wrapper" for="sync-chk-${idx}" title="Cocher pour importer cette valeur">
                                <input
                                    type="checkbox"
                                    class="rgaa-sync-checkbox"
                                    id="sync-chk-${idx}"
                                    data-key="${escapeHtml(row.key)}"
                                    data-proposed="${escapeHtml(row.proposed)}"
                                    ${isChecked ? 'checked="checked"' : ''}
                                    aria-label="Mettre à jour le champ ${escapeHtml(row.label)}"
                                />
                            </label>
                        </td>
                    </tr>
                `;
            });

            html += `
                        </tbody>
                    </table>
                </div>
            `;

            diffBody.innerHTML = html;
            diffCard.classList.remove('d-none');
            diffCard.scrollIntoView({ behavior: 'smooth', block: 'start' });

            // Rendre la cellule entière cliquable pour cocher/décocher confortablement
            diffBody.querySelectorAll('.rgaa-diff-check-cell').forEach(cell => {
                cell.addEventListener('click', (e) => {
                    if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'LABEL') {
                        const cb = cell.querySelector('.rgaa-sync-checkbox');
                        if (cb) {
                            cb.checked = !cb.checked;
                            cb.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                });
            });

            // Boutons Tout cocher / Tout décocher
            const chkAll = document.getElementById('diff-check-all');
            const unchkAll = document.getElementById('diff-uncheck-all');
            const checkboxes = diffBody.querySelectorAll('.rgaa-sync-checkbox');

            if (chkAll) {
                chkAll.addEventListener('click', () => {
                    checkboxes.forEach(cb => { cb.checked = true; });
                });
            }
            if (unchkAll) {
                unchkAll.addEventListener('click', () => {
                    checkboxes.forEach(cb => { cb.checked = false; });
                });
            }
        }

        // 4. Bouton "Appliquer les champs sélectionnés au formulaire"
        if (applyBtn) {
            applyBtn.addEventListener('click', function () {
                if (!lastFetchedData || !diffBody) return;

                const checkboxes = diffBody.querySelectorAll('.rgaa-sync-checkbox:checked');
                if (!checkboxes.length) {
                    alert('Veuillez cocher au moins un champ à importer.');
                    return;
                }

                let appliedCount = 0;

                checkboxes.forEach(cb => {
                    const key = cb.getAttribute('data-key');
                    const proposedVal = cb.getAttribute('data-proposed');
                    if (!key) return;

                    // Recherche de l'input correspondant dans le formulaire
                    // name="settings[commune.nom]" ou textarea name="settings[commune.horaires]"
                    const inputElem = document.querySelector(`[name="settings[${key}]"]`);
                    if (inputElem) {
                        inputElem.value = proposedVal;
                        inputElem.classList.add('rgaa-input-highlight');
                        setTimeout(() => inputElem.classList.remove('rgaa-input-highlight'), 3000);
                        appliedCount++;
                    }
                });

                // Renseignement des identifiants techniques Innogam
                if (lastFetchedData.detail) {
                    const idInput = document.querySelector('[name="settings[commune.innogam_id]"]');
                    const typeInput = document.querySelector('[name="settings[commune.innogam_type]"]');
                    const lastSyncInput = document.querySelector('[name="settings[commune.innogam_last_sync]"]');

                    if (idInput) idInput.value = lastFetchedData.detail.id;
                    if (typeInput) typeInput.value = lastFetchedData.detail.type;
                    if (lastSyncInput && lastFetchedData.settings['commune.innogam_last_sync']) {
                        lastSyncInput.value = lastFetchedData.settings['commune.innogam_last_sync'];
                    }

                    // Mise à jour des attributs du conteneur
                    syncCard.setAttribute('data-innogam-id', lastFetchedData.detail.id);
                    syncCard.setAttribute('data-innogam-type', lastFetchedData.detail.type);
                }

                // Masquage du panneau de comparaison
                diffCard.classList.add('d-none');

                // Notification visuelle
                alert(`✨ ${appliedCount} champ(s) ont été reportés avec succès dans le formulaire ci-dessous.\n\nVous pouvez les vérifier puis cliquer sur « Enregistrer les modifications » au bas de la page.`);
            });
        }

        // 5. Bouton "Annuler"
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                if (diffCard) diffCard.classList.add('d-none');
            });
        }
    }

    /**
     * Démarrage automatique au chargement du DOM
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initWizardInnogam();
            initIndexInnogamSync();
        });
    } else {
        initWizardInnogam();
        initIndexInnogamSync();
    }
})();
