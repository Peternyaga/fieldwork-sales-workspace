---
name: fieldwork-setup
description: Set up the local Fieldwork sales workspace after installing the plugin, including opening the browser for first-run owner account creation and checking the local service.
---

# Set up Fieldwork

Use this skill when the user asks to set up or connect Fieldwork. The plugin package does not launch applications merely by being installed. Begin this guided setup on first use and open the local Fieldwork page in a browser so the user can complete the owner form or sign in. The Fieldwork **Connect Codex** button only prepares a local 30-day access token. Do not describe the plugin as connected until a live authenticated MCP read succeeds.

1. Check whether the local web app responds at `http://127.0.0.1:5178` and the PHP API responds at `http://127.0.0.1:8788/api/bootstrap-status`. If either is unavailable, use the `sales-service/README.md` file in the source repository to help start MySQL, PHP, and Vite on the user's machine. Do not claim a background service is running unless verified. If this plugin is installed away from the source repository, configure its MCP server process with `FIELDWORK_SERVICE_ROOT` set to the absolute local `sales-service` directory before testing tools.
2. Open `http://127.0.0.1:5178` for the user. On an empty local database, the site shows owner setup. The owner enters business name, name, email, and a password directly in the browser. Never ask for that password in chat, read it from the page, copy it to a prompt, or save it in the plugin.
3. On a provisioned workspace, the site shows sign-in. Owners can invite team members from **Team & access**; the local site displays a one-use setup link for private sharing. Lost owner access can be recovered locally with the documented console recovery command until email delivery exists.
4. After the user signs in, guide them to select **Connect Codex** in Fieldwork if its status says access has not been prepared. If access is already prepared, do not rotate the token unnecessarily. The button writes a local connection token without putting a password in chat. Restart the plugin connection, then verify a live read-only tool call such as `list_leads`. That authentication changes the app's status from **Access token prepared** to **Plugin access verified**. If the user resets their password or the 30-day token expires, repeat the connection step.

The current implementation is local-only. Installing the plugin does not install PHP, MySQL, Vite, or the optional desktop alarm helper by itself. Do not deploy it or connect external messaging accounts during this setup.
