import colors from "tailwindcss/colors.js";

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        "./resources/**/*.blade.php",
        "./resources/**/*.js",
        "./resources/**/*.vue",
    ],
    theme: {
        extend: {
            colors: {
                dark: colors.green,
            }
        },
    },
    plugins: [],
}

