/** @type {import('tailwindcss').Config} */
module.exports = {
    darkMode: 'class',
    content: [
        './resources/views/**/*.blade.php',
    ],
    // Status / state modifiers are sometimes composed dynamically in PHP, so
    // keep them out of the purge.
    safelist: [
        'is-success', 'is-warn', 'is-danger', 'is-info', 'is-neutral',
        'is-running', 'is-paused', 'is-terminating', 'is-active',
    ],
    theme: {
        extend: {
            fontFamily: {
                // Offline-safe system stack (no web-font fetch) for UI/headings.
                sans: [
                    'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI',
                    'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif',
                ],
                // Monospace is reserved for data (ids, durations, sql, metrics).
                mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'Liberation Mono', 'monospace'],
            },
        },
    },
    plugins: [],
};
