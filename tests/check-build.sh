#!/bin/bash
#
# Checks build_plugin.sh.
#
# The first part runs the script in a scratch copy of the plugin, with example.test env files and
# an `npm` stub that writes a one-line bundle naming the dashboard it was built for, so it never
# touches the real env files or build/: argument and env-file failures leave no archive, each
# archive's bundle and includes/config.php come from its own env file and nothing else differs,
# a missing .env.dev skips the development archive.
#
# The second part checks the real archives in build/, so run ./build_plugin.sh first: they
# differ only in the bundle and includes/config.php, each configuration holds its env file's
# addresses, and each bundle names its own dashboard and no host of the other environment.
#
# Run: bash tests/check-build.sh

set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SCRATCH="$(mktemp -d)"
trap 'rm -rf "$SCRATCH"' EXIT

passes=0
failures=0

pass() {
  passes=$((passes + 1))
  echo "PASS  $1"
}

fail() {
  failures=$((failures + 1))
  echo "FAIL  $1"
  [ -n "${2:-}" ] && echo "      $2"
}

# ---------------------------------------------------------------------
# Scratch copy
# ---------------------------------------------------------------------

SANDBOX="$SCRATCH/plugin"
mkdir -p "$SANDBOX/app/source" "$SCRATCH/bin"
cp "$ROOT/build_plugin.sh" "$ROOT/pixelflow.php" "$ROOT/uninstall.php" "$ROOT/readme.txt" "$ROOT/README.md" "$SANDBOX/"
cp -r "$ROOT/includes" "$SANDBOX/"
rm -f "$SANDBOX/includes/config.php"

cat > "$SCRATCH/bin/npm" <<'EOF'
#!/bin/bash
# Stands in for `npm run build`: writes the bundle Vite would write to ../dist, with the
# dashboard address Vite would inline.
mkdir -p ../dist
echo "console.log('${VITE_UI_BASE_URL:-}');" > ../dist/index.js
echo '.pf{}' > ../dist/style.css
EOF
chmod +x "$SCRATCH/bin/npm"

write_env() {
  local file="$1" name="$2"
  cat > "$SANDBOX/app/source/$file" <<EOF
# $name addresses
VITE_API_BASE_URL=https://api.$name.example.test
VITE_UI_BASE_URL="https://dashboard.$name.example.test"
VITE_CDN_URL='https://cdn.$name.example.test/pfm.js'
EOF
}

reset_sandbox() {
  rm -rf "$SANDBOX/build" "$SANDBOX/app/dist" "$SANDBOX/includes/config.php"
  rm -f "$SANDBOX/app/source/.env"*
}

build() {
  (cd "$SANDBOX" && PATH="$SCRATCH/bin:$PATH" ./build_plugin.sh "$@" > "$SCRATCH/out.txt" 2>&1)
}

no_archive() {
  [ ! -e "$SANDBOX/build/pixelflow.zip" ] && [ ! -e "$SANDBOX/build/pixelflow_dev.zip" ]
}

unpack() {
  local zip="$1" dir="$2"
  rm -rf "$dir"
  mkdir -p "$dir"
  unzip -q "$zip" -d "$dir"
}

# Lists the files that differ between two unpacked archives, one per line.
differing_files() {
  diff -rq "$1" "$2" | sed -E 's/^Files (.*) and .* differ$/\1/; s/^Only in (.*): (.*)$/\1\/\2 (only one side)/'
}

# ---------------------------------------------------------------------
# Failures leave no archive
# ---------------------------------------------------------------------

for arg in dev prod anything; do
  reset_sandbox
  write_env .env prod
  write_env .env.dev dev
  if build "$arg"; then
    fail "An argument ($arg) is rejected" "the build succeeded"
  elif ! grep -q 'Usage: ./build_plugin.sh' "$SCRATCH/out.txt"; then
    fail "An argument ($arg) is rejected" "no usage message: $(cat "$SCRATCH/out.txt")"
  elif ! no_archive; then
    fail "An argument ($arg) is rejected" "an archive was built"
  else
    pass "An argument ($arg) is rejected with a usage message and no archive"
  fi
done

reset_sandbox
write_env .env.dev dev
if build; then
  fail "A missing .env fails the build" "the build succeeded"
elif ! grep -q 'app/source/.env is missing' "$SCRATCH/out.txt" || ! no_archive; then
  fail "A missing .env fails the build" "$(cat "$SCRATCH/out.txt")"
else
  pass "A missing .env fails the build, names the file and builds no archive"
fi

for file in .env .env.dev; do
  for key in VITE_API_BASE_URL VITE_UI_BASE_URL VITE_CDN_URL; do
    for broken in empty missing; do
      reset_sandbox
      write_env .env prod
      write_env .env.dev dev
      if [ "$broken" = empty ]; then
        sed -i.bak -E "s/^$key=.*/$key=/" "$SANDBOX/app/source/$file"
      else
        sed -i.bak -E "/^$key=/d" "$SANDBOX/app/source/$file"
      fi
      rm -f "$SANDBOX/app/source/$file.bak"
      [ "$broken" = empty ] && article="An" || article="A"
      label="$article $broken $key in $file fails the build"
      if build; then
        fail "$label" "the build succeeded"
      elif ! grep -q "app/source/$file: $key is missing or empty" "$SCRATCH/out.txt" || ! no_archive; then
        fail "$label" "$(cat "$SCRATCH/out.txt")"
      else
        pass "$label, names the file and the key, and builds no archive"
      fi
    done
  done
done

reset_sandbox
write_env .env prod
write_env .env.dev dev
sed -i.bak -E "s#^VITE_API_BASE_URL=.*#VITE_API_BASE_URL=https://api.example.test/'.die().'#" "$SANDBOX/app/source/.env"
rm -f "$SANDBOX/app/source/.env.bak"
if build; then
  fail "A value with a quote fails the build" "the build succeeded"
elif ! no_archive; then
  fail "A value with a quote fails the build" "an archive was built"
else
  pass "A value with a quote fails the build and builds no archive"
fi

reset_sandbox
write_env .env prod
write_env .env.dev dev
echo 'not an assignment' >> "$SANDBOX/app/source/.env.dev"
if build || ! no_archive; then
  fail "A line that is not KEY=VALUE fails the build"
else
  pass "A line that is not KEY=VALUE fails the build"
fi

# ---------------------------------------------------------------------
# Both env files
# ---------------------------------------------------------------------

reset_sandbox
write_env .env prod
write_env .env.dev dev
if ! build; then
  fail "The build succeeds with both env files" "$(cat "$SCRATCH/out.txt")"
else
  if [ -f "$SANDBOX/build/pixelflow.zip" ] && [ -f "$SANDBOX/build/pixelflow_dev.zip" ]; then
    pass "Both archives are built"
  else
    fail "Both archives are built" "$(ls "$SANDBOX/build")"
  fi

  unpack "$SANDBOX/build/pixelflow.zip" "$SCRATCH/prod"
  unpack "$SANDBOX/build/pixelflow_dev.zip" "$SCRATCH/dev"
  diffs="$(differing_files "$SCRATCH/prod/pixelflow" "$SCRATCH/dev/pixelflow" | sed "s#$SCRATCH/prod/pixelflow/##")"
  if [ "$diffs" = "$(printf 'app/dist/index.js\nincludes/config.php')" ]; then
    pass "The archives unpack to pixelflow/ and differ only in the bundle and includes/config.php"
  else
    fail "The archives differ only in the bundle and includes/config.php" "$diffs"
  fi

  if grep -q "https://dashboard.prod.example.test" "$SCRATCH/prod/pixelflow/app/dist/index.js" \
    && grep -q "https://dashboard.dev.example.test" "$SCRATCH/dev/pixelflow/app/dist/index.js"; then
    pass "Each archive's bundle is built with its own env file"
  else
    fail "Each archive's bundle is built with its own env file" "$(cat "$SCRATCH"/{prod,dev}/pixelflow/app/dist/index.js)"
  fi

  if grep -q "https://dashboard.dev.example.test" "$SANDBOX/app/dist/index.js"; then
    pass "The working tree keeps the development bundle"
  else
    fail "The working tree keeps the development bundle" "$(cat "$SANDBOX/app/dist/index.js")"
  fi

  for env in prod dev; do
    config="$SCRATCH/$env/pixelflow/includes/config.php"
    if grep -q "'api_url' => 'https://api.$env.example.test'," "$config" \
      && grep -q "'ui_url'  => 'https://dashboard.$env.example.test'," "$config" \
      && grep -q "'cdn_url' => 'https://cdn.$env.example.test/pfm.js'," "$config" \
      && php -l "$config" > /dev/null; then
      pass "The $env archive's configuration holds its env file's addresses, quotes stripped"
    else
      fail "The $env archive's configuration holds its env file's addresses" "$(cat "$config")"
    fi
  done

  if grep -q "https://api.dev.example.test" "$SANDBOX/includes/config.php" 2> /dev/null; then
    pass "The working tree gets the development configuration"
  else
    fail "The working tree gets the development configuration" "$(cat "$SANDBOX/includes/config.php" 2>&1)"
  fi

  # A rebuild replaces both archives; the working tree's configuration never reaches an archive.
  write_env .env.dev dev2
  if build && unpack "$SANDBOX/build/pixelflow_dev.zip" "$SCRATCH/dev2" && unpack "$SANDBOX/build/pixelflow.zip" "$SCRATCH/prod2" \
    && grep -q "api.dev2.example.test" "$SCRATCH/dev2/pixelflow/includes/config.php" \
    && grep -q "api.prod.example.test" "$SCRATCH/prod2/pixelflow/includes/config.php"; then
    pass "A rebuild replaces both archives, and the working tree's configuration reaches neither"
  else
    fail "A rebuild replaces both archives" "$(cat "$SCRATCH/out.txt")"
  fi
fi

# ---------------------------------------------------------------------
# Without .env.dev, and with a leftover env file
# ---------------------------------------------------------------------

# Starts from the archives of the build above, so a stale development archive exists.
rm -f "$SANDBOX/app/source/.env.dev"
write_env .env.local dev
if ! build; then
  fail "The build succeeds without .env.dev" "$(cat "$SCRATCH/out.txt")"
else
  if [ -f "$SANDBOX/build/pixelflow.zip" ] && [ ! -e "$SANDBOX/build/pixelflow_dev.zip" ]; then
    pass "Without .env.dev only the production archive remains, the stale development one is gone"
  else
    fail "Without .env.dev only the production archive remains" "$(ls "$SANDBOX/build")"
  fi
  if grep -q 'development archive will be skipped' "$SCRATCH/out.txt" \
    && grep -q 'PRODUCTION addresses' "$SCRATCH/out.txt" \
    && grep -q "https://api.prod.example.test" "$SANDBOX/includes/config.php"; then
    pass "Without .env.dev the working tree gets the production configuration, with warnings"
  else
    fail "Without .env.dev the working tree gets the production configuration" "$(cat "$SCRATCH/out.txt")"
  fi
  if grep -q '.env.local is no longer read by the build' "$SCRATCH/out.txt"; then
    pass "A leftover .env.local is reported"
  else
    fail "A leftover .env.local is reported" "$(cat "$SCRATCH/out.txt")"
  fi
fi

# ---------------------------------------------------------------------
# The real archives
# ---------------------------------------------------------------------

env_value() {
  sed -n -E "s/^$2=[\"']?([^\"']*)[\"']?[[:space:]]*$/\1/p" "$1" | tail -n 1
}

host_of() {
  echo "$1" | sed -E 's#^https?://([^/]+).*#\1#'
}

if [ ! -f "$ROOT/build/pixelflow.zip" ]; then
  fail "The real archives exist" "build/pixelflow.zip is missing: run ./build_plugin.sh first"
else
  unpack "$ROOT/build/pixelflow.zip" "$SCRATCH/real-prod"
  envs="prod"
  if [ -f "$ROOT/app/source/.env.dev" ]; then
    envs="prod dev"
    if [ -f "$ROOT/build/pixelflow_dev.zip" ]; then
      unpack "$ROOT/build/pixelflow_dev.zip" "$SCRATCH/real-dev"
      diffs="$(differing_files "$SCRATCH/real-prod/pixelflow" "$SCRATCH/real-dev/pixelflow" | sed "s#$SCRATCH/real-prod/pixelflow/##")"
      if [ "$diffs" = "$(printf 'app/dist/index.js\nincludes/config.php')" ]; then
        pass "The real archives differ only in the bundle and includes/config.php"
      else
        fail "The real archives differ only in the bundle and includes/config.php" "$diffs"
      fi
    else
      fail "The real development archive exists" "app/source/.env.dev exists but build/pixelflow_dev.zip does not"
    fi
  fi

  for env in $envs; do
    [ "$env" = prod ] && env_file="$ROOT/app/source/.env" || env_file="$ROOT/app/source/.env.dev"
    config="$SCRATCH/real-$env/pixelflow/includes/config.php"
    [ -f "$config" ] || continue
    ok=1
    for pair in api_url:VITE_API_BASE_URL ui_url:VITE_UI_BASE_URL cdn_url:VITE_CDN_URL; do
      value="$(env_value "$env_file" "${pair#*:}")"
      grep -qF "'${pair%%:*}' => '${value%/}'," "$config" || grep -qF "'${pair%%:*}'  => '${value%/}'," "$config" || ok=0
    done
    if [ "$ok" = 1 ]; then
      pass "The real $env archive's configuration holds $(basename "$env_file")'s addresses"
    else
      fail "The real $env archive's configuration holds $(basename "$env_file")'s addresses" "$(cat "$config")"
    fi

  done

  # Each bundle carries the addresses its environment's libraries read at build time.
  if [ -d "$SCRATCH/real-dev" ]; then
    for own in prod dev; do
      [ "$own" = prod ] && other=dev || other=prod
      [ "$own" = prod ] && own_file="$ROOT/app/source/.env" || own_file="$ROOT/app/source/.env.dev"
      [ "$other" = prod ] && other_file="$ROOT/app/source/.env" || other_file="$ROOT/app/source/.env.dev"
      bundle="$SCRATCH/real-$own/pixelflow/app/dist"

      own_dashboard="$(host_of "$(env_value "$own_file" VITE_UI_BASE_URL)")"
      if grep -rqF "$own_dashboard" "$bundle"; then
        pass "The $own bundle names its own dashboard"
      else
        fail "The $own bundle names its own dashboard" "$own_dashboard not found"
      fi

      found=""
      for key in VITE_API_BASE_URL VITE_UI_BASE_URL VITE_CDN_URL; do
        host="$(host_of "$(env_value "$other_file" "$key")")"
        if [ -n "$host" ] && [ "$host" != "$(host_of "$(env_value "$own_file" "$key")")" ] && grep -rqF "$host" "$bundle"; then
          found="$found $host"
        fi
      done
      if [ -z "$found" ]; then
        pass "The $own bundle contains no $other host"
      else
        fail "The $own bundle contains no $other host" "found:$found"
      fi
    done
  fi
fi

echo ""
echo "$passes passed, $failures failed"
[ "$failures" -eq 0 ]
