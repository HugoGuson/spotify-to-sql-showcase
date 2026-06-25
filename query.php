<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Spotify to SQL</title>
    <meta name="description" content="Export your Spotify library to a local, read-only SQL.js database. Slice, filter, and explore your playlists with plain SQL.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=1.1">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
</head>

<body>
    <div id="divNotifications" class="notifications" aria-live="polite"></div>

    <main class="container query-page">
        <header class="page-header">
            <a class="brand" href="index.php">Spotify to SQL</a>
            <h1>Query</h1>
        </header>

        <div class="query-layout">
            <div class="query-main">
                <div class="editor-wrapper">
                    <div id="divCodeEditor"></div>
                </div>
                <div class="toolbar">
                    <div class="toolbar-group">
                        <button id="btnRun" class="btn-primary">Run</button>
                        <button id="btnCreatePlaylist" class="btn-secondary" disabled>Create playlist from query</button>
                        <button id="btnReset" class="btn-secondary">Reset session</button>
                    </div>
                </div>
                <p id="pErrorMessage" class="query-error"></p>
                <div id="divSummary" class="summary"></div>
                <div id="divResultContainer"></div>
            </div>
            <aside id="asideQuerySidebar" class="query-sidebar"></aside>
        </div>
    </main>

    <dialog id="dialogPlaylist" class="dialog">
        <h3>Create playlist</h3>
        <label>
            Playlist name
            <input type="text" id="inputPlaylistName" class="input" placeholder="My playlist" maxlength="100" />
        </label>
        <label id="labelColumnSelector">
            Track ID column
            <select id="selectColumn" class="select"></select>
        </label>
        <div id="divDialogError" class="dialog-error"></div>
        <div id="divDialogButtons" class="dialog-buttons">
            <button id="btnDialogCancel" class="btn-secondary" type="button">Cancel</button>
            <button id="btnDialogSubmit" class="btn-primary" type="button">Create</button>
        </div>
    </dialog>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/sql.js/1.8.0/sql-wasm.js"></script>
    <script type="module">
    import { basicSetup, EditorView } from 'https://esm.sh/codemirror@6.0.2';
    import { SQLite } from 'https://esm.sh/@codemirror/lang-sql@6.10.0';
    import { oneDark } from 'https://esm.sh/@codemirror/theme-one-dark@6.1.2';

    (async () => {
        const QUERY_STORAGE_KEY  = 'spotify-sql-query';
        const SPOTIFY_ID_PATTERN = /^[A-Za-z0-9]{22}$/;
        const PREFERRED_NAMES    = new Set(['id', 'track_id', 'spotify_id', 'trackid']);

        // ── DOM ──────────────────────────────────────────────────────────────

        const elDivSummary              = document.getElementById('divSummary');
        const elPErrorMessage           = document.getElementById('pErrorMessage');
        const elBtnRun                  = document.getElementById('btnRun');
        const elBtnReset                = document.getElementById('btnReset');
        const elBtnCreatePlaylist       = document.getElementById('btnCreatePlaylist');
        const elDivResultContainer      = document.getElementById('divResultContainer');
        const elDialogPlaylist          = document.getElementById('dialogPlaylist');
        const elInputPlaylistName       = document.getElementById('inputPlaylistName');
        const elSelectColumn            = document.getElementById('selectColumn');
        const elLabelColumnSelector     = document.getElementById('labelColumnSelector');
        const elDivDialogError          = document.getElementById('divDialogError');
        const elBtnDialogCancel         = document.getElementById('btnDialogCancel');
        const elBtnDialogSubmit         = document.getElementById('btnDialogSubmit');
        const elAsideQuerySidebar       = document.getElementById('asideQuerySidebar');
        const elDivNotifications        = document.getElementById('divNotifications');

        // ── Notifications ──────────────────────────────────────────────────────
        //
        // Dismissible messages anchored at the top of the page. This is the
        // general pattern for all user notifications (success, error, info).

        function notify(content, type = 'info') {
            const el = document.createElement('div');
            el.className = `notification ${type}`;

            const contentEl = document.createElement('div');
            contentEl.className = 'notification-content';
            if (content instanceof Node) {
                contentEl.appendChild(content);
            } else {
                contentEl.textContent = content;
            }

            const dismiss = document.createElement('button');
            dismiss.type = 'button';
            dismiss.className = 'notification-dismiss';
            dismiss.setAttribute('aria-label', 'Dismiss');
            dismiss.textContent = '×';
            dismiss.addEventListener('click', () => el.remove());

            el.appendChild(contentEl);
            el.appendChild(dismiss);
            elDivNotifications.prepend(el);
            return el;
        }

        const codeEditor = new EditorView({
            extensions: [
                basicSetup,
                SQLite,
                oneDark,
                EditorView.updateListener.of(update => {
                    if (update.docChanged) {
                        localStorage.setItem(QUERY_STORAGE_KEY, update.state.doc.toString());
                    }
                }),
            ],
            parent: document.getElementById('divCodeEditor'),
        });

        function setCodeEditorValue(text) {
            codeEditor.dispatch({
                changes: { from: 0, to: codeEditor.state.doc.length, insert: text },
            });
        }

        let lastResults = null;
        let db = null;

        // ── IndexedDB ────────────────────────────────────────────────────────

        function loadFromIndexedDB() {
            return new Promise((resolve, reject) => {
                const req = indexedDB.open('spotify-sql', 1);
                req.onupgradeneeded = e => e.target.result.createObjectStore('db');
                req.onsuccess = e => {
                    const idb = e.target.result;
                    const tx = idb.transaction('db', 'readonly');
                    const getReq = tx.objectStore('db').get('database');
                    getReq.onsuccess = () => resolve(getReq.result ?? null);
                    getReq.onerror = reject;
                };
                req.onerror = reject;
            });
        }

        // ── Sidebar ──────────────────────────────────────────────────────────

        function loadQuerySidebar() {
            const tables = db.exec(
                `SELECT name FROM sqlite_master WHERE type='table' ORDER BY name`
            );

            if (!tables.length || !tables[0].values.length) return;

            const elSchemaSection = document.createElement('div');
            elSchemaSection.className = 'sidebarSection';
            elAsideQuerySidebar.appendChild(elSchemaSection);

            const elH3SchemaHeader = document.createElement('h3');
            elH3SchemaHeader.className = 'sidebarSectionHeader';
            elH3SchemaHeader.textContent = 'Tables'
            elSchemaSection.appendChild(elH3SchemaHeader);

            const elUlSchemaTableList = document.createElement('ul');

            for (const [tableName] of tables[0].values) {
                const countRes = db.exec(`SELECT COUNT(*) FROM "${tableName}"`);
                const count = countRes[0]?.values[0][0] ?? 0;

                const li = document.createElement('li');

                const btn = document.createElement('button');
                btn.className = 'btnSchemaTable';
                btn.textContent = tableName;
                btn.addEventListener('click', () => {
                    setCodeEditorValue(`SELECT * FROM ${tableName};`);
                    runQuery();
                });

                const countSpan = document.createElement('span');
                countSpan.className = 'schemaRecordCount';
                countSpan.textContent = `${count} records`;

                li.appendChild(btn);
                li.appendChild(countSpan);
                elUlSchemaTableList.appendChild(li);
            }

            elSchemaSection.appendChild(elUlSchemaTableList);

            const elASchemaLink = document.createElement('a');
            elASchemaLink.href = 'images/spotify-to-sql-schema.png';
            elASchemaLink.target = '_blank';
            elASchemaLink.rel = 'noopener';
            elASchemaLink.className = 'schemaLink';
            elASchemaLink.textContent = 'View schema diagram →';
            elSchemaSection.appendChild(elASchemaLink);

            const elSuggestedSection = document.createElement('div');
            elSuggestedSection.className = 'sidebarSection';
            elAsideQuerySidebar.appendChild(elSuggestedSection);

            const elH3SuggestedQueriesHeader = document.createElement('h3');
            elH3SuggestedQueriesHeader.className = 'sidebarSectionHeader';
            elH3SuggestedQueriesHeader.textContent = 'Suggested queries';
            elSuggestedSection.appendChild(elH3SuggestedQueriesHeader);

            const elUlSuggestedQueriesList = document.createElement('ul');

            const SUGGESTED_QUERIES = [
                {
                    label: 'Top tracks',
                    query:
                        'SELECT t.name AS track, a.name AS artist, COUNT(*) AS playlist_count\n' +
                        'FROM tracks t\n' +
                        'JOIN albums a ON a.id = t.album_id\n' +
                        'JOIN track_playlists tp ON tp.track_id = t.id\n' +
                        'GROUP BY t.id\n' +
                        'ORDER BY playlist_count DESC\n' +
                        'LIMIT 20;',
                },
                {
                    label: 'Recent additions',
                    query:
                        'SELECT t.id AS track_id, t.name AS track, a.name AS album, p.name AS playlist, tp.added_at\n' +
                        'FROM tracks t\n' +
                        'JOIN albums a ON a.id = t.album_id\n' +
                        'JOIN track_playlists tp ON tp.track_id = t.id\n' +
                        'JOIN playlists p ON p.id = tp.playlist_id\n' +
                        'ORDER BY tp.added_at DESC\n' +
                        'LIMIT 50;',
                },
            ];

            for (const sugg of SUGGESTED_QUERIES) {
                const li = document.createElement('li');
                const btn = document.createElement('button');
                btn.className = 'btnSuggestedQuery';
                btn.textContent = sugg.label;
                btn.addEventListener('click', () => {
                    setCodeEditorValue(sugg.query);
                    runQuery();
                });
                li.appendChild(btn);
                elUlSuggestedQueriesList.appendChild(li);
            }

            elSuggestedSection.appendChild(elUlSuggestedQueriesList);
        }

        // ── Track ID column detection ─────────────────────────────────────────
        //
        // A candidate column must:
        //   1. Have all non-null values match the Spotify ID format (22-char base62).
        //   2. Have at least one value present in the local tracks table.
        //
        // Condition 2 filters out artist/album IDs, which share the same format.

        function detectTrackIdColumns(results) {
            if (!results || results.length === 0) return [];

            // Collect unique column names across all result sets and check each.
            const seen = new Map(); // colName -> best candidate object

            for (const res of results) {
                for (let i = 0; i < res.columns.length; i++) {
                    const colName = res.columns[i];
                    if (seen.has(colName)) continue;

                    const nonNullValues = res.values
                        .map(row => row[i])
                        .filter(v => v != null)
                        .map(String);

                    if (nonNullValues.length === 0) continue;
                    if (!nonNullValues.every(v => SPOTIFY_ID_PATTERN.test(v))) continue;

                    // Cross-reference against the local tracks table (samples up to 20 values).
                    const sample = nonNullValues.slice(0, 20);
                    const safeList = sample.map(v => `'${v}'`).join(',');
                    let matchCount = 0;
                    try {
                        const check = db.exec(`SELECT COUNT(*) FROM tracks WHERE id IN (${safeList})`);
                        matchCount = check[0]?.values[0][0] ?? 0;
                    } catch (_) {}

                    if (matchCount === 0) continue;

                    seen.set(colName, {
                        colName,
                        preferred: PREFERRED_NAMES.has(colName.toLowerCase()),
                    });
                }
            }

            const candidates = [...seen.values()];
            candidates.sort((a, b) => b.preferred - a.preferred);
            return candidates;
        }

        function collectTrackIds(results, colName) {
            const ids = new Set();
            for (const res of results) {
                const idx = res.columns.indexOf(colName);
                if (idx === -1) continue;
                for (const row of res.values) {
                    const v = row[idx];
                    if (v && SPOTIFY_ID_PATTERN.test(String(v))) ids.add(String(v));
                }
            }
            return [...ids];
        }

        // ── Render results ────────────────────────────────────────────────────

        // Build one result table. Rows never wrap — overflow scrolls
        // horizontally inside the wrapper instead of expanding row height.
        function buildResultTable(res) {
            const wrapper = document.createElement('div');
            wrapper.className = 'table-wrapper';

            const table = document.createElement('table');
            table.className = 'data-table';
            wrapper.appendChild(table);

            const headerRow = table.createTHead().insertRow();
            for (const col of res.columns) {
                const th = document.createElement('th');
                th.textContent = col;
                headerRow.appendChild(th);
            }

            const tbody = table.createTBody();
            for (const row of res.values) {
                const tr = tbody.insertRow();
                for (const cell of row) {
                    const td = tr.insertCell();
                    if (cell === null || cell === undefined) {
                        td.classList.add('col-null');
                    } else {
                        td.textContent = cell;
                        if (typeof cell === 'number') td.classList.add('col-numeric');
                    }
                }
            }

            return wrapper;
        }

        function renderResults(results) {
            elDivResultContainer.innerHTML = '';
            lastResults = results;

            if (!results || results.length === 0) {
                elDivSummary.textContent = 'Number of records: 0';
                elDivResultContainer.innerHTML = '<p class="empty-note">No rows.</p>';
                elBtnCreatePlaylist.disabled = true;
                return;
            }

            const totalRows = results.reduce((sum, res) => sum + res.values.length, 0);
            elDivSummary.textContent = `Number of records: ${totalRows}`;

            for (const res of results) {
                elDivResultContainer.appendChild(buildResultTable(res));
            }

            elBtnCreatePlaylist.disabled = detectTrackIdColumns(results).length === 0;
        }

        // ── Query execution ───────────────────────────────────────────────────

        function runQuery() {
            elPErrorMessage.textContent = '';
            try {
                db.exec('PRAGMA query_only = ON;');
                const results = db.exec(codeEditor.state.doc.toString());
                db.exec('PRAGMA query_only = OFF;');
                renderResults(results);
            } catch (err) {
                try { db.exec('PRAGMA query_only = OFF;'); } catch (_) {}
                elPErrorMessage.textContent = err.message;
                elDivSummary.textContent = '';
                elDivResultContainer.innerHTML = '';
                lastResults = null;
                elBtnCreatePlaylist.disabled = true;
            }
        }

        // ── Init ─────────────────────────────────────────────────────────────

        const SQL = await initSqlJs({
            locateFile: file => `https://cdnjs.cloudflare.com/ajax/libs/sql.js/1.8.0/${file}`
        });

        const binary = await loadFromIndexedDB();

        if (!binary) {
            elDivSummary.textContent = '';
            elDivResultContainer.innerHTML =
                '<div class="empty-state">' +
                '<p>No database found. Import your playlists to start querying.</p>' +
                '<a class="btn-primary" href="index.php">Import playlists</a>' +
                '</div>';
            return;
        }

        db = new SQL.Database(binary);

        loadQuerySidebar();

        const savedQuery = localStorage.getItem(QUERY_STORAGE_KEY);
        setCodeEditorValue(savedQuery ?? (
            'SELECT t.id AS track_id, t.name AS track, a.name AS album, p.name AS playlist, tp.added_at\n' +
            'FROM tracks t\n' +
            'JOIN albums a ON a.id = t.album_id\n' +
            'JOIN track_playlists tp ON tp.track_id = t.id\n' +
            'JOIN playlists p ON p.id = tp.playlist_id\n' +
            'ORDER BY tp.added_at DESC\n' +
            'LIMIT 100;'
        ));

        elBtnRun.addEventListener('click', runQuery);

        elBtnReset.addEventListener('click', () => {
            const req = indexedDB.open('spotify-sql', 1);
            req.onsuccess = e => {
                const idb = e.target.result;
                const tx = idb.transaction('db', 'readwrite');
                tx.objectStore('db').delete('database');
                tx.oncomplete = () => {
                    localStorage.removeItem(QUERY_STORAGE_KEY);
                    window.location.href = 'index.php';
                };
            };
        });

        elBtnCreatePlaylist.addEventListener('click', () => {
            const candidates = detectTrackIdColumns(lastResults);
            if (candidates.length === 0) return;

            elDivDialogError.textContent = '';
            elInputPlaylistName.value = '';
            elSelectColumn.innerHTML = '';

            for (const c of candidates) {
                const opt = document.createElement('option');
                opt.value = c.colName;
                opt.textContent = c.colName + (c.preferred ? ' ★' : '');
                elSelectColumn.appendChild(opt);
            }

            // Hide the column picker when there is only one unambiguous candidate.
            elLabelColumnSelector.style.display = candidates.length === 1 ? 'none' : '';

            elDialogPlaylist.showModal();
            elInputPlaylistName.focus();
        });

        elBtnDialogCancel.addEventListener('click', () => elDialogPlaylist.close());

        elBtnDialogSubmit.addEventListener('click', async () => {
            const name = elInputPlaylistName.value.trim();
            if (!name) {
                elDivDialogError.textContent = 'Enter a playlist name.';
                return;
            }

            const colName  = elSelectColumn.value;
            const trackIds = collectTrackIds(lastResults, colName);

            if (trackIds.length === 0) {
                elDivDialogError.textContent = 'No valid Spotify track IDs found in the selected column.';
                return;
            }

            elBtnDialogSubmit.disabled = true;
            elBtnDialogSubmit.textContent = 'Creating…';
            elDivDialogError.textContent = '';

            try {
                async function createPlaylist() {
                    return fetch('api/create_playlist.php', {
                        method: 'POST',
                        credentials: 'include',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ name, trackIds }),
                    });
                }

                let response = await createPlaylist();

                if (response.status === 401 || response.status === 403) {
                    window.location.href = 'index.php?returnTo=query.php';
                    return;
                }

                const data = await response.json();

                if (!response.ok) {
                    elDivDialogError.textContent = data.error ?? 'Unknown error.';
                    return;
                }

                elDialogPlaylist.close();

                const content = document.createDocumentFragment();
                content.append(`Playlist “${name}” created with ${data.trackCount} tracks.`);
                if (data.playlistUrl) {
                    const link = document.createElement('a');
                    link.href = data.playlistUrl;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    link.textContent = 'Open in Spotify';
                    content.append(' ', link);
                }
                notify(content, 'success');

            } catch (err) {
                elDivDialogError.textContent = 'Request failed: ' + err.message;
            } finally {
                elBtnDialogSubmit.disabled = false;
                elBtnDialogSubmit.textContent = 'Create';
            }
        });

        runQuery();

    })();
    </script>
</body>
</html>