---
name: fieldwork-setup
description: Set up the local Fieldwork sales workspace after installing the plugin, including opening the browser for first-run owner account creation and checking the local service.
---

# Set up Fieldwork

Use this skill when the user asks to set up or connect Fieldwork. The plugin package does not launch applications merely by being installed. Begin this guided setup on first use and open the local Fieldwork page in a browser so the user can complete the owner form.

1. Check whether the local web app responds at `http://127.0.0.1:5178` and the PHP API responds at `http://127.0.0.1:8788/api/bootstrap-status`. If either is unavailable, use the `sales-service/README.md` file in the repository alongside this plugin to help start MySQL, PHP, and Vite on the user's machine. Do not claim a background service is running unless verified.
2. Open `http://127.0.0.1:5178` for the user. On an empty local database, the site shows owner setup. The owner enters business name, name, email, and a password directly in the browser. Never ask for that password in chat, read it from the page, copy it to a prompt, or save it in the plugin.
3. On a provisioned workspace, the site shows sign-in. Owners can invite team members from **Team & access**; the local site displays a one-use setup link for private sharing. Lost owner access can be recovered locally with the documented console recovery command until email delivery exists.
4. Verify that the workspace opens after setup. The current MCP bridge still uses the legacy local-account file and is not yet paired with browser-created accounts. State this limitation plainly. Do not claim the Codex tools are connected until a live tool call succeeds.

The current implementation is local-only. Installing the plugin does not install PHP, MySQL, Vite, or the optional desktop alarm helper by itself. Do not deploy it or connect external messaging accounts during this setup.
