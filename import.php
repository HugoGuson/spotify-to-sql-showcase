<?php
session_start();
require_once __DIR__ . '/config.php';
$isDemo = isset($_GET['demo']) && $_GET['demo'] === '1';
?>

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
    <main class="page">
        <header class="page-header">
            <a class="brand" href="index.php">Spotify to SQL</a>
            <h1>Select playlists</h1>
            <p class="page-description">Imports music tracks from up to 250 of your playlists. Podcast episodes are skipped automatically. Note that you can only import playlists you own or collaborate on — others will return an error.</p>
        </header>

        <div class="toolbar">
            <div class="toolbar-group">
                <label class="field" for="selectSort">
                    <span class="label">Sort by</span>
                    <select id="selectSort" class="select">
                        <option value="name">Name (A–Z)</option>
                        <option value="tracks">Track count</option>
                    </select>
                </label>
                <button id="btnSelectAll" class="btn-secondary">Select all</button>
                <button id="btnSelectNone" class="btn-secondary">Select none</button>
            </div>
            <button id="btnRetrieve" class="btn-primary" disabled>Import</button>
        </div>

        <div id="divStatus" class="status"></div>

        <div id="divPlaylists"></div>
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/sql.js/1.8.0/sql-wasm.js"></script>
    <script>
        // ── DOM ──────────────────────────────────────────────────────────────

        const MAX_TRACKS_GLOBAL = <?= MAX_TRACKS ?>;
        const TRACKS_PAGE_LIMIT = 50;
        const IS_DEMO = <?= $isDemo ? 'true' : 'false' ?>;

        const elDivPlaylists =      document.getElementById('divPlaylists');
        const elBtnRetrieve =       document.getElementById('btnRetrieve');
        const elBtnSelectAll =      document.getElementById('btnSelectAll');
        const elBtnSelectNone =     document.getElementById('btnSelectNone');
        const elSelectSort =        document.getElementById('selectSort');
        const elDivStatus =         document.getElementById('divStatus');

        let playlistItems = [];
        let db = null;

        // ── Helpers ───────────────────────────────────────────────────────────

        function setStatus(message, isError = false) {
            elDivStatus.textContent = message;
            elDivStatus.classList.toggle('error', isError);
        }

        /**
        * Fetch JSON and surface error messages for failed requests.
        */
        async function fetchJson(url) {
            const response = await fetch(url, { credentials: 'include' });
            const contentType = response.headers.get('content-type') || '';
            const bodyText = await response.text();

            let data = null;
            if (contentType.includes('application/json')) {
                try {
                    data = JSON.parse(bodyText);
                } catch (_) {}
            }

            if (!response.ok) {
                const message =
                    (data && (data.error || data.message)) ||
                    `${response.status} ${response.statusText}` ||
                    'Request failed';

                throw new Error(`${message} (${url})`);
            }

            if (data == null) {
                throw new Error(`Expected JSON but got: ${bodyText.slice(0, 200)} (${url})`);
            }

            return data;
        }

        // Escape HTML special characters before inserting text into innerHTML.      
        function escapeHtml(value) {
            return String(value)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        // ── Database ──────────────────────────────────────────────────────────

        async function initDb() {
            const SQL = await initSqlJs({
                locateFile: file =>
                    `https://cdnjs.cloudflare.com/ajax/libs/sql.js/1.8.0/${file}`
            });
            db = new SQL.Database();
            db.run(`
                CREATE TABLE IF NOT EXISTS "artists" (
                    "id" TEXT NOT NULL UNIQUE,
                    "name" TEXT,
                    PRIMARY KEY("id")
                );

                CREATE TABLE IF NOT EXISTS "albums" (
                    "id" TEXT NOT NULL UNIQUE,
                    "name" TEXT,
                    "release_date" DATE,
                    "album_type" TEXT CHECK("album_type" IN ('album', 'single', 'compilation')),
                    PRIMARY KEY("id")
                );

                CREATE TABLE IF NOT EXISTS "playlists" (
                    "id" TEXT NOT NULL UNIQUE,
                    "name" TEXT,
                    "collaborative" BOOLEAN,
                    "public" BOOLEAN,
                    PRIMARY KEY("id")
                );

                CREATE TABLE IF NOT EXISTS "tracks" (
                    "id" TEXT NOT NULL UNIQUE,
                    "name" TEXT,
                    "album_id" TEXT,
                    "duration_ms" INTEGER,
                    PRIMARY KEY("id"),
                    FOREIGN KEY ("album_id") REFERENCES "albums"("id")
                    ON UPDATE NO ACTION ON DELETE NO ACTION
                );

                CREATE TABLE IF NOT EXISTS "album_artists" (
                    "album_id" TEXT NOT NULL,
                    "artist_id" TEXT NOT NULL,
                    PRIMARY KEY("album_id", "artist_id"),
                    FOREIGN KEY ("album_id") REFERENCES "albums"("id")
                    ON UPDATE NO ACTION ON DELETE NO ACTION,
                    FOREIGN KEY ("artist_id") REFERENCES "artists"("id")
                    ON UPDATE NO ACTION ON DELETE NO ACTION
                );

                CREATE TABLE IF NOT EXISTS "track_artists" (
                    "track_id" TEXT NOT NULL,
                    "artist_id" TEXT NOT NULL,
                    PRIMARY KEY("track_id", "artist_id"),
                    FOREIGN KEY ("track_id") REFERENCES "tracks"("id")
                    ON UPDATE NO ACTION ON DELETE NO ACTION,
                    FOREIGN KEY ("artist_id") REFERENCES "artists"("id")
                    ON UPDATE NO ACTION ON DELETE NO ACTION
                );

                CREATE TABLE IF NOT EXISTS "track_playlists" (
                    "track_id" TEXT NOT NULL,
                    "playlist_id" TEXT NOT NULL,
                    "position" INTEGER NOT NULL,
                    "added_at" DATE,
                    PRIMARY KEY("playlist_id", "position"),
                    FOREIGN KEY ("track_id") REFERENCES "tracks"("id")
                    ON UPDATE NO ACTION ON DELETE NO ACTION,
                    FOREIGN KEY ("playlist_id") REFERENCES "playlists"("id")
                    ON UPDATE NO ACTION ON DELETE NO ACTION
                );
            `);
        }

        function insertPlaylistsIntoDb(items) {
            const stmt = db.prepare(
                'INSERT OR IGNORE INTO playlists (id, name, collaborative, public) VALUES (?, ?, ?, ?)'
            );
            db.run('BEGIN');
            for (const item of items) {
                stmt.run([item.id, item.name ?? null, item.collaborative ?? null, item.public ?? null]);
            }
            db.run('COMMIT');
            stmt.free();
        }

        function insertPageIntoDb(pageItems) {
            const stmtAlbum = db.prepare(
                'INSERT OR IGNORE INTO albums (id, name, release_date, album_type) VALUES (?, ?, ?, ?)'
            );
            const stmtTrack = db.prepare(
                'INSERT OR IGNORE INTO tracks (id, name, album_id, duration_ms) VALUES (?, ?, ?, ?)'
            );
            const stmtArtist = db.prepare(
                'INSERT OR IGNORE INTO artists (id, name) VALUES (?, ?)'
            );
            const stmtAlbumArtist = db.prepare(
                'INSERT OR IGNORE INTO album_artists (album_id, artist_id) VALUES (?, ?)'
            );
            const stmtTrackArtist = db.prepare(
                'INSERT OR IGNORE INTO track_artists (track_id, artist_id) VALUES (?, ?)'
            );
            const stmtTrackPlaylist = db.prepare(
                'INSERT OR IGNORE INTO track_playlists (track_id, playlist_id, position, added_at) VALUES (?, ?, ?, ?)'
            );

            db.run('BEGIN');
            for (const t of pageItems) {
                stmtAlbum.run([t.albumId, t.albumName ?? null, t.albumReleaseDate ?? null, t.albumType ?? null]);
                stmtTrack.run([t.trackId, t.trackName ?? null, t.albumId ?? null, t.trackDurationMs ?? null]);
                stmtTrackPlaylist.run([t.trackId, t.playlistId, t.position, t.trackAddedAt ?? null]);

                for (const a of (t.artists ?? [])) {
                    stmtArtist.run([a.artistId, a.artistName ?? null]);
                    stmtTrackArtist.run([t.trackId, a.artistId]);
                }
                for (const a of (t.albumArtists ?? [])) {
                    stmtArtist.run([a.artistId, a.artistName ?? null]);
                    stmtAlbumArtist.run([t.albumId, a.artistId]);
                }
            }
            db.run('COMMIT');

            stmtAlbum.free();
            stmtTrack.free();
            stmtArtist.free();
            stmtAlbumArtist.free();
            stmtTrackArtist.free();
            stmtTrackPlaylist.free();
        }

        async function saveDbAndRedirect() {
            const binary = db.export();
            await new Promise((resolve, reject) => {
                const req = indexedDB.open('spotify-sql', 1);
                req.onupgradeneeded = e => {
                    e.target.result.createObjectStore('db');
                };
                req.onsuccess = e => {
                    const idb = e.target.result;
                    const tx = idb.transaction('db', 'readwrite');
                    tx.objectStore('db').put(binary, 'database');
                    tx.oncomplete = resolve;
                    tx.onerror = reject;
                };
                req.onerror = reject;
            });
            localStorage.removeItem('spotify-sql-query');
            window.location.href = 'query.php';
        }

        // ── Playlists ─────────────────────────────────────────────────────────

        function getSelectedItems() {
            const selectedItems = [];
            const checkboxes = elDivPlaylists.querySelectorAll('input[type="checkbox"]');

            for (const checkbox of checkboxes) {
                if (!checkbox.checked) {
                    continue;
                }

                const kind = checkbox.dataset.kind;
                const id = checkbox.dataset.id;
                const selectedItem = playlistItems.find((playlistItem) => playlistItem.kind === kind && playlistItem.id === id);

                if (selectedItem) {
                    selectedItems.push(selectedItem);
                }
            }

            return selectedItems;
        }

        function updateRetrieveButtonState() {
            elBtnRetrieve.disabled = getSelectedItems().length === 0;
        }

        function getSortedItems() {
            const sorted = [...playlistItems];
            if (elSelectSort.value === 'tracks') {
                sorted.sort((a, b) => (b.total ?? -1) - (a.total ?? -1));
            } else {
                sorted.sort((a, b) => (a.name ?? '').localeCompare(b.name ?? ''));
            }
            return sorted;
        }

        // Render selectable playlists.
        function renderPlaylists(items) {
            elDivPlaylists.innerHTML = '';

            const list = document.createElement('div');
            list.className = 'playlistList';

            for (const item of items) {
                const row = document.createElement('label');
                row.classList.add('playlistRow');

                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.dataset.kind = item.kind;
                checkbox.dataset.id = item.id;
                checkbox.dataset.endpoint = item.tracks_endpoint || '';
                checkbox.addEventListener('change', updateRetrieveButtonState);

                const meta = document.createElement('div');
                meta.className = 'playlistMeta';
                const countHtml =
                    item.total === null || item.total === undefined
                        ? ''
                        : `<span class="playlistCount">${item.total} tracks</span>`;

                meta.innerHTML = `
                    <div class="playlistName">${escapeHtml(item.name || '(untitled)')}${countHtml}</div>
                    <div class="playlistId">${escapeHtml(item.id)}</div>
                `;

                row.appendChild(checkbox);
                row.appendChild(meta);
                list.appendChild(row);
            }

            elDivPlaylists.appendChild(list);
        }

        // ── Fetch ─────────────────────────────────────────────────────────────

        /**
        * Fetch all tracks for a single playlist via paginated requests.
        * The endpoint is expected to support limit/offset and return:
        * { items, total, limit, offset, next? }
        *
        * If total is missing, pagination continues until a page contains fewer
        * items than the requested limit.
        */
        async function fetchAllTracksForPlaylist(item, currentGlobalFetched) {
            let offset = 0;
            let totalFetchedThisPlaylist = 0;
        
            while (true) {
                const params = new URLSearchParams({
                    ...(IS_DEMO ? { demo: '1' } : {}),
                    kind: item.kind,
                    id: item.id,
                    limit: String(TRACKS_PAGE_LIMIT),
                    offset: String(offset),
                    totalFetched: String(currentGlobalFetched + totalFetchedThisPlaylist),
                });
                const page = await fetchJson(`api/tracks.php?${params}`);
        
                const pageItems = Array.isArray(page.items) ? page.items : [];

                if (pageItems.length > 0) insertPageIntoDb(pageItems);

                totalFetchedThisPlaylist += pageItems.length;
        
                // If the backend signals the limit has been reached
                if (page.limitReached === true) {
                    break;
                }
        
                // Normal pagination-stop
                if (pageItems.length < TRACKS_PAGE_LIMIT || 
                    (page.total && offset + TRACKS_PAGE_LIMIT >= page.total)) {
                    break;
                }
        
                offset += TRACKS_PAGE_LIMIT;
                await new Promise(r => setTimeout(r, 350)); // delay to avoid rate limiting
            }
        
            return {
                playlist: { kind: item.kind, id: item.id, name: item.name },
                totalFetched: totalFetchedThisPlaylist,
                limitReached: totalFetchedThisPlaylist + currentGlobalFetched >= MAX_TRACKS_GLOBAL
            };
        }

        /**
        * Fetch tracks for all selected playlists.
        * Requests are executed sequentially to reduce the risk of Spotify throttling.
        */
        async function retrieveTracksFromSelected() {
            const selectedItems = getSelectedItems();
            if (selectedItems.length === 0) return;

            elBtnRetrieve.disabled = true;
            setStatus('Initializing database...');
            await initDb();
            insertPlaylistsIntoDb(selectedItems);
            setStatus(`Retrieving tracks from ${selectedItems.length} playlists...`);

            let totalFetchedGlobal = 0;
            let limitReached = false;

            try {
                for (let index = 0; index < selectedItems.length; index++) {
                    if (limitReached) {
                        break;
                    }

                    const item = selectedItems[index];
                    setStatus(`(${index + 1}/${selectedItems.length}) Retrieving: ${item.name}...`);

                    const result = await fetchAllTracksForPlaylist(item, totalFetchedGlobal);

                    totalFetchedGlobal += result.totalFetched || 0;

                    // Check if global limit is reached
                    if (result.limitReached === true || totalFetchedGlobal >= MAX_TRACKS_GLOBAL) {
                        limitReached = true;
                        const msg = `Global limit ${MAX_TRACKS_GLOBAL} tracks reached. Skips remaining playlists.`;
                        setStatus(msg);
                        console.warn(msg);
                    }
                }

                setStatus(`Retrieved ${totalFetchedGlobal} tracks. Saving database...`);
                await saveDbAndRedirect();
            } catch (error) {
                const message = error instanceof Error ? error.message : String(error);
                setStatus(`Error: ${message}`, true);
            } finally {
                updateRetrieveButtonState();
            }
        }

        // ── Init ──────────────────────────────────────────────────────────────

        async function init() {
            setStatus('Loading playlists...');
            elBtnRetrieve.disabled = true;

            try {
                const data = await fetchJson(`api/playlists.php${IS_DEMO ? '?demo=1' : ''}`);
                playlistItems = Array.isArray(data.items) ? data.items : [];

                renderPlaylists(getSortedItems());
                setStatus(`Done. Found ${playlistItems.length} playlists.`);
            } catch (error) {
                const message = error instanceof Error ? error.message : String(error);
                setStatus(`Error: ${message}`, true);
            }
        }

        // ── Event listeners ───────────────────────────────────────────────────

        elSelectSort.addEventListener('change', () => renderPlaylists(getSortedItems()));

        elBtnRetrieve.addEventListener('click', retrieveTracksFromSelected);

        elBtnSelectAll.addEventListener('click', () => {
            for (const checkbox of elDivPlaylists.querySelectorAll('input[type="checkbox"]')) {
                checkbox.checked = true;
            }
            updateRetrieveButtonState();
        });

        elBtnSelectNone.addEventListener('click', () => {
            for (const checkbox of elDivPlaylists.querySelectorAll('input[type="checkbox"]')) {
                checkbox.checked = false;
            }
            updateRetrieveButtonState();
        });

        init();
    </script>
</body>
</html>