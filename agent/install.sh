#!/usr/bin/env bash
#
# Krishna Printer — location agent installer.
#
# Installs the agent as a systemd service on a Debian/Ubuntu/Raspberry Pi OS
# machine. Run it on the shop's always-on Linux box, after adding the printer
# to CUPS.
#
#   sudo ./install.sh --server https://print.example.com --token kpms_xxxxx
#
set -euo pipefail

SERVER=""
TOKEN=""
POLL_INTERVAL=10
SERVICE_USER="kpms-agent"
INSTALL_DIR="/opt/kpms-agent"
CONFIG_DIR="/etc/kpms-agent"
WORK_DIR="/var/lib/kpms-agent"
SKIP_DEPS=0
NO_VERIFY_TLS=0

red()   { printf '\033[0;31m%s\033[0m\n' "$*"; }
green() { printf '\033[0;32m%s\033[0m\n' "$*"; }
blue()  { printf '\033[0;34m%s\033[0m\n' "$*"; }
warn()  { printf '\033[0;33m%s\033[0m\n' "$*"; }

usage() {
  cat <<'USAGE'
Usage: sudo ./install.sh --server URL --token TOKEN [options]

Required:
  --server URL         The cloud application address (https://…)
  --token  TOKEN       The agent token from Admin → Print agents

Options:
  --poll-interval N    Seconds between polls (default 10)
  --skip-deps          Do not install apt packages
  --no-verify-tls      Disable TLS verification (private CA only)
  --help               Show this message
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --server)        SERVER="$2"; shift 2 ;;
    --token)         TOKEN="$2"; shift 2 ;;
    --poll-interval) POLL_INTERVAL="$2"; shift 2 ;;
    --skip-deps)     SKIP_DEPS=1; shift ;;
    --no-verify-tls) NO_VERIFY_TLS=1; shift ;;
    --help|-h)       usage; exit 0 ;;
    *) red "Unknown option: $1"; usage; exit 1 ;;
  esac
done

if [[ $EUID -ne 0 ]]; then
  red "This installer must run as root (use sudo)."
  exit 1
fi

if [[ -z "$SERVER" || -z "$TOKEN" ]]; then
  red "Both --server and --token are required."
  usage
  exit 1
fi

if [[ ! "$SERVER" =~ ^https:// ]]; then
  warn "The server URL is not HTTPS."
  warn "The token and every customer document would travel in clear text."
  read -r -p "Continue anyway? [y/N] " reply
  [[ "$reply" =~ ^[Yy]$ ]] || exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

blue "==> Installing the Krishna Printer agent"

# --- Dependencies ----------------------------------------------------------
if [[ $SKIP_DEPS -eq 0 ]]; then
  blue "==> Installing packages"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq \
    python3 \
    cups \
    cups-client \
    poppler-utils \
    libreoffice-core \
    libreoffice-writer \
    libreoffice-calc \
    libreoffice-impress \
    fonts-liberation
  green "    packages installed"
fi

if ! command -v lpstat >/dev/null 2>&1; then
  red "CUPS client tools are missing. Install cups-client and run again."
  exit 1
fi

# --- Service account -------------------------------------------------------
if ! id "$SERVICE_USER" >/dev/null 2>&1; then
  blue "==> Creating the service account"
  # A system account with no login shell: the agent needs to print, nothing else.
  useradd --system --no-create-home --shell /usr/sbin/nologin "$SERVICE_USER"
  green "    created $SERVICE_USER"
fi

# It must be able to submit jobs to CUPS.
if getent group lpadmin >/dev/null 2>&1; then
  usermod -aG lpadmin "$SERVICE_USER"
fi
if getent group lp >/dev/null 2>&1; then
  usermod -aG lp "$SERVICE_USER"
fi

# --- Files -----------------------------------------------------------------
blue "==> Installing files"
mkdir -p "$INSTALL_DIR" "$CONFIG_DIR" "$WORK_DIR"

install -m 0755 "$SCRIPT_DIR/kpms-agent.py" "$INSTALL_DIR/kpms-agent.py"

# The config holds the token, so it is readable only by the service account.
cat > "$CONFIG_DIR/config.json" <<EOF
{
  "server": "$SERVER",
  "token": "$TOKEN",
  "poll_interval": $POLL_INTERVAL,
  "work_dir": "$WORK_DIR",
  "verify_tls": $([ $NO_VERIFY_TLS -eq 1 ] && echo false || echo true)
}
EOF

chown -R "$SERVICE_USER:$SERVICE_USER" "$CONFIG_DIR" "$WORK_DIR"
chmod 0700 "$CONFIG_DIR"
chmod 0600 "$CONFIG_DIR/config.json"
green "    files installed"

# --- Connection test -------------------------------------------------------
blue "==> Testing the connection"
if ! sudo -u "$SERVICE_USER" python3 "$INSTALL_DIR/kpms-agent.py" \
     --config "$CONFIG_DIR/config.json" --test; then
  red "The connection test failed. The service was not started."
  red "Check the server URL and the token, then run:"
  red "  sudo -u $SERVICE_USER python3 $INSTALL_DIR/kpms-agent.py --config $CONFIG_DIR/config.json --test"
  exit 1
fi
green "    connection OK"

# --- systemd ---------------------------------------------------------------
blue "==> Installing the systemd service"
cat > /etc/systemd/system/kpms-agent.service <<EOF
[Unit]
Description=Krishna Printer location agent
Documentation=https://github.com/akshaykananidwk/KRISHNA-printer
After=network-online.target cups.service
Wants=network-online.target
Requires=cups.service

[Service]
Type=simple
User=$SERVICE_USER
Group=$SERVICE_USER
ExecStart=/usr/bin/python3 $INSTALL_DIR/kpms-agent.py --config $CONFIG_DIR/config.json
Restart=always
RestartSec=10
WorkingDirectory=$WORK_DIR

# The agent needs the network, CUPS and its own scratch directory. Nothing else.
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=$WORK_DIR
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
RestrictNamespaces=true
LockPersonality=true
MemoryDenyWriteExecute=true
RestrictRealtime=true
SystemCallArchitectures=native

StandardOutput=journal
StandardError=journal
SyslogIdentifier=kpms-agent

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable kpms-agent.service
systemctl restart kpms-agent.service

sleep 3

if systemctl is-active --quiet kpms-agent.service; then
  green ""
  green "==> The agent is installed and running."
  green ""
  echo "  Status:  sudo systemctl status kpms-agent"
  echo "  Logs:    sudo journalctl -u kpms-agent -f"
  echo "  Test:    sudo -u $SERVICE_USER python3 $INSTALL_DIR/kpms-agent.py --config $CONFIG_DIR/config.json --test"
  echo ""
  echo "Next: attach a printer to this agent in Admin → Printers, then run a"
  echo "capability probe. No print option is offered to customers until it has"
  echo "been verified on the actual printer."
else
  red "The service failed to start. Check the logs:"
  red "  sudo journalctl -u kpms-agent -n 50 --no-pager"
  exit 1
fi
