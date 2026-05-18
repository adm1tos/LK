#!/bin/bash
set -euo pipefail

ACTION="${1:-}"
TARGET_USER="${2:-}"
KEY_VALUE="${3:-}"

list_users() {
    echo root
    awk -F: '$3 >= 1000 && $3 < 65534 {print $1}' /etc/passwd
}

ensure_user_exists() {
    id "$TARGET_USER" >/dev/null 2>&1 || {
        echo "User not found: $TARGET_USER" >&2
        exit 1
    }
}

user_home() {
    if [ "$TARGET_USER" = "root" ]; then
        echo /root
    else
        getent passwd "$TARGET_USER" | cut -d: -f6
    fi
}

add_key() {
    ensure_user_exists

    HOME_DIR="$(user_home)"
    SSH_DIR="$HOME_DIR/.ssh"
    AUTH_FILE="$SSH_DIR/authorized_keys"

    mkdir -p "$SSH_DIR"
    chmod 700 "$SSH_DIR"

    touch "$AUTH_FILE"

    grep -Fxq -- "$KEY_VALUE" "$AUTH_FILE" || printf '%s\n' "$KEY_VALUE" >> "$AUTH_FILE"

    chmod 600 "$AUTH_FILE"
    chown -R "$TARGET_USER:$TARGET_USER" "$SSH_DIR" 2>/dev/null || true
}

remove_key() {
    ensure_user_exists

    HOME_DIR="$(user_home)"
    AUTH_FILE="$HOME_DIR/.ssh/authorized_keys"

    [ -f "$AUTH_FILE" ] || exit 0

    grep -Fxv -- "$KEY_VALUE" "$AUTH_FILE" > "$AUTH_FILE.tmp" || true
    mv "$AUTH_FILE.tmp" "$AUTH_FILE"

    chmod 600 "$AUTH_FILE"
    chown "$TARGET_USER:$TARGET_USER" "$AUTH_FILE" 2>/dev/null || true
}

case "$ACTION" in
    list-users)
        list_users
        ;;

    add-key)
        add_key
        ;;

    remove-key)
        remove_key
        ;;

    *)
        echo "Usage: $0 {list-users|add-key|remove-key} [user] [key]" >&2
        exit 1
        ;;
esac
