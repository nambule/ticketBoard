# Tickets Kanban

This project contains a browser UI and a PHP proxy for loading tickets from an API and displaying them as a kanban board grouped by `statut`.

## Files

- `index.html`: compact session-level connection bar plus kanban board and ticket detail drawer.
- `proxy.php`: server-side proxy that forwards requests to the target API and accepts custom upstream headers from the UI.

## Usage

1. Serve this folder from a local PHP-capable web server. Do not open `index.html` with `file://`.
2. Optionally configure a default auth secret with either:
   - environment variable `API_AUTH_KEY`
   - environment variable `API_AUTH_KEY_FILE` pointing to a text file outside the web directory
3. Optionally set `API_AUTH_HEADER` if the upstream expects a different header name. The default is `X-Auth-Key`.
4. Open `index.html` through the local server.
5. Enter the base API endpoint URL.
6. Enter the `X-API-KEY` header value as JSON, for example `{"X-API-KEY":"your-api-key"}`.
7. Set `ticketid`, `moduleid`, and `lotid`. They default to `-1`.
8. Click **Load Tickets**.
9. Open a card to view its details in the right-side drawer.

## Notes

- The browser talks only to `proxy.php`, so CORS is no longer required on the upstream API for local testing.
- The UI builds the final request URL by appending `ticketid`, `moduleid`, and `lotid` as query parameters.
- The kanban columns follow a fixed business status order.
- `Réserve`, `Annulé`, and `Refusé / won't fix` are hidden by default and can be revealed with the board toggle.
- If `API_AUTH_KEY` is configured, the proxy sends it as `X-Auth-Key` by default, or as the header named by `API_AUTH_HEADER`.
- If you supply the configured auth header directly in the UI, the proxy does not append its default auth header on top.
- Do not store secrets in this web directory. Prefer `API_AUTH_KEY`, or point `API_AUTH_KEY_FILE` to a file stored outside the document root.
