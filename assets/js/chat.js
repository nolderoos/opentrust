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
                // User hit Stop. Leave whatever is buffered in the bubble.
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
                        // Server closed without a done event. Leave whatever arrived.
                        commitAssistantFromBubble(bubble);
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
            case 'citation':
                if (evt.data && evt.data.url) {
                    bubble.citations.push(evt.data);
                    appendCitationMarker(bubble, evt.data);
                }
                break;
            case 'done':
                bubble.refused = !!(evt.data && evt.data.refused);
                finalizeBubble(bubble);
                commitAssistantFromBubble(bubble);
                break;
            case 'error':
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
    var SHARE_SVG      = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>';
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
        };

        if (finalized) {
            finalizeBubble(obj);
        }

        return obj;
    }

    function appendTokenText(bubble, text) {
        bubble.tokenBuffer += text;
        // Strip any inline [[cite:id]] tags from the visible stream — server
        // emits citation events for them separately.
        var cleaned = bubble.tokenBuffer.replace(/\[\[cite:[a-z0-9_\-]+\]\]/gi, '');
        bubble.textNode.data = cleaned;
    }

    function appendCitationMarker(bubble, citation) {
        // While streaming (not yet finalized), append chips after the text node
        // at the end of the body. On finalize, renderMarkdown may re-insert them.
        var sup = document.createElement('a');
        sup.className = 'ot-chat-cite';
        sup.href = citation.url;
        sup.target = '_self';
        sup.setAttribute('aria-label', (strings.cite || 'Cite source') + ': ' + (citation.title || ''));
        sup.title = citation.title || '';
        sup.textContent = String(bubble.citations.length);
        bubble.bodyEl.appendChild(sup);
    }

    function finalizeBubble(bubble) {
        if (bubble.finalized) return;
        bubble.finalized = true;

        // Re-render the body: parse the accumulated tokenBuffer as markdown and
        // replace the streaming text-node tree with a structured tree. This only
        // runs once per message at completion — never mid-stream — so there's no
        // risk of partial-markdown flicker.
        var cleanText = (bubble.tokenBuffer || '').replace(/\[\[cite:[a-z0-9_\-]+\]\]/gi, '');
        var mdTree = renderMarkdown(cleanText);

        // Empty body guard: if the model returned nothing, show a placeholder.
        if (cleanText.trim().length === 0 && !bubble.refused) {
            var empty = document.createElement('p');
            empty.style.color = '#9ca3af';
            empty.style.fontStyle = 'italic';
            empty.textContent = strings.empty_response || 'No content returned by the model.';
            mdTree.appendChild(empty);
        }

        bubble.bodyEl.replaceChildren(mdTree);

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
                var p = mdTree.querySelector('p') || document.createElement('p');
                p.textContent = strings.refused_headline || "I don't have enough information to answer that.";
                if (!p.parentNode) mdTree.appendChild(p);
            }
            if (config.contact_url) {
                var cta = document.createElement('a');
                cta.className = 'ot-chat-contact-btn';
                cta.href = config.contact_url;
                cta.textContent = strings.refused_contact || 'Contact security team →';
                bubble.bodyEl.appendChild(cta);
            }
        }

        // Sources block (after the body, inside content column).
        if (bubble.citations.length > 0) {
            var sources = document.createElement('div');
            sources.className = 'ot-chat-msg__sources';

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
            disclaimer.className = 'ot-chat-msg__disclaimer';
            disclaimer.textContent = strings.disclaimer || 'AI-generated answer. Not legal, security, or compliance advice. Verify against the sources above.';
            bubble.bodyEl.parentNode.appendChild(disclaimer);
        }

        // Action bar — Copy / Share / Print with icons.
        var actions = document.createElement('div');
        actions.className = 'ot-chat-msg__actions';
        actions.appendChild(buildActionButton(COPY_SVG, strings.copy || 'Copy', function (btn) { copyBubble(bubble, btn); }));
        actions.appendChild(buildActionButton(SHARE_SVG, strings.share || 'Share', function (btn) { shareLastQuestion(btn); }));
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

    function shareLastQuestion(btn) {
        var lastUser = null;
        for (var i = history.length - 1; i >= 0; i--) {
            if (history[i].role === 'user') { lastUser = history[i]; break; }
        }
        if (!lastUser) return;
        var url = (config.base_url || '') + 'ask/?q=' + encodeURIComponent(lastUser.content);
        copyToClipboard(url).then(function (ok) {
            if (ok) flashButton(btn, strings.link_copied || 'Link copied');
        });
    }

})();
