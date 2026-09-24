<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'eScriptorium Proxy') }} - API Documentation</title>
    <meta name="description" content="Documentazione API per eScriptorium Proxy - Trascrizione OCR automatica di manoscritti storici">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📜</text></svg>">
    <style>
        /* Custom theme overrides */
        :root {
            --theme-accent: #8b5cf6;
            --theme-accent-hover: #7c3aed;
        }

        .scalar-app {
            --scalar-color-accent: var(--theme-accent);
            --scalar-button-1: var(--theme-accent);
            --scalar-button-1-hover: var(--theme-accent-hover);
        }

        /* Partner logos bar */
        .partner-logos {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 3rem;
            padding: 1.5rem 2rem;
            background: #1a1a2e;
            border-bottom: 1px solid rgba(139, 92, 246, 0.2);
        }

        .partner-logos img {
            height: 80px;
            object-fit: contain;
            opacity: 0.9;
            transition: opacity 0.2s ease;
        }

        .partner-logos img:hover {
            opacity: 1;
        }

        .partner-logos .invert-logo {
            filter: brightness(0) invert(1);
        }

        .partner-logos .separator {
            width: 1px;
            height: 50px;
            background: rgba(255, 255, 255, 0.15);
        }

        /* Custom scrollbar */
        .scalar-app ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        .scalar-app ::-webkit-scrollbar-track {
            background: transparent;
        }

        .scalar-app ::-webkit-scrollbar-thumb {
            background: rgba(139, 92, 246, 0.3);
            border-radius: 4px;
        }

        .scalar-app ::-webkit-scrollbar-thumb:hover {
            background: rgba(139, 92, 246, 0.5);
        }

        /* Smooth transitions */
        .scalar-app * {
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }
    </style>
</head>

<body>
    <div class="partner-logos">
        <img src="/images/clarin-it-logo.png" alt="CLARIN-IT" class="invert-logo">
        <div class="separator"></div>
        <img src="/images/net7-logo.svg" alt="Net7" class="invert-logo">
    </div>
    <script id="api-reference" data-url="/docs/api.json"></script>
    <script>
        var configuration = {
            theme: 'deepSpace',
            darkMode: true,
            hideDarkModeToggle: false,
            showSidebar: true,
            hideModels: false,

            // HTTP Client settings
            defaultHttpClient: {
                targetKey: 'shell',
                clientKey: 'curl'
            },

            // Authentication
            authentication: {
                preferredSecurityScheme: 'apiKey',
                apiKey: {
                    token: ''
                }
            },

            // Metadata
            metaData: {
                title: 'eScriptorium Proxy API',
                description: 'API RESTful per trascrizione OCR/HTR automatica di manoscritti storici',
                ogDescription: 'Documentazione API eScriptorium Proxy - Da Manifest IIIF a testo trascritto',
                ogTitle: 'eScriptorium Proxy API Documentation'
            },

            // Layout
            layout: 'modern',
            defaultOpenAllTags: true,

            // Search
            searchHotKey: 'k'
        }

        document.getElementById('api-reference').dataset.configuration = JSON.stringify(configuration)
    </script>
    <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
</body>

</html>
