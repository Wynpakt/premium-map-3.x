#!/bin/sh
# Download one Photon dump. A single long GET to the GraphHopper mirror
# collapses to about 1 MB/s. Eight short Range requests stay fast.
# Sourced by fetch-index.sh and by the Compose entrypoint.

RANGE_COUNT=8

# Print "start end" lines (inclusive) that cover length exactly.
range_plan() {
  length=$1
  count=$2
  chunk=$(((length + count - 1) / count))
  i=0
  while [ "$i" -lt "$count" ]; do
    start=$((i * chunk))
    if [ "$start" -ge "$length" ]; then
      break
    fi
    end=$((start + chunk - 1))
    if [ "$end" -ge "$length" ]; then
      end=$((length - 1))
    fi
    printf '%s %s\n' "$start" "$end"
    i=$((i + 1))
  done
}

download_part() {
  part_url=$1
  part_start=$2
  part_end=$3
  part_out=$4
  part_ua=$5
  code=$(curl -sS -L --retry 5 --retry-delay 5 -A "$part_ua" \
    --range "${part_start}-${part_end}" \
    -o "$part_out" -w "%{http_code}" "$part_url" || true)
  if [ "$code" != "206" ]; then
    rm -f "$part_out"
    return 1
  fi
}

# download_archive <url> <dest-file>
download_archive() {
  url=$1
  dest=$2
  ua=${UA:-premium-landkarte-photon}
  length=$(curl -fsSL -A "$ua" -I -L "$url" \
    | awk 'tolower($1)=="content-length:" {print $2}' \
    | tr -d '\r' | tail -1)
  case "$length" in
    ''|*[!0-9]*) length="" ;;
  esac

  probe=$(curl -sS -L -A "$ua" --range 0-0 --max-filesize 64 \
    -o /dev/null -w "%{http_code}" "$url" || true)
  if [ -z "$length" ] || [ "$length" -lt "$RANGE_COUNT" ] || [ "$probe" != "206" ]; then
    curl -fL --retry 5 --retry-delay 5 -A "$ua" -o "$dest" "$url"
    return
  fi

  part_dir=$(mktemp -d "${dest}.parts.XXXXXX")
  range_plan "$length" "$RANGE_COUNT" > "${part_dir}/plan"
  : > "${part_dir}/pids"
  while read -r start end; do
    download_part "$url" "$start" "$end" "${part_dir}/part.${start}" "$ua" </dev/null &
    echo $! >> "${part_dir}/pids"
  done < "${part_dir}/plan"
  failed=0
  while read -r pid; do
    wait "$pid" || failed=1
  done < "${part_dir}/pids"
  if [ "$failed" -ne 0 ]; then
    rm -rf "$part_dir"
    echo "Range download failed for ${url}, using one connection" >&2
    curl -fL --retry 5 --retry-delay 5 -A "$ua" -o "$dest" "$url"
    return
  fi

  : > "$dest"
  while read -r start end; do
    cat "${part_dir}/part.${start}" >> "$dest"
  done < "${part_dir}/plan"
  rm -rf "$part_dir"

  size=$(wc -c < "$dest" | tr -d ' ')
  if [ "$size" != "$length" ]; then
    echo "Range download wrote ${size} bytes, expected ${length}, for ${url}; using one connection" >&2
    curl -fL --retry 5 --retry-delay 5 -A "$ua" -o "$dest" "$url"
  fi
}
