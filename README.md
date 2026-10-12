# Fieldwork Reach sales workspace

React, PHP and MySQL sales workspace with lead intake, projects, team access, contact profiles, sales opportunities, internal follow-ups and durable agent activity reports.

- [Application source and local setup](sales-service/README.md)
- [Hosted deployment instructions](sales-service/deploy/README.md)
- [Hosted OAuth connection](sales-service/HOSTED-CONNECTION.md)
- [Hosted Codex plugin and installation](sales-workspace-hosted/README.md)
- [Local stdio plugin](sales-workspace/)

The hosted application is at https://reach.vumbualabs.com. The hosted plugin is distributed through the existing [Vumbua Labs marketplace](https://github.com/Peternyaga/timeline-curator), alongside Timeline Curator. Testers need their own authorized Fieldwork account; publication does not grant access to another workspace. External messaging, calls and calendar synchronization are not implemented.

Keep database credentials, OAuth/poller state, customer records, database backups and generated release archives outside Git. Configuration templates contain placeholders only.
