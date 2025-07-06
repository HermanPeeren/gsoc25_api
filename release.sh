#!/bin/bash
# Automated release script for CCM component
# Usage: ./release.sh 1.0.1

set -e

if [ $# -eq 0 ]; then
    echo "Usage: $0 <version>"
    echo "Example: $0 1.0.1"
    exit 1
fi

VERSION=$1
echo "Creating release for version $VERSION"

# Update version
php bump-version.php $VERSION

# Build package
php build.php

# Create git tag
git add -A
git commit -m "Release v$VERSION" || true
git tag -a "v$VERSION" -m "Release v$VERSION"

# Push to GitHub
git push origin main
git push origin "v$VERSION"

echo "✅ Release v$VERSION completed!"
echo "Package: dist/com_ccm-$VERSION.zip"
echo "Tag: v$VERSION pushed to GitHub"
echo ""
echo "Next steps:"
echo "1. Go to GitHub releases page"
echo "2. Create release from tag v$VERSION"
echo "3. Upload dist/com_ccm-$VERSION.zip as release asset"
