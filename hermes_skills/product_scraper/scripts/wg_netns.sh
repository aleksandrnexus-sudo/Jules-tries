#!/usr/bin/env bash
#
# wg_netns.sh — маршрутизация product_scraper через WireGuard (ваш RU residential IP)
# в ИЗОЛИРОВАННОМ network namespace.
#
# Зачем namespace, а не `wg-quick up`:
#   В вашем конфиге AllowedIPs = 0.0.0.0/0 (full-tunnel). Системный `wg-quick up`
#   увёл бы ВЕСЬ трафик хоста, включая управляющий канал агента Hermes — агент
#   мог бы отвалиться. Здесь тоннель живёт в отдельном netns, и через ваш RU-IP
#   идёт только процесс скрапера; хост/агент не затронуты.
#
# Использование (нужен root):
#   sudo WG_CONF=/path/to/hermes-ru.conf ./wg_netns.sh up
#   sudo ./wg_netns.sh exec python /opt/hermes/run_scrape.py    # запуск в тоннеле
#   sudo ./wg_netns.sh status
#   sudo ./wg_netns.sh down
#
# Переменные окружения (со значениями по умолчанию из вашего роутера):
#   WG_CONF     путь к hermes-ru.conf (по умолчанию рядом: ../wireguard/hermes-ru.conf)
#   WG_NS       имя namespace        (hermes_wg)
#   WG_IF       имя интерфейса       (wg-ru)
#   WG_ADDRESS  адрес клиента        (10.0.0.7/32)
#   WG_DNS      DNS внутри тоннеля   (10.0.0.1)
#   WG_MTU      MTU                  (1200)
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

WG_NS="${WG_NS:-hermes_wg}"
WG_IF="${WG_IF:-wg-ru}"
WG_CONF="${WG_CONF:-${SCRIPT_DIR}/../wireguard/hermes-ru.conf}"

# Читаем параметр из [Interface] конфига (первое вхождение KEY = VALUE).
conf_value() {
  [[ -f "${WG_CONF}" ]] || return 0
  sed -nE "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*(.+)$/\1/p" "${WG_CONF}" | head -n1
}

# Значения берём из конфига, с возможностью переопределить через env.
WG_ADDRESS="${WG_ADDRESS:-$(conf_value Address)}"
WG_DNS="${WG_DNS:-$(conf_value DNS)}"
WG_MTU="${WG_MTU:-$(conf_value MTU)}"
WG_ADDRESS="${WG_ADDRESS:-10.0.0.7/32}"
WG_DNS="${WG_DNS:-10.0.0.1}"
WG_MTU="${WG_MTU:-1200}"

require_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    echo "Нужны root-права: запускайте через sudo" >&2
    exit 1
  fi
}

up() {
  require_root
  if [[ ! -f "${WG_CONF}" ]]; then
    echo "Не найден конфиг WireGuard: ${WG_CONF}" >&2
    echo "Скопируйте wireguard/hermes-ru.conf.example -> hermes-ru.conf и впишите ключи." >&2
    exit 1
  fi

  # 1. Создаём network namespace и поднимаем в нём loopback.
  ip netns add "${WG_NS}"
  ip netns exec "${WG_NS}" ip link set lo up

  # 2. Создаём wg-интерфейс в корневом netns и переносим внутрь namespace
  #    (так wg-сокет создаётся в root-netns и может достучаться до Endpoint,
  #    а сам интерфейс живёт в изолированном namespace).
  ip link add "${WG_IF}" type wireguard
  ip link set "${WG_IF}" netns "${WG_NS}"

  # 3. Грузим ключи/peer из конфига. `wg-quick strip` убирает Address/DNS/MTU
  #    (их применяем вручную ниже), оставляя только то, что понимает `wg setconf`.
  ip netns exec "${WG_NS}" wg setconf "${WG_IF}" <(wg-quick strip "${WG_CONF}")

  # 4. Адрес, MTU, поднятие линка, дефолтный маршрут через тоннель.
  ip netns exec "${WG_NS}" ip address add "${WG_ADDRESS}" dev "${WG_IF}"
  ip netns exec "${WG_NS}" ip link set "${WG_IF}" mtu "${WG_MTU}" up
  ip netns exec "${WG_NS}" ip route add default dev "${WG_IF}"

  # 5. DNS внутри namespace (ip netns exec автоматически берёт /etc/netns/<ns>/resolv.conf).
  #    WG_DNS может содержать несколько серверов через запятую/пробел
  #    (напр. "1.1.1.1, 1.0.0.1") — пишем КАЖДЫЙ отдельной строкой nameserver,
  #    иначе resolver получит невалидную запись "nameserver 1.1.1.1, 1.0.0.1".
  mkdir -p "/etc/netns/${WG_NS}"
  : > "/etc/netns/${WG_NS}/resolv.conf"
  local dns
  for dns in ${WG_DNS//,/ }; do
    [[ -n "${dns}" ]] && echo "nameserver ${dns}" >> "/etc/netns/${WG_NS}/resolv.conf"
  done

  echo "WireGuard поднят в namespace '${WG_NS}' (интерфейс ${WG_IF})."
  echo "Проверка: sudo ./wg_netns.sh exec curl -s https://ifconfig.co/json"
}

down() {
  require_root
  ip netns exec "${WG_NS}" ip link del "${WG_IF}" 2>/dev/null || true
  ip netns del "${WG_NS}" 2>/dev/null || true
  rm -f "/etc/netns/${WG_NS}/resolv.conf"
  rmdir "/etc/netns/${WG_NS}" 2>/dev/null || true
  echo "Namespace '${WG_NS}' и интерфейс ${WG_IF} удалены."
}

status() {
  require_root
  if ! ip netns list | grep -qw "${WG_NS}"; then
    echo "Namespace '${WG_NS}' не поднят."
    exit 0
  fi
  echo "== wg =="
  ip netns exec "${WG_NS}" wg show "${WG_IF}" || true
  echo "== routes =="
  ip netns exec "${WG_NS}" ip route
}

run_in_ns() {
  require_root
  if [[ "$#" -eq 0 ]]; then
    echo "Укажите команду: ./wg_netns.sh exec <команда>" >&2
    exit 1
  fi
  exec ip netns exec "${WG_NS}" "$@"
}

cmd="${1:-}"
shift || true
case "${cmd}" in
  up) up ;;
  down) down ;;
  status) status ;;
  exec) run_in_ns "$@" ;;
  *)
    echo "Использование: $0 {up|down|status|exec <команда>}" >&2
    exit 1
    ;;
esac
