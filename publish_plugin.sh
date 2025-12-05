#!/bin/sh

# PixelFlow Plugin SVN Publishing Script
# Publishes the plugin to WordPress.org SVN repository
# Usage: ./publish_plugin.sh [version] [svn-username]
# If version is not provided, it will be extracted from pixelflow.php

set -e  # Exit on error

# Get the plugin directory
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# Extract version from pixelflow.php if not provided
if [ -z "$1" ] || [[ "$1" =~ ^[a-zA-Z] ]]; then
  # First arg is empty or looks like a username, extract version from file
  if [ -f "pixelflow.php" ]; then
    VERSION=$(grep "^ \* Version:" pixelflow.php | head -1 | awk '{print $3}' | tr -d '\r')
    if [ -z "$VERSION" ]; then
      echo "❌ Error: Could not extract version from pixelflow.php"
      exit 1
    fi
    echo "📋 Auto-detected version: $VERSION"
    SVN_USERNAME="${1:-}"
  else
    echo "❌ Error: pixelflow.php not found"
    echo "Usage: ./publish_plugin.sh [version] [svn-username]"
    exit 1
  fi
else
  # First arg is the version
  VERSION="$1"
  SVN_USERNAME="${2:-}"
fi

# Plugin details
PLUGIN_NAME="pixelflow"
PLUGIN_SLUG="pixelflow"
SVN_DIR="$SCRIPT_DIR/svn"
SVN_URL="https://plugins.svn.wordpress.org/$PLUGIN_SLUG"
BUILD_ZIP="$SCRIPT_DIR/build/${PLUGIN_NAME}.zip"

echo "🚀 Publishing PixelFlow Plugin to WordPress.org"
echo "📦 Version: $VERSION"
[ -n "$SVN_USERNAME" ] && echo "👤 SVN Username: $SVN_USERNAME"
echo ""

# Step 1: Build the plugin
echo "📦 Step 1: Building plugin for production..."
if [ -f "build_plugin.sh" ]; then
  echo "🔨 Running: sh build_plugin.sh prod"
  sh build_plugin.sh prod
else
  echo "❌ Error: build_plugin.sh not found"
  exit 1
fi

# Check if build was successful
if [ ! -f "$BUILD_ZIP" ]; then
  echo "❌ Error: Build failed. $BUILD_ZIP not found"
  exit 1
fi

echo "✅ Build complete: $BUILD_ZIP"
echo ""

# Step 2: Setup/Update SVN
echo "📥 Step 2: Setting up SVN repository..."

# Check if SVN directory exists and is a valid working copy
if [ -d "$SVN_DIR" ]; then
  if [ -d "$SVN_DIR/.svn" ]; then
    echo "📥 SVN directory exists. Updating..."
    cd "$SVN_DIR"
    svn up
  else
    echo "⚠️  SVN directory exists but is not a valid working copy. Removing and re-checking out..."
    rm -rf "$SVN_DIR"
    mkdir -p "$SVN_DIR"
    
    if [ -n "$SVN_USERNAME" ]; then
      svn co "$SVN_URL" "$SVN_DIR" --depth immediates --username "$SVN_USERNAME"
    else
      svn co "$SVN_URL" "$SVN_DIR" --depth immediates
    fi
    
    cd "$SVN_DIR"
    svn up trunk
    svn up assets
    svn up tags --depth empty
  fi
else
  echo "📥 SVN directory not found. Checking out from WordPress.org..."
  mkdir -p "$SVN_DIR"
  
  if [ -n "$SVN_USERNAME" ]; then
    svn co "$SVN_URL" "$SVN_DIR" --depth immediates --username "$SVN_USERNAME"
  else
    svn co "$SVN_URL" "$SVN_DIR" --depth immediates
  fi
  
  cd "$SVN_DIR"
  svn up trunk
  svn up assets
  svn up tags --depth empty
fi

echo "✅ SVN ready"
echo ""

# Step 3: Clear trunk and extract new build
echo "📋 Step 3: Updating trunk with new build..."

cd "$SVN_DIR"

# Clear trunk
rm -rf trunk/*

# Extract the built plugin
unzip -q "$BUILD_ZIP" -d temp_extract
cp -r temp_extract/${PLUGIN_NAME}/* trunk/
rm -rf temp_extract

echo "✅ Trunk updated"
echo ""

# Step 4: Copy assets to SVN
echo "🎨 Step 4: Copying assets to SVN..."

cd "$SCRIPT_DIR"

# Check if assets directory exists in plugin root
if [ -d "assets" ]; then
  echo "📁 Found assets directory, copying to SVN..."
  
  # Create SVN assets directory if it doesn't exist
  if [ ! -d "$SVN_DIR/assets" ]; then
    cd "$SVN_DIR"
    svn up assets
  fi
  
  # Copy assets
  cp -r assets/* "$SVN_DIR/assets/" 2>/dev/null || true
  
  # Add new assets to SVN
  cd "$SVN_DIR/assets"
  svn add --force * --auto-props --parents --depth infinity -q 2>/dev/null || true
  
  echo "✅ Assets copied"
else
  echo "⚠️  No assets directory found in plugin root, skipping"
fi

echo ""

# Step 5: Check for changes
echo "🔍 Step 5: Checking for changes..."

cd "$SVN_DIR"
svn status

# Check if there are any changes
if [ -z "$(svn status)" ]; then
  echo "⚠️  No changes detected. Nothing to commit."
  exit 0
fi

echo ""

# Step 6: Add new files
echo "➕ Step 6: Adding new files..."

cd "$SVN_DIR/trunk"
svn add --force * --auto-props --parents --depth infinity -q 2>/dev/null || true

echo "✅ New files added"
echo ""

# Step 7: Commit to trunk and assets
echo "💾 Step 7: Committing to trunk and assets..."
echo "Commit message: Update to version $VERSION"
echo ""

read -p "Continue with commit to trunk and assets? (y/n) " -r REPLY
echo
if [ "$REPLY" != "y" ] && [ "$REPLY" != "Y" ]; then
  echo "❌ Aborted by user"
  exit 1
fi

cd "$SVN_DIR"

if [ -n "$SVN_USERNAME" ]; then
  svn ci -m "Update to version $VERSION" --username "$SVN_USERNAME"
else
  svn ci -m "Update to version $VERSION"
fi

echo "✅ Committed to trunk and assets"
echo ""

# Step 8: Tag the release
echo "🏷️  Step 8: Tagging release $VERSION..."

cd "$SVN_DIR"

# Check if tag already exists
if [ -d "tags/$VERSION" ]; then
  echo "⚠️  Warning: Tag $VERSION already exists!"
  read -p "Overwrite existing tag? (y/n) " -r REPLY
  echo
  if [ "$REPLY" != "y" ] && [ "$REPLY" != "Y" ]; then
    echo "❌ Aborted by user"
    exit 1
  fi
  svn rm "tags/$VERSION"
  svn ci -m "Removing old tag $VERSION"
fi

# Create new tag
svn cp trunk "tags/$VERSION"

if [ -n "$SVN_USERNAME" ]; then
  svn ci -m "Tagging version $VERSION" --username "$SVN_USERNAME"
else
  svn ci -m "Tagging version $VERSION"
fi

echo "✅ Tagged version $VERSION"
echo ""

# Summary
echo "🎉 Successfully published PixelFlow v$VERSION to WordPress.org!"
echo ""
echo "📋 Summary:"
echo "  ✅ Built plugin for production"
echo "  ✅ Updated SVN trunk"
echo "  ✅ Copied assets to SVN"
echo "  ✅ Committed changes"
echo "  ✅ Tagged version $VERSION"
echo ""
echo "⏳ The plugin should be available on WordPress.org within 10-15 minutes"
echo "🔗 Check status at: https://wordpress.org/plugins/$PLUGIN_SLUG/"
echo ""

