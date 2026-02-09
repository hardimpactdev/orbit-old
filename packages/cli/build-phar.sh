#!/usr/bin/env bash
set -e

echo "🔧 Preparing orbit-core for phar build..."

CORE_VENDOR_PATH="vendor/hardimpactdev/orbit-core"

# Check if orbit-core is a symlink
if [ -L "$CORE_VENDOR_PATH" ]; then
    echo "📦 Found symlinked orbit-core, preparing to copy files..."

    # Save the original symlink target (relative path)
    ORIGINAL_SYMLINK_TARGET=$(readlink "$CORE_VENDOR_PATH")
    echo "📝 Saved symlink target: $ORIGINAL_SYMLINK_TARGET"

    # Resolve the symlink to absolute path for copying
    CORE_ABSOLUTE_PATH=$(cd "$(dirname "$CORE_VENDOR_PATH")" && cd "$ORIGINAL_SYMLINK_TARGET" && pwd)
    echo "📍 Resolved to: $CORE_ABSOLUTE_PATH"

    # Remove the symlink
    rm "$CORE_VENDOR_PATH"

    # Copy the actual files
    echo "📋 Copying orbit-core files..."
    cp -R "$CORE_ABSOLUTE_PATH" "$CORE_VENDOR_PATH"

    echo "✅ orbit-core files copied successfully"
else
    echo "ℹ️  orbit-core is not a symlink, skipping copy"
    ORIGINAL_SYMLINK_TARGET=""
fi

echo ""
echo "🏗️  Building phar with Box..."
~/.composer/vendor/bin/box compile

echo ""
if [ -n "$ORIGINAL_SYMLINK_TARGET" ]; then
    echo "🔄 Restoring orbit-core symlink..."
    rm -rf "$CORE_VENDOR_PATH"
    ln -s "$ORIGINAL_SYMLINK_TARGET" "$CORE_VENDOR_PATH"
    echo "✅ Symlink restored: $CORE_VENDOR_PATH -> $ORIGINAL_SYMLINK_TARGET"
fi

echo ""
echo "✅ Build complete! Phar location: builds/orbit.phar"
echo "📊 Phar size: $(du -h builds/orbit.phar | cut -f1)"
