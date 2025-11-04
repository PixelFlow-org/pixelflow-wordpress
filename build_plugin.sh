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
  if [ -f ".env.local" ]; then
    echo "📋 Using .env.local for build (will NOT be included in zip)"
  else
    echo "⚠️  Warning: .env.local not found - build will use default/empty env vars"
  fi
  npm run build-dev
fi

cd "$SCRIPT_DIR"

# Create build directory if it doesn't exist
BUILD_DIR="$SCRIPT_DIR/build"
mkdir -p "$BUILD_DIR"

# Plugin name and version
PLUGIN_NAME="pixelflow"
ZIP_NAME="${PLUGIN_NAME}.zip"
ZIP_PATH="$BUILD_DIR/$ZIP_NAME"

# Remove previous zip file if it exists
if [ -f "$ZIP_PATH" ]; then
  echo "🗑️  Removing previous build: $ZIP_NAME"
  rm -f "$ZIP_PATH"
fi

echo "📁 Creating deployment package: $ZIP_NAME"

# Create zip with production files (NO source directory, NO env files)
zip -r "$ZIP_PATH" \
  app/dist/ \
  includes/ \
  admin/ \
  pixelflow.php \
  uninstall.php \
  README.md \
  readme.txt \
  languages/ \
  -x "*.DS_Store" \
  -x "*__MACOSX*" \
  -x "*.git*" \
  -x "app/source/*" \
  -x "app/source/**/*"

echo "✅ Build complete!"
echo "📦 Package location: build/$ZIP_NAME"
echo "🏷️  Build mode: $BUILD_MODE"
echo ""
echo "Files included:"
echo "  ✅ app/dist/"
echo "  ✅ includes/"
echo "  ✅ admin/"
echo "  ✅ pixelflow.php"
echo "  ✅ uninstall.php"
echo "  ✅ README.md"
echo "  ✅ readme.txt"
echo "  ✅ languages/"
echo ""
echo "Files excluded:"
echo "  ❌ app/source/ (entire directory)"
echo "  ❌ .env files (used during build only, not included)"
echo ""
echo "🎉 Ready for deployment!"

