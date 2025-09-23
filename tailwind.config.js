// tailwind.config.js
const defaultTheme = require('tailwindcss/defaultTheme');
const plugin = require('tailwindcss/plugin');

module.exports = {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
        './resources/**/*.ts',
        './resources/**/*.tsx',
        './app/Livewire/**/*.php',
        './storage/framework/views/*.php',
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
    ],
    theme: {
        container: {
            center: true,
            // padding por breakpoint (de breakpoints.xlsx + guía)
            padding: {
                DEFAULT: '16px',   // mobile
                tablet: '40px',
                desktop: '72px',
                xl: '120px',
            },
            // opcional, si quieres atar container a breakpoints por nombre
            screens: {
                desktop: '1200px',
                xl: '1440px',
            },
        },

        // Breakpoints (Mobile <=512, Tablet 513–1199, Desktop >=1200; XL >=1440)
        screens: {
            mobile: { max: '512px' },
            tablet: { min: '513px', max: '1199px' },
            desktop: { min: '1200px' },
            xl: { min: '1440px' },
        },

        extend: {
            // Colores de marca (DEFAULT = base). Mapeo: +400→900 … -400→100
            colors: {
                primary: {
                    900: '#0B613E',  // +400
                    800: '#086D44',  // +300
                    700: '#05794A',  // +200
                    600: '#038450',  // +100
                    DEFAULT: '#009056', // base
                    500: '#009056',
                    400: '#66BC9A',  // -100
                    300: '#CCE9DD',  // -200
                    200: '#E5F4EE',  // -300
                    100: '#F2F9F6',  // -400
                },
                secondary: {
                    900: '#FFC918',
                    800: '#FFCE29',
                    700: '#FFD543',
                    600: '#FFDA55',
                    DEFAULT: '#FFE16F',
                    500: '#FFE16F',
                    400: '#FFEA9A',
                    300: '#FFF0B7',
                    200: '#FFF6D4',
                    100: '#FFFCF0',
                },
                tertiary: {
                    900: '#0C8A4D',
                    800: '#099D56',
                    700: '#07AF5E',
                    600: '#04C267',
                    DEFAULT: '#02D46F',
                    500: '#02D46F',
                    400: '#4EE19A',
                    300: '#B3F2D4',
                    200: '#E6FBF1',
                    100: '#F2FDF8',
                },
                complementary: {
                    900: '#0B795D',
                    800: '#088968',
                    700: '#059973',
                    600: '#03A87E',
                    DEFAULT: '#00B889',
                    500: '#00B889',
                    400: '#4CCDAC',
                    300: '#B2EADC',
                    200: '#E5F8F3',
                    100: '#F2FBF9',
                },
                gray: { // Greyscale
                    950: '#1B1B1B', // Black
                    900: '#282828', // +400
                    800: '#414141', // +300
                    700: '#5B5B5B', // +200
                    600: '#757575', // +100
                    500: '#9B9B9B', // base
                    400: '#B9B9B9', // -100
                    300: '#E1E1E1', // -200
                    200: '#F5F5F5', // -300
                    100: '#FAFAFA', // -400
                    50: '#FFFFFF', // White (alias muy claro)
                },
                danger: {
                    900: '#8F2B34',
                    800: '#A22D38',
                    700: '#B5303D',
                    600: '#C93241',
                    DEFAULT: '#DC3545',
                    500: '#DC3545',
                    400: '#E7727D',
                    300: '#EE9AA2',
                    200: '#F5C2C7',
                    100: '#FBEBEC',
                },
                info: {
                    900: '#0B56A4',
                    800: '#0860BB',
                    700: '#056AD1',
                    600: '#0374E8',
                    DEFAULT: '#007EFF',
                    500: '#007EFF',
                    400: '#0374E8',  // repetido en guía
                    300: '#80BFFF',
                    200: '#B2D8FF',
                    100: '#E5F2FF',
                },
                success: {
                    900: '#D7B23E',
                    800: '#E0B52E',
                    700: '#EBB91F',
                    600: '#F5BD0F',
                    DEFAULT: '#FFC100',
                    500: '#FFC100',
                    400: '#FFD44D',
                    300: '#FFE080',
                    200: '#FFECB2',
                    100: '#FFF9E5',
                },
            },

            // Tipografía (de tu hoja consolidada)
            fontFamily: {
                // usa lo que tengas en el proyecto; Jetstream suele usar Inter
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },

            // Tamaños base (mobile). Escala desktop en el plugin "headings" más abajo
            fontSize: {
                // Heading / Mobile
                h1: ['1.75rem', { lineHeight: '1.2' }], // 28pt
                h2: ['1.5rem', { lineHeight: '1.2' }], // 24pt
                h3: ['1.375rem', { lineHeight: '1.2' }], // 22pt
                h4: ['1.25rem', { lineHeight: '1.2' }], // 20pt
                h5: ['1.125rem', { lineHeight: '1.2' }], // 18pt
                h6: ['1rem', { lineHeight: '1.2' }], // 16pt

                body: ['1rem', { lineHeight: '1.4' }], // 16pt
                label: ['0.875rem', { lineHeight: '1.4' }], // 14pt
                caption: ['0.75rem', { lineHeight: '1.2' }], // 12pt
                button: ['1rem', { lineHeight: '24px' }], // 16pt, LH 24px
            },

            lineHeight: {
                tight: '1.2',
                body: '1.4',
            },

            // Atajos de spacing para “gutter”/“margen” si los quieres como tokens
            spacing: {
                gutter: '24px',     // guía general
                'gutter-sm': '16px',// mobile/tablet de breakpoints.xlsx
                'margin-desktop': '72px',
                'margin-xl': '120px',
            },
        },
    },

    plugins: [
        require('@tailwindcss/forms'),
        require('@tailwindcss/typography'),
        require('@tailwindcss/aspect-ratio'),

        // Utilidades de headings: mobile por defecto, desktop/xl escalan
        plugin(({ addComponents, theme }) => {
            const base = {
                '.text-h1': { fontSize: theme('fontSize.h1')[0], lineHeight: theme('fontSize.h1')[1].lineHeight },
                '.text-h2': { fontSize: theme('fontSize.h2')[0], lineHeight: theme('fontSize.h2')[1].lineHeight },
                '.text-h3': { fontSize: theme('fontSize.h3')[0], lineHeight: theme('fontSize.h3')[1].lineHeight },
                '.text-h4': { fontSize: theme('fontSize.h4')[0], lineHeight: theme('fontSize.h4')[1].lineHeight },
                '.text-h5': { fontSize: theme('fontSize.h5')[0], lineHeight: theme('fontSize.h5')[1].lineHeight },
                '.text-h6': { fontSize: theme('fontSize.h6')[0], lineHeight: theme('fontSize.h6')[1].lineHeight },
                '.text-body': { fontSize: theme('fontSize.body')[0], lineHeight: theme('fontSize.body')[1].lineHeight },
                '.text-label': { fontSize: theme('fontSize.label')[0], lineHeight: theme('fontSize.label')[1].lineHeight },
                '.text-caption': { fontSize: theme('fontSize.caption')[0], lineHeight: theme('fontSize.caption')[1].lineHeight },
                '.text-button': { fontSize: theme('fontSize.button')[0], lineHeight: theme('fontSize.button')[1].lineHeight },
            };

            const desktopUp = {
                '@screen desktop': {
                    '.text-h1': { fontSize: '2.25rem' }, // 36pt
                    '.text-h2': { fontSize: '2rem' },    // 32pt
                    '.text-h3': { fontSize: '1.75rem' }, // 28pt
                    '.text-h4': { fontSize: '1.25rem' }, // ya coincide
                    '.text-h5': { fontSize: '1.25rem' }, // H5 desktop 20pt = 1.25rem
                    '.text-h6': { fontSize: '1rem' },    // 16pt
                },
            };

            addComponents({ ...base, ...desktopUp });
        }),
    ],
};
