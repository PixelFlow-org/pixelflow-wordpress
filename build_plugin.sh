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
npm run build
cd "$SCRIPT_DIR"

# Create build directory if it doesn't exist
BUILD_DIR="$SCRIPT_DIR/build"
mkdir -p "$BUILD_DIR"

# Plugin name and version
PLUGIN_NAME="pixelflow"
ZIP_NAME="${PLUGIN_NAME}.zip"

echo "📁 Creating deployment package: $ZIP_NAME"

# Determine which env file to include
ENV_FILE=""
if [ "$BUILD_MODE" = "dev" ]; then
  if [ -f "app/source/.env.local" ]; then
    ENV_FILE="app/source/.env.local"
    echo "📋 Including .env.local for dev build"
  else
    echo "⚠️  Warning: .env.local not found, continuing without it"
  fi
else
  if [ -f "app/source/.env.production" ]; then
    ENV_FILE="app/source/.env.production"
    echo "📋 Including .env.production for prod build"
  else
    echo "⚠️  Warning: .env.production not found, continuing without it"
  fi
fi

# Create zip with production files
if [ -n "$ENV_FILE" ]; then
  # Include env file in the zip
  zip -r "$BUILD_DIR/$ZIP_NAME" \
    app/dist/ \
    includes/ \
    admin/ \
    pixelflow.php \
    uninstall.php \
    README.md \
    readme.txt \
    "$ENV_FILE" \
    -x "*.DS_Store" \
    -x "*__MACOSX*" \
    -x "*.git*"
else
  # No env file to include
  zip -r "$BUILD_DIR/$ZIP_NAME" \
    app/dist/ \
    includes/ \
    admin/ \
    pixelflow.php \
    README.md \
    readme.txt \
    -x "*.DS_Store" \
    -x "*__MACOSX*" \
    -x "*.git*"
fi

echo "✅ Build complete!"
echo "📦 Package location: build/$ZIP_NAME"
echo "🏷️  Build mode: $BUILD_MODE"
echo ""
echo "Files included:"
echo "  ✅ app/dist/"
echo "  ✅ includes/"
echo "  ✅ admin/"
echo "  ✅ pixelflow.php"
echo "  ✅ README.md"
echo "  ✅ readme.txt"
if [ -n "$ENV_FILE" ]; then
  echo "  ✅ $ENV_FILE"
fi
echo ""
echo "Files excluded:"
echo "  ❌ app/source/"
echo ""
echo "🎉 Ready for deployment!"

