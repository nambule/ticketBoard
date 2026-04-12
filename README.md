# Simple REST API Connector

This project contains a minimal HTML page and a PHP proxy that send requests to a REST API without exposing the default auth secret in browser code.

## Files

- `index.html`: browser-based client that sends requests to the local proxy.
- `proxy.php`: server-side proxy that forwards requests to the target API, optionally adds a default auth header, and accepts custom upstream headers from the UI.

## Usage

1. Serve this folder from a local PHP-capable web server. Do not open `index.html` with `file://`.
2. Optionally configure a default auth secret with either:
   - environment variable `API_AUTH_KEY`
   - environment variable `API_AUTH_KEY_FILE` pointing to a text file outside the web directory
3. Optionally set `API_AUTH_HEADER` if the upstream expects a different header name. The default is `X-Auth-Key`.
4. Open `index.html` through the local server.
5. Enter your API endpoint URL.
6. Choose the HTTP method.
7. Add a JSON body for non-`GET` requests if needed.
8. Add request headers as a JSON object when the upstream requires `Authorization`, cookies, or custom headers.
9. Click **Send Request**.

## Notes

- The browser talks only to `proxy.php`, so CORS is no longer required on the upstream API for local testing.
- If `API_AUTH_KEY` is configured, the proxy sends it as `X-Auth-Key` by default, or as the header named by `API_AUTH_HEADER`.
- If you supply `Authorization` or `X-Auth-Key` in the UI headers field, the proxy does not append its default auth header on top.
- Do not store secrets in this web directory. Prefer `API_AUTH_KEY`, or point `API_AUTH_KEY_FILE` to a file stored outside the document root.
