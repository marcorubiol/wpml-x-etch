#!/bin/bash

# WPML x Etch - Distribution Build Script
# Generates 4 distributable versions of the plugin

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="$SCRIPT_DIR/dist"
PLUGIN_SLUG="wpml-x-etch"

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Common excludes for all distribution zips
RSYNC_EXCLUDES=(
	--exclude='dist'
	--exclude='build-distributions.sh'
	--exclude='.git'
	--exclude='.gitignore'
	--exclude='.DS_Store'
	--exclude='node_modules'
	--exclude='src/js'
	--exclude='package.json'
	--exclude='package-lock.json'
	--exclude='vite.config.js'
	--exclude='AGENTS.md'
	--exclude='DISTRIBUTION.md'
	--exclude='CLAUDE.md'
	--exclude='.claude'
	--exclude='.zed'
	--exclude='intelephense.json'
	--exclude='composer.lock'
	--exclude='.github'
	--exclude='wxe-language-switcher.html'
)

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  WPML x Etch - Distribution Builder${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# Build production assets
echo -e "${YELLOW}Building production assets...${NC}"
cd "$SCRIPT_DIR"
npm run build
echo -e "${GREEN}✓ Assets built${NC}"

# Install production-only dependencies
echo -e "${YELLOW}Installing production dependencies...${NC}"
composer install --no-dev --optimize-autoloader --quiet 2>/dev/null
echo -e "${GREEN}✓ Production vendor ready${NC}"
echo ""

# Clean and create build directory
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

# Helper: copy plugin files, patch locking mode, zip
build_variant() {
	local dir="$1" mode="$2"
	mkdir -p "$dir"
	rsync -a "${RSYNC_EXCLUDES[@]}" "$SCRIPT_DIR/" "$dir/"
	sed -i.bak "s/apply_filters( 'zs_wxe_locking_mode', '[^']*' )/apply_filters( 'zs_wxe_locking_mode', '$mode' )/g" "$dir/src/Admin/PanelConfig.php"
	rm "$dir/src/Admin/PanelConfig.php.bak"
}

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# 1. FREE VERSION (Shows lock icons)
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

echo -e "${YELLOW}[1/4]${NC} Building FREE version..."
build_variant "$BUILD_DIR/${PLUGIN_SLUG}-free" "free"
cd "$BUILD_DIR"
zip -rq "${PLUGIN_SLUG}-free.zip" "${PLUGIN_SLUG}-free"
echo -e "${GREEN}✓${NC} Created: ${PLUGIN_SLUG}-free.zip"

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# 2. SUPPORTER VERSION (Hides locked features)
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

echo -e "${YELLOW}[2/4]${NC} Building SUPPORTER version..."
build_variant "$BUILD_DIR/${PLUGIN_SLUG}-supporter" "supporter"
cd "$BUILD_DIR"
zip -rq "${PLUGIN_SLUG}-supporter.zip" "${PLUGIN_SLUG}-supporter"
echo -e "${GREEN}✓${NC} Created: ${PLUGIN_SLUG}-supporter.zip"

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# 3. PRO VERSION (Everything unlocked)
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

echo -e "${YELLOW}[3/4]${NC} Building PRO version..."
build_variant "$BUILD_DIR/${PLUGIN_SLUG}-pro" "pro"

# Remove locking files from pro
rm -f "$BUILD_DIR/${PLUGIN_SLUG}-pro/assets/wxe-locking.js"
rm -f "$BUILD_DIR/${PLUGIN_SLUG}-pro/assets/wxe-locking.css"
rm -f "$BUILD_DIR/${PLUGIN_SLUG}-pro/assets/wxe-supporter.css"

cd "$BUILD_DIR"
zip -rq "${PLUGIN_SLUG}-pro.zip" "${PLUGIN_SLUG}-pro"
echo -e "${GREEN}✓${NC} Created: ${PLUGIN_SLUG}-pro.zip"

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# 4. PUBLIC VERSION (Pro with neutral naming)
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

echo -e "${YELLOW}[4/4]${NC} Building PUBLIC version..."
cp -r "$BUILD_DIR/${PLUGIN_SLUG}-supporter" "$BUILD_DIR/${PLUGIN_SLUG}"
cd "$BUILD_DIR"
zip -rq "${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}"
echo -e "${GREEN}✓${NC} Created: ${PLUGIN_SLUG}.zip (public distribution, supporter tier)"

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# Summary
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

# Restore dev dependencies for local development
composer install --quiet 2>/dev/null || true

echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✓ Build Complete!${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Distribution files created in: $BUILD_DIR"
echo ""
echo "  1. ${PLUGIN_SLUG}-free.zip       → free mode (lock icons visible)"
echo "  2. ${PLUGIN_SLUG}-supporter.zip  → supporter mode (all translation features)"
echo "  3. ${PLUGIN_SLUG}-pro.zip        → pro mode (everything unlocked)"
echo "  4. ${PLUGIN_SLUG}.zip            → public distribution (same as supporter)"
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
