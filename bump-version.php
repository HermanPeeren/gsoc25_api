#!/usr/bin/env php
<?php
/**
 * Version bump script for CCM component
 *
 * Usage: php bump-version.php [new_version]
 * Example: php bump-version.php 1.0.1
 *
 * @package     CCM
 * @author      Joomla! Project
 * @copyright   2025 Joomla! Project
 * @license     GNU General Public License version 2 or later
 */

class VersionBumper
{
    private $buildScript = 'build.php';
    private $manifestFile = 'component_manifest.xml';
    
    public function __construct()
    {
        echo "CCM Component Version Bumper\n";
        echo "============================\n";
    }
    
    public function bump($newVersion)
    {
        if (!$this->validateVersion($newVersion)) {
            throw new Exception("Invalid version format. Use semantic versioning (e.g., 1.0.0)");
        }
        
        $this->updateBuildScript($newVersion);
        $this->updateManifest($newVersion);
        
        echo "Version bumped to: $newVersion\n";
        echo "Files updated:\n";
        echo "- $this->buildScript\n";
        echo "- $this->manifestFile\n";
        echo "\nRun 'php build.php' to create the new package.\n";
    }
    
    private function validateVersion($version)
    {
        return preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9]+)?$/', $version);
    }
    
    private function updateBuildScript($newVersion)
    {
        $content = file_get_contents($this->buildScript);
        $pattern = "/private \$version = '[^']+';/";
        $replacement = "private \$version = '$newVersion';";
        
        $newContent = preg_replace($pattern, $replacement, $content);
        
        if ($newContent === $content) {
            throw new Exception("Could not find version in build script");
        }
        
        file_put_contents($this->buildScript, $newContent);
    }
    
    private function updateManifest($newVersion)
    {
        $content = file_get_contents($this->manifestFile);
        $pattern = '/<version>.*<\/version>/';
        $replacement = "<version>$newVersion</version>";
        
        $newContent = preg_replace($pattern, $replacement, $content);
        
        if ($newContent === $content) {
            // Try with placeholder
            $pattern = '/<version>__DEPLOY_VERSION__<\/version>/';
            $replacement = "<version>__DEPLOY_VERSION__</version>";
            $newContent = preg_replace($pattern, $replacement, $content);
        }
        
        file_put_contents($this->manifestFile, $newContent);
    }
    
    public function getCurrentVersion()
    {
        $content = file_get_contents($this->buildScript);
        if (preg_match("/private \\\$version = '([^']+)';/", $content, $matches)) {
            return $matches[1];
        }
        return null;
    }
}

// Main execution
try {
    $bumper = new VersionBumper();
    
    if ($argc < 2) {
        $current = $bumper->getCurrentVersion();
        echo "Current version: " . ($current ?: 'unknown') . "\n";
        echo "Usage: php bump-version.php [new_version]\n";
        echo "Example: php bump-version.php 1.0.1\n";
        exit(0);
    }
    
    $newVersion = $argv[1];
    $bumper->bump($newVersion);
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
