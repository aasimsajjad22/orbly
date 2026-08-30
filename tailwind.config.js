/** @type {import('tailwindcss').Config} */
module.exports = {
    // Tailwind only generates the classes it actually finds. These globs
    // tell it where to look — miss a directory and those pages come out
    // unstyled with no error to explain why.
    content: [
        "./assets/**/*.js",
        "./templates/**/*.html.twig",
        // Live Components render from src/, so scan there too once we
        // start writing them in chunk 4.
        "./src/**/*.php",
    ],
    theme: {
        extend: {},
    },
    plugins: [],
};
