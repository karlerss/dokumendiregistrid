<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Document Registries API</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
    <style>
        body { margin: 0; background: #fafafa; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .notice {
            max-width: 1460px;
            margin: 24px auto 0;
            padding: 16px 20px;
            border: 1px solid #e0c97f;
            background: #fff8e1;
            border-radius: 6px;
            color: #5a4500;
        }
        .notice h2 { margin: 0 0 8px; font-size: 18px; }
        .notice ul { margin: 8px 0 0 20px; padding: 0; }
        .notice code { background: rgba(0,0,0,0.06); padding: 1px 5px; border-radius: 3px; }
    </style>
</head>
<body>
    <div class="notice">
        <h2>Before you start</h2>
        <ul>
            <li><strong>Rate limit:</strong> 10 requests per 10 seconds per IP. Exceeding it returns HTTP 429.</li>
            <li><strong>User-Agent required:</strong> Send a descriptive <code>User-Agent</code> header identifying your application and a contact, e.g.
                <code>MyApp/1.0 (you@example.com)</code>. Requests without a <code>User-Agent</code> are rejected with HTTP 400.</li>
        </ul>
    </div>
    <div id="swagger-ui"></div>
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        window.addEventListener('load', function () {
            window.ui = SwaggerUIBundle({
                url: @json(url('/api/openapi.json')),
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [SwaggerUIBundle.presets.apis],
                layout: 'BaseLayout',
            });
        });
    </script>
</body>
</html>
