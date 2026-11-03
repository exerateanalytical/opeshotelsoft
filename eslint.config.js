// Minimal flat config. Run: npx -p eslint@9 -p typescript-eslint@8 -p @eslint/js@9 eslint resources/js
import js from '@eslint/js';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    { ignores: ['public/**', 'vendor/**', 'node_modules/**', 'storage/**', 'mobile/**'] },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        rules: {
            '@typescript-eslint/no-explicit-any': 'off',
            '@typescript-eslint/no-unused-vars': 'warn',
        },
    },
);
