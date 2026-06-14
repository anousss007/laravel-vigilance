/** @type {import('tailwindcss').Config} */
module.exports = {
    darkMode: 'class',
    content: [
        './resources/views/**/*.blade.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'Liberation Mono', 'monospace'],
            },
        },
    },
    plugins: [],
};
