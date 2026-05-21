<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ config('app.name') }} — API Docs</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css" />
    <style>
        body { margin: 0; }
        .swagger-ui .topbar { background-color: #1a1a2e; }
        .swagger-ui .topbar .topbar-wrapper .link span { display: none; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        SwaggerUIBundle({
            url: "{{ url('/api.json') }}",
            dom_id: '#swagger-ui',
            deepLinking: true,
            tryItOutEnabled: true,
            presets: [
                SwaggerUIBundle.presets.apis,
                SwaggerUIBundle.SwaggerUIStandalonePreset,
            ],
            plugins: [
                SwaggerUIBundle.plugins.DownloadUrl,
            ],
            layout: 'BaseLayout',
            requestInterceptor: function(request) {
                return request;
            },
        })
    </script>
</body>
</html>
