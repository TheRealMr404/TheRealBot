# Mirza Control

Mirza Control is the host-level management panel for isolated customer bots.
It is separate from each bot's own admin panel.

## Security model

- The web container does not receive the Docker socket.
- A root-owned Unix-socket agent exposes only a fixed allowlist of operations.
- Telegram bot tokens are transferred once to the agent and are not stored in
  the panel database.
- Every bot keeps its own application and MySQL containers, volumes, network,
  domain, backups, and update lifecycle.
- Expired bots are stopped by the host agent even when nobody has the web panel
  open.
- Login attempts are rate limited, state-changing requests require CSRF tokens,
  and the panel sends restrictive browser security headers.

## Installation

The panel is installed outside all bot directories. Its runtime root is
`/opt/mirza-control-panel`, while customer bots remain under
`/opt/mirza/instances`.

From the standalone package, run:

```bash
sudo bash install.sh --domain manager.example.com
```

The standalone package contains `mirza-manager.sh` and a separate
`bot-template/` directory, so it can initialize a fresh server without placing
the control panel inside the bot source. To use another bot template:

```bash
sudo bash install.sh --domain manager.example.com --bot-source /path/to/bot/source
```

When using the full Mirza source instead, choose **Web multi-bot control
panel** from its main installer or run `mirza panel-install`.

The installer prints the generated initial username and password once. Change
the password from the Settings page after the first login.

## Host paths

- Panel files: `/opt/mirza-control-panel`
- Panel database: `/opt/mirza-control-panel/data/panel.sqlite`
- Agent configuration: `/etc/mirza-panel-agent.json`
- Agent queue: `/var/lib/mirza-panel-agent/jobs`
- Agent log: `/var/log/mirza-panel-agent.log`
- Agent socket: `/run/mirza-panel/control.sock`

Do not expose the panel application port directly. The installer binds it to
localhost and publishes only the configured HTTPS domain through the existing
Mirza gateway.
