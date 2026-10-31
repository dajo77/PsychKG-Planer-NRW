/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        './public/**/*.php',
        './templates/**/*.php',
        './src/**/*.php',
    ],
    theme: {
        extend: {
            /* ----------------------------------------------------------------
             * Design-Tokens: Farbsystem (Primary = Indigo, Semantic = Status)
             * ---------------------------------------------------------------- */
            colors: {
                primary: {
                    50:  '#eef2ff',
                    100: '#e0e7ff',
                    200: '#c7d2fe',
                    300: '#a5b4fc',
                    400: '#818cf8',
                    500: '#6366f1',
                    600: '#4f46e5',
                    700: '#4338ca',
                    800: '#3730a3',
                    900: '#312e81',
                },
                success: {
                    50: '#f0fdf4', 100: '#dcfce7', 500: '#22c55e',
                    600: '#16a34a', 700: '#15803d',
                },
                warning: {
                    50: '#fffbeb', 100: '#fef3c7', 500: '#f59e0b',
                    600: '#d97706', 700: '#b45309',
                },
                danger: {
                    50: '#fef2f2', 100: '#fee2e2', 500: '#ef4444',
                    600: '#dc2626', 700: '#b91c1c',
                },
            },
            /* ----------------------------------------------------------------
             * Typografie: modulare Skala + Tabular-Nums fuer Kennzahlen
             * ---------------------------------------------------------------- */
            fontFamily: {
                display: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'],
            },
            fontSize: {
                '2xs': ['0.6875rem', { lineHeight: '0.9375rem' }],
                xs:    ['0.75rem',   { lineHeight: '1rem' }],
                sm:    ['0.875rem',  { lineHeight: '1.25rem' }],
                base:  ['1rem',      { lineHeight: '1.5rem' }],
                lg:    ['1.125rem',  { lineHeight: '1.75rem' }],
                xl:    ['1.25rem',   { lineHeight: '1.75rem' }],
                '2xl': ['1.5rem',    { lineHeight: '2rem' }],
                '3xl': ['1.875rem',  { lineHeight: '2.25rem' }],
            },
            /* ----------------------------------------------------------------
             * Border-Radius: konsistente Skala (Cards/Buttons/Modals)
             * ---------------------------------------------------------------- */
            borderRadius: {
                sm:   '0.375rem',
                DEFAULT: '0.5rem',
                md:   '0.625rem',
                lg:   '0.75rem',
                xl:   '1rem',
                '2xl':'1.25rem',
                '3xl':'1.5rem',
            },
            /* ----------------------------------------------------------------
             * Schatten: weiche, professionelle Tiefe
             * ---------------------------------------------------------------- */
            boxShadow: {
                xs:   '0 1px 2px rgba(15, 23, 42, 0.04)',
                sm:   '0 1px 3px rgba(15, 23, 42, 0.06), 0 1px 2px rgba(15, 23, 42, 0.04)',
                DEFAULT: '0 4px 12px rgba(15, 23, 42, 0.06)',
                md:   '0 8px 24px rgba(15, 23, 42, 0.08)',
                lg:   '0 16px 40px rgba(15, 23, 42, 0.10)',
                card: '0 4px 20px rgba(15, 23, 42, 0.04)',
                'card-hover': '0 8px 24px rgba(15, 23, 42, 0.08)',
            },
        },
    },
    plugins: [],
};
