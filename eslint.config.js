import prettier from "eslint-plugin-prettier/recommended";
import globals from "globals";
import js from "@eslint/js";

export default [
    //js.configs.recommended,
    prettier,
    {
        ignores: ["vendor/*", "views/build/*"],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: "module",
            globals: {
                ...globals.browser,
                ...globals.node,
            },
        },
    },
];
