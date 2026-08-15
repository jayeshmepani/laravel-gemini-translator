<div
    class="translation-manager"
    data-translation-manager
    data-csrf="{{ csrf_token() }}"
    data-endpoint-data="{{ $endpoints['data'] ?? '' }}"
    data-endpoint-languages="{{ $endpoints['languages'] ?? '' }}"
    data-endpoint-existing="{{ $endpoints['existing'] ?? '' }}"
    data-endpoint-scan="{{ $endpoints['scan'] ?? '' }}"
    data-endpoint-save="{{ $endpoints['save'] ?? '' }}"
    data-endpoint-add-languages="{{ $endpoints['add_languages'] ?? '' }}"
    data-language-names='@json($languageNames ?? [])'
    data-pack-map='@json($packMap ?? [])'
>
    @once
        <script>
            (function () {
                try {
                    if (document.documentElement.getAttribute('data-theme')) {
                        return;
                    }
                    var stored = localStorage.getItem('gemini-translator-theme');
                    var theme = stored === 'dark' || stored === 'light'
                        ? stored
                        : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                    document.documentElement.setAttribute('data-theme', theme);
                    document.documentElement.style.colorScheme = theme;
                } catch (error) {}
            }());
        </script>
        @if (filled($assetCss ?? null))
            <link rel="stylesheet" href="{{ $assetCss }}">
        @elseif (filled($managerStyles ?? null))
            <style>{!! $managerStyles !!}</style>
        @endif
    @endonce

    <div class="manager-shell">
        <header class="manager-masthead">
            <div class="manager-masthead-row">
                <div class="manager-identity">
                    <h1 class="manager-title" id="manager-heading">{{ $pageTitle }}</h1>
                    <p class="manager-subtitle">{{ $pageLede }}</p>
                </div>
                <div class="manager-actions">
                    <div class="manager-theme" title="Toggle theme">
                        <label class="manager-theme-toggle" for="manager-theme-toggle">
                            <input
                                type="checkbox"
                                id="manager-theme-toggle"
                                class="manager-theme-input"
                                data-action="theme"
                                role="switch"
                                aria-checked="false"
                                aria-label="Use dark theme"
                            >
                            <span class="manager-theme-slider" aria-hidden="true">
                                <svg class="manager-theme-sun" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="4" fill="currentColor" stroke="none" />
                                    <line x1="12" y1="1" x2="12" y2="3" stroke="currentColor" />
                                    <line x1="12" y1="21" x2="12" y2="23" stroke="currentColor" />
                                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" stroke="currentColor" />
                                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" stroke="currentColor" />
                                    <line x1="1" y1="12" x2="3" y2="12" stroke="currentColor" />
                                    <line x1="21" y1="12" x2="23" y2="12" stroke="currentColor" />
                                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" stroke="currentColor" />
                                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" stroke="currentColor" />
                                </svg>
                                <span class="manager-theme-knob"></span>
                                <svg class="manager-theme-moon" viewBox="0 0 24 24" stroke="none" fill="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                                </svg>
                            </span>
                        </label>
                    </div>
                    <button type="button" class="manager-button" data-action="sync">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M19.95 11a8 8 0 1 0 -.55 5.5" />
                            <path d="M20 4v4h-4" />
                        </svg>
                        Sync
                    </button>
                    <button type="button" class="manager-button manager-button-save" data-action="save" disabled>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M6 4h10l4 4v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2" />
                            <circle cx="12" cy="14" r="2" />
                            <path d="M14 4v4H8V4" />
                        </svg>
                        <span>Save</span>
                        <span class="manager-save-count" data-save-count aria-live="polite">0</span>
                    </button>
                </div>
            </div>

            <form class="manager-filters" data-filters novalidate aria-labelledby="manager-filters-legend">
                <fieldset class="manager-filter-set">
                    <legend class="manager-legend" id="manager-filters-legend">Filter translations</legend>

                    <div class="manager-filter">
                        <label class="manager-label" for="manager-type">Type</label>
                        <select id="manager-type" class="manager-select" data-filter="type" autocomplete="off">
                            <option value="all" selected>All</option>
                            <option value="module">Module</option>
                            <option value="non-module">Non-module</option>
                        </select>
                    </div>

                    <div class="manager-filter manager-filter-module is-hidden" data-module-filter>
                        <label class="manager-label" for="manager-module">Module</label>
                        <select id="manager-module" class="manager-select" data-filter="module" autocomplete="off">
                            <option value="all">All Modules</option>
                            @foreach ($modules as $module)
                                <option value="{{ $module }}">{{ $module }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="manager-filter manager-filter-pack is-hidden" data-pack-filter>
                        <label class="manager-label" for="manager-pack">Pack</label>
                        <select id="manager-pack" class="manager-select" data-filter="pack" autocomplete="off">
                            <option value="all" selected>All packs</option>
                        </select>
                    </div>

                    <div class="manager-filter">
                        <label class="manager-label" for="manager-scope">Scope</label>
                        <select id="manager-scope" class="manager-select" data-filter="scope" autocomplete="off">
                            <option value="all" selected>All Scopes</option>
                            @foreach ($scopes as $scope)
                                <option value="{{ $scope }}">{{ ucfirst((string) $scope) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="manager-filter manager-filter-files is-hidden" data-file-filter>
                        <span class="manager-label" id="manager-files-label">PHP files</span>
                        <button
                            type="button"
                            class="manager-files-toggle"
                            data-files-toggle
                            popovertarget="manager-files-menu"
                            aria-controls="manager-files-menu"
                            aria-expanded="false"
                            aria-haspopup="true"
                            aria-labelledby="manager-files-label"
                        >
                            <span data-files-summary>All PHP files</span>
                        </button>
                        <div class="manager-columns-menu manager-files-menu" id="manager-files-menu" data-files-menu popover="auto">
                            @forelse (($files ?? []) as $file)
                                <label class="manager-check">
                                    <input type="checkbox" data-file-toggle value="{{ $file }}">
                                    <span>{{ $file }}</span>
                                </label>
                            @empty
                                <p class="manager-empty-copy">No PHP language files found.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="manager-filter">
                        <label class="manager-label" for="manager-language">Language</label>
                        <select id="manager-language" class="manager-select" data-filter="language" autocomplete="off">
                            <option value="all" selected>All Languages</option>
                            @foreach ($languages as $language)
                                <option value="{{ $language }}" data-language-code="{{ $language }}">{{ strtoupper((string) $language) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="manager-filter manager-filter-missing">
                        <label class="manager-check" for="manager-missing">
                            <input id="manager-missing" type="checkbox" data-filter="missing">
                            <span>Show only missing</span>
                        </label>
                    </div>
                </fieldset>

                <div class="manager-filter manager-filter-languages-action">
                    <button type="button" class="manager-button manager-button-accent" data-action="add-languages" aria-haspopup="dialog">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M4 5h7" />
                            <path d="M9 3v2c0 4.418-2.239 8-5 8" />
                            <path d="M5 9c0 2.144 2.952 3.908 6.7 4" />
                            <path d="M12 20l4-9 4 9" />
                            <path d="M19.1 18h-6.2" />
                        </svg>
                        Add Languages
                    </button>
                </div>
            </form>
        </header>

        <main class="manager-panel" id="manager-main" tabindex="-1" aria-labelledby="manager-heading">
            <div class="manager-tools">
                <search class="manager-search" aria-label="Search translations">
                    <div class="manager-search-field">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
                            <circle cx="11" cy="11" r="7" />
                            <path d="M21 21l-4.3-4.3" />
                        </svg>
                        <label class="manager-visually-hidden" for="manager-search">Search</label>
                        <input id="manager-search" type="search" data-search placeholder="Search" autocomplete="off" spellcheck="false">
                    </div>
                </search>
                <div class="manager-tool-group">
                    <button type="button" class="manager-icon-button" data-action="refresh" aria-label="Refresh">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
                            <path d="M20 11a8 8 0 1 0-1.7 4.9" />
                            <path d="M20 4v7h-7" />
                        </svg>
                    </button>
                    <button type="button" class="manager-icon-button" data-action="columns" popovertarget="manager-columns-menu" aria-controls="manager-columns-menu" aria-expanded="false" aria-haspopup="true" aria-label="Columns">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
                            <path d="M4 6h16" />
                            <path d="M4 12h16" />
                            <path d="M4 18h16" />
                        </svg>
                    </button>
                    <div class="manager-columns-menu" id="manager-columns-menu" data-columns-menu popover="auto"></div>
                </div>
            </div>

            <div class="manager-table-wrap" data-table-wrap>
                <table class="manager-table" data-table>
                    <caption class="manager-visually-hidden">Translation keys and values by language</caption>
                    <thead data-table-head></thead>
                    <tbody data-table-body></tbody>
                </table>
            </div>

            <div class="manager-feedback" data-feedback>
                <div class="manager-skeleton" data-skeleton hidden aria-hidden="true">
                    <div class="manager-skeleton-row"></div>
                    <div class="manager-skeleton-row"></div>
                    <div class="manager-skeleton-row"></div>
                </div>
                <div class="manager-empty" data-empty hidden>
                    <h2 class="manager-empty-title">No translations match</h2>
                    <p class="manager-empty-copy">Try a different search or clear the filters.</p>
                </div>
                <div class="manager-error" data-error hidden role="alert">
                    <h2 class="manager-empty-title">Could not load translations</h2>
                    <p class="manager-empty-copy" data-error-copy></p>
                    <button type="button" class="manager-button" data-action="retry">Try again</button>
                </div>
            </div>

            <nav class="manager-pager" data-pager hidden aria-label="Translation pagination">
                <div class="manager-pager-status">
                    <p class="manager-pager-meta" data-pager-meta></p>
                    <label class="manager-pager-size-wrap" for="manager-page-size">
                        <span class="manager-visually-hidden">Rows per page</span>
                        <select id="manager-page-size" class="manager-select manager-pager-size" data-page-size>
                            <option value="5" selected>5</option>
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="All">All</option>
                        </select>
                    </label>
                    <span class="manager-pager-size-copy" aria-hidden="true">rows per page</span>
                </div>
                <div class="manager-pager-nav" data-pager-nav></div>
            </nav>
        </main>
    </div>

    <dialog class="manager-dialog" data-dialog aria-labelledby="manager-add-langs-title" aria-describedby="manager-add-langs-desc">
        <div class="manager-dialog-header">
            <h2 class="manager-dialog-title" id="manager-add-langs-title">Add New Languages</h2>
            <button type="button" class="manager-dialog-close" data-dialog-close aria-label="Close">&times;</button>
        </div>
        <p class="manager-dialog-lede" id="manager-add-langs-desc">Select languages to add. Existing locales stay checked.</p>
        <div class="manager-dialog-body">
            <div class="manager-language-grid" data-language-grid></div>
        </div>
        <div class="manager-dialog-footer">
            <button type="button" class="manager-button" data-dialog-close value="cancel">Cancel</button>
            <button type="button" class="manager-button manager-button-accent" data-action="confirm-languages">Add Selected</button>
        </div>
    </dialog>

    <div class="manager-toast-stack" data-toasts role="status" aria-live="polite" aria-atomic="true"></div>

    @once
        @if (filled($assetJs ?? null))
            <script src="{{ $assetJs }}" defer></script>
        @elseif (filled($managerScript ?? null))
            <script>{!! $managerScript !!}</script>
        @endif
    @endonce
</div>
