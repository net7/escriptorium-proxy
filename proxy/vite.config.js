import { defineConfig, lazyPlugins } from "vite-plus";
import laravel from "laravel-vite-plugin";
import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
    fmt: {},
    lint: {
        jsPlugins: [{ name: "vite-plus", specifier: "vite-plus/oxlint-plugin" }],
        rules: { "vite-plus/prefer-vite-plus-imports": "error" },
        options: { typeAware: true, typeCheck: true },
    },
    plugins: lazyPlugins(() => [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.jsx"],
            refresh: true,
        }),
        tailwindcss(),
        react(),
    ]),
    server: {
        host: "0.0.0.0",
        port: 5173,
        strictPort: true,
        hmr: {
            host: "localhost",
            port: 5173,
        },
        watch: {
            ignored: ["**/storage/framework/views/**"],
        },
    },
});
