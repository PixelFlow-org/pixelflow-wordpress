#!/bin/bash

# PixelFlow Plugin Build Script
# Builds the frontend and creates a production-ready zip file
# Usage: ./build_plugin.sh [dev|prod]

set -e  # Exit on error

# Get build mode (default to prod)
BUILD_MODE="${1:-prod}"

if [ "$BUILD_MODE" != "dev" ] && [ "$BUILD_MODE" != "prod" ]; then
  echo "❌ Invalid build mode: $BUILD_MODE"
  echo "Usage: ./build_plugin.sh [dev|prod]"
  exit 1
fi

echo "🚀 Building PixelFlow WordPress Plugin (${BUILD_MODE} mode)..."

# Get the plugin directory (script location)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# Build frontend assets
echo "📦 Building frontend assets..."
cd app/source

# Use env file for build (Vite will load it automatically, but we verify it exists)
if [ "$BUILD_MODE" = "prod" ]; then
  if [ -f ".env.production" ]; then
    echo "📋 Using .env.production for build (will NOT be included in zip)"
    # Vite automatically loads .env.production when running 'vite build'
  else
    echo "⚠️  Warning: .env.production not found - build will use default/empty env vars"
  fi
  npm run build
else
  if [ -f ".env.development" ]; then
    echo "📋 Using .env.development for build (will NOT be included in zip)"
  elif [ -f ".env.local" ]; then
    echo "📋 Using .env.local for build (will NOT be included in zip)"
  else
    echo "⚠️  Warning: .env.development or .env.local not found - build will use default/empty env vars"
  fi
  npm run build-dev
fi

cd "$SCRIPT_DIR"

# Create build directory if it doesn't exist
BUILD_DIR="$SCRIPT_DIR/build"
mkdir -p "$BUILD_DIR"

# Plugin name and version
PLUGIN_NAME="pixelflow"
if [ "$BUILD_MODE" = "prod" ]; then
  ZIP_NAME="${PLUGIN_NAME}.zip"
else
  ZIP_NAME="${PLUGIN_NAME}_dev.zip"
fi
ZIP_PATH="$BUILD_DIR/$ZIP_NAME"

# Remove previous zip file if it exists
if [ -f "$ZIP_PATH" ]; then
  echo "🗑️  Removing previous build: $ZIP_NAME"
  rm -f "$ZIP_PATH"
fi

# Create temporary staging directory
STAGING_DIR="$BUILD_DIR/staging"
PLUGIN_STAGING_DIR="$STAGING_DIR/$PLUGIN_NAME"

# Clean up any existing staging directory
if [ -d "$STAGING_DIR" ]; then
  rm -rf "$STAGING_DIR"
fi

# Create plugin directory in staging
mkdir -p "$PLUGIN_STAGING_DIR"

echo "📁 Creating deployment package: $ZIP_NAME"
echo "📋 Copying files to staging directory..."

# Copy production files to staging (NO source directory, NO env files, NO publish script)
mkdir -p "$PLUGIN_STAGING_DIR/app"
cp -r app/dist "$PLUGIN_STAGING_DIR/app/" 2>/dev/null || true
cp -r includes "$PLUGIN_STAGING_DIR/" 2>/dev/null || true
[ -d "admin" ] && cp -r admin "$PLUGIN_STAGING_DIR/" || true
cp pixelflow.php "$PLUGIN_STAGING_DIR/"
cp uninstall.php "$PLUGIN_STAGING_DIR/"
[ -f "README.md" ] && cp README.md "$PLUGIN_STAGING_DIR/" || true
cp readme.txt "$PLUGIN_STAGING_DIR/"
[ -d "languages" ] && cp -r languages "$PLUGIN_STAGING_DIR/" || true

# Create zip from staging directory
cd "$STAGING_DIR"
zip -r "$ZIP_PATH" "$PLUGIN_NAME" \
  -x "*.DS_Store" \
  -x "*__MACOSX*" \
  -x "*.git*" \
  -x "*build_plugin.sh" \
  -x "*publish_plugin.sh" \
  -x "*/svn/*"

# Clean up staging directory
cd "$SCRIPT_DIR"
rm -rf "$STAGING_DIR"

echo "✅ Build complete!"
echo "📦 Package location: build/$ZIP_NAME"
echo "🏷️  Build mode: $BUILD_MODE"
echo ""
echo "Files included:"
echo "  ✅ app/dist/"
echo "  ✅ includes/"
[ -d "admin" ] && echo "  ✅ admin/" || echo "  ⚠️  admin/ (not found, skipped)"
echo "  ✅ pixelflow.php"
echo "  ✅ uninstall.php"
[ -f "README.md" ] && echo "  ✅ README.md" || echo "  ⚠️  README.md (not found, skipped)"
echo "  ✅ readme.txt"
[ -d "languages" ] && echo "  ✅ languages/" || echo "  ⚠️  languages/ (not found, skipped)"
echo ""
echo "Files excluded:"
echo "  ❌ app/source/ (entire directory)"
echo "  ❌ .env files (used during build only, not included)"
echo "  ❌ build_plugin.sh (build script)"
echo "  ❌ publish_plugin.sh (publish script)"
echo "  ❌ svn/ (SVN directory)"
echo ""
echo "🎉 Ready for deployment!"

