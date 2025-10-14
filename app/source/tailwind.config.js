/** @type {import('tailwindcss').Config} */
import defaultTheme from 'tailwindcss/defaultTheme';

export const texts = {
  'title-h1': [
    '3.5rem',
    {
      lineHeight: '4rem',
      letterSpacing: '-0.01em',
      fontWeight: '500',
    },
  ],
  'title-h2': [
    '3rem',
    {
      lineHeight: '3.5rem',
      letterSpacing: '-0.01em',
      fontWeight: '500',
    },
  ],
  'title-h3': [
    '2.5rem',
    {
      lineHeight: '3rem',
      letterSpacing: '-0.01em',
      fontWeight: '500',
    },
  ],
  'title-h4': [
    '2rem',
    {
      lineHeight: '2.5rem',
      letterSpacing: '-0.005em',
      fontWeight: '500',
    },
  ],
  'title-h5': [
    '1.5rem',
    {
      lineHeight: '2rem',
      letterSpacing: '0em',
      fontWeight: '500',
    },
  ],
  'title-h6': [
    '1.25rem',
    {
      lineHeight: '1.75rem',
      letterSpacing: '0em',
      fontWeight: '500',
    },
  ],
  'label-xl': [
    '1.5rem',
    {
      lineHeight: '2rem',
      letterSpacing: '-0.015em',
      fontWeight: '500',
    },
  ],
  'label-lg': [
    '1.125rem',
    {
      lineHeight: '1.5rem',
      letterSpacing: '-0.015em',
      fontWeight: '500',
    },
  ],
  'label-md': [
    '1rem',
    {
      lineHeight: '1.5rem',
      letterSpacing: '-0.011em',
      fontWeight: '500',
    },
  ],
  'label-sm': [
    '.875rem',
    {
      lineHeight: '1.25rem',
      letterSpacing: '-0.006em',
      fontWeight: '500',
    },
  ],
  'label-xs': [
    '.75rem',
    {
      lineHeight: '1rem',
      letterSpacing: '0em',
      fontWeight: '500',
    },
  ],
  'paragraph-xl': [
    '1.5rem',
    {
      lineHeight: '2rem',
      letterSpacing: '-0.015em',
      fontWeight: '400',
    },
  ],
  'paragraph-lg': [
    '1.125rem',
    {
      lineHeight: '1.5rem',
      letterSpacing: '-0.015em',
      fontWeight: '400',
    },
  ],
  'paragraph-md': [
    '1rem',
    {
      lineHeight: '1.5rem',
      letterSpacing: '-0.011em',
      fontWeight: '400',
    },
  ],
  'paragraph-sm': [
    '.875rem',
    {
      lineHeight: '1.25rem',
      letterSpacing: '-0.006em',
      fontWeight: '400',
    },
  ],
  'paragraph-xs': [
    '.75rem',
    {
      lineHeight: '1rem',
      letterSpacing: '0em',
      fontWeight: '400',
    },
  ],
  'subheading-md': [
    '1rem',
    {
      lineHeight: '1.5rem',
      letterSpacing: '0.06em',
      fontWeight: '500',
    },
  ],
  'subheading-sm': [
    '.875rem',
    {
      lineHeight: '1.25rem',
      letterSpacing: '0.06em',
      fontWeight: '500',
    },
  ],
  'subheading-xs': [
    '.75rem',
    {
      lineHeight: '1rem',
      letterSpacing: '0.04em',
      fontWeight: '500',
    },
  ],
  'subheading-2xs': [
    '.6875rem',
    {
      lineHeight: '.75rem',
      letterSpacing: '0.02em',
      fontWeight: '500',
    },
  ],
};

export const shadows = {
  'regular-xs': '0 1px 2px 0 #0a0d1408',
  'regular-sm': '0 2px 4px #1b1c1d0a',
  'regular-md': '0 16px 32px -12px #0e121b1a',
  'button-primary-focus': ['0 0 0 2px theme(colors.bg[white-0])', '0 0 0 4px theme(colors.primary[alpha-10])'],
  'button-important-focus': ['0 0 0 2px theme(colors.bg[white-0])', '0 0 0 4px theme(colors.neutral[alpha-16])'],
  'button-error-focus': ['0 0 0 2px theme(colors.bg[white-0])', '0 0 0 4px theme(colors.red[alpha-10])'],
};

export const borderRadii = {
  10: '.625rem',
  20: '1.25rem',
};

const config = {
  darkMode: ['class'],
  safelist: ['.dark'],
  content: [
    './src/**/*.{js,ts,jsx,tsx,mdx}',
    './components/**/*.{js,ts,jsx,tsx}',
    './node_modules/@pixelflow-org/plugin-features/dist/**/*.js',
    './node_modules/@pixelflow-org/plugin-ui/dist/**/*.js',
  ],
  theme: {
    colors: {
      ...defaultTheme.colors,
      gray: {
        ...defaultTheme.colors?.gray,
        0: 'var(--gray-0)',
        50: 'var(--gray-50)',
        100: 'var(--gray-100)',
        150: 'var(--gray-150)',
        200: 'var(--gray-200)',
        300: 'var(--gray-300)',
        400: 'var(--gray-400)',
        500: 'var(--gray-500)',
        600: 'var(--gray-600)',
        700: 'var(--gray-700)',
        800: 'var(--gray-800)',
        900: 'var(--gray-900)',
        950: 'var(--gray-950)',
        'alpha-24': 'var(--gray-alpha-24)',
        'alpha-16': 'var(--gray-alpha-16)',
        'alpha-10': 'var(--gray-alpha-10)',
      },
      neutral: {
        0: 'var(--neutral-0)',
        50: 'var(--neutral-50)',
        100: 'var(--neutral-100)',
        200: 'var(--neutral-200)',
        300: 'var(--neutral-300)',
        400: 'var(--neutral-400)',
        500: 'var(--neutral-500)',
        600: 'var(--neutral-600)',
        700: 'var(--neutral-700)',
        800: 'var(--neutral-800)',
        900: 'var(--neutral-900)',
        950: 'var(--neutral-950)',
        'alpha-24': 'var(--neutral-alpha-24)',
        'alpha-16': 'var(--neutral-alpha-16)',
        'alpha-10': 'var(--neutral-alpha-10)',
      },
      blue: {
        50: 'var(--blue-50)',
        100: 'var(--blue-100)',
        200: 'var(--blue-200)',
        300: 'var(--blue-300)',
        400: 'var(--blue-400)',
        500: 'var(--blue-500)',
        600: 'var(--blue-600)',
        700: 'var(--blue-700)',
        800: 'var(--blue-800)',
        900: 'var(--blue-900)',
        950: 'var(--blue-950)',
        'alpha-24': 'var(--blue-alpha-24)',
        'alpha-16': 'var(--blue-alpha-16)',
        'alpha-10': 'var(--blue-alpha-10)',
      },
      red: {
        50: 'var(--red-50)',
        100: 'var(--red-100)',
        200: 'var(--red-200)',
        300: 'var(--red-300)',
        400: 'var(--red-400)',
        500: 'var(--red-500)',
        600: 'var(--red-600)',
        700: 'var(--red-700)',
        800: 'var(--red-800)',
        900: 'var(--red-900)',
        950: 'var(--red-950)',
        'alpha-24': 'var(--red-alpha-24)',
        'alpha-16': 'var(--red-alpha-16)',
        'alpha-10': 'var(--red-alpha-10)',
      },
      primary: {
        dark: 'var(--primary-dark)',
        darker: 'var(--primary-darker)',
        base: 'var(--primary-base)',
        'alpha-24': 'var(--primary-alpha-24)',
        'alpha-16': 'var(--primary-alpha-16)',
        'alpha-10': 'var(--primary-alpha-10)',
      },
      static: {
        black: 'var(--static-black)',
        white: 'var(--static-white)',
      },
      'static-white': 'var(--static-white)',
      white: {
        DEFAULT: '#ffffff',
        'alpha-24': 'var(--white-alpha-24)',
        'alpha-16': 'var(--white-alpha-16)',
        'alpha-10': 'var(--white-alpha-10)',
      },
      black: {
        DEFAULT: '#000000',
        'alpha-24': 'var(--black-alpha-24)',
        'alpha-16': 'var(--black-alpha-16)',
        'alpha-10': 'var(--black-alpha-10)',
      },
      bg: {
        'strong-950': 'var(--bg-strong-950)',
        'surface-800': 'var(--bg-surface-800)',
        'sub-300': 'var(--bg-sub-300)',
        'soft-200': 'var(--bg-soft-200)',
        'weak-50': 'var(--bg-weak-50)',
        'white-0': 'var(--bg-white-0)',
      },
      text: {
        'strong-950': 'var(--text-strong-950)',
        'sub-600': 'var(--text-sub-600)',
        'soft-400': 'var(--text-soft-400)',
        'disabled-300': 'var(--text-disabled-300)',
        'white-0': 'var(--text-white-0)',
      },
      stroke: {
        'strong-950': 'var(--stroke-strong-950)',
        'sub-300': 'var(--stroke-sub-300)',
        'soft-200': 'var(--stroke-soft-200)',
        'white-0': 'var(--stroke-white-0)',
      },
      error: {
        dark: 'var(--error-dark)',
        base: 'var(--error-base)',
        light: 'var(--error-light)',
        lighter: 'var(--error-lighter)',
      },
      background: 'var(--color-background)',
      foreground: 'var(--color-foreground)',
      muted: {
        DEFAULT: 'var(--color-muted)',
        foreground: 'var(--color-muted-foreground)',
      },
      transparent: 'transparent',
      current: 'currentColor',
    },
    fontSize: {
      ...defaultTheme.fontSize,
      ...texts,
      inherit: 'inherit',
    },
    boxShadow: {
      ...defaultTheme.boxShadow,
      ...shadows,
      none: defaultTheme.boxShadow.none,
    },
    extend: {
      fontFamily: {
        sans: ['"SF Pro Display"', 'sans-serif'],
        magnetik: ['Magnetik', 'sans-serif'],
      },
      borderRadius: {
        ...defaultTheme.borderRadius,
        ...borderRadii,
      },
      borderColor: {
        primary: 'var(--border-primary)',
        secondary: 'var(--border-secondary)',
        accent: 'var(--border-accent)',
        error: 'var(--border-error)',
        subtle: 'var(--border-subtle)',
        strong: 'var(--border-strong)',
      },
    },
  },
  plugins: [],
};

export default config;
