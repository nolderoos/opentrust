/**
 * OpenTrust AI chat page controller.
 *
 * - Streams SSE events from /wp-json/opentrust/v1/chat.
 * - Renders tokens, citations, and done events into message bubbles.
 * - Maintains conversation history in sessionStorage.
 * - Handles refuse-to-answer, stop, retry, copy, share, print.
 * - All answer text rendered via text nodes — never innerHTML.
 */
(function () {
    'use strict';

    var configEl = document.getElementById('ot-chat-config');
    if (!configEl) {
        return;
    }
    var config;
    try {
        config = JSON.parse(configEl.textContent || '{}');
    } catch (e) {
        return;
    }

    var strings = config.strings || {};
    var STORAGE_KEY = 'opentrust.chat.history';

    // Honor the visitor's reduced-motion preference. When set, we skip the
    // rAF-paced typewriter and render whatever's in the buffer the moment a
    // token arrives — still progressively-formatted, just not smoothed.
    var REDUCED_MOTION = false;
    try {
        REDUCED_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches === true;
    } catch (e) { /* old browsers — keep default */ }

    // ── DOM refs ──────────────────────────────

    var shellEl    = document.querySelector('[data-ot-chat-shell]');
    var messagesEl = document.querySelector('[data-ot-chat-messages]');
    var form       = document.querySelector('[data-ot-chat-form]');
    var input      = document.querySelector('[data-ot-chat-input]');
    var sendBtn    = document.querySelector('[data-ot-chat-send]');
    var resetBtn   = document.querySelector('[data-ot-chat-reset]');

    if (!form || !input || !messagesEl || !shellEl) {
        return;
    }

    // ── State ─────────────────────────────────

    var history = loadHistory();
    var streaming = false;
    var currentAbort = null;
    var turnstileRequired = !!config.turnstile_required;
    var turnstileSolved = false;

    // ── Init ──────────────────────────────────

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        bindEvents();
        renderHistory();

        // Auto-submit prefilled question if present.
        if (config.prefill_q && history.length === 0) {
            input.value = config.prefill_q;
            autoResize(input);
            submitUserMessage(config.prefill_q);
        }

        // Focus the input on load so visitors can just start typing.
        // Deferred to the next frame so the browser's own scroll-to-hash /
        // autofocus heuristics don't fight with ours.
        requestAnimationFrame(function () {
            if (input && document.activeElement !== input) {
                input.focus({ preventScroll: true });
                // Place cursor at the end of any prefilled text.
                var len = input.value ? input.value.length : 0;
                try { input.setSelectionRange(len, len); } catch (e) {}
            }
        });

        // Global keydown capture: if a visitor starts typing anywhere on the
        // page while nothing else is focused, redirect the keystroke into the
        // chat input. Matches Claude/ChatGPT behavior.
        document.addEventListener('keydown', function (e) {
            if (!input || streaming) return;

            // Ignore modifier combos (cmd/ctrl/alt) — let them do their thing.
            if (e.metaKey || e.ctrlKey || e.altKey) return;

            // Ignore non-printable keys.
            if (!e.key || e.key.length !== 1) return;

            // Ignore if focus is already on a real input / textarea / button /
            // contenteditable element.
            var active = document.activeElement;
            if (active && active !== document.body) {
                var tag = (active.tagName || '').toLowerCase();
                if (tag === 'input' || tag === 'textarea' || tag === 'select' ||
                    tag === 'button' || active.isContentEditable) {
                    return;
                }
            }

            input.focus({ preventScroll: true });
            // Let the natural keystroke land in the input — no need to
            // preventDefault or manually append.
        });
    }

    function bindEvents() {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var text = (input.value || '').trim();
            if (!text) return;
            input.value = '';
            autoResize(input);
            submitUserMessage(text);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                form.dispatchEvent(new Event('submit'));
            }
        });
        input.addEventListener('input', function () { autoResize(input); });

        // Suggested question chips. Submit directly — do NOT populate the
        // input field, or the text would linger there after the submit (the
        // input-clear only happens inside the form submit handler).
        document.querySelectorAll('[data-ot-chat-chip]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var q = btn.getAttribute('data-ot-chat-chip') || '';
                if (!q) return;
                submitUserMessage(q);
            });
        });

        // Reset button — clears history, removes chatting state.
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                if (streaming && currentAbort) {
                    currentAbort.abort();
                }
                clearHistory();
                history = [];
                messagesEl.replaceChildren();
                setChattingState(false);
                input.focus();
            });
        }
    }

    // ── Chatting state (single-shell toggle, not two-state swap) ──

    function setChattingState(on) {
        // Toggle on body so descendants in both the main shell (intro/thread)
        // AND the dock (chips) can key off one class without :has() tricks.
        document.body.classList.toggle('is-chatting', !!on);
        if (shellEl) {
            shellEl.classList.toggle('is-chatting', !!on);
        }
        updateResetButtonState();
    }

    function updateResetButtonState() {
        if (!resetBtn) return;
        resetBtn.disabled = history.length === 0;
    }

    // ── History (sessionStorage) ───────────────

    function loadHistory() {
        try {
            var raw = sessionStorage.getItem(STORAGE_KEY);
            if (!raw) return [];
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }
    function saveHistory() {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(history));
        } catch (e) { /* quota exceeded — ignore */ }
    }
    function clearHistory() {
        try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) {}
    }

    function renderHistory() {
        if (history.length === 0) {
            setChattingState(false);
            return;
        }
        setChattingState(true);
        history.forEach(function (msg) {
            if (msg.role === 'user') {
                appendUserBubble(msg.content);
            } else {
                appendAssistantBubble(msg.content, msg.citations || [], !!msg.refused, true);
            }
        });
        scrollToBottom();
    }

    // ── Submit a user message ──────────────────

    function submitUserMessage(text) {
        if (streaming) return;

        if (text.length > (config.max_length || 1000)) {
            text = text.substring(0, config.max_length || 1000);
        }

        // User just submitted — unconditionally re-enable auto-scroll so the
        // new question + its answer become visible even if they were scrolled
        // up beforehand.
        autoScrollEnabled = true;

        setChattingState(true);
        appendUserBubble(text);
        history.push({ role: 'user', content: text });
        updateResetButtonState();
        saveHistory();

        // Soft "long conversation" hint.
        if (estimateHistoryTokens() > 8000) {
            appendLongHint();
        }

        var responseBubble = appendAssistantBubble('', [], false, false);
        // Insert the "Thinking…" indicator as a SIBLING before the text node
        // rather than replacing it — so the text node stays live in the DOM
        // and streamed tokens actually appear.
        var thinkingEl = buildThinkingIndicator();
        responseBubble.bodyEl.insertBefore(thinkingEl, responseBubble.textNode);

        streamResponse(responseBubble, thinkingEl, text);
    }

    // ── Stream response via SSE ────────────────

    function streamResponse(bubble, thinkingEl, userText) {
        streaming = true;
        setSendButtonMode('stop');
        currentAbort = new AbortController();

        var headers = {
            'Content-Type': 'application/json',
            'Accept': 'text/event-stream',
            'X-WP-Nonce': config.nonce
        };

        var body = {
            messages: history.map(function (m) { return { role: m.role, content: m.content }; })
        };

        // Turnstile: first message of session must carry a token when enabled.
        if (turnstileRequired && !turnstileSolved) {
            thinkingEl.remove();
            renderTurnstileWidget(function (token) {
                body.turnstile_token = token;
                turnstileSolved = true;
                sendChatRequest(bubble, body, userText);
            });
            return;
        }

        sendChatRequest(bubble, body, userText, thinkingEl);
    }

    function sendChatRequest(bubble, body, userText, thinkingEl) {
        if (!thinkingEl) {
            thinkingEl = buildThinkingIndicator();
            bubble.bodyEl.insertBefore(thinkingEl, bubble.textNode);
        }

        var headers = {
            'Content-Type': 'application/json',
            'Accept': 'text/event-stream',
            'X-WP-Nonce': config.nonce
        };

        fetch(config.rest_url, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(body),
            signal: currentAbort.signal,
            credentials: 'same-origin'
        }).then(function (resp) {
            if (!resp.ok) {
                return resp.json().then(function (err) {
                    // Distinguish rate-limit / budget-exhausted / turnstile from generic errors.
                    var code = err && (err.code || err.error) || '';
                    if (code === 'ai_rate_limited_ip' || code === 'ai_rate_limited_session' || resp.status === 429) {
                        thinkingEl.remove();
                        appendBanner('warn', (err && err.message) || (strings.rate_limited || 'Please slow down.'));
                        streaming = false;
                        setSendButtonMode('send');
                        return;
                    }
                    if (code === 'budget_exhausted') {
                        thinkingEl.remove();
                        renderBudgetExhaustedState(err && err.data && err.data.reset_at);
                        streaming = false;
                        return;
                    }
                    if (code === 'ai_turnstile_required') {
                        thinkingEl.remove();
                        turnstileSolved = false;
                        renderTurnstileWidget(function (token) {
                            body.turnstile_token = token;
                            turnstileSolved = true;
                            sendChatRequest(bubble, body, userText);
                        });
                        return;
                    }
                    throw new Error((err && err.message) || (strings.provider_error || 'Provider error'));
                }, function () {
                    throw new Error(strings.provider_error || 'Provider error');
                });
            }
            var contentType = resp.headers.get('Content-Type') || '';
            if (contentType.indexOf('text/event-stream') === -1) {
                // JSON fallback (server chose not to stream).
                return resp.json().then(function (data) {
                    thinkingEl.remove();
                    applyJsonResponseToBubble(bubble, data);
                    streaming = false;
                    setSendButtonMode('send');
                });
            }
            return consumeSSE(resp, bubble, thinkingEl, userText);
        }).catch(function (err) {
            if (err && err.name === 'AbortError') {
                // User hit Stop. Finalize the partial response so the cursor
                // stops blinking and the action bar shows up — but only if
                // we already had a streaming bubble going.
                if (bubble && bubble.usedStreaming && !bubble.finalized) {
                    bubble.streamDone = true;
                    commitAssistantFromBubble(bubble);
                    forceFinalize(bubble);
                }
            } else {
                thinkingEl.remove();
                appendRetryBanner(userText);
            }
            streaming = false;
            setSendButtonMode('send');
            focusInput();
        });
    }

    function consumeSSE(response, bubble, thinkingEl, userText) {
        var reader = response.body.getReader();
        var decoder = new TextDecoder('utf-8');
        var buffer = '';
        var thinkingRemoved = false;
        var doneReceived = false;

        function pump() {
            return reader.read().then(function (result) {
                if (result.done) {
                    if (!doneReceived) {
                        // Server closed without a done event. Leave whatever
                        // arrived but still finalize so the cursor stops
                        // blinking and the action bar shows up.
                        bubble.streamDone = true;
                        commitAssistantFromBubble(bubble);
                        forceFinalize(bubble);
                    }
                    streaming = false;
                    setSendButtonMode('send');
                    focusInput();
                    return;
                }
                buffer += decoder.decode(result.value, { stream: true });

                var events = buffer.split('\n\n');
                buffer = events.pop() || '';

                events.forEach(function (block) {
                    var evt = parseSSEBlock(block);
                    if (!evt) return;
                    if (!thinkingRemoved && (evt.event === 'token' || evt.event === 'error')) {
                        thinkingEl.remove();
                        thinkingRemoved = true;
                    }
                    handleSSEEvent(evt, bubble);
                    if (evt.event === 'done') {
                        doneReceived = true;
                    }
                });

                return pump();
            });
        }
        return pump();
    }

    function parseSSEBlock(block) {
        var lines = block.split('\n');
        var evt = null;
        var data = '';
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            if (line.indexOf('event: ') === 0) {
                evt = line.substring(7).trim();
            } else if (line.indexOf('data: ') === 0) {
                data += line.substring(6);
            }
        }
        if (!evt) return null;
        var parsed = null;
        if (data) {
            try { parsed = JSON.parse(data); } catch (e) { /* ignore */ }
        }
        return { event: evt, data: parsed || {} };
    }

    function handleSSEEvent(evt, bubble) {
        switch (evt.event) {
            case 'token':
                if (evt.data && typeof evt.data.text === 'string') {
                    appendTokenText(bubble, evt.data.text);
                }
                break;
            case 'tool_intent':
                // Early-intent pill. Fired by the Anthropic provider the
                // moment it sees the first tool_use content block in the
                // stream — well before the model has finished generating
                // all the parallel tool inputs and the aggregated tool_call
                // event can be emitted. Lets the visitor see a "Searching
                // documents…" pill in ~1-2s instead of staring at the
                // thinking dots for 6-8s. Does NOT increment totalToolCount;
                // the aggregated tool_call event below carries the real
                // count and will morph this pill's label via the reuse path.
                if (evt.data && typeof evt.data.summary === 'string' && evt.data.summary !== '') {
                    var thinkingEl = bubble.bodyEl.querySelector('.ot-chat-thinking');
                    if (thinkingEl) thinkingEl.remove();
                    showToolStatus(bubble, evt.data.summary);
                }
                break;
            case 'tool_call':
                // ONE morphing pill per bubble. First event creates it,
                // subsequent events update its label and extend the timer.
                // Track total tools fired for the past-tense settle label,
                // and capture the per-turn settled_summary so single-turn
                // flows can morph the pill's verb tense at settle (e.g.
                // "Searching for X" → "Searched for X") instead of leaving
                // the active-tense label up forever.
                if (evt.data && typeof evt.data.summary === 'string' && evt.data.summary !== '') {
                    var thinking = bubble.bodyEl.querySelector('.ot-chat-thinking');
                    if (thinking) thinking.remove();
                    bubble.totalToolCount      = (bubble.totalToolCount || 0) + (parseInt(evt.data.count, 10) || 1);
                    bubble.toolCallEventCount  = (bubble.toolCallEventCount || 0) + 1;
                    if (typeof evt.data.settled_summary === 'string' && evt.data.settled_summary !== '') {
                        bubble.lastSettledSummary = evt.data.settled_summary;
                    }
                    showToolStatus(bubble, evt.data.summary);
                }
                break;
            case 'citation':
                if (evt.data && evt.data.url) {
                    bubble.citations.push(evt.data);
                    // No DOM insert here — Sources panel renders them on finalize.
                }
                break;
            case 'done':
                // Settle any still-active pill so its dots stop animating —
                // the conversation is over, no more retrieval is happening.
                // We do NOT remove the pill: it stays as a permanent record
                // of what the model retrieved.
                settleActivePill(bubble);
                bubble.refused = !!(evt.data && evt.data.refused);
                bubble.streamDone = true;
                // Save to history immediately so the record doesn't depend on
                // the typewriter finishing.
                commitAssistantFromBubble(bubble);
                if (REDUCED_MOTION || !bubble.usedStreaming) {
                    finalizeBubble(bubble);
                } else {
                    // Defer finalize until the typewriter has played out the
                    // tail. The rAF loop will call finalizeBubble itself once
                    // streamRendered catches up.
                    bubble.streamFinalizePending = true;
                    scheduleTypewriter(bubble);
                }
                break;
            case 'error':
                settleActivePill(bubble);
                if (evt.data && evt.data.message) {
                    appendBanner('error', evt.data.message);
                }
                break;
        }
        scrollToBottom();
    }

    // ── Bubble rendering (flat Slack-style layout, text-nodes only) ─────

    var USER_SVG       = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
    var ASSISTANT_SVG  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.39 4.84L20 8l-4 3.9.94 5.5L12 14.77 7.06 17.4 8 11.9 4 8l5.61-1.16L12 2z"/></svg>';
    var WARNING_SVG    = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
    var COPY_SVG       = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
    var CHECK_SVG      = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    var PRINT_SVG      = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>';

    function svgIcon(markup) {
        var span = document.createElement('span');
        // Safe: these SVG strings are hard-coded static constants controlled by this file.
        // No user data ever enters here. innerHTML is intentional and auditable.
        span.innerHTML = markup;
        return span.firstChild;
    }

    function buildThinkingIndicator() {
        var wrap = document.createElement('span');
        wrap.className = 'ot-chat-thinking';
        for (var i = 0; i < 3; i++) {
            var dot = document.createElement('span');
            dot.className = 'ot-chat-thinking-dot';
            wrap.appendChild(dot);
        }
        return wrap;
    }

    function nowLabel() {
        return strings.just_now || 'just now';
    }

    /**
     * Build the shared message scaffold: grid row with avatar + content column
     * containing header (name/separator/time/menu) + body element.
     */
    function buildMessageScaffold(role, name) {
        var msg = document.createElement('div');
        msg.className = 'ot-chat-msg ot-chat-msg--' + role;

        var avatar = document.createElement('div');
        avatar.className = 'ot-chat-msg__avatar';
        avatar.setAttribute('aria-hidden', 'true');

        if (role === 'assistant' && config.avatar_url) {
            var avatarImg = document.createElement('img');
            avatarImg.className = 'ot-chat-msg__avatar-img';
            avatarImg.src = config.avatar_url;
            avatarImg.alt = '';
            avatar.appendChild(avatarImg);
        } else {
            var iconEl = svgIcon(role === 'user' ? USER_SVG : ASSISTANT_SVG);
            if (iconEl && iconEl.nodeType === 1) {
                iconEl.classList.add('ot-chat-msg__avatar-icon');
            }
            avatar.appendChild(iconEl);
        }

        // Pre-render a warning icon kept hidden by default; CSS reveals it
        // when the parent row gains `.ot-chat-msg--refused`.
        if (role === 'assistant') {
            var warning = svgIcon(WARNING_SVG);
            if (warning && warning.nodeType === 1) {
                warning.classList.add('ot-chat-msg__avatar-warning');
                warning.setAttribute('style', 'display:none');
            }
            avatar.appendChild(warning);
        }

        var content = document.createElement('div');
        content.className = 'ot-chat-msg__content';

        var header = document.createElement('header');
        header.className = 'ot-chat-msg__header';

        var nameEl = document.createElement('strong');
        nameEl.className = 'ot-chat-msg__name';
        nameEl.textContent = name;

        var sep = document.createElement('span');
        sep.className = 'ot-chat-msg__separator';
        sep.textContent = '·';

        var time = document.createElement('time');
        time.className = 'ot-chat-msg__time';
        time.textContent = nowLabel();

        header.appendChild(nameEl);
        header.appendChild(sep);
        header.appendChild(time);

        var body = document.createElement('div');
        body.className = 'ot-chat-msg__body';

        content.appendChild(header);
        content.appendChild(body);

        msg.appendChild(avatar);
        msg.appendChild(content);

        return { msgEl: msg, bodyEl: body };
    }

    function appendUserBubble(text) {
        var scaffold = buildMessageScaffold('user', strings.user_name || 'You');
        scaffold.bodyEl.appendChild(document.createTextNode(text));
        messagesEl.appendChild(scaffold.msgEl);
        maybeAutoScroll();
    }

    function appendAssistantBubble(text, citations, refused, finalized) {
        var scaffold = buildMessageScaffold('assistant', config.company_name || 'Assistant');
        if (refused) {
            scaffold.msgEl.classList.add('ot-chat-msg--refused');
        }

        // During streaming we hold a single text node in the body that receives
        // raw tokens. On `done` we replace it with a parsed markdown tree.
        var textNode = document.createTextNode(text);
        scaffold.bodyEl.appendChild(textNode);

        messagesEl.appendChild(scaffold.msgEl);

        var obj = {
            msgEl:       scaffold.msgEl,
            bodyEl:      scaffold.bodyEl,
            bubbleEl:    scaffold.bodyEl, // legacy alias — some call sites still use bubbleEl
            textNode:    textNode,
            tokenBuffer: text,
            citations:   citations || [],
            refused:     refused,
            finalized:   false,
            // Streaming render state. usedStreaming is set the moment a token
            // arrives — finalizeBubble keys off it to decide whether to keep
            // the live tree or do a one-shot static render (history replay).
            usedStreaming:    false,
            // Current LIVE text segment (typewriter operates on this). When a
            // tool_call arrives we lock the current buffer into priorSegments
            // and reset streamCharBuffer for the next text segment.
            streamCharBuffer: text.replace(/\[\[cite:[a-z0-9_\-]+\]\]/gi, ''),
            streamRendered:   0,
            streamDone:       false,
            streamRaf:        null,
            streamThinkingHidden: false,
            streamFinalizePending: false,
            // Inline retrieval markers + completed text segments. Renders to
            // the body in order: priorSegments[] → live streamCharBuffer.
            // While activePill is non-null, incoming tokens are buffered on
            // the pill until its min-duration window elapses, so the answer
            // visibly waits at the pill rather than running ahead of it.
            priorSegments: [],
            activePill:    null,
            // Stable DOM nodes for the segment-aware render. Prior segments
            // are appended once and never replaced; only the liveTailEl is
            // re-rendered per typewriter frame. Without this, replaceChildren
            // every ~16ms would restart each pill's entry animation and the
            // dots would never reach full opacity.
            priorSegmentsRendered: 0,
            liveTailEl:    null,
        };

        if (finalized) {
            finalizeBubble(obj);
        }

        return obj;
    }

    // Inline status pill: minimum visible duration. On cached prompts the
    // tool→answer turnaround can be under 200ms — well below the threshold
    // for reading a label that pops in and out. We hold incoming tokens
    // (and the answer's visible advance) on the pill for this long after
    // it first appears, so the visitor actually sees what the model is
    // doing instead of catching it in their peripheral vision.
    var TOOL_STATUS_MIN_MS = 1200;

    function appendTokenText(bubble, text) {
        bubble.tokenBuffer += text;
        bubble.usedStreaming = true;

        // Drop the placeholder text node the first time real content arrives —
        // it lives next to the thinking indicator and otherwise persists as a
        // stale empty node beneath the parsed tree.
        if (bubble.textNode && bubble.textNode.parentNode) {
            bubble.textNode.parentNode.removeChild(bubble.textNode);
            bubble.textNode = null;
        }

        // Strip [[cite:id]] tags before they reach the visible buffer — server
        // emits citation events for them separately.
        var cleaned = text.replace(/\[\[cite:[a-z0-9_\-]+\]\]/gi, '');

        // If a status pill is currently mid-min-duration, buffer the tokens
        // on the pill so the answer doesn't visibly race past while the
        // pill's "Searching for X…" label is still on screen. The pill's
        // own setTimeout flushes the buffer when its time is up.
        if (bubble.activePill) {
            bubble.activePill.bufferedText = (bubble.activePill.bufferedText || '') + cleaned;
            scheduleTypewriter(bubble);
            return;
        }

        bubble.streamCharBuffer += cleaned;
        scheduleTypewriter(bubble);
    }

    /**
     * Show or update the bubble's single tool-status pill. First call:
     * lock any in-flight live text into a prior segment, push a pill
     * segment at the cursor position. Subsequent calls reuse the same pill
     * — they update its label and reset its min-duration timer so the
     * answer keeps waiting until retrieval truly settles.
     *
     * No preamble case (tokenBuffer empty when first tool_call fires): the
     * pill is the first thing rendered. Preamble case: pill sits between
     * the locked preamble text and the (paused) answer text.
     */
    function showToolStatus(bubble, summary) {
        if (!bubble || !bubble.bodyEl) return;

        // Reuse path — pill already exists on this bubble. Update label and
        // restart the min-duration timer so the answer keeps waiting if the
        // model fires another tool call.
        if (bubble.toolPill) {
            var pill = bubble.toolPill;

            // Lock any tokens that have buffered on the pill since the
            // last tool call (typically a between-turn preamble like
            // "Let me search more specifically…") as their own prior
            // text segment. Without this, every preamble the model
            // writes between turns would accumulate on the pill and end
            // up mashed into the final answer body when the pill
            // settled. Insert the segment AFTER the pill so the
            // visible order matches the conversation: preamble1 →
            // [pill] → preamble2 → [pill re-active] → answer.
            if (pill.bufferedText && pill.bufferedText.length > 0) {
                bubble.priorSegments.push({ kind: 'text', text: pill.bufferedText });
                pill.bufferedText = '';
                ensureLiveTail(bubble);
                flushPendingPriorSegments(bubble);
            }

            pill.summary  = summary;
            pill.locked   = false;
            pill.shownAt  = Date.now();
            bubble.activePill = pill;

            if (pill.el) {
                pill.el.classList.remove('is-static');
                var labelEl = pill.el.querySelector('.ot-chat-thinking__label');
                if (labelEl) labelEl.textContent = summary;
            }
            if (bubble.toolPillTimer) clearTimeout(bubble.toolPillTimer);
            bubble.toolPillTimer = setTimeout(function () { settlePillTimer(bubble, pill); }, TOOL_STATUS_MIN_MS);
            scheduleTypewriter(bubble);
            return;
        }

        // First-tool path — create the pill segment at the cursor position.

        // Drop the placeholder text node if still present — otherwise it sits
        // as a stale empty node above the pill once we insert it.
        if (bubble.textNode && bubble.textNode.parentNode) {
            bubble.textNode.parentNode.removeChild(bubble.textNode);
            bubble.textNode = null;
        }

        // 1. Lock whatever live text exists today as a finished prior segment.
        if (bubble.streamCharBuffer && bubble.streamCharBuffer.length > 0) {
            bubble.priorSegments.push({ kind: 'text', text: bubble.streamCharBuffer });
        }
        bubble.streamCharBuffer = '';
        bubble.streamRendered   = 0;

        // 2. Push the new pill. While active it animates and buffers tokens.
        var newPill = {
            kind:         'pill',
            summary:      summary,
            shownAt:      Date.now(),
            locked:       false,
            bufferedText: '',
        };
        bubble.priorSegments.push(newPill);
        bubble.toolPill   = newPill;
        bubble.activePill = newPill;

        // 3. Insert the pill (and any preamble text segment) into the DOM
        //    SYNCHRONOUSLY — not on the next rAF. On cached prompts the
        //    full SSE burst (tool_call → tokens → done) lands in one task
        //    tick; if we waited for renderStreamFrame the pill would be
        //    born after `done` had already mutated locked=true, so it
        //    would render with `is-static` and never animate.
        ensureLiveTail(bubble);
        flushPendingPriorSegments(bubble);
        // Hide the "Thinking…" indicator now that the pill is on screen —
        // both serve the same "something is happening" purpose.
        bubble.streamThinkingHidden = true;
        var thinkingEl = bubble.bodyEl.querySelector('.ot-chat-thinking');
        if (thinkingEl) thinkingEl.remove();

        bubble.toolPillTimer = setTimeout(function () { settlePillTimer(bubble, newPill); }, TOOL_STATUS_MIN_MS);
        scheduleTypewriter(bubble);
    }

    /**
     * Ensure the live-tail span (the per-frame replaceChildren target) exists
     * inside the bubble body. Prior segments are inserted before it so the
     * order in the DOM matches the order in `priorSegments`.
     */
    function ensureLiveTail(bubble) {
        if (!bubble.liveTailEl) {
            bubble.liveTailEl = document.createElement('span');
            bubble.liveTailEl.className = 'ot-chat-msg__live-tail';
            bubble.bodyEl.appendChild(bubble.liveTailEl);
        }
    }

    /**
     * Walk `priorSegments` from the last-rendered index up to the current
     * length and append stable DOM for each new entry. Pills cache their
     * element on the segment so timer callbacks can mutate the class
     * directly without rebuilding.
     */
    function flushPendingPriorSegments(bubble) {
        var segs = bubble.priorSegments || [];
        while (bubble.priorSegmentsRendered < segs.length) {
            var seg = segs[bubble.priorSegmentsRendered];
            var el  = null;
            if (seg.kind === 'text') {
                el = document.createElement('div');
                el.className = 'ot-chat-msg__seg-text';
                el.appendChild(renderMarkdown(seg.text));
            } else if (seg.kind === 'pill') {
                el = buildPillElement(seg.summary, seg.locked);
            }
            if (el) {
                seg.el = el;
                bubble.bodyEl.insertBefore(el, bubble.liveTailEl);
            }
            bubble.priorSegmentsRendered++;
        }
    }

    /**
     * Internal: timer-fire handler that locks the pill's animated state,
     * flushes any buffered tokens into the live stream, and clears the
     * activePill flag so further tokens render normally. Called either
     * from the natural 1.2s timer OR from `settleActivePill` once the
     * remainder of the min-duration window has elapsed after `done`.
     */
    function settlePillTimer(bubble, pill) {
        settlePillNow(bubble, pill);
    }

    /**
     * Lock the pill, flush buffered tokens, and (if `done` has been
     * received and we know the final tool count) swap the label to its
     * past-tense aggregate. Idempotent — safe to call even after the
     * pill is already locked.
     */
    function settlePillNow(bubble, pill) {
        if (bubble.toolPillTimer) {
            clearTimeout(bubble.toolPillTimer);
            bubble.toolPillTimer = null;
        }

        if (pill.bufferedText) {
            bubble.streamCharBuffer += pill.bufferedText;
            pill.bufferedText = '';
        }

        pill.locked = true;
        if (pill.el) pill.el.classList.add('is-static');

        // Aggregate label only when `done` has been received — for a
        // mid-stream natural-timer settle we keep the active label so a
        // subsequent tool_call can update it cleanly.
        //
        // Three settle-label paths, in priority order:
        //  1. Multi-turn aggregate ("Searched N documents") — when more
        //     than one tool_call event fired across multiple turns. The
        //     specific per-turn label can't represent the whole arc.
        //  2. Server-supplied past-tense summary — when only one turn
        //     fired tool calls (single OR parallel). Server already knows
        //     the right verb form ("Searched for X" / "Read X" / "Read N
        //     documents") and ships it as `settled_summary`.
        //  3. Otherwise keep the current active label — typically when
        //     only the early intent fired and no aggregated tool_call
        //     followed (failure path; rare).
        if (bubble.toolPillSettlePending) {
            bubble.toolPillSettlePending = false;
            var total       = bubble.totalToolCount     || 0;
            var turnsFired  = bubble.toolCallEventCount || 0;
            var settled     = null;
            if (turnsFired > 1) {
                settled = 'Searched ' + total + ' documents';
            } else if (bubble.lastSettledSummary) {
                settled = bubble.lastSettledSummary;
            }
            if (settled) {
                pill.summary = settled;
                if (pill.el) {
                    var labelEl = pill.el.querySelector('.ot-chat-thinking__label');
                    if (labelEl) labelEl.textContent = settled;
                }
            }
        }

        if (bubble.activePill === pill) {
            bubble.activePill = null;
        }
        scheduleTypewriter(bubble);
    }

    /**
     * Build a DOM node for one inline status pill. `locked=true` switches
     * to the settled appearance — dots stop animating, label stays as a
     * permanent record of what the model retrieved at this point in the
     * conversation.
     */
    function buildPillElement(summary, locked) {
        var pill = document.createElement('div');
        pill.className = 'ot-chat-tool-status' + (locked ? ' is-static' : '');
        for (var i = 0; i < 3; i++) {
            var dot = document.createElement('span');
            dot.className = 'ot-chat-thinking-dot';
            pill.appendChild(dot);
        }
        var label = document.createElement('span');
        label.className = 'ot-chat-thinking__label';
        label.textContent = summary;
        pill.appendChild(label);
        return pill;
    }

    function appendCitationMarker(bubble, citation) {
        // Server emits citations in a batch right before the `done` event.
        // We collect them and let the Sources panel render them on finalize —
        // inserting inline chips here would only flash for one rAF tick before
        // the next render-frame replaceChildren wipes them.
        bubble.citations.push(citation);
    }

    // ── Typewriter cadence + progressive markdown ─────
    //
    // Decouples network arrival from visual rendering. Tokens accumulate in
    // streamCharBuffer; an rAF loop drips them into the parser at a rate that
    // tries to clear the backlog over ~6 frames (~100ms) with a per-frame cap
    // so a fat burst doesn't dump instantly. Result: smooth Claude/ChatGPT
    // flow regardless of whether the provider hands us one char at a time or
    // a 200-char chunk every half-second.

    function scheduleTypewriter(bubble) {
        if (REDUCED_MOTION) {
            // Skip the cadence layer entirely — show whatever's been received.
            bubble.streamRendered = bubble.streamCharBuffer.length;
            renderStreamFrame(bubble);
            if (bubble.streamFinalizePending) {
                bubble.streamFinalizePending = false;
                finalizeBubble(bubble);
            }
            return;
        }
        if (bubble.streamRaf !== null) return;
        bubble.streamRaf = requestAnimationFrame(function () {
            bubble.streamRaf = null;
            advanceTypewriter(bubble);
        });
    }

    function advanceTypewriter(bubble) {
        // Time-based cadence: accumulate fractional chars per frame so we hit
        // the target characters-per-second exactly, instead of being floored
        // to "1 char per frame = 60 cps minimum". Backlog and the done-flag
        // bump the rate so we don't drag when the buffer is far ahead.
        var now = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
        var dt = bubble.streamLastTick ? Math.min(now - bubble.streamLastTick, 100) : 16;
        bubble.streamLastTick = now;

        var target = bubble.streamCharBuffer.length;
        var current = bubble.streamRendered;
        var gap = target - current;

        if (gap > 0) {
            // Steady-state typing speed. Tuned to match the Claude/ChatGPT
            // visual pace — slow enough to be readable, fast enough to feel
            // responsive.
            var cps = 50;
            if (bubble.streamDone) cps = 120;       // race the tail after done
            else if (gap > 200) cps = 220;          // big network burst
            else if (gap > 80)  cps = 100;          // small backlog catch-up

            bubble.streamCharsAccrued = (bubble.streamCharsAccrued || 0) + (cps * dt / 1000);
            var step = Math.floor(bubble.streamCharsAccrued);
            if (step > 0) {
                bubble.streamCharsAccrued -= step;
                bubble.streamRendered = current + step;
                if (bubble.streamRendered > target) bubble.streamRendered = target;
                renderStreamFrame(bubble);
            }
        }

        var caughtUp = bubble.streamRendered >= bubble.streamCharBuffer.length;

        // Hold the typewriter while a pill is mid-min-duration. Without
        // this, `done` arriving in the same task tick as `tool_call`
        // would short-circuit straight to finalize: streamCharBuffer is
        // empty (tokens are buffered on the pill), so `caughtUp` is true,
        // and `streamDone` is true, so the bubble would render its final
        // tree immediately — defeating the whole min-duration window.
        // settlePillNow will scheduleTypewriter again once it fires.
        if (bubble.activePill) {
            return;
        }

        if (!caughtUp || !bubble.streamDone) {
            bubble.streamRaf = requestAnimationFrame(function () {
                bubble.streamRaf = null;
                advanceTypewriter(bubble);
            });
            return;
        }

        if (bubble.streamFinalizePending) {
            bubble.streamFinalizePending = false;
            finalizeBubble(bubble);
        }
    }

    function renderStreamFrame(bubble) {
        var visible = bubble.streamCharBuffer.substring(0, bubble.streamRendered);

        // Hide the "Thinking…" indicator the first time a character actually
        // becomes visible OR the first time a pill / prior segment exists.
        // The pill is itself a "something is happening" signal — keeping the
        // dots indicator alongside it would be redundant.
        var hasPriorContent = (bubble.priorSegments && bubble.priorSegments.length > 0);
        if (!bubble.streamThinkingHidden && (visible.length > 0 || hasPriorContent)) {
            bubble.streamThinkingHidden = true;
            var thinking = bubble.bodyEl.querySelector('.ot-chat-thinking');
            if (thinking) thinking.remove();
        }

        // The live-tail container is the ONE element we re-render every
        // typewriter tick; everything before it is appended once and never
        // replaced. Without this the pill's entry animation would restart
        // each frame and the dots would never reach full opacity. Pills
        // (and any locked preamble text) are typically already in the DOM
        // here — `showToolStatus` inserts them synchronously — but text
        // segments locked later (mid-stream tool_call after some answer
        // text) still arrive through this path.
        ensureLiveTail(bubble);
        flushPendingPriorSegments(bubble);

        // Render the live tail. Pad unclosed inline markers so partial
        // **bold**, *italic*, and `code` render optimistically as they're
        // typed instead of showing as raw asterisks until the closer arrives.
        var patched  = patchOpenInlineMarkers(visible);
        var liveTree = renderMarkdown(patched);

        bubble.liveTailEl.replaceChildren(liveTree);
        maybeAutoScroll();
    }

    /**
     * Final-state pill handler. Called from `done`, `error`, and
     * `finalizeBubble`. Locks the pill (dots stop animating) and, when
     * more than one tool fired during the message, swaps the active label
     * ("Reading 8 documents") for a past-tense aggregate ("Searched 8
     * documents").
     *
     * Honors the 1.2s minimum-duration window: on cached prompts where
     * `done` arrives in the same task tick as `tool_call`, settling
     * immediately would steal the visitor's chance to read the pill at
     * all. So if the window hasn't elapsed yet, we defer the lock + flush
     * until it does — only the activePill flag stays set in the meantime,
     * which keeps the typewriter paused.
     *
     * `force: true` overrides the window — used by `finalizeBubble` so
     * the final render never blocks behind a not-yet-fired timer.
     */
    function settleActivePill(bubble, opts) {
        if (!bubble) return;
        opts = opts || {};
        var pill = bubble.toolPill;
        if (!pill) return;

        // Mark that the next settle should apply the aggregate label.
        // settlePillNow consumes this flag whether it runs now (force /
        // window-elapsed) or later (deferred via the timer).
        bubble.toolPillSettlePending = true;

        if (!opts.force) {
            var elapsed = Date.now() - (pill.shownAt || 0);
            var remaining = TOOL_STATUS_MIN_MS - elapsed;
            if (remaining > 0) {
                if (bubble.toolPillTimer) clearTimeout(bubble.toolPillTimer);
                bubble.toolPillTimer = setTimeout(function () {
                    settlePillNow(bubble, pill);
                }, remaining);
                return;
            }
        }

        settlePillNow(bubble, pill);
    }

    function patchOpenInlineMarkers(text) {
        // Order matters: count `**` first (bold), then count solo `*` after
        // stripping pairs (italic), then ``` and ` (code). Each unclosed
        // marker gets its closer appended; the parser then emits a complete
        // `<strong>partial</strong>` instead of literal `**partial`.
        var out = text;

        // Bold: number of `**` occurrences. Odd → unclosed.
        var boldMatches = text.match(/\*\*/g);
        var boldCount = boldMatches ? boldMatches.length : 0;
        if (boldCount % 2 === 1) out += '**';

        // Italic: solo `*` (after stripping `**`). Odd → unclosed.
        var stripped = text.replace(/\*\*/g, '');
        var italicMatches = stripped.match(/\*/g);
        var italicCount = italicMatches ? italicMatches.length : 0;
        if (italicCount % 2 === 1) out += '*';

        // Inline code.
        var codeMatches = text.match(/`/g);
        var codeCount = codeMatches ? codeMatches.length : 0;
        if (codeCount % 2 === 1) out += '`';

        return out;
    }

    // After streaming completes we still want bare URLs in the answer to
    // become clickable, but we couldn't anchorize them mid-stream because
    // we don't know where the URL ends until a boundary char arrives.
    // Walk text nodes in the streamed tree once and replace URL substrings
    // with <a> elements.
    function linkifyTextNodesInPlace(rootEl) {
        var URL_RE = /https?:\/\/[^\s<>()"']+[^\s<>()"'.,;:!?]/g;
        var walker = document.createTreeWalker(rootEl, NodeFilter.SHOW_TEXT, null);
        var nodes = [];
        var node;
        while ((node = walker.nextNode())) {
            // Skip text already inside an <a> — e.g., explicit links from MD.
            if (node.parentNode && node.parentNode.nodeName === 'A') continue;
            if (URL_RE.test(node.data)) nodes.push(node);
            URL_RE.lastIndex = 0;
        }
        nodes.forEach(function (textNode) {
            var text = textNode.data;
            var frag = document.createDocumentFragment();
            var lastEnd = 0;
            var m;
            URL_RE.lastIndex = 0;
            while ((m = URL_RE.exec(text)) !== null) {
                if (m.index > lastEnd) {
                    frag.appendChild(document.createTextNode(text.substring(lastEnd, m.index)));
                }
                var a = document.createElement('a');
                a.href = m[0];
                a.textContent = m[0];
                a.rel = 'noopener';
                frag.appendChild(a);
                lastEnd = m.index + m[0].length;
            }
            if (lastEnd < text.length) {
                frag.appendChild(document.createTextNode(text.substring(lastEnd)));
            }
            if (textNode.parentNode) {
                textNode.parentNode.replaceChild(frag, textNode);
            }
        });
    }

    function finalizeBubble(bubble) {
        if (bubble.finalized) return;
        bubble.finalized = true;

        // Cancel any pending typewriter frame — we're taking over.
        if (bubble.streamRaf !== null) {
            cancelAnimationFrame(bubble.streamRaf);
            bubble.streamRaf = null;
        }

        var cleanText = (bubble.tokenBuffer || '').replace(/\[\[cite:[a-z0-9_\-]+\]\]/gi, '');

        if (bubble.usedStreaming) {
            // Streaming path: the tree was built progressively from prior
            // segments + a live buffer. Settle the bubble first — drain any
            // tokens still buffered on an active pill, mark it locked — then
            // render one final frame including all pills + text in order.
            // Force the settle even if the min-duration window hasn't
            // elapsed; finalize is the absolute end of the road.
            settleActivePill(bubble, { force: true });
            bubble.streamRendered = bubble.streamCharBuffer.length;

            var frag = document.createDocumentFragment();
            (bubble.priorSegments || []).forEach(function (seg) {
                if (seg.kind === 'text') {
                    frag.appendChild(renderMarkdown(seg.text));
                } else if (seg.kind === 'pill') {
                    frag.appendChild(buildPillElement(seg.summary, true));
                }
            });
            frag.appendChild(renderMarkdown(bubble.streamCharBuffer));
            bubble.bodyEl.replaceChildren(frag);
            linkifyTextNodesInPlace(bubble.bodyEl);
        } else {
            // History replay or JSON-fallback path: parse and replace once.
            var mdTree = renderMarkdown(cleanText);
            bubble.bodyEl.replaceChildren(mdTree);
        }

        // Empty body guard: if the model returned nothing, show a placeholder.
        if (cleanText.trim().length === 0 && !bubble.refused) {
            var empty = document.createElement('p');
            empty.style.color = '#9ca3af';
            empty.style.fontStyle = 'italic';
            empty.textContent = strings.empty_response || 'No content returned by the model.';
            bubble.bodyEl.replaceChildren(empty);
        }

        // Only animate the post-stream elements in if this bubble was
        // actually streamed live. History replay and JSON-fallback paths
        // render fully-formed messages — animating them on every reload
        // would be visual noise.
        var animateReveal = bubble.usedStreaming && !REDUCED_MOTION;
        var revealClass = animateReveal ? ' ot-chat-msg__reveal' : '';

        // Refusal copy + contact CTA.
        if (bubble.refused && bubble.citations.length === 0) {
            bubble.msgEl.classList.add('ot-chat-msg--refused');

            // Reveal the pre-rendered warning icon inside the avatar so the
            // refusal signal lives on the avatar (yellow + orange warning),
            // not the body.
            var warningEl = bubble.msgEl.querySelector('.ot-chat-msg__avatar-warning');
            if (warningEl) {
                warningEl.removeAttribute('style');
            }

            if (cleanText.trim().length === 0) {
                var p = bubble.bodyEl.querySelector('p') || document.createElement('p');
                p.textContent = strings.refused_headline || "I don't have enough information to answer that.";
                if (!p.parentNode) bubble.bodyEl.appendChild(p);
            }
            if (config.contact_url) {
                var cta = document.createElement('a');
                cta.className = 'ot-chat-contact-btn' + revealClass;
                if (animateReveal) cta.style.animationDelay = '40ms';
                cta.href = config.contact_url;
                cta.textContent = strings.refused_contact || 'Contact security team →';
                bubble.bodyEl.appendChild(cta);
            }
        }

        // Sources block (after the body, inside content column).
        if (bubble.citations.length > 0) {
            var sources = document.createElement('div');
            sources.className = 'ot-chat-msg__sources' + revealClass;
            if (animateReveal) sources.style.animationDelay = '40ms';

            var h4 = document.createElement('h4');
            h4.textContent = strings.sources_label || 'Sources';
            sources.appendChild(h4);

            var ol = document.createElement('ol');
            bubble.citations.forEach(function (c) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.href = c.url;
                a.textContent = c.title || c.url;
                li.appendChild(a);
                ol.appendChild(li);
            });
            sources.appendChild(ol);

            // Append to the content column (sibling of bodyEl) so it sits below.
            bubble.bodyEl.parentNode.appendChild(sources);
        }

        // Per-answer disclaimer. Skipped on refused messages — adding "verify
        // the sources above" to "I don't have enough info" is nonsensical.
        if (!(bubble.refused && bubble.citations.length === 0)) {
            var disclaimer = document.createElement('p');
            disclaimer.className = 'ot-chat-msg__disclaimer' + revealClass;
            if (animateReveal) disclaimer.style.animationDelay = '140ms';
            disclaimer.textContent = strings.disclaimer || 'AI-generated answer. Not legal, security, or compliance advice. Verify against the sources above.';
            bubble.bodyEl.parentNode.appendChild(disclaimer);
        }

        // Action bar — Copy / Share / Print with icons.
        var actions = document.createElement('div');
        actions.className = 'ot-chat-msg__actions' + revealClass;
        if (animateReveal) actions.style.animationDelay = '220ms';
        actions.appendChild(buildActionButton(COPY_SVG, strings.copy || 'Copy', function (btn) { copyBubble(bubble, btn); }));
        actions.appendChild(buildActionButton(PRINT_SVG, strings.print || 'Print', function () { window.print(); }));
        bubble.bodyEl.parentNode.appendChild(actions);

        maybeAutoScroll();
    }

    function buildActionButton(svgMarkup, label, handler) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.appendChild(svgIcon(svgMarkup));
        btn.appendChild(document.createTextNode(label));
        btn.addEventListener('click', function () { handler(btn); });
        return btn;
    }

    /**
     * Temporarily swap an action button's icon + label to a success state,
     * then restore the original after a short delay.
     */
    function flashButton(btn, successLabel) {
        if (!btn) return;
        // Snapshot original children.
        var originals = Array.prototype.slice.call(btn.childNodes);
        btn.replaceChildren(svgIcon(CHECK_SVG), document.createTextNode(successLabel));
        btn.classList.add('is-success');
        setTimeout(function () {
            btn.replaceChildren.apply(btn, originals);
            btn.classList.remove('is-success');
        }, 1600);
    }

    /**
     * Drain the typewriter immediately and finalize. Used when the stream
     * ends abnormally (server closed without `done`, user hit Stop) — we
     * don't want the cursor to keep blinking on a stalled bubble.
     */
    function forceFinalize(bubble) {
        if (bubble.finalized) return;
        if (bubble.streamRaf !== null) {
            cancelAnimationFrame(bubble.streamRaf);
            bubble.streamRaf = null;
        }
        bubble.streamRendered = bubble.streamCharBuffer.length;
        finalizeBubble(bubble);
    }

    function commitAssistantFromBubble(bubble) {
        var clean = (bubble.tokenBuffer || '').replace(/\[\[cite:[a-z0-9_\-]+\]\]/gi, '');
        history.push({
            role: 'assistant',
            content: clean,
            citations: bubble.citations,
            refused: !!bubble.refused,
        });
        updateResetButtonState();
        saveHistory();
    }

    function applyJsonResponseToBubble(bubble, data) {
        bubble.tokenBuffer = (data && data.answer) || '';
        bubble.citations = (data && Array.isArray(data.citations)) ? data.citations : [];
        bubble.refused = !!(data && data.refused);
        finalizeBubble(bubble);
        commitAssistantFromBubble(bubble);
    }

    // ── Safe markdown renderer ────────────────
    //
    // Supports: paragraphs, numbered lists (1. …), bullet lists (- …),
    // bold (**…**), italic (*…*), inline code (`…`), bare URLs.
    // Never uses innerHTML — builds a DOM tree node-by-node.
    //
    // Walks the text line-by-line rather than splitting on blank lines, so
    // list items separated by blank lines (common in LLM output) collapse
    // into a single <ol>/<ul> with correct numbering, and a bold header
    // immediately followed by a bullet list on the next line renders as a
    // <p> + <ul> instead of one flattened paragraph.
    var RE_BLANK = /^\s*$/;
    var RE_NUM_ITEM = /^\s*\d+\.\s+/;
    var RE_BULLET_ITEM = /^\s*[-*]\s+/;
    var RE_ANY_ITEM = /^\s*(\d+\.|[-*])\s+/;
    var RE_HEADING = /^\s*(#{1,6})\s+(.+?)\s*#*\s*$/;
    var RE_HR = /^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/;

    function renderMarkdown(text) {
        var root = document.createDocumentFragment();
        if (!text) return root;

        // Normalize line endings.
        text = text.replace(/\r\n/g, '\n');

        var lines = text.split('\n');
        var i = 0;

        while (i < lines.length) {
            var line = lines[i];

            if (RE_BLANK.test(line)) {
                i++;
                continue;
            }

            // Horizontal rule — standalone line of 3+ dashes / asterisks / underscores.
            // Checked before headings because `---` matches the heading regex's
            // trailing-hash group if we're not careful.
            if (RE_HR.test(line)) {
                root.appendChild(document.createElement('hr'));
                i++;
                continue;
            }

            // ATX heading — `#` through `######`. We bump the rendered level by 2
            // so chat-bubble headings stay visually subordinate to the message
            // header: `##` → <h4>, `###` → <h5>, capped at <h6>.
            var hMatch = line.match(RE_HEADING);
            if (hMatch) {
                var level = Math.min(6, Math.max(3, hMatch[1].length + 2));
                var h = document.createElement('h' + level);
                renderInlineMarkdown(h, hMatch[2]);
                root.appendChild(h);
                i++;
                continue;
            }

            if (RE_NUM_ITEM.test(line)) {
                var consumed = consumeList(lines, i, 'ol', RE_NUM_ITEM);
                root.appendChild(consumed.node);
                i = consumed.next;
                continue;
            }

            if (RE_BULLET_ITEM.test(line)) {
                var consumed = consumeList(lines, i, 'ul', RE_BULLET_ITEM);
                root.appendChild(consumed.node);
                i = consumed.next;
                continue;
            }

            // Paragraph: consume consecutive non-blank lines that aren't a
            // list, heading, or horizontal rule.
            var paraLines = [line];
            i++;
            while (i < lines.length) {
                var l = lines[i];
                if (RE_BLANK.test(l) || RE_ANY_ITEM.test(l) || RE_HEADING.test(l) || RE_HR.test(l)) break;
                paraLines.push(l);
                i++;
            }
            var p = document.createElement('p');
            renderInlineMarkdown(p, paraLines.join(' '));
            root.appendChild(p);
        }

        return root;
    }

    // Consume a contiguous list starting at lines[start]. Blank lines between
    // items of the same kind are allowed; a different list kind, a non-list
    // line, or two blank lines end the list.
    function consumeList(lines, start, tagName, itemRegex) {
        var list = document.createElement(tagName);
        var i = start;
        while (i < lines.length) {
            var l = lines[i];

            if (RE_BLANK.test(l)) {
                // Peek past a single blank line: if the next non-blank line
                // is still a list item of the same kind, keep going.
                if (i + 1 < lines.length && itemRegex.test(lines[i + 1])) {
                    i++;
                    continue;
                }
                break;
            }

            if (!itemRegex.test(l)) break;

            var itemText = l.replace(itemRegex, '');
            i++;

            // Consume continuation lines — non-blank, non-list wrapped text
            // that isn't a heading or horizontal rule either.
            while (i < lines.length) {
                var nl = lines[i];
                if (RE_BLANK.test(nl) || RE_ANY_ITEM.test(nl) || RE_HEADING.test(nl) || RE_HR.test(nl)) break;
                itemText += ' ' + nl.trim();
                i++;
            }

            var li = document.createElement('li');
            renderInlineMarkdown(li, itemText);
            list.appendChild(li);
        }
        return { node: list, next: i };
    }

    function renderInlineMarkdown(el, text) {
        // Match **bold**, `code`, *italic*, and bare https?:// URLs in one pass.
        // URL group stops at whitespace or common trailing punctuation so the
        // period ending a sentence doesn't get eaten into the link.
        var regex = /\*\*([^*]+?)\*\*|`([^`]+?)`|\*([^*]+?)\*|(https?:\/\/[^\s<>()"']+[^\s<>()"'.,;:!?])/g;
        var lastEnd = 0;
        var m;
        while ((m = regex.exec(text)) !== null) {
            if (m.index > lastEnd) {
                el.appendChild(document.createTextNode(text.substring(lastEnd, m.index)));
            }
            if (m[1] !== undefined) {
                var strong = document.createElement('strong');
                strong.textContent = m[1];
                el.appendChild(strong);
            } else if (m[2] !== undefined) {
                var code = document.createElement('code');
                code.textContent = m[2];
                el.appendChild(code);
            } else if (m[3] !== undefined) {
                var em = document.createElement('em');
                em.textContent = m[3];
                el.appendChild(em);
            } else if (m[4] !== undefined) {
                var link = document.createElement('a');
                link.href = m[4];
                link.textContent = m[4];
                link.rel = 'noopener';
                el.appendChild(link);
            }
            lastEnd = m.index + m[0].length;
        }
        if (lastEnd < text.length) {
            el.appendChild(document.createTextNode(text.substring(lastEnd)));
        }
    }

    // ── UI helpers ────────────────────────────

    function appendBanner(kind, text) {
        var b = document.createElement('div');
        b.className = 'ot-chat-banner ot-chat-banner--' + kind;
        b.appendChild(document.createTextNode(text));
        messagesEl.appendChild(b);
    }

    function appendRetryBanner(userText) {
        var b = document.createElement('div');
        b.className = 'ot-chat-banner ot-chat-banner--error';
        b.appendChild(document.createTextNode(strings.retry || 'Connection lost. Retry?'));

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = strings.retry || 'Retry';
        btn.addEventListener('click', function () {
            b.remove();
            // Remove the last incomplete assistant turn from history so we re-send the same user message.
            if (history.length && history[history.length - 1].role === 'assistant') {
                history.pop();
                saveHistory();
            }
            var responseBubble = appendAssistantBubble('', [], false, false);
            var thinkingEl = document.createElement('span');
            thinkingEl.className = 'ot-chat-thinking';
            thinkingEl.textContent = strings.thinking || 'Thinking…';
            responseBubble.bodyEl.insertBefore(thinkingEl, responseBubble.textNode);
            streamResponse(responseBubble, thinkingEl, userText);
        });
        b.appendChild(btn);
        messagesEl.appendChild(b);
    }

    function appendLongHint() {
        if (document.querySelector('.ot-chat-long-hint')) return;
        var hint = document.createElement('div');
        hint.className = 'ot-chat-long-hint';
        hint.appendChild(document.createTextNode(strings.long_hint || 'This conversation is getting long.'));
        messagesEl.appendChild(hint);
    }

    function setSendButtonMode(mode) {
        [sendBtn].forEach(function (btn) {
            if (!btn) return;
            var labelEl = btn.querySelector('.ot-chat-send__label');
            if (mode === 'stop') {
                btn.classList.add('ot-chat-send--stop');
                btn.type = 'button';
                btn.onclick = function () {
                    if (currentAbort) currentAbort.abort();
                };
                if (labelEl) labelEl.textContent = strings.stop || 'Stop';
            } else {
                btn.classList.remove('ot-chat-send--stop');
                btn.type = 'submit';
                btn.onclick = null;
                if (labelEl) labelEl.textContent = strings.send || 'Send';
            }
        });
    }

    function focusInput() {
        if (input) input.focus();
    }

    // ── Auto-scroll (ResizeObserver-driven, respects user scroll) ─────
    //
    // We observe the messages container. Any size change (new bubble, new
    // token, markdown re-render, citation insert, sources block) fires the
    // observer, and we snap to the bottom of the document — unless the user
    // has manually scrolled away from the bottom. In that case autoScroll
    // stays off until they scroll back near the bottom.

    var autoScrollEnabled = true;
    var scrollRafPending  = false;
    var BOTTOM_THRESHOLD  = 120; // px tolerance for "near bottom"

    function computeNearBottom() {
        var scrollTop = window.scrollY || window.pageYOffset || 0;
        var viewport  = window.innerHeight;
        var docHeight = document.documentElement.scrollHeight;
        return (scrollTop + viewport + BOTTOM_THRESHOLD) >= docHeight;
    }

    window.addEventListener('scroll', function () {
        autoScrollEnabled = computeNearBottom();
    }, { passive: true });

    function snapToBottom() {
        if (scrollRafPending) return;
        scrollRafPending = true;
        requestAnimationFrame(function () {
            scrollRafPending = false;
            window.scrollTo(0, document.documentElement.scrollHeight);
        });
    }

    function maybeAutoScroll() {
        if (!autoScrollEnabled) return;
        snapToBottom();
    }

    // Observe the messages container for any size change and auto-scroll.
    // Covers token streaming, markdown re-render, citation inserts, sources.
    if (typeof ResizeObserver !== 'undefined' && messagesEl) {
        var ro = new ResizeObserver(function () {
            if (autoScrollEnabled) {
                snapToBottom();
            }
        });
        ro.observe(messagesEl);
    }

    // Legacy alias — old call sites.
    function scrollToBottom() { maybeAutoScroll(); }

    function autoResize(el) {
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 200) + 'px';
    }

    function estimateHistoryTokens() {
        var total = 0;
        history.forEach(function (m) { total += (m.content || '').length; });
        return Math.floor(total / 4);
    }

    function copyBubble(bubble, btn) {
        var text = bubble.tokenBuffer || '';
        // Replace inline tags with [N] markers corresponding to citation order.
        bubble.citations.forEach(function (c, i) {
            text = text.replace(new RegExp('\\[\\[cite:' + c.id + '\\]\\]', 'gi'), '[' + (i + 1) + ']');
        });
        if (bubble.citations.length) {
            text += '\n\nSources:\n';
            bubble.citations.forEach(function (c, i) {
                text += '[' + (i + 1) + '] ' + (c.title || '') + ' — ' + c.url + '\n';
            });
        }
        copyToClipboard(text).then(function (ok) {
            if (ok) flashButton(btn, strings.copied || 'Copied');
        });
    }

    /**
     * Copy text to the clipboard using the modern API where available,
     * falling back to the execCommand('copy') shim so this works on older
     * browsers and insecure-context (localhost) cases.
     */
    function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext !== false) {
            return navigator.clipboard.writeText(text).then(
                function () { return true; },
                function () { return legacyCopy(text); }
            );
        }
        return Promise.resolve(legacyCopy(text));
    }

    function legacyCopy(text) {
        try {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'absolute';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            var ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        } catch (e) {
            return false;
        }
    }

    function renderTurnstileWidget(onSuccess) {
        var holder = document.createElement('div');
        holder.className = 'ot-chat-turnstile';
        holder.style.margin = '12px 0';

        var note = document.createElement('p');
        note.style.fontSize = '13px';
        note.style.color = '#6b7280';
        note.textContent = 'Please confirm you are human:';
        holder.appendChild(note);

        var widget = document.createElement('div');
        holder.appendChild(widget);
        messagesEl.appendChild(holder);

        function attempt() {
            if (window.turnstile && typeof window.turnstile.render === 'function') {
                window.turnstile.render(widget, {
                    sitekey: config.turnstile_key,
                    callback: function (token) {
                        holder.remove();
                        onSuccess(token);
                    }
                });
                return;
            }
            setTimeout(attempt, 200);
        }
        attempt();

        streaming = false;
        setSendButtonMode('send');
    }

    function renderBudgetExhaustedState(resetAt) {
        setChattingState(true);
        var banner = document.createElement('div');
        banner.className = 'ot-chat-banner ot-chat-banner--warn';
        var msg = strings.unavailable || 'AI chat is temporarily unavailable.';
        if (resetAt) {
            var when = new Date(resetAt);
            if (!isNaN(when.getTime())) {
                msg += ' ' + 'Resets at ' + when.toLocaleString() + '.';
            }
        }
        banner.appendChild(document.createTextNode(msg));
        messagesEl.appendChild(banner);

        // Disable inputs.
        [input, sendBtn, resetBtn].forEach(function (el) {
            if (el) el.disabled = true;
        });
    }

})();
