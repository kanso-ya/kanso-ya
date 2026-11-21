/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './Core/Ui/**/*.{php,ts,html}',
    './modules/**/*Ui/**/*.{php,ts,html}',
    './public/index.php',
  ],
  safelist: [
    'hidden',
    'flex',
    'grid',
    'block',
    'inline-flex',
    'bg-brand-primary',
    'text-brand-primary',
    'border-brand-primary',
    'bg-brand-accent',
    'text-brand-accent',
    'border-brand-accent',
  ],
  theme: {
    extend: {},
  },
  plugins: [],
};
