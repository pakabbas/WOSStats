<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$config = loadConfig();
$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#070b12">
    <title>Live Chat — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
</head>
<body class="page-chat">
    <div class="container container-chat">
        <?php renderSiteNav('chat'); ?>

        <section id="live-chat" class="chat-layout">
            <div class="chat-main">
                <div class="chat-lang-bar" id="chat-lang-bar">
                    <label class="chat-lang-label" for="chat-lang-search">
                        <span class="chat-lang-icon" aria-hidden="true">🌐</span>
                        <span>Your language</span>
                    </label>
                    <div class="chat-lang-combo" id="chat-lang-combo">
                        <input type="hidden" id="chat-lang-select" value="">
                        <input
                            id="chat-lang-search"
                            class="chat-lang-select"
                            type="search"
                            placeholder="Search or choose language…"
                            autocomplete="off"
                            spellcheck="false"
                            role="combobox"
                            aria-autocomplete="list"
                            aria-expanded="false"
                            aria-controls="chat-lang-options"
                            aria-label="Chat translate language"
                        >
                        <ul id="chat-lang-options" class="chat-lang-options" role="listbox" hidden>
                            <li role="option" data-value="" data-label="Choose language">Choose language…</li>
                            <li role="option" data-value="en" data-label="English">English</li>
                            <li role="option" data-value="ar" data-label="Arabic العربية">العربية (Arabic)</li>
                            <li role="option" data-value="zh-cn" data-label="Chinese Simplified 简体中文">简体中文 (Chinese Simplified)</li>
                            <li role="option" data-value="zh-tw" data-label="Chinese Traditional 繁體中文">繁體中文 (Chinese Traditional)</li>
                            <li role="option" data-value="de" data-label="German Deutsch">Deutsch (German)</li>
                            <li role="option" data-value="es" data-label="Spanish Español">Español (Spanish)</li>
                            <li role="option" data-value="fr" data-label="French Français">Français (French)</li>
                            <li role="option" data-value="hi" data-label="Hindi हिन्दी">हिन्दी (Hindi)</li>
                            <li role="option" data-value="id" data-label="Indonesian Bahasa Indonesia">Bahasa Indonesia</li>
                            <li role="option" data-value="it" data-label="Italian Italiano">Italiano (Italian)</li>
                            <li role="option" data-value="ja" data-label="Japanese 日本語">日本語 (Japanese)</li>
                            <li role="option" data-value="ko" data-label="Korean 한국어">한국어 (Korean)</li>
                            <li role="option" data-value="ms" data-label="Malay Bahasa Melayu">Bahasa Melayu</li>
                            <li role="option" data-value="nl" data-label="Dutch Nederlands">Nederlands (Dutch)</li>
                            <li role="option" data-value="pl" data-label="Polish Polski">Polski (Polish)</li>
                            <li role="option" data-value="pt" data-label="Portuguese Português">Português (Portuguese)</li>
                            <li role="option" data-value="ru" data-label="Russian Русский">Русский (Russian)</li>
                            <li role="option" data-value="th" data-label="Thai ไทย">ไทย (Thai)</li>
                            <li role="option" data-value="tr" data-label="Turkish Türkçe">Türkçe (Turkish)</li>
                            <li role="option" data-value="uk" data-label="Ukrainian Українська">Українська (Ukrainian)</li>
                            <li role="option" data-value="ur" data-label="Urdu اردو">اردو (Urdu)</li>
                            <li role="option" data-value="vi" data-label="Vietnamese Tiếng Việt">Tiếng Việt (Vietnamese)</li>
                        </ul>
                    </div>
                </div>

                <div id="chat-messages" class="chat-messages chat-messages-page" aria-live="polite">
                    <p class="chat-empty">No messages yet. Be the first.</p>
                </div>

                <div class="chat-composer-dock">
                    <p id="chat-status" class="chat-status chat-status-dock" role="status"></p>

                    <div id="chat-nick-gate" class="chat-nick-gate">
                        <label for="chat-nick-input">Choose a nick</label>
                        <div class="chat-nick-row">
                            <input id="chat-nick-input" type="text" maxlength="20" placeholder="e.g. BenD0ver" autocomplete="nickname">
                            <button type="button" id="chat-nick-save" class="btn-primary">Join</button>
                        </div>
                    </div>

                    <form id="chat-composer" class="chat-composer" hidden>
                        <div class="chat-composer-meta">
                            <span>As <strong id="chat-nick-label"></strong></span>
                            <button type="button" id="chat-change-nick" class="chat-link-btn">Change</button>
                        </div>

                        <div id="chat-emoji-panel" class="chat-emoji-panel" hidden>
                            <button type="button" data-emoji="😀">😀</button>
                            <button type="button" data-emoji="😂">😂</button>
                            <button type="button" data-emoji="🔥">🔥</button>
                            <button type="button" data-emoji="❤️">❤️</button>
                            <button type="button" data-emoji="👍">👍</button>
                            <button type="button" data-emoji="🎉">🎉</button>
                            <button type="button" data-emoji="❄️">❄️</button>
                            <button type="button" data-emoji="⚔️">⚔️</button>
                            <button type="button" data-emoji="🛡️">🛡️</button>
                            <button type="button" data-emoji="👑">👑</button>
                            <button type="button" data-emoji="💪">💪</button>
                            <button type="button" data-emoji="🙏">🙏</button>
                            <button type="button" data-emoji="😅">😅</button>
                            <button type="button" data-emoji="😎">😎</button>
                            <button type="button" data-emoji="🤝">🤝</button>
                            <button type="button" data-emoji="💯">💯</button>
                        </div>

                        <div id="chat-image-preview" class="chat-image-preview" hidden>
                            <img id="chat-image-preview-img" alt="Selected image preview">
                            <div class="chat-image-preview-meta">
                                <span id="chat-image-preview-name"></span>
                                <button type="button" id="chat-image-clear" class="chat-link-btn">Remove</button>
                            </div>
                        </div>

                        <div class="chat-compose-row">
                            <button type="button" id="chat-emoji-toggle" class="chat-icon-btn" title="Emoji" aria-label="Emoji">😊</button>
                            <button type="button" id="chat-image-btn" class="chat-image-btn" title="Attach image (max 512 KB)" aria-label="Attach image">
                                <span class="chat-image-btn-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.8"/>
                                        <circle cx="9" cy="10" r="1.6" fill="currentColor"/>
                                        <path d="M4.5 16.5l4.2-4.2a1 1 0 0 1 1.4 0L14 16l2.1-2.1a1 1 0 0 1 1.4 0l2 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </span>
                                <span class="chat-image-btn-label">Image</span>
                            </button>
                            <input id="chat-image-input" type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                            <input id="chat-text-input" type="text" maxlength="300" placeholder="Message…" autocomplete="off" enterkeyhint="send">
                            <button type="submit" id="chat-send" class="btn-primary chat-send-btn" title="Send" aria-label="Send">
                                <span class="chat-send-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M4 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </span>
                                <span class="chat-send-label">Send</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <aside class="chat-side">
                <header class="chat-side-header">
                    <p class="eyebrow">WHITEOUT SURVIVAL</p>
                    <h1>Live Chat</h1>
                    <p class="subtitle"><?= h($stateName) ?> · UTC</p>
                </header>

                <div class="chat-side-card">
                    <h2>How to use</h2>
                    <ol class="chat-guide">
                        <li>Choose your language at the top</li>
                        <li>Pick a nick (2–20 characters)</li>
                        <li>Paste or attach an image (max 512 KB)</li>
                        <li>Tap ⇄ beside a message to translate</li>
                        <li>Original stays on top; translation below</li>
                    </ol>
                </div>

                <div id="chat-setup" class="chat-setup" hidden>
                    <strong>Firebase rules need publishing</strong>
                    <ol>
                        <li>Open <a href="https://console.firebase.google.com/project/state4627/database/state4627-default-rtdb/rules" target="_blank" rel="noopener noreferrer">Realtime Database → Rules</a></li>
                        <li>Paste contents of <code>firebase-database.rules.json</code></li>
                        <li>Click <strong>Publish</strong>, then refresh</li>
                    </ol>
                </div>

                <div class="chat-side-card chat-side-card-muted">
                    <p>Keep it civil. No spam. Nicks are local to your browser.</p>
                </div>

                <p class="chat-side-credit">
                    Built by <strong>Abbas</strong>
                    · <a href="https://wa.me/923052848987" target="_blank" rel="noopener noreferrer">WhatsApp</a>
                </p>
            </aside>
        </section>
    </div>

    <script type="module" src="<?= assetUrl('assets/chat.js') ?>"></script>
</body>
</html>
