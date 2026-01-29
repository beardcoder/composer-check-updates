#!/usr/bin/env bash
#
# Release script for composer-check-updates
# Usage: ./release.sh [major|minor|patch|x.y.z]
#

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Get current version from git tags
get_current_version() {
    git describe --tags --abbrev=0 2>/dev/null | sed 's/^v//' || echo "0.0.0"
}

# Increment version
increment_version() {
    local version=$1
    local type=$2
    
    IFS='.' read -r major minor patch <<< "$version"
    
    case $type in
        major)
            echo "$((major + 1)).0.0"
            ;;
        minor)
            echo "${major}.$((minor + 1)).0"
            ;;
        patch)
            echo "${major}.${minor}.$((patch + 1))"
            ;;
        *)
            echo "$type"
            ;;
    esac
}

# Update CHANGELOG.md
update_changelog() {
    local version=$1
    local date=$(date +%Y-%m-%d)
    local temp_file=$(mktemp)
    
    # Get commits since last tag
    local last_tag=$(git describe --tags --abbrev=0 2>/dev/null || echo "")
    local commits=""
    
    if [ -n "$last_tag" ]; then
        commits=$(git log "${last_tag}..HEAD" --pretty=format:"- %s" --no-merges 2>/dev/null || echo "")
    else
        commits=$(git log --pretty=format:"- %s" --no-merges 2>/dev/null || echo "")
    fi
    
    # Create new changelog entry
    {
        echo "# Changelog"
        echo ""
        echo "All notable changes to this project will be documented in this file."
        echo ""
        echo "## [${version}] - ${date}"
        echo ""
        if [ -n "$commits" ]; then
            echo "$commits"
        else
            echo "- Release ${version}"
        fi
        echo ""
        
        # Append old changelog (skip header)
        if [ -f CHANGELOG.md ]; then
            tail -n +4 CHANGELOG.md
        fi
    } > "$temp_file"
    
    mv "$temp_file" CHANGELOG.md
}

# Main
main() {
    local bump_type="${1:-patch}"
    local current_version=$(get_current_version)
    local new_version
    
    # Check for uncommitted changes
    if [ -n "$(git status --porcelain)" ]; then
        echo -e "${RED}Error: You have uncommitted changes${NC}"
        exit 1
    fi
    
    # Calculate new version
    if [[ "$bump_type" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        new_version="$bump_type"
    else
        new_version=$(increment_version "$current_version" "$bump_type")
    fi
    
    echo -e "${CYAN}Current version:${NC} ${current_version}"
    echo -e "${CYAN}New version:${NC}     ${new_version}"
    echo ""
    
    # Confirm
    read -p "Release v${new_version}? [y/N] " -n 1 -r
    echo ""
    
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${YELLOW}Aborted${NC}"
        exit 0
    fi
    
    echo ""
    echo -e "${CYAN}Updating CHANGELOG.md...${NC}"
    update_changelog "$new_version"
    
    echo -e "${CYAN}Creating commit...${NC}"
    git add CHANGELOG.md
    git commit -m "chore: release v${new_version}"
    
    echo -e "${CYAN}Creating tag v${new_version}...${NC}"
    git tag -a "v${new_version}" -m "Release v${new_version}"
    
    echo ""
    echo -e "${GREEN}✓ Release v${new_version} created!${NC}"
    echo ""
    echo -e "Run the following to publish:"
    echo -e "  ${YELLOW}git push origin main --tags${NC}"
    echo ""
    echo -e "Then create a GitHub release:"
    echo -e "  ${YELLOW}gh release create v${new_version} --generate-notes${NC}"
}

main "$@"
