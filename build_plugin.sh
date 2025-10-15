#!/bin/bash

# PixelFlow Plugin Build Script
# Builds the frontend and creates a production-ready zip file

set -e  # Exit on error

echo "🚀 Building PixelFlow WordPress Plugin..."

# Get the plugin directory (script location)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Build frontend assets
echo "📦 Building frontend assets..."
cd app/source
npm run build
cd "$SCRIPT_DIR"

# Create build directory if it doesn't exist
BUILD_DIR="$SCRIPT_DIR/build"
mkdir -p "$BUILD_DIR"

# Plugin name and version (you can extract version from pixelflow.php if needed)
PLUGIN_NAME="pixelflow"
ZIP_NAME="${PLUGIN_NAME}.zip"

echo "📁 Creating deployment package: $ZIP_NAME"

# Create zip with only production files
zip -r "$BUILD_DIR/$ZIP_NAME" \
  app/dist/ \
  includes/ \
  admin/ \
  pixelflow.php \
  README.md \
  -x "*.DS_Store" \
  -x "*__MACOSX*" \
  -x "*.git*"

echo "✅ Build complete!"
echo "📦 Package location: build/$ZIP_NAME"
echo ""
echo "Files included:"
echo "  ✅ app/dist/"
echo "  ✅ includes/"
echo "  ✅ admin/"
echo "  ✅ pixelflow.php"
echo "  ✅ README.md"
echo ""
echo "Files excluded:"
echo "  ❌ app/source/"
echo ""
echo "🎉 Ready for deployment!"

