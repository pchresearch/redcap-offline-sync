/**
 * Offline Sync, offline mode.
 *
 * Mirrors the open form into encrypted browser storage and drips changed values
 * back to the server on a queue that survives the wifi going away.
 *
 * Two rules hold the design together. The DOM is the truth: the pending set is
 * always rebuilt from what is on screen, never bookkept, which is what makes a
 * deletion behave like any other edit. And every tab mirrors locally; only
 * sending is restricted to one tab at a time.
 */
$(function() {
    'use strict';

    // The action-tag layer already hangs save(), init() and findInput() off the
    // module object, so keep our state somewhere else and borrow only ajax().
    let module = (window.OfflineSync = {});
    let transport = OfflineSyncModule;
    let cfg = OfflineSyncSettings;

    module.DB_NAME = 'offlineSync';
    module.DB_VERSION = 1;
    module.STORE_KEYS = 'keys';
    module.STORE_DRAFTS = 'drafts';

    module.TYPING_DEBOUNCE = 600;   // ms of quiet before a keystroke becomes a draft
    module.AJAX_TIMEOUT = 30000;    // a request that never answers must not wedge the queue
    module.LEASE_RENEW = 2000;      // fallback election only
    module.LEASE_STALE = 6000;
    module.TAB_PROBE = 250;         // how long to wait for another tab to answer

    module.db = null;
    module.cryptoKey = null;
    module.baseline = {};        // what the server had when this page loaded
    module.lastKnownServer = {}; // updated as batches save, used for conflict checks
    module.pending = {};         // changed since the last successful save
    module.conflicted = {};      // fields waiting on the user to pick a side
    module.refused = {};         // field => { value, why } REDCap will not accept
    module.flushTimer = null;
    module.typingTimer = null;
    module.leaseTimer = null;
    module.retryDelay = 0;
    module.busy = false;
    module.running = false;      // handlers bound, mirroring to the device
    module.isLeader = false;     // this tab is the one allowed to talk to the server
    module.stopped = false;      // the server said retrying will never help
    module.storageBroken = false;
    module.draftId = null;       // not known until the tab token is settled
    module.draftChain = Promise.resolve();
    module.held = null;          // a draft offered on screen and not yet answered
    module.savedHere = {};       // fields this page has saved since it loaded
    module.sendSeq = 0;          // every batch gets a number
    module.acceptedSeq = 0;      // the newest one whose answer we have used

    module.TAB_SLOT = 'ofs:tab';
    module.CHANNEL = 'ofs-tabs';

    module.recordKey = function() {
        return (cfg.record !== null && cfg.record !== '') ? cfg.record : 'new-record';
    };

    // A survey page is keyed by page number as well: the respondent has no
    // username, and a multi-page survey shows one page's fields at a time, so
    // each page keeps its own row. The link hash is left out on purpose: a
    // public survey moves from the public link to the record's own link after
    // its first page, and a key carrying the hash would split one response in
    // two. Event ids are unique across a REDCap server, and the project id is
    // there for good measure.
    module.baseKey = cfg.survey
        ? ['survey', cfg.projectId || 0, module.recordKey(), cfg.eventId, cfg.instrument, cfg.instance, 'p' + (cfg.page || 1)].join('|')
        : [cfg.user, module.recordKey(), cfg.eventId, cfg.instrument, cfg.instance].join('|');
    // all pages of one survey response, whatever page or record they carry;
    // must match surveySeries() on the server
    module.series = cfg.survey
        ? ['survey', cfg.projectId || 0, cfg.eventId, cfg.instrument, cfg.instance].join('|')
        : ['entry', cfg.projectId || 0, cfg.user].join('|');   // one user's data entry rows in this project
    module.lockName = 'ofs:sync:' + module.baseKey;
    module.leaseKey = 'ofs:lease:' + module.baseKey;

    /* ------------------------------------------------------------------ */
    /* which tab am I                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * A per-tab token, so two tabs on one record keep separate drafts.
     * sessionStorage survives a reload and a captive portal bounce but dies with
     * the tab, which is exactly the lifetime we want. Snag: opening a link in a
     * new tab copies sessionStorage, so ask around first and mint a fresh token
     * if another tab answers to this one.
     */
    module.settleTabToken = function() {
        let stored = null;
        try { stored = sessionStorage.getItem(module.TAB_SLOT); } catch (e) {}

        let mint = function() {
            let fresh = 't' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36);
            try { sessionStorage.setItem(module.TAB_SLOT, fresh); } catch (e) {}
            return fresh;
        };

        if (!stored) return Promise.resolve(mint());
        if (!(navigator.locks && navigator.locks.request) && !cfg.survey) {
            // Without Web Locks the liveness probe can miss a frozen original,
            // so only a reload, which cannot be a duplicate, keeps the token.
            // Not on a survey: there, Next is a navigation, and the token has
            // to survive it so earlier pages can be retired.
            let nav = (performance.getEntriesByType ? performance.getEntriesByType('navigation') : [])[0];
            if (nav && nav.type != 'reload' && nav.type != 'back_forward') return Promise.resolve(mint());
        }
        return module.tabAlive(stored).then(function(alive) { return alive ? mint() : stored; });
    };

    module.answerProbes = function() {
        if (typeof BroadcastChannel == 'undefined') return;
        module.channel = new BroadcastChannel(module.CHANNEL);
        module.channel.onmessage = function(e) {
            if (e.data && e.data.type == 'ping' && e.data.token == module.tabToken) {
                module.channel.postMessage({ type: 'pong', token: module.tabToken });
            }
        };
    };

    /* ------------------------------------------------------------------ */
    /* one sender at a time                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Web Locks is ideal here: held while the promise is unresolved, released by
     * the browser when the tab dies, crash included. No heartbeat to get wrong.
     * The localStorage lease below is only for browsers that lack it.
     */
    module.electLeader = function() {
        if (navigator.locks && navigator.locks.request) {
            navigator.locks.request(module.lockName, { mode: 'exclusive' }, function() {
                module.becomeLeader();
                return new Promise(function() {}); // held until this tab is gone
            }).catch(function() { module.leaseElection(); });
            return;
        }
        module.leaseElection();
    };

    module.leaseElection = function() {
        let tryClaim = function() {
            let now = Date.now();
            let held = null;
            try {
                let raw = localStorage.getItem(module.leaseKey);
                if (raw) held = JSON.parse(raw);
            } catch (e) {
                module.becomeLeader();
                return;
            }

            if (held && held.tab != module.tabToken && (now - held.at) < module.LEASE_STALE) {
                // Lost it. Stand down rather than becoming a second sender:
                // background tabs get throttled to one timer a minute, so
                // quietly losing a six second lease is routine.
                if (module.isLeader) {
                    module.isLeader = false;
                    module.setStatus();
                }
                return;
            }

            try { localStorage.setItem(module.leaseKey, JSON.stringify({ tab: module.tabToken, at: now })); } catch (e) {}
            module.becomeLeader();
        };

        tryClaim();
        module.leaseTimer = setInterval(tryClaim, module.LEASE_RENEW);
    };

    module.becomeLeader = function() {
        if (module.isLeader) return;
        module.isLeader = true;
        // no re-reading the baseline: anything typed while standing by is a
        // real change and still needs sending
        module.setStatus();
        if (Object.keys(module.pending).length) module.flush();
    };

    module.releaseLease = function() {
        if (!module.leaseTimer) return;
        try {
            let raw = localStorage.getItem(module.leaseKey);
            if (raw && JSON.parse(raw).tab == module.tabToken) localStorage.removeItem(module.leaseKey);
        } catch (e) {}
    };

    /* ------------------------------------------------------------------ */
    /* device storage                                                      */
    /* ------------------------------------------------------------------ */

    module.openDb = function() {
        return new Promise(function(resolve, reject) {
            let req = indexedDB.open(module.DB_NAME, module.DB_VERSION);
            req.onupgradeneeded = function(e) {
                let db = e.target.result;
                if (!db.objectStoreNames.contains(module.STORE_KEYS)) db.createObjectStore(module.STORE_KEYS);
                if (!db.objectStoreNames.contains(module.STORE_DRAFTS)) db.createObjectStore(module.STORE_DRAFTS);
            };
            req.onsuccess = function(e) { resolve(e.target.result); };
            req.onerror = function(e) { reject(e.target.error); };
            req.onblocked = function() { reject(new Error('another tab is holding an old version of the database')); };
        });
    };

    module.idbPut = function(store, key, value) {
        return new Promise(function(resolve, reject) {
            let tx = module.db.transaction(store, 'readwrite');
            tx.objectStore(store).put(value, key);
            tx.oncomplete = resolve;
            tx.onerror = function(e) { reject(e.target.error); };
            tx.onabort = function(e) { reject(e.target.error || new Error('transaction aborted')); };
        });
    };

    module.idbGet = function(store, key) {
        return new Promise(function(resolve, reject) {
            let tx = module.db.transaction(store, 'readonly');
            let req = tx.objectStore(store).get(key);
            req.onsuccess = function() { resolve(req.result); };
            req.onerror = function(e) { reject(e.target.error); };
            tx.onabort = function(e) { reject(e.target.error || new Error('transaction aborted')); };
        });
    };

    module.idbDelete = function(store, key) {
        return new Promise(function(resolve, reject) {
            let tx = module.db.transaction(store, 'readwrite');
            tx.objectStore(store).delete(key);
            tx.oncomplete = resolve;
            tx.onerror = function(e) { reject(e.target.error); };
            tx.onabort = function(e) { reject(e.target.error || new Error('transaction aborted')); };
        });
    };

    module.idbEachDraft = function(callback) {
        return new Promise(function(resolve, reject) {
            let tx = module.db.transaction(module.STORE_DRAFTS, 'readwrite');
            let req = tx.objectStore(module.STORE_DRAFTS).openCursor();
            req.onsuccess = function() {
                let cursor = req.result;
                if (!cursor) { resolve(); return; }
                callback(cursor);
                cursor.continue();
            };
            req.onerror = function(e) { reject(e.target.error); };
        });
    };

    /**
     * One AES-GCM key per user per browser, stored as a CryptoKey rather than as
     * bytes. extractable:false means nothing can read the key back out, so
     * lifting the IndexedDB files off the tablet yields ciphertext and no key.
     */
    module.loadKey = async function() {
        let keyName = 'aes:' + cfg.user;
        let existing = await module.idbGet(module.STORE_KEYS, keyName);
        if (existing) return existing;

        let key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
        await module.idbPut(module.STORE_KEYS, keyName, key);
        return key;
    };

    module.encrypt = async function(obj) {
        let iv = crypto.getRandomValues(new Uint8Array(12));
        let plain = new TextEncoder().encode(JSON.stringify(obj));
        let cipher = await crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv }, module.cryptoKey, plain);
        return { iv: Array.from(iv), body: Array.from(new Uint8Array(cipher)) };
    };

    module.decrypt = async function(blob) {
        let iv = new Uint8Array(blob.iv);
        let body = new Uint8Array(blob.body);
        let plain = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: iv }, module.cryptoKey, body);
        return JSON.parse(new TextDecoder().decode(plain));
    };

    /**
     * What a row holds, inside the encrypted blob:
     *   values   the whole form as this tab last saw it
     *   pending  this tab's own unsaved changes
     *   seen     what this tab believed the server held for each field
     *   held     answers recovered from an earlier page and not yet answered,
     *            kept apart from pending so typing can never overwrite them
     *
     * Chained behind any write already in flight. Two encrypts finishing out of
     * order would leave the older snapshot on disk, which is the one thing a
     * draft store must never do.
     */
    module.saveDraft = function() {
        if (!module.db || !module.cryptoKey || !module.draftId) return module.draftChain;
        // nothing is written until the previous page's row has been read: a
        // blur or a tab switch during startup used to overwrite it with a clean
        // snapshot, and the offer it carried was gone before it was seen
        if (!module.offerSettled) return module.draftChain;

        module.draftChain = module.draftChain.then(async function() {
            try {
                let pending = $.extend({}, module.pending);
                let held = module.heldForStorage();
                let blob = await module.encrypt({
                    values: module.readForm(),
                    pending: pending,
                    seen: $.extend({}, module.lastKnownServer),
                    held: held
                });
                await module.idbPut(module.STORE_DRAFTS, module.draftId, {
                    id: module.draftId,
                    series: module.series,   // a survey's pages share this, so earlier pages can be retired
                    page: cfg.survey ? (cfg.page || 1) : null,
                    savedAt: Date.now(),
                    hasPending: Object.keys(pending).length > 0 || held !== null, // a yes/no is not a secret, and it lets the scan skip clean rows
                    ttlHours: cfg.ttlHours,
                    base: module.baseKey,
                    tab: module.tabToken,
                    user: cfg.user,
                    record: cfg.record,
                    instrument: cfg.instrument,
                    blob: blob
                });
                module.lastWriteOk = true;
                if (module.storageBroken) { module.storageBroken = false; module.setStatus(); }
            } catch (err) {
                console.log('Offline Sync: could not write draft', err);
                module.lastWriteOk = false;
                module.storageBroken = true;
                module.setStatus();
            }
        });
        return module.draftChain;
    };

    /**
     * The offer as it should go to disk: what is still on offer, plus any
     * "mine" from a clash raised while restoring, which is on no screen and in
     * no pending set until the user answers it.
     */
    module.heldForStorage = function() {
        let values = {}, seen = {}, since = null, sources = [];
        if (module.held) {
            values = $.extend({}, module.held.values);
            seen = $.extend({}, module.held.seen);
            since = module.held.since;
            sources = module.held.sources.slice();
        }
        Object.keys(module.conflicted).forEach(function(field) {
            let clash = module.conflicted[field];
            if (!clash.apply) return;
            values[field] = clash.mine;
            if ('seen' in clash) seen[field] = clash.seen;
            if (clash.since && (!since || clash.since < since)) since = clash.since;
        });
        if (!Object.keys(values).length) return null;
        return { values: values, seen: seen, since: since || Date.now(), sources: sources };
    };

    /**
     * Everything this device holds for this record that the server does not,
     * gathered into one offer. Our own row first, then rows left by tabs that
     * are no longer alive, newest first; the first row to speak for a field
     * wins. A row that has nothing to add and no living tab is deleted on the
     * way past, or it would sit in front of older ones until the TTL.
     */
    module.collectOffer = async function() {
        let rows = [];
        await module.idbEachDraft(function(cursor) {
            let row = cursor.value;
            if (!row || row.base != module.baseKey) return;
            if (module.expired(row)) { cursor.delete(); return; }
            if (row.hasPending === false) return;
            row.__key = cursor.primaryKey;
            rows.push(row);
        });

        rows.sort(function(a, b) {
            if (a.tab == module.tabToken) return -1;
            if (b.tab == module.tabToken) return 1;
            return b.savedAt - a.savedAt;
        });

        let offer = { values: {}, seen: {}, fieldSince: {}, since: null, sources: [], fromAnotherTab: false };

        for (let i = 0; i < rows.length; i++) {
            let row = rows[i];
            let own = (row.tab == module.tabToken);
            if (!own && await module.tabAlive(row.tab)) continue;   // that tab is looking after its own work

            let opened;
            try { opened = await module.decrypt(row.blob); }
            catch (err) {
                // Wrong key, so this row belongs to another user on this tablet.
                // Leave it: deleting someone else's unsaved work to tidy our own
                // screen is not a trade worth making. The TTL will clear it.
                console.log('Offline Sync: a draft here could not be opened with this key, leaving it');
                continue;
            }

            let added = false;
            let take = function(field, value, seenValue, since) {
                if (!cfg.fields[field]) return;
                if (!module.valuesDiffer(value, module.baseline[field])) return;   // the page already shows it
                // newest answer for the field wins, whichever row it came from
                if (field in offer.values && !(since && offer.fieldSince[field] && since > offer.fieldSince[field])) return;
                offer.values[field] = value;
                offer.fieldSince[field] = since || 0;
                if (typeof seenValue != 'undefined') offer.seen[field] = seenValue; else delete offer.seen[field];
                if (since && (!offer.since || since < offer.since)) offer.since = since;
                added = true;
            };

            // that tab's own unsaved changes first, alive as of its last write:
            // they are newer than anything it was itself still offering
            let unsaved = module.unsavedIn(opened);
            Object.keys(unsaved).forEach(function(field) {
                take(field, unsaved[field], opened.seen ? opened.seen[field] : undefined, row.savedAt);
            });

            // then answers an earlier page was already offering, if they have not aged out
            if (opened.held && opened.held.values && module.hoursSince(opened.held.since || row.savedAt) <= (row.ttlHours || cfg.ttlHours)) {
                Object.keys(opened.held.values).forEach(function(field) {
                    take(field, opened.held.values[field], opened.held.seen ? opened.held.seen[field] : undefined, opened.held.since || row.savedAt);
                });
                (opened.held.sources || []).forEach(function(id) { if (offer.sources.indexOf(id) < 0) offer.sources.push(id); });
            }

            if (own) continue;                       // our row is rewritten anyway
            if (!added) { try { await module.idbDelete(module.STORE_DRAFTS, row.__key); } catch (e) {} continue; }
            offer.fromAnotherTab = true;
            if (offer.sources.indexOf(row.__key) < 0) offer.sources.push(row.__key);
        }

        return Object.keys(offer.values).length ? offer : null;
    };

    /**
     * Is the tab that minted this token still alive. Web Locks first: a tab
     * holds a lock named after its token for as long as the page exists, so a
     * frozen tab still counts as alive and a crashed or discarded one does not,
     * with no waiting. Without Web Locks, ask over the channel and give it a
     * moment to answer.
     */
    module.tabAlive = function(token) {
        if (!token) return Promise.resolve(false);
        if (navigator.locks && navigator.locks.request) {
            return navigator.locks.request('ofs:tab:' + token, { ifAvailable: true }, function(lock) {
                return lock === null;       // somebody else holds it, so they are alive
            }).catch(function() { return false; });
        }
        if (typeof BroadcastChannel == 'undefined') return Promise.resolve(false);
        return new Promise(function(resolve) {
            let answered = false;
            let probe = new BroadcastChannel(module.CHANNEL);
            probe.onmessage = function(e) {
                if (e.data && e.data.type == 'pong' && e.data.token == token) answered = true;
            };
            probe.postMessage({ type: 'ping', token: token });
            setTimeout(function() { probe.close(); resolve(answered); }, module.TAB_PROBE);
        });
    };

    /**
     * The framework runs every module.ajax() call through one promise queue, so
     * two requests never overlap. Fine, except that a request whose fetch never
     * settles, the classic roaming-tablet black hole, blocks that queue for the
     * life of the page: our own 30 s timeout gives up on the promise, the next
     * flush enqueues behind the hung fetch, and nothing leaves the browser again
     * until a reload. Seen live: seven "sends", one request on the wire, pill
     * flipping between Saving and Waiting for ever.
     *
     * The queue is looked up by property on every call, so it can be replaced
     * with one that keeps the serialisation but moves on once a task has been
     * silent for longer than our timeout. The task itself is returned untouched.
     */
    module.unwedgeFrameworkQueue = function() {
        if (!window.ExternalModules || typeof ExternalModules.__ajaxQueue != 'function') return;
        if (ExternalModules.__ajaxQueue.__ofs) return;
        let queue = Promise.resolve();
        let enqueue = function(requestFunc) {
            let task = queue.then(requestFunc);
            let release = new Promise(function(resolve) {
                let done = false;
                // a little longer than our own timeout, so our request gives up first
                let timer = setTimeout(function() { if (!done) { done = true; resolve(); } }, module.AJAX_TIMEOUT + 1000);
                let settle = function() { if (!done) { done = true; clearTimeout(timer); resolve(); } };
                task.then(settle, settle);
            });
            queue = release;
            return task;
        };
        enqueue.__ofs = true;
        ExternalModules.__ajaxQueue = enqueue;
    };

    /** hold this tab's liveness lock for the life of the page */
    module.holdTabLock = function() {
        if (!(navigator.locks && navigator.locks.request)) return;
        navigator.locks.request('ofs:tab:' + module.tabToken, function() {
            return new Promise(function() {});   // released by the browser when the page goes
        }).catch(function() {});
    };

    /**
     * Discard: forget the offer and remove the rows it was gathered from. Rows
     * nobody was shown are left alone; a draft the user never saw is not
     * something they agreed to throw away. Runs behind any write in flight so
     * a save already past the fold cannot resurrect what was just discarded.
     */
    module.dropDraft = function() {
        let sources = module.held ? module.held.sources.slice() : [];
        module.held = null;
        if (!module.draftId || !module.db) return Promise.resolve();
        module.draftChain = module.draftChain.then(async function() {
            for (let i = 0; i < sources.length; i++) {
                try { await module.idbDelete(module.STORE_DRAFTS, sources[i]); } catch (e) {}
            }
        });
        return module.draftChain;
    };

    /** the source rows have done their job once our own row carries the answers */
    module.releaseSources = function(sources) {
        if (!sources || !sources.length || !module.db) return;
        module.draftChain = module.draftChain.then(async function() {
            if (!module.lastWriteOk) return;   // our row did not land, so theirs must stay
            for (let i = 0; i < sources.length; i++) {
                if (sources[i] == module.draftId) continue;
                try { await module.idbDelete(module.STORE_DRAFTS, sources[i]); } catch (e) {}
            }
        });
    };

    /**
     * The answers in a row that the server does not have: what it recorded as
     * pending, or for a row written before pending was recorded, whatever
     * differs from the values this page loaded with.
     */
    module.unsavedIn = function(draft) {
        let out = {};
        if (!draft || !draft.values) return out;
        // a row with an empty pending set is a clean row, not a row from before
        // pending was recorded; only the latter falls back to comparing values
        let source = ('pending' in draft && draft.pending) ? draft.pending : draft.values;
        Object.keys(source).forEach(function(field) {
            if (!cfg.fields[field]) return;
            let value = (field in draft.values) ? draft.values[field] : source[field];
            if (module.valuesDiffer(value, module.baseline[field])) out[field] = value;
        });
        return out;
    };

    /** the offer, minus fields the user is typing in right now (hidden, not dropped) */
    module.stillUnsaved = function(offer) {
        let out = {};
        if (!offer) return out;
        Object.keys(offer.values).forEach(function(field) {
            let live = module.readField(field);
            if (live !== null && module.valuesDiffer(live, module.baseline[field])) return;
            out[field] = offer.values[field];
        });
        return out;
    };

    /**
     * Which offered fields somebody else changed between the draft and this
     * page: the draft's idea of the server differs from what the server held
     * when this page loaded. Never compared against the screen, which @DEFAULT
     * may have pre-filled with something the database does not have, and not
     * against what this page has saved since, which is our own doing.
     */
    module.contestedIn = function(offer, fields) {
        let out = {};
        if (!offer || !offer.seen) return out;
        let server = module.serverAtLoad || module.lastKnownServer;
        Object.keys(fields).forEach(function(field) {
            if (!(field in offer.seen) || !(field in server)) return;
            if (module.valuesDiffer(offer.seen[field], server[field])) out[field] = server[field];
        });
        return out;
    };

    // Rows age from their last write; a tab that is alive keeps its row fresh
    // and a dead one stops. Offered answers age separately, from when they were
    // first held, in collectOffer, so an ignored offer does not live for ever
    // just because every page load rewrites the row that carries it.
    module.expired = function(row) {
        if (!row || !row.savedAt) return true;
        let limit = row.ttlHours ? row.ttlHours : cfg.ttlHours;
        return module.hoursSince(row.savedAt) > limit;
    };

    /**
     * A new record's first form is mirrored under 'new-record' because the
     * record has no id until REDCap saves it. Once this tab is looking at a real
     * record, that save happened, and the 'new-record' row it left behind must
     * go: seen live, record 10-3's consent answers offered as unsaved on the
     * blank consent form of record 10-4. Rows written by other tabs are left,
     * because a tab that died before saving is exactly the case worth keeping.
     * The other way for this tab to reach a saved record is to walk away from
     * the new one through REDCap's "Leave site?" prompt, and a draft is a
     * safety net for crashes and reloads, not for a form left on purpose.
     */
    module.retireNewRecordRows = function() {
        if (!module.series || cfg.survey) return Promise.resolve();
        return module.idbEachDraft(function(cursor) {
            let row = cursor.value;
            if (!row || row.series != module.series || row.tab != module.tabToken) return;
            if (row.record === null || row.record === '') cursor.delete();
        });
    };

    /**
     * Reaching page N of a survey in this tab means pages before it were
     * submitted and saved, so this tab's rows for them have done their job.
     * Left behind, the first page's row of a public survey would be offered to
     * the next respondent on a shared tablet.
     */
    module.retireEarlierPages = function() {
        if (!module.series) return Promise.resolve();
        return module.idbEachDraft(function(cursor) {
            let row = cursor.value;
            if (!row || row.series != module.series || row.tab != module.tabToken) return;
            if (typeof row.page == 'number' && row.page < (cfg.page || 1)) cursor.delete();
        });
    };

    module.purgeStaleDrafts = function() {
        return module.idbEachDraft(function(cursor) {
            if (module.expired(cursor.value)) cursor.delete();
        });
    };

    module.hoursSince = function(when) {
        return (Date.now() - when) / 3600000;
    };

    /* ------------------------------------------------------------------ */
    /* reading and writing the form                                        */
    /* ------------------------------------------------------------------ */

    // field names are trusted-ish, but they still end up inside a selector
    module.sel = function(name) {
        if (window.CSS && CSS.escape) return CSS.escape(name);
        return String(name).replace(/["\\]/g, '\\$&');
    };


    /**
     * One checkbox choice, as REDCap 17 really renders it:
     *
     *   <input type=hidden   name="__chk__<field>_RC_<code>">      holds the code
     *   <input type=checkbox id="id-__chk__<field>_RC_<code>" name="__chkn__<field>">
     *
     * Note what is NOT there: nothing is named <field>___<code>. That is the name
     * saveData wants, which is a different thing entirely, and confusing the two
     * meant checkbox support silently did nothing for three review rounds.
     */
    module.checkboxInput = function(field, code) {
        let byId = document.getElementById('id-__chk__' + field + '_RC_' + code);
        if (byId) return $(byId);
        // fall back to the flat naming, for any rendering that uses it
        return $('input[type=checkbox][name="' + module.sel(field + '___' + code) + '"]');
    };

    module.readField = function(field) {
        let spec = cfg.fields[field];
        if (!spec) return null;

        if (spec.type == 'checkbox') {
            let ticked = [];
            let seen = 0;
            spec.choices.forEach(function(code) {
                let box = module.checkboxInput(field, code);
                if (!box.length) return;
                seen++;
                if (box.prop('checked')) ticked.push(String(code));
            });
            if (!seen) return null;
            ticked.sort();
            return ticked;
        }

        let input = module.mirrorInput(field);
        if (!input.length) return null;
        return input.val();
    };

    /** the choices this page actually rendered, which is not always all of them */
    module.visibleChoices = function(field) {
        let spec = cfg.fields[field];
        if (!spec || spec.type != 'checkbox') return null;
        return spec.choices.filter(function(code) {
            return module.checkboxInput(field, code).length > 0;
        }).map(String);
    };

    module.readForm = function() {
        let values = {};
        Object.keys(cfg.fields).forEach(function(field) {
            let v = module.readField(field);
            if (v !== null) values[field] = v;
        });
        return values;
    };

    /**
     * What the server holds, as the starting point for the pending set. The
     * page hands it over when it can; otherwise the screen has to stand in for
     * it. The two differ on a form with no data yet, where @DEFAULT and friends
     * pre-fill the screen: taking those as saved would raise a false conflict
     * on the first edit and leave the pre-filled values unsaved for good.
     */
    module.serverSnapshot = function() {
        let out = {};
        let given = (cfg.serverValues && typeof cfg.serverValues == 'object') ? cfg.serverValues : null;
        Object.keys(module.baseline).forEach(function(field) {
            if (given && field in given) out[field] = given[field];
            else if (given) out[field] = (cfg.fields[field].type == 'checkbox') ? [] : '';
            else out[field] = module.baseline[field];
        });
        return out;
    };

    /**
     * Radios and checkboxes need a real click, not .checked = true. REDCap keeps
     * the submitted value in a parallel hidden input that only its own handler
     * updates, so assigning the property shows the right thing and submits the
     * wrong one.
     */
    module.writeField = function(field, value) {
        let spec = cfg.fields[field];
        if (!spec) return;

        if (spec.type == 'checkbox') {
            let wanted = (value || []).map(String);
            spec.choices.forEach(function(code) {
                let box = module.checkboxInput(field, code);
                if (!box.length) return;
                let shouldBeOn = wanted.indexOf(String(code)) > -1;
                if (box.prop('checked') != shouldBeOn) box.trigger('click');
            });
            return;
        }

        if (spec.type == 'radio' || spec.type == 'yesno' || spec.type == 'truefalse') {
            if (value === '' || value === null || typeof value == 'undefined') {
                module.clearRadio(field);
                return;
            }
            let button = module.radioButton(field, value);
            if (button.length) {
                if (!button.prop('checked')) button.trigger('click');
                // REDCap's own click handler copies the value into the hidden
                // mirror that actually gets submitted. If that did not happen,
                // for whatever reason, do it by hand rather than leave the
                // screen and the stored value disagreeing.
                let mirror = module.mirrorInput(field);
                if (mirror.length && String(mirror.val()) !== String(value)) {
                    button.prop('checked', true);
                    mirror.val(value).trigger('change');
                }
                return;
            }
            // No button carries this value: a missing-data code, or one hidden
            // by @HIDECHOICE. Clear the group, or the screen keeps the old
            // answer while the stored value changes underneath it.
            module.clearRadio(field);
        }

        let input = module.mirrorInput(field);
        if (!input.length || String(input.val()) === String(value)) return false;
        if (input.is('select') && String(value) !== '' && !input.find('option').filter(function() { return String(this.value) === String(value); }).length) {
            // no such option: a code removed from the codebook or hidden with
            // @HIDECHOICE. Setting it would make .val() read null and the field
            // would quietly fall out of the mirror. Leave it and say so.
            return false;
        }
        input.val(value);
        module.syncAutocompleteLabel(input);
        input.trigger('change');
        return true;
    };

    /** the element that carries a field's submitted value: never a radio button */
    module.mirrorInput = function(field) {
        return $('[name="' + module.sel(field) + '"]').not('[type=radio]').first();
    };

    /**
     * One radio button. REDCap gives every button an id of opt-<field>_<code>,
     * so look there first; the name-and-value selector is the fallback for a
     * rendering without ids. Values are compared as strings on purpose.
     */
    module.radioButton = function(field, value) {
        // opt- on an ordinary radio, mtxopt- on a matrix row
        let byId = document.getElementById('opt-' + field + '_' + value) || document.getElementById('mtxopt-' + field + '_' + value);
        if (byId && byId.type == 'radio' && byId.name == field + '___radio') return $(byId);
        let group = $('input[type=radio][name="' + module.sel(field + '___radio') + '"]');
        return group.filter(function() { return String(this.value) === String(value); }).first();
    };

    /**
     * Clearing a radio is not clicking one: no button has a blank value, so both
     * halves have to be undone by hand. REDCap renders a reset link,
     * radioResetVal('<field>','form'), but calling it on 17.0.3 does nothing at
     * all, tested on a live form. So ignore the link. Side benefit: with no link
     * to match, there is no way to match the wrong field's link.
     */
    module.clearRadio = function(field) {
        let group = $('input[type=radio][name="' + module.sel(field + '___radio') + '"]');
        let hidden = module.mirrorInput(field);
        let mirrorHolds = hidden.length && String(hidden.val()) !== '';
        if (!group.filter(':checked').length && !mirrorHolds) return;

        group.prop('checked', false);
        if (hidden.length) {
            hidden.val('');
            hidden.trigger('change');
        }
    };

    /**
     * Put a draft back. A recovery action must never destroy work, so anything
     * the user has touched since the page loaded is left alone.
     */
    module.writeForm = function(values, onlyThese) {
        let skipped = [];
        let allowed = onlyThese ? Object.keys(onlyThese) : Object.keys(values);
        allowed.forEach(function(field) {
            if (!(field in values)) return;
            let draftValue = values[field];
            let live = module.readField(field);
            if (live === null) { skipped.push(field); return; }   // not on this page, so it stays held
            let draftBlank = (draftValue === '' || (Array.isArray(draftValue) && !draftValue.length));
            let liveBlank = (live === null || live === '' || (Array.isArray(live) && !live.length));

            // The baseline is what REDCap rendered from the database on this
            // load. Still matching it means the box holds the old server value,
            // which is what the draft is here to replace. Different means the
            // user has typed since, and that is theirs.
            let untouchedSinceLoad = !module.valuesDiffer(live, module.baseline[field]);
            if (!untouchedSinceLoad) { skipped.push(field); return; }
            // With a pending-derived list a blank is a deliberate answer: the
            // person cleared the field while offline. Only a whole-form draft
            // from before pending was recorded gets the benefit of the doubt.
            if (!onlyThese && draftBlank && !liveBlank) { skipped.push(field); return; }
            let done = module.writeField(field, draftValue);
            // a dropdown with no option for the value is left alone, and counted
            if (done === false && module.valuesDiffer(module.readField(field), draftValue)) skipped.push(field);
        });
        module.recalculate();
        return skipped;
    };

    /**
     * An autocomplete dropdown shows its label in a separate box, which REDCap
     * gives the id rc-ac-input_<field>. Target that, not the container: two
     * autocompletes in one block would otherwise swap labels.
     */
    module.syncAutocompleteLabel = function(input) {
        if (!input.is('select.rc-autocomplete')) return;
        let label = input.find('option:selected').text();
        let name = input.attr('name');
        let box = name ? $(document.getElementById('rc-ac-input_' + name)) : $();
        if (!box.length) box = input.closest('div,td').find('input.rc-autocomplete').first();
        box.val(label);
    };

    module.recalculate = function() {
        try { if (typeof doBranching == 'function') doBranching(); } catch (e) {}
        try { if (typeof calculate == 'function') calculate(); } catch (e) {}
    };

    /**
     * Joined on a space, not on nothing: ['1','23'] and ['12','3'] both collapse
     * to "123" otherwise, and two different sets of ticks compare as equal. Any
     * checkbox with ten or more choices can hit it. Mismatched shapes simply
     * differ rather than throwing.
     */
    module.valuesDiffer = function(a, b) {
        let aList = Array.isArray(a), bList = Array.isArray(b);
        if (aList || bList) {
            if (aList != bList) return true;
            let x = a.map(String).sort();
            let y = b.map(String).sort();
            return x.join(' ') != y.join(' ');
        }
        return String(a == null ? '' : a) !== String(b == null ? '' : b);
    };

    /* ------------------------------------------------------------------ */
    /* syncing                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Rebuild the pending set from the screen, every time. Nothing is ever
     * removed because we saved it; it is absent next time only if screen and
     * server now agree. That is what stops a deletion vanishing.
     */
    module.recomputePending = function() {
        let now = module.readForm();

        Object.keys(now).forEach(function(field) {
            let value = now[field];

            // they have edited a field that was in conflict. Take that as the
            // answer: their correction wins.
            if (module.conflicted[field]) {
                let clash = module.conflicted[field];
                // "edited since the clash was raised" is measured against what
                // the box showed at that moment, not against the value that was
                // in flight: they can differ if the person kept typing
                if (module.valuesDiffer(value, clash.shown)) {
                    clash.apply = false;   // the box holds their correction, do not overwrite it
                    module.resolveConflict(field, 'mine', module.inFlush);
                }
            }

            if (module.refused[field]) {
                if (!module.valuesDiffer(value, module.refused[field].value)) {
                    // still the value REDCap refused, so do not queue it and do
                    // not pretend it saved
                    delete module.pending[field];
                    module.showRefusal(field, module.refused[field].why);
                    return;
                }
                // corrected, so let it through again
                delete module.refused[field];
                $('.ofs-refusal[data-ofs-field="' + module.sel(field) + '"]').remove();
            }

            if (module.valuesDiffer(value, module.lastKnownServer[field])) {
                module.pending[field] = value;
            } else {
                delete module.pending[field];
            }
        });

        // A field that has left the page cannot be re-read. Drop it once the
        // server agrees, or it is retried forever.
        Object.keys(module.pending).forEach(function(field) {
            if (module.readField(field) !== null) return;
            if (!module.valuesDiffer(module.pending[field], module.lastKnownServer[field])) delete module.pending[field];
        });
    };

    module.stalledCount = function() {
        return Object.keys(module.refused).length;
    };

    module.noteChanges = function() {
        module.recomputePending();
        module.refreshOffer();
        module.saveDraft();
        module.scheduleFlush();
        module.setStatus();
    };

    module.scheduleFlush = function() {
        if (module.flushTimer || module.stopped) return;
        let wait = module.retryDelay || (cfg.flushSeconds * 1000);
        module.flushTimer = setTimeout(function() {
            module.flushTimer = null;
            module.flush();
        }, wait);
    };

    module.flush = function() {
        if (!cfg.syncEnabled || !module.running || !module.isLeader || module.stopped) return;
        if (module.busy) { module.scheduleFlush(); return; }

        // The screen is the truth, so read it again now. REDCap's expand-notes
        // and missing-data dialogs live outside the form and write back with
        // .val(), which fires nothing the handlers below would hear.
        module.inFlush = true;
        try { module.recomputePending(); } finally { module.inFlush = false; }

        // fields awaiting a decision stay out, or each cycle stacks a new panel
        let sendable = Object.keys(module.pending).filter(function(f) { return !module.conflicted[f]; });

        if (!sendable.length) { module.retryDelay = 0; module.setStatus(); return; }

        if (!navigator.onLine) {
            module.setStatus('queued');
            module.retryDelay = 15000;
            module.scheduleFlush();
            return;
        }

        let batch = {};
        sendable.slice(0, 200).forEach(function(field) {
            batch[field] = { value: module.pending[field], seen: module.serverValue(field) };
            let choices = module.visibleChoices(field);
            if (choices) batch[field].choices = choices;
        });

        module.busy = true;
        module.setStatus('sending');

        // Number every batch. A timed-out request is abandoned, not cancelled,
        // so it can still land later describing a world two edits stale. Acting
        // on that answer rolls lastKnownServer backwards.
        let seq = ++module.sendSeq;
        let stale = function() { return seq <= module.acceptedSeq; };

        module.withTimeout(transport.ajax(cfg.syncAction, { changes: batch })).then(function(response) {
            if (stale()) { console.log('Offline Sync: ignoring a late answer for batch ' + seq); return; }
            module.acceptedSeq = seq;
            module.busy = false;

            if (!response || typeof response != 'object') {
                module.backOff();
                return;
            }

            (response.saved || []).forEach(function(field) {
                module.lastKnownServer[field] = batch[field].value;
                // a real edit, as opposed to the module saving a value that
                // REDCap pre-filled, is what moves a person past an old draft
                if (module.valuesDiffer(batch[field].value, module.baseline[field])) module.savedHere[field] = true;
                delete module.refused[field];
                $('.ofs-refusal[data-ofs-field="' + module.sel(field) + '"]').remove();
            });

            (response.notes || []).forEach(function(note) {
                console.log('Offline Sync: REDCap noted - ' + note);
            });

            Object.keys(response.rejected || {}).forEach(function(field) {
                if (!batch[field]) return;
                module.refused[field] = { value: batch[field].value, why: response.rejected[field] };
                console.log('Offline Sync: REDCap refused ' + field + ' - ' + response.rejected[field]);
                module.showRefusal(field, response.rejected[field]);
            });

            (response.conflicts || []).forEach(function(clash) { module.showConflict(clash); });

            if ((response.errors || []).length) {
                console.log('Offline Sync: server reported', response.errors);
                if (response.terminal) {
                    module.stopped = true;
                    module.showStopped(response.errors.join('; '));
                    module.setStatus();
                    return;
                }
                // nothing in the batch was written, so hold the queue
                module.backOff();
                return;
            }

            module.retryDelay = 0;
            // recompute rather than delete: they may have kept typing while
            // that request was in the air
            module.recomputePending();
            module.refreshOffer();   // a field just saved leaves the offer
            module.saveDraft();
            module.setStatus();
            if (Object.keys(module.pending).length) module.scheduleFlush();
            else module.settleRedcapFlag();
        }).catch(function(err) {
            if (stale()) return;
            module.acceptedSeq = seq;
            module.busy = false;
            console.log('Offline Sync: sync failed, keeping the queue', err);
            module.backOff();
        });
    };

    /**
     * REDCap warns "Leave site?" whenever a field has changed since the page
     * loaded. Once everything typed has reached the server that warning is a
     * false alarm, and on a ward it teaches people to click through warnings.
     * Lower the flag when nothing is outstanding and the form status dropdown,
     * which this module never saves, is as it was. REDCap raises it again on
     * the next keystroke by itself.
     */
    module.settleRedcapFlag = function() {
        if (cfg.survey || typeof window.dataEntryFormValuesChanged == 'undefined') return;
        if (Object.keys(module.pending).length || Object.keys(module.conflicted).length || module.stalledCount() || module.held) return;
        let status = $('select[name="' + module.sel(cfg.instrument + '_complete') + '"]');
        if (status.length && module.statusAtLoad !== null && String(status.val()) !== String(module.statusAtLoad)) return;
        window.dataEntryFormValuesChanged = false;
    };

    /**
     * A roaming tablet does not always get a refusal; sometimes it gets silence.
     * Without a timeout, busy stays true and the queue is wedged for the life of
     * the page while the pill claims to be waiting.
     */
    module.withTimeout = function(promise) {
        return new Promise(function(resolve, reject) {
            let done = false;
            let timer = setTimeout(function() {
                if (done) return;
                done = true;
                reject(new Error('no answer from the server'));
            }, module.AJAX_TIMEOUT);

            promise.then(function(v) {
                if (done) return;
                done = true; clearTimeout(timer); resolve(v);
            }, function(e) {
                if (done) return;
                done = true; clearTimeout(timer); reject(e);
            });
        });
    };

    module.serverValue = function(field) {
        let held = module.lastKnownServer[field];
        if (typeof held == 'undefined') return (cfg.fields[field] && cfg.fields[field].type == 'checkbox') ? [] : '';
        return held;
    };

    // 5s, 10s, 20s, 40s, then settle at a minute
    module.backOff = function() {
        module.retryDelay = module.retryDelay ? Math.min(module.retryDelay * 2, 60000) : 5000;
        module.setStatus('queued');
        module.scheduleFlush();
    };

    /* ------------------------------------------------------------------ */
    /* what the user sees                                                  */
    /* ------------------------------------------------------------------ */

    module.host = function() {
        let center = $('#center');
        if (center.length) return center;
        let form = $('#form');
        if (form.length) return form;
        return $('body');
    };

    /**
     * Panels sit directly above the question table and take its width, so they
     * line up with the form instead of running under REDCap's floating save
     * box on the right. Pages without a question table get the old behaviour.
     */
    module.mount = function(panel) {
        let table = $('#questiontable');
        if (!table.length) { module.host().prepend(panel); return; }
        panel.addClass('ofs-inform').insertBefore(table);
        module.fitPanels();
        // a survey page keeps its table hidden until its own scripts have run
        setTimeout(module.fitPanels, 600);
        setTimeout(module.fitPanels, 2500);
    };

    module.fitPanels = function() {
        let table = $('#questiontable');
        if (!table.length) return;
        let width = table.outerWidth();
        $('.ofs-inform').css('width', width > 320 ? width + 'px' : '');
    };

    module.setStatus = function(state) {
        if (!cfg.showStatus) return;
        let pill = $('#ofs-status');
        if (!pill.length) pill = $('<div id="ofs-status" class="ofs-status"></div>').appendTo('body');

        let waiting = Object.keys(module.pending).length;
        let clashes = Object.keys(module.conflicted).length;
        let stalled = module.stalledCount();
        let offered = module.held ? Object.keys(module.held.values).length : 0;

        // these outrank whatever the caller asked for
        if (module.storageBroken) state = 'broken';
        else if (module.stopped) state = 'stopped';
        else if (clashes) state = 'clash';
        else if (stalled) state = 'stalled';
        else if (offered && !waiting) state = 'offered';
        else if (!state) {
            if (!cfg.syncEnabled) state = 'device-only';
            else if (!module.isLeader) state = 'standby';
            else state = waiting ? 'queued' : 'clean';
        }

        pill.removeClass('ofs-clean ofs-queued ofs-sending ofs-broken ofs-standby');
        pill.attr('title', '');

        if (state == 'broken') {
            pill.addClass('ofs-broken').text('On-device backup FAILED');
        } else if (state == 'stopped') {
            pill.addClass('ofs-broken').text('Saving stopped, see the message above');
        } else if (state == 'clash') {
            pill.addClass('ofs-broken').text(clashes + (clashes == 1 ? ' change needs' : ' changes need') + ' your decision');
        } else if (state == 'stalled') {
            pill.addClass('ofs-broken').text(stalled + (stalled == 1 ? ' value was' : ' values were') + ' not accepted');
        } else if (state == 'offered') {
            // nothing queued on this page, but the banner above is holding
            // answers the server does not have, so "all saved" would be a lie
            pill.addClass('ofs-queued').text(offered + (offered == 1 ? ' unsaved answer' : ' unsaved answers') + ' held, see above');
        } else if (state == 'device-only') {
            // Mirror only, no background saving on this page. Amber once there
            // is something on the device the server does not have; the reason
            // the queue is shut sits in the tooltip and the console.
            pill.addClass(waiting ? 'ofs-queued' : 'ofs-standby')
                .text(waiting ? 'Held on this device only, not saved' : 'Held on this device')
                .attr('title', 'Background saving is off on this page. ' + (cfg.syncReason || ''));
        } else if (state == 'standby') {
            pill.addClass(waiting ? 'ofs-queued' : 'ofs-standby')
                .text(waiting ? 'Held on this device only; another tab has the connection' : 'Another tab has the connection for this record')
                .attr('title', 'Two tabs have this record open. Only one sends to the server, and each sends only what is typed in it.');
        } else if (state == 'sending') {
            pill.addClass('ofs-sending').text('Saving ' + waiting + ' change' + (waiting == 1 ? '' : 's'));
        } else if (state == 'queued') {
            pill.addClass('ofs-queued').text((navigator.onLine ? 'Waiting to save ' : 'Offline, holding ') + waiting + ' change' + (waiting == 1 ? '' : 's'));
        } else {
            pill.addClass('ofs-clean').text('All changes saved');
        }
    };

    /**
     * Offer what the device holds. The offer stays, reload after reload, until
     * the user puts the answers back or discards them: module.held is written
     * into every draft in the meantime, apart from anything being typed.
     */
    module.showRestoreBar = function(offer) {
        module.held = offer;
        let bar = $('<div class="ofs-bar ofs-restore"></div>');
        $('<div class="ofs-bar-text"></div>').appendTo(bar);
        let buttons = $('<div class="ofs-bar-buttons"></div>').appendTo(bar);

        $('<button type="button" class="ofs-btn ofs-btn-go">Put them back</button>')
            .on('click', function() {
                let remaining = module.stillUnsaved(module.held);
                // Fields somebody else changed after the draft was written are
                // not put back blind: the server's value is newer than the
                // page the draft saw, so ask, the same way a live clash asks.
                let contested = module.contestedIn(module.held, remaining);
                let plain = {};
                Object.keys(remaining).forEach(function(field) {
                    if (!(field in contested)) plain[field] = remaining[field];
                });

                // Only what was recorded as unsaved. The rest of any draft is a
                // stale copy of the server's values, and rewriting it would
                // overwrite somebody's later edit with a baseline that matches.
                let skipped = module.writeForm(module.held.values, plain);
                let seen = $.extend({}, module.held.seen);   // a copy: the loop below trims the original
                let since = module.held.since;

                // what was put back, or handed to a clash, leaves the offer; a
                // field the person is editing right now stays held, so nothing
                // is lost if they change their mind
                Object.keys(remaining).forEach(function(field) {
                    if (skipped.indexOf(field) > -1) return;
                    delete module.held.values[field];
                    delete module.held.seen[field];
                });
                Object.keys(contested).forEach(function(field) {
                    module.showConflict({
                        field: field, mine: remaining[field], theirs: contested[field],
                        apply: true, seen: seen[field], since: since
                    });
                });
                module.noteChanges();   // refreshes the offer, releasing the sources once it is empty
                if (skipped.length) {
                    module.showNote(skipped.length + (skipped.length == 1 ? ' field' : ' fields') +
                                    ' were left as they are, either because you are editing them or because they are not on this page, and are still held.');
                }
            }).appendTo(buttons);

        $('<button type="button" class="ofs-btn">Discard</button>')
            .on('click', function() {
                if (!confirm('Throw away the unsaved answers held on this device?')) return;
                bar.remove();
                module.offerBar = null;
                module.dropDraft().then(function() { module.saveDraft(); }, function() {});
                module.setStatus();
            }).appendTo(buttons);

        module.offerBar = bar;
        module.mount(bar);
        module.refreshOffer();
        module.scrollTo(bar);
    };

    /**
     * Keep the offer honest while it sits there. A field this page has since
     * saved by a real edit leaves the offer for good; a field being typed in is
     * kept but flagged; when nothing is left the bar goes away on its own.
     */
    module.refreshOffer = function() {
        if (!module.held) return;
        Object.keys(module.held.values).forEach(function(field) {
            if (module.savedHere[field]) { delete module.held.values[field]; delete module.held.seen[field]; }
        });
        if (!Object.keys(module.held.values).length) {
            if (module.offerBar) module.offerBar.remove();
            module.offerBar = null;
            module.releaseSources(module.held.sources);
            module.held = null;
            module.saveDraft();
            return;
        }
        if (!module.offerBar) return;
        let count = Object.keys(module.held.values).length;
        let editing = count - Object.keys(module.stillUnsaved(module.held)).length;
        let text = '<strong>Unsaved answers found on this device.</strong> ' +
            count + (count == 1 ? ' answer' : ' answers') + ' from ' + module.agoText(module.held.since) + ' never reached the server.';
        if (editing) text += ' ' + editing + ' of them ' + (editing == 1 ? 'is' : 'are') + ' for a field you are editing now, which will be left as you have it.';
        if (module.held.fromAnotherTab) text += ' They came from another window, so check they belong to this record.';
        module.offerBar.find('.ofs-bar-text').html(text);
    };

    module.agoText = function(when) {
        let minutes = Math.round((Date.now() - when) / 60000);
        if (minutes < 2) return 'a moment ago';
        if (minutes < 90) return minutes + ' minutes ago';
        let hours = Math.round(minutes / 60);
        if (hours < 36) return hours + ' hours ago';
        return Math.round(hours / 24) + ' days ago';
    };

    module.showConflict = function(clash) {
        if (module.conflicted[clash.field]) return; // already asking about this one
        clash.shown = module.readField(clash.field);   // what the box holds right now
        module.conflicted[clash.field] = clash;

        let mine = Array.isArray(clash.mine) ? clash.mine.join(', ') : clash.mine;
        let theirs = Array.isArray(clash.theirs) ? clash.theirs.join(', ') : clash.theirs;
        let panel = $('<div class="ofs-bar ofs-clash"></div>').attr('data-ofs-field', clash.field);

        $('<div class="ofs-bar-text"></div>').html(
            '<strong>Somebody else changed "' + module.escapeHtml(module.labelOf(clash.field)) + '" while you were offline.</strong><br>' +
            'Yours: <code>' + module.escapeHtml(mine || '(blank)') + '</code> &nbsp; ' +
            'Already saved: <code>' + module.escapeHtml(theirs || '(blank)') + '</code><br>' +
            'You can also just correct the field itself, and your correction will be saved.'
        ).appendTo(panel);

        let buttons = $('<div class="ofs-bar-buttons"></div>').appendTo(panel);

        $('<button type="button" class="ofs-btn ofs-btn-go">Keep mine</button>')
            .on('click', function() { module.resolveConflict(clash.field, 'mine'); }).appendTo(buttons);

        $('<button type="button" class="ofs-btn">Keep theirs</button>')
            .on('click', function() { module.resolveConflict(clash.field, 'theirs'); }).appendTo(buttons);

        module.mount(panel);
        module.scrollTo(panel);
        module.setStatus();
    };

    module.resolveConflict = function(field, side, quiet) {
        let clash = module.conflicted[field];
        if (!clash) return;

        // their value becomes the base either way: it is what the server holds now
        module.lastKnownServer[field] = clash.theirs;
        module.savedHere[field] = true;   // decided on this page, either way
        delete module.conflicted[field];
        delete module.refused[field];
        $('.ofs-clash[data-ofs-field="' + module.sel(field) + '"]').remove();

        if (side == 'theirs') {
            delete module.pending[field];
            module.writeField(field, clash.theirs);
            module.recalculate();
            module.saveDraft();
            module.setStatus();
            return;
        }

        if (clash.apply) {
            // a clash raised while restoring a draft: "mine" is not on screen
            // yet, so put it there first
            module.writeField(field, clash.mine);
            module.recalculate();
        }

        // read the box, do not trust the value that was in flight when the clash
        // happened. The user has very likely typed since.
        let live = module.readField(field);
        if (live !== null) {
            module.pending[field] = live;
        } else if (clash.apply) {
            // the field is not on this page, so there is nowhere to put "mine";
            // keep holding it rather than lose it
            module.holdAnswer(field, clash.mine, clash.seen, clash.since);
        }
        module.saveDraft();
        module.retryDelay = 0;
        module.setStatus();
        // quiet when called from inside a flush, which sends anyway; a nested
        // flush would send the same batch twice, the second with a stale seen
        if (!quiet) module.flush();
    };

    /** put one answer (back) into the offer, creating it if need be */
    module.holdAnswer = function(field, value, seen, since) {
        if (!module.held) module.held = { values: {}, seen: {}, since: since || Date.now(), sources: [], fromAnotherTab: false };
        module.held.values[field] = value;
        if (typeof seen != 'undefined') module.held.seen[field] = seen;
        if (since && since < module.held.since) module.held.since = since;
    };

    /**
     * REDCap refused a value. Say so once rather than retrying it silently every
     * ten seconds; the panel clears itself when the field saves.
     */
    module.showRefusal = function(field, why) {
        if ($('.ofs-refusal[data-ofs-field="' + module.sel(field) + '"]').length) return;

        let panel = $('<div class="ofs-bar ofs-clash ofs-refusal"></div>').attr('data-ofs-field', field);
        $('<div class="ofs-bar-text"></div>').html(
            '<strong>REDCap would not accept "' + module.escapeHtml(module.labelOf(field)) + '", so it has not been saved.</strong><br>' +
            module.escapeHtml(why) + '<br>Correct the value and it will be sent again. Everything else on the form saved normally.'
        ).appendTo(panel);

        module.mount(panel);
        module.scrollTo(panel);
    };

    module.showStopped = function(why) {
        if ($('.ofs-stopped').length) return;
        let panel = $('<div class="ofs-bar ofs-clash ofs-stopped"></div>');
        $('<div class="ofs-bar-text"></div>').html(
            '<strong>Background saving has stopped for this form.</strong><br>' +
            module.escapeHtml(why) + '<br>' +
            'Your answers are still on this device and still on screen, but they are not being written to the database. ' +
            'Save the form by hand, or tell the study team before you close this page.'
        ).appendTo(panel);
        $('<div class="ofs-bar-buttons"></div>')
            .append($('<button type="button" class="ofs-btn ofs-btn-go">Try again</button>')
                .on('click', function() { module.rearm(); module.retryDelay = 0; module.flush(); }))
            .appendTo(panel);
        module.mount(panel);
        module.scrollTo(panel);
    };

    module.showNote = function(text) {
        let panel = $('<div class="ofs-bar"></div>');
        $('<div class="ofs-bar-text"></div>').text(text).appendTo(panel);
        $('<div class="ofs-bar-buttons"></div>')
            .append($('<button type="button" class="ofs-btn">OK</button>').on('click', function() { panel.remove(); }))
            .appendTo(panel);
        module.mount(panel);
        module.scrollTo(panel);
    };

    // a panel prepended out of sight on a long form is a panel nobody reads
    module.scrollTo = function(el) {
        try { el[0].scrollIntoView({ block: 'center' }); } catch (e) {}
    };

    /** the field's label as the server sent it, or its name if there is none */
    module.labelOf = function(field) {
        let spec = cfg.fields[field];
        let label = spec && spec.label ? String(spec.label).replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim() : '';
        return label || field;
    };

    module.escapeHtml = function(s) {
        return $('<div></div>').text(s == null ? '' : s).html();
    };

    /* ------------------------------------------------------------------ */

    /**
     * 'change' on a text box only fires at blur, so somebody who types a
     * paragraph and walks away would be captured by nothing. Hence 'input' too,
     * debounced. Anything that looks like the page is about to die forces a save
     * rather than waiting for the timer.
     */
    module.bindHandlers = function() {
        // the document, not #form: REDCap's dialogs are appended to body
        let form = $(document);

        form.on('change blur', 'input, select, textarea', function() {
            clearTimeout(module.typingTimer);
            setTimeout(module.noteChanges, 50); // let REDCap's own handlers land first
        });

        form.on('input', 'input, textarea', function() {
            clearTimeout(module.typingTimer);
            module.typingTimer = setTimeout(module.noteChanges, module.TYPING_DEBOUNCE);
        });

        $(document).on('visibilitychange', function() {
            if (document.visibilityState == 'hidden') {
                clearTimeout(module.typingTimer);
                module.noteChanges();
                module.flush();
            }
        });

        $(window).on('pagehide', function() {
            clearTimeout(module.typingTimer);
            module.recomputePending();
            module.saveDraft();       // best effort, the browser may not let it finish
            module.releaseLease();
        });

        $(window).on('online', function() {
            module.retryDelay = 0;
            // a refusal can be permanent (no rights) or merely current (the form
            // was locked, the locking table was briefly unreadable). Coming back
            // online is a reasonable moment to find out which, rather than
            // stranding the rest of the shift's work behind one bad answer.
            module.rearm();
            module.flush();
        });
        $(window).on('offline', function() { module.setStatus('queued'); });

        // Nothing special happens on submit, deliberately. REDCap cancels its
        // own submit for required fields, and an earlier version threw away the
        // queue when that happened. A submit that does go through leaves the
        // next page loaded with what was submitted, so the draft compares equal
        // to the page and is dropped by the ordinary path; a version that noted
        // the submit in sessionStorage and dropped the draft blind was found to
        // delete answers that were still being offered.
    };

    /** a refusal can be permanent or merely current; let it try again */
    module.rearm = function() {
        if (!module.stopped) return;
        module.stopped = false;
        $('.ofs-stopped').remove();
        module.setStatus();
    };

    module.start = async function() {
        // Baseline before any await. Opening IndexedDB and generating a key take
        // long enough on a tablet that anything typed in the gap would otherwise
        // be mistaken for what the server already had.
        module.baseline = module.readForm();
        let status = $('select[name="' + module.sel(cfg.instrument + '_complete') + '"]');
        module.statusAtLoad = status.length ? String(status.val()) : null;
        module.lastKnownServer = module.serverSnapshot();
        module.serverAtLoad = $.extend(true, {}, module.lastKnownServer);
        module.running = true;

        module.bindHandlers();
        module.tabToken = await module.settleTabToken();
        module.draftId = module.baseKey + '|' + module.tabToken;
        module.holdTabLock();
        module.unwedgeFrameworkQueue();
        module.answerProbes();
        if (cfg.syncEnabled) module.electLeader();
        module.setStatus();

        try {
            module.db = await module.openDb();
            module.cryptoKey = await module.loadKey();
        } catch (err) {
            console.log('Offline Sync: no usable device storage', err); // private browsing, most likely
            module.storageBroken = true;
            module.offerSettled = true;
            module.setStatus();
            module.recomputePending();      // the queue still works without a mirror
            if (Object.keys(module.pending).length) module.scheduleFlush();
            module.report();
            $(window).on('resize', module.fitPanels);
            return;
        }

        try { await module.purgeStaleDrafts(); } catch (e) {}
        // only once REDCap has a response for us: that is the proof the earlier pages were saved
        if (cfg.survey && cfg.record) { try { await module.retireEarlierPages(); } catch (e) {} }
        // likewise, a tab that has arrived at a saved record has saved its new record
        if (!cfg.survey && cfg.record) { try { await module.retireNewRecordRows(); } catch (e) {} }

        // anything typed during those awaits is a real change, so pick it up now
        module.recomputePending();

        let offer = null;
        try {
            offer = await module.collectOffer();
            module.offerSettled = true;
        } catch (e) {
            // Leave offerSettled false: nothing is written over the previous
            // page's row until it has been read, and say so on the pill.
            console.log('Offline Sync: could not read drafts', e);
            module.storageBroken = true;
        }
        if (offer) module.showRestoreBar(offer);

        module.report();
        module.saveDraft();
        module.setStatus();
        if (Object.keys(module.pending).length) module.scheduleFlush();
        $(window).on('resize', module.fitPanels);
    };

    /**
     * One console line saying what this page is and is not doing, so the
     * question "why is it not working for field X" has an answer without
     * reading code. Nothing here is an error; warnings are used so the lines
     * stand out in a console that REDCap fills with its own noise.
     */
    module.report = function() {
        let covered = Object.keys(cfg.fields);
        let unseen = covered.filter(function(f) { return module.readField(f) === null; });
        let uncovered = cfg.uncovered || {};

        console.log('Offline Sync ' + (cfg.version || '') + ': ' + covered.length + ' field' +
            (covered.length == 1 ? '' : 's') + ' mirrored on ' + cfg.instrument + (cfg.survey ? ' (survey page ' + cfg.page + ')' : '') +
            ', background saving ' + (cfg.syncEnabled ? 'on' : 'OFF') + '.');

        if (!cfg.syncEnabled && !cfg.survey) {
            console.warn('Offline Sync: background saving is off on this page. ' + (cfg.syncReason || 'No reason was given.'));
        }
        if (Object.keys(uncovered).length) {
            console.warn('Offline Sync: fields on this instrument that are NOT covered, and why:', uncovered);
        }
        if (unseen.length) {
            console.warn('Offline Sync: ' + unseen.length + ' of ' + covered.length +
                ' fields declared saveable on this instrument were not found in the page, so they are NOT being ' +
                'protected. This usually means an unsupported field rendering. Please report it with this list: ',
                unseen);
        }
    };

    /** call OfflineSync.diagnose() in the console to get the state as one object */
    module.diagnose = function() {
        return {
            version: cfg.version,
            instrument: cfg.instrument,
            record: cfg.record,
            syncEnabled: cfg.syncEnabled,
            syncReason: cfg.syncReason,
            isLeader: module.isLeader,
            storageBroken: module.storageBroken,
            stopped: module.stopped,
            covered: Object.keys(cfg.fields),
            notFoundOnPage: Object.keys(cfg.fields).filter(function(f) { return module.readField(f) === null; }),
            uncovered: cfg.uncovered,
            pending: module.pending,
            conflicted: Object.keys(module.conflicted),
            refused: module.refused,
            heldAnswers: module.held ? module.held.values : null
        };
    };

    // On a new record syncEnabled is false: nothing on the server to write to
    // yet. The device mirror still runs, so a reload does not lose the form.
    module.start();
});
